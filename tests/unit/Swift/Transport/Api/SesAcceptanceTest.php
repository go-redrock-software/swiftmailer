<?php

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_SesAcceptanceTest extends TestCase
{
    /** @dataProvider invalidResponses */
    public function testMalformedAcceptanceIsPermanentBeforeSdkCoercion(string $kind, bool $dsn, string $wrapper, string $body): void
    {
        $fixture   = new SesSdkFixture([new MockResponse($body, ['http_code' => 200])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $transport = $this->transport($fixture, $kind, $dsn);
        $fallback  = $this->transport($fixture, $kind, $dsn);
        if ('retry' === $wrapper) {
            $transport = new Swift_Transport_RetryTransport($transport, 2, 0, new class implements Swift_Transport_RetryClassifier {
                public function isRetryable(Swift_TransportException $error): bool
                {
                    return true;
                }
            });
        } elseif ('plain' !== $wrapper) {
            $pool = 'failover' === $wrapper ? new Swift_Transport_FailoverTransport() : new Swift_Transport_LoadBalancedTransport();
            $pool->setTransports([$transport, $fallback]);
            $transport = $pool;
        }
        $message = $this->message();
        $failed  = [];
        try {
            $transport->send($message, $failed);
            $this->fail('Malformed acceptance cannot be reported as an ordinary success/zero send.');
        } catch (Swift_TransportException $error) {
            $this->assertTrue(Swift_Transport_RetryPolicy::isTerminalFailure($error));
            $this->assertSame(200, $error->getCode());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $fixture->requests);
        $this->assertSame(['to@example.test'], $failed);
        $this->assertNull($message->getHeaders()->get('X-SES-Message-ID'));
    }

    public static function invalidResponses(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach ([false, true] as $dsn) {
                foreach (['plain', 'retry', 'failover', 'roundrobin'] as $wrapper) {
                    foreach (['{}', '[]', '{"MessageId":null}', '{"MessageId":[]}', '{"MessageId":false}', '{"MessageId":7}', '{"MessageId":""}', '{"MessageId":" \\t "}', '{bad-json', '{"MessageId":"bad\\r\\nid"}', '"id"', '{"messageId":"wrong-case"}'] as $body) {
                        yield [$kind, $dsn, $wrapper, $body];
                    }
                }
            }
        }
    }

    /** @dataProvider validResponses */
    public function testNonemptyStringIdsIncludingZeroRemainValid(string $kind, bool $dsn, int $status, string $id): void
    {
        $fixture   = new SesSdkFixture([new MockResponse(\json_encode(['MessageId' => $id], JSON_THROW_ON_ERROR), ['http_code' => $status])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $transport = $this->transport($fixture, $kind, $dsn);
        $message   = $this->message();
        $this->assertSame(1, $transport->send($message));
        $this->assertCount(1, $fixture->requests);
        if ('Http' === $kind) {
            $this->assertSame($id, $message->getHeaders()->get('X-SES-Message-ID')->getFieldBody());
        }
    }

    public static function validResponses(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach ([false, true] as $dsn) {
                foreach ([200, 201, 202] as $status) {
                    yield [$kind, $dsn, $status, 'fixture-id'];
                    yield [$kind, $dsn, $status, '0'];
                }
            }
        }
    }

    /** @dataProvider constructions */
    public function testInterruptedSuccessfulBodyIsPermanent(string $kind, bool $dsn): void
    {
        $body = (static function (): Generator {
            yield '{"MessageId":';
            throw new Symfony\Component\HttpClient\Exception\TransportException('fixture body interrupted');
        })();
        $fixture   = new SesSdkFixture([new MockResponse($body, ['http_code' => 200])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $transport = $this->transport($fixture, $kind, $dsn);
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($this->message());
            $this->fail('Interrupted 2xx acceptance must reject permanently.');
        } catch (Swift_TransportException $error) {
            $this->assertTrue(Swift_Transport_RetryPolicy::isTerminalFailure($error));
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $fixture->requests);
    }

    /** @dataProvider constructions */
    public function testAcceptanceRefusalDoesNotPoisonTheNextExplicitSend(string $kind, bool $dsn): void
    {
        $fixture   = new SesSdkFixture([new MockResponse('{}', ['http_code' => 200])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $transport = $this->transport($fixture, $kind, $dsn);
        try {
            $transport->send($this->message());
            $this->fail('The first malformed response must reject.');
        } catch (Swift_TransportException $error) {
            $this->assertTrue(Swift_Transport_RetryPolicy::isTerminalFailure($error));
        }
        $this->assertSame(1, $transport->send($this->message()));
        $this->assertCount(2, $fixture->requests);
    }

    public static function constructions(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            yield [$kind, false];
            yield [$kind, true];
        }
    }

    public function testArrayResponseInterfaceUsesTheSameAcceptanceValidation(): void
    {
        $fixture = new SesSdkFixture([new MockResponse('{"MessageId":"0"}', ['http_code' => 200])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $result  = Swift_Transport_Api_SesRequestPolicy::run($fixture->client, static function (AsyncAws\Ses\SesClient $client): array {
            $http = Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client);

            return $http->request('POST', 'https://ses.example.test/v2/email/outbound-emails')->toArray(false);
        });
        $this->assertSame(['MessageId' => '0'], $result);
        $fixture = new SesSdkFixture([new MockResponse('{"MessageId":[]}', ['http_code' => 200])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $this->expectException(Swift_Transport_NonRetryableException::class);
        Swift_Transport_Api_SesRequestPolicy::run($fixture->client, static function (AsyncAws\Ses\SesClient $client): array {
            $http = Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client);

            return $http->request('POST', 'https://ses.example.test/v2/email/outbound-emails')->toArray(false);
        });
    }

    public function testSwallowedAcceptanceFailureRemainsLatched(): void
    {
        $fixture = new SesSdkFixture([new MockResponse('{}', ['http_code' => 200])], credentials: new Credentials('fixture-key', 'fixture-secret'));
        $this->expectException(Swift_Transport_NonRetryableException::class);
        Swift_Transport_Api_SesRequestPolicy::run($fixture->client, static function (AsyncAws\Ses\SesClient $client): string {
            $http = Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client);
            try {
                $http->request('POST', 'https://ses.example.test/v2/email/outbound-emails')->getContent(false);
            } catch (Swift_Transport_NonRetryableException) {
                // SDK diagnostics/cleanup may swallow a response exception.
            }

            return 'must-not-escape';
        });
    }

    private function transport(SesSdkFixture $fixture, string $kind, bool $dsn): Swift_Transport
    {
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = $dsn ? (new Swift_Transport_DsnTransportFactory($fixture->http))->fromDsnString('amazon+'.('Api' === $kind ? 'api' : 'http').'://fixture-key:fixture-secret@default') : new $class($fixture->client);
        $client    = (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
        $this->assertSame($fixture->http, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client));

        return $transport;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('acceptance', 'body'))->setFrom('sender@example.test')->setTo('to@example.test');
    }
}
