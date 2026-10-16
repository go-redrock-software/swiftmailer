<?php

use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Ses\SesClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_AcceptedReplayCompatibilityTest extends TestCase
{
    /** @dataProvider gmailRetries */
    public function testGmailCallerMiddlewareCannotResendAcceptedMessage(int $status, bool $throws): void
    {
        $calls = 0;
        $stack = HandlerStack::create(static function () use (&$calls, $status) {
            ++$calls;

            return Create::promiseFor(new Response($status, [], '{"id":"accepted"}'));
        });
        if ($throws) {
            $stack->push(static fn (callable $next) => static fn ($request, $options) => $next($request, $options)->otherwise(static fn () => $next($request, $options)));
            $stack->push(static fn (callable $next) => static fn ($request, $options) => $next($request, $options)->then(static function () {
                throw new RuntimeException('fixture callback after acceptance');
            }));
        } else {
            $stack->push(Middleware::retry(static fn ($retry) => $retry < 1, static fn () => 0));
        }
        $http = new Client(['handler' => $stack]);
        $sdk  = new Google\Client();
        $sdk->setAccessToken(['access_token' => 'fixture-token', 'created' => \time(), 'expires_in' => 3600]);
        $sdk->setHttpClient($http);
        try {
            $this->wrapped(new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message(), $failed);
            $this->fail('An accepted message cannot be replayed by caller middleware.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame($status, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(1, $calls);
        $this->assertSame(['to@example.test'], $failed);
        $this->assertSame($http, $sdk->getHttpClient());
    }

    public static function gmailRetries(): iterable
    {
        foreach ([200, 201, 202] as $status) {
            foreach ([false, true] as $throws) {
                yield [$status, $throws];
            }
        }
    }

    /** @dataProvider sesRetries */
    public function testSesCallerStrategyCannotResendAcceptedMessage(string $kind, int $status, string $mode): void
    {
        $fixture  = new SesSdkFixture([new MockResponse('{"MessageId":"accepted"}', ['http_code' => $status])]);
        $strategy = new class($mode) implements RetryStrategyInterface {
            public function __construct(private string $mode)
            {
            }

            public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
            {
                if ('throw' === $this->mode) {
                    throw new RuntimeException('fixture strategy after acceptance');
                }

                return 'body' === $this->mode && null === $responseContent ? null : true;
            }

            public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
            {
                return 0;
            }
        };
        $http  = new RetryableHttpClient($fixture->http, $strategy, 1);
        $sdk   = new SesClient(['region' => 'us-east-1'], new Credentials('fixture-key', 'fixture-secret'), $http);
        $class = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        try {
            $this->wrapped(new $class($sdk))->send($this->message(), $failed);
            $this->fail('An accepted SES request cannot be replayed by a caller strategy.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame($status, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $fixture->requests);
        $this->assertSame(['to@example.test'], $failed);
        $this->assertSame($http, Swift_Transport_Api_SesRequestPolicy::read(AsyncAws\Core\AbstractApi::class, 'httpClient', $sdk));
        $this->assertSame($strategy, Swift_Transport_Api_SesRequestPolicy::read(RetryableHttpClient::class, 'strategy', $http));
    }

    public static function sesRetries(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach ([200, 201, 202] as $status) {
                foreach (['status', 'body', 'throw'] as $mode) {
                    yield [$kind, $status, $mode];
                }
            }
        }
    }

    public function testSesSuccessfulCredentialRequestMayStillUseCallerRetryStrategy(): void
    {
        $fixture = new SesSdkFixture([new MockResponse('{}'), new MockResponse('{}')]);
        $http    = new RetryableHttpClient($fixture->http, new GenericRetryStrategy([200], 0), 1);
        $sdk     = new SesClient(['region' => 'us-east-1'], new Credentials('fixture-key', 'fixture-secret'), $http);
        $result  = Swift_Transport_Api_SesRequestPolicy::run($sdk, static function (SesClient $scoped): string {
            $client = Swift_Transport_Api_SesRequestPolicy::read(AsyncAws\Core\AbstractApi::class, 'httpClient', $scoped);

            return $client->request('POST', 'https://credentials.example.test/token')->getContent(false);
        });
        $this->assertSame('{}', $result);
        $this->assertCount(2, $fixture->requests);
    }

    private function wrapped(Swift_Transport $transport): Swift_Transport
    {
        return new Swift_Transport_RetryTransport($transport, 2, 0, new class implements Swift_Transport_RetryClassifier {
            public function isRetryable(Swift_TransportException $error): bool
            {
                return true;
            }
        });
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('retry review', 'body'))->setFrom('sender@example.test')->setTo('to@example.test');
    }
}
