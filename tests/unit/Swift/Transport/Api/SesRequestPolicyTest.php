<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_SesRequestPolicyTest extends TestCase
{
    /** @dataProvider redirects */
    public function testRedirectCannotBeFollowedRetriedOrAccepted(string $kind, int $status, string $destination, bool $retry): void
    {
        $fixture   = new SesSdkFixture([new MockResponse('fixture-body secret', ['http_code' => $status, 'response_headers' => ['Location: '.$destination]])], $retry);
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = new $class($fixture->client);
        $failed    = [];
        try {
            (new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('A redirected SES send cannot count as accepted mail.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertStringNotContainsString('fixture-body secret', $error->getMessage());
            $this->assertStringNotContainsString($destination, $error->getMessage());
        }
        $this->assertCount(1, $fixture->requests);
        $this->assertSame(0, $fixture->requests[0]['options']['max_redirects']);
        $this->assertSame(['to@example.test', 'blind@example.test'], $failed);
        $reflection = new ReflectionMethod($transport, 'getApiConnection');
        $this->assertSame($fixture->client, $reflection->invoke($transport));
    }

    public static function redirects(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach ([301, 302, 303, 307, 308] as $status) {
                foreach (['/same-origin', 'https://other.example.test/ses', 'http://other.example.test/ses', '//other.example.test/ses'] as $destination) {
                    foreach ([false, true] as $retry) {
                        yield $kind.' '.$status.' '.$destination.' retry '.(int) $retry => [$kind, $status, $destination, $retry];
                    }
                }
            }
        }
    }

    /** @dataProvider kinds */
    public function testOrdinary503RetainsCallerRetryAndSigning(string $kind): void
    {
        $fixture = new SesSdkFixture([new MockResponse('{"message":"busy"}', ['http_code' => 503])], true);
        $class   = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $this->assertSame(2, (new $class($fixture->client))->send($this->message()));
        $this->assertCount(2, $fixture->requests);
        foreach ($fixture->requests as $request) {
            $this->assertSame(0, $request['options']['max_redirects']);
            $this->assertStringContainsString('AWS4-HMAC-SHA256', \implode("\n", $request['options']['headers']));
            $this->assertStringContainsString('fixture-key/', \implode("\n", $request['options']['headers']));
        }
    }

    /** @dataProvider kinds */
    public function testHealthResolvesActualSdkOperation(string $kind): void
    {
        $fixture = new SesSdkFixture([new MockResponse('{}', ['http_code' => 200])]);
        $class   = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $this->assertTrue((new $class($fixture->client))->ping());
        $this->assertCount(1, $fixture->requests);
        $this->assertSame('GET', $fixture->requests[0]['method']);
        $this->assertSame(0, $fixture->requests[0]['options']['max_redirects']);
    }

    /** @dataProvider healthResponses */
    public function testHealthResponsePolicy(string $kind, int $status, bool $healthy): void
    {
        $body    = 404 === $status ? '{"message":"not suppressed"}' : '{}';
        $headers = 404 === $status ? ['x-amzn-errortype: NotFoundException'] : ['Location: https://other.example.test/health'];
        $fixture = new SesSdkFixture([new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers])], true);
        $class   = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $this->assertSame($healthy, (new $class($fixture->client))->ping());
        $this->assertCount(1, $fixture->requests);
        $this->assertSame(0, $fixture->requests[0]['options']['max_redirects']);
        $this->assertStringContainsString('/suppression/addresses/ping%40swiftmailer.invalid', $fixture->requests[0]['url']);
    }

    public static function healthResponses(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach ([200 => true, 301 => false, 302 => false, 303 => false, 307 => false, 308 => false, 401 => false, 403 => false, 404 => true, 400 => false, 500 => false] as $status => $healthy) {
                yield "$kind $status" => [$kind, $status, $healthy];
            }
        }
    }

    /** @dataProvider kinds */
    public function testDebugLoggingAndSdkCleanupCannotHideOrRepeatRefusal(string $kind): void
    {
        $fixture   = new SesSdkFixture([new MockResponse('{"MessageId":"never-accepted"}', ['http_code' => 307, 'response_headers' => ['Location: https://other.example.test/collect']])], true, null, ['debug' => 'true']);
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = new $class($fixture->client);
        try {
            $transport->send($this->message());
            $this->fail('Debug logging must preserve a permanent refusal.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $fixture->requests);
        unset($transport, $fixture);
        \gc_collect_cycles();
        $this->addToAssertionCount(1);
    }

    public static function kinds(): iterable
    {
        yield 'Api' => ['Api'];
        yield 'Http' => ['Http'];
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture-subject', 'fixture-body secret'))
            ->setFrom('from@example.test')->setTo('to@example.test')->setBcc('blind@example.test');
    }
}
