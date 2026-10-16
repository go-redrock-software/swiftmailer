<?php

use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_Api_GmailAcceptanceTest extends TestCase
{
    /** @dataProvider invalidResponses */
    public function testInvalidAcceptanceIsPermanentAcrossDeliveryWrappers(string $body, string $wrapper, bool $deferred): void
    {
        $wire         = [];
        [$sdk, $http] = $this->sdk([new Response(200, [], $body), new Response(200, [], '{"id":"unexpected-replay"}')], $wire);
        $sdk->setDefer($deferred);
        $transport        = new Swift_Transport_Api_GoogleTransport($sdk);
        $observer         = $this->observe($transport);
        $fallback         = new Swift_Transport_NullTransport(new Swift_Events_SimpleEventDispatcher());
        $fallbackObserver = $this->observe($fallback);
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
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        try {
            $transport->send($message, $failed, Swift_Envelope::fromMessage($message));
            $this->fail('Invalid Gmail acceptance must not return success or ordinary zero.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $wire);
        $this->assertEqualsCanonicalizing(['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
        $this->assertSame([1, 1, Swift_Events_SendEvent::RESULT_FAILED], [$observer->before, $observer->completed, $observer->result]);
        $this->assertEqualsCanonicalizing($failed, $observer->failed);
        $this->assertSame([0, 0], [$fallbackObserver->before, $fallbackObserver->completed]);
        $this->assertSame($http, $sdk->getHttpClient());
        $this->assertSame($deferred, $sdk->shouldDefer());
        $this->assertSame($original, $message->toString());
    }

    public static function invalidResponses(): iterable
    {
        foreach (['{}', '[]', 'null', '"id"', '{bad-json', '{"id":null}', '{"id":[]}', '{"id":{}}', '{"id":false}', '{"id":123}', '{"id":""}', '{"id":" \\t "}', '{"id":"bad\\r\\nid"}', '{"id":"bad\\u00a0id"}', '{"Id":"wrong-case"}'] as $body) {
            foreach (['plain', 'retry', 'failover', 'roundrobin'] as $wrapper) {
                foreach ([false, true] as $deferred) {
                    yield [$body, $wrapper, $deferred];
                }
            }
        }
    }

    /** @dataProvider validResponses */
    public function testValidAcceptancePreservesNonseekableBodiesAndZeroIds(int $status, string $id, bool $nonseekable): void
    {
        $wire = [];
        $body = Utils::streamFor(\json_encode(['id' => $id], JSON_THROW_ON_ERROR));
        if ($nonseekable) {
            $body = new NoSeekStream($body);
        }
        [$sdk, $http] = $this->sdk([new Response($status, [], $body)], $wire);
        $message      = $this->message()->attach(new Swift_Attachment("binary\0\xff", 'fixture.bin'));
        $original     = $message->toString();
        try {
            $count = (new Swift_Transport_Api_GoogleTransport($sdk))->send($message, $failed);
        } catch (Throwable $error) {
            $this->fail('Valid Gmail acceptance was rejected with '.$error::class);
        }
        $this->assertSame(3, $count);
        $this->assertSame([], $failed);
        $this->assertCount(1, $wire);
        $this->assertSame('Bearer fixture-access', $wire[0]['request']->getHeaderLine('Authorization'));
        $this->assertFalse($wire[0]['options']['allow_redirects']);
        $payload = \json_decode($wire[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($original, Swift\base64url_decode($payload['raw']));
        $this->assertSame($http, $sdk->getHttpClient());
    }

    public static function validResponses(): iterable
    {
        foreach ([200, 201, 202] as $status) {
            foreach (['fixture-id', '0'] as $id) {
                foreach ([false, true] as $nonseekable) {
                    yield [$status, $id, $nonseekable];
                }
            }
        }
    }

    /** @dataProvider successStatuses */
    public function testEmptySuccessfulResponsesArePermanent(int $status): void
    {
        $wire  = [];
        [$sdk] = $this->sdk([new Response($status)], $wire);
        try {
            (new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message());
            $this->fail('Empty success is ambiguous.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame($status, $error->getCode());
        }
        $this->assertCount(1, $wire);
    }

    public static function successStatuses(): iterable
    {
        foreach ([200, 201, 202, 204, 299] as $status) {
            yield [$status];
        }
    }

    public function testInterruptedSuccessfulResponseCannotBeReplayedBySdkOrCallerRetryMiddleware(): void
    {
        $wire    = [];
        $attempt = 0;
        $stream  = new PumpStream(static function () use (&$attempt) {
            if (0 === $attempt++) {
                return '{"id":';
            }
            throw new RuntimeException('TRACE-BODY-DIAGNOSTIC');
        });
        [$sdk, , $stack] = $this->sdk([new Response(200, [], $stream), new Response(200, [], '{"id":"unexpected"}')], $wire);
        $stack->push(static function (callable $next): callable {
            return static function ($request, $options) use ($next) {
                return $next($request, $options)->otherwise(static fn () => $next($request, $options));
            };
        }, 'fixture_retry_any_failure');
        $sdk->setConfig('retry', ['retries' => 2, 'initial_delay' => 0]);
        $sdk->setConfig('retry_map', [200 => Google\Task\Runner::TASK_RETRY_ALWAYS]);
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_GoogleTransport($sdk), 2, 0))->send($this->message());
            $this->fail('Interrupted acceptance is permanent.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $wire);
    }

    /** @dataProvider rejectedTransfers */
    public function testRejectedTransfersWithSuccessfulHeadersArePermanent(string $body, bool $async): void
    {
        $wire         = [];
        $responseBody = 'interrupted' === $body ? new PumpStream(static function () {
            throw new RuntimeException('fixture incomplete body');
        }) : $body;
        [$sdk] = $this->sdk([static function (RequestInterface $request) use ($responseBody, $async) {
            $error = new GuzzleHttp\Exception\RequestException('fixture incomplete transfer', $request, new Response(200, [], $responseBody));
            if ($async) {
                return GuzzleHttp\Promise\Create::rejectionFor($error);
            }
            throw $error;
        }], $wire);
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_GoogleTransport($sdk), 2, 0))->send($this->message(), $failed);
            $this->fail('Known-success headers on an incomplete transfer are ambiguous.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $wire);
        $this->assertCount(3, $failed);
    }

    public static function rejectedTransfers(): iterable
    {
        foreach (['{}', '{"id":"received-before-transfer-error"}', 'interrupted'] as $body) {
            foreach ([false, true] as $async) {
                yield [$body, $async];
            }
        }
    }

    public function testMalformedRefusalCannotBeSwallowedByCallerMiddleware(): void
    {
        $wire            = [];
        [$sdk, , $stack] = $this->sdk([new Response(200, [], '{}')], $wire);
        $stack->push(static fn (callable $next): callable => static fn ($request, $options) => $next($request, $options)->otherwise(static fn () => new Response(200, [], '{"id":"invented"}')), 'fixture_swallow');
        $this->expectException(Swift_Transport_NonRetryableException::class);
        try {
            (new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message());
        } finally {
            $this->assertCount(1, $wire);
        }
    }

    public function testCallerMiddlewareCannotRewriteMalformedSuccessIntoAcceptance(): void
    {
        $wire            = [];
        [$sdk, , $stack] = $this->sdk([new Response(200, [], '{}')], $wire);
        $stack->push(static fn (callable $next): callable => static fn ($request, $options) => $next($request, $options)->then(static fn () => new Response(200, [], '{"id":"invented"}')), 'fixture_rewrite');
        $this->expectException(Swift_Transport_NonRetryableException::class);
        try {
            (new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message());
        } finally {
            $this->assertCount(1, $wire);
        }
    }

    public function testLaterExplicitSendGetsANewAcceptanceScope(): void
    {
        $wire      = [];
        [$sdk]     = $this->sdk([new Response(200, [], '{}'), new Response(200, [], '{"id":"next"}')], $wire);
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        try {
            $transport->send($this->message());
            $this->fail('The first response must reject.');
        } catch (Swift_Transport_NonRetryableException) {
        }
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(2, $wire);
    }

    public function testTokenRefreshJsonKeepsItsOwnSchemaAndCallerState(): void
    {
        $wire         = [];
        [$sdk, $http] = $this->sdk([new Response(200, [], '{"access_token":"refreshed-access","expires_in":3600}'), new Response(200, [], '{"id":"accepted"}')], $wire);
        $sdk->setClientId('fixture-client');
        $sdk->setClientSecret('fixture-secret');
        $sdk->setAccessToken(['access_token' => 'expired', 'refresh_token' => 'fixture-refresh', 'created' => \time() - 4000, 'expires_in' => 3600]);
        $this->assertSame(3, (new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message()));
        $this->assertCount(2, $wire);
        $this->assertStringContainsString('refresh_token=fixture-refresh', $wire[0]['body']);
        $this->assertSame('Bearer refreshed-access', $wire[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame($http, $sdk->getHttpClient());
    }

    /** @dataProvider sdkCustomizations */
    public function testSdkCannotHideRefusalOrInventAcceptance(string $mode): void
    {
        $wire              = [];
        [$original, $http] = $this->sdk([new Response(200, [], 'swallow' === $mode ? '{}' : '{"id":"accepted"}')], $wire);
        $sdk               = new class($mode) extends GoogleClient {
            public function __construct(private string $mode)
            {
                parent::__construct();
            }

            public function execute(RequestInterface $request, $expectedClass = null)
            {
                if ('no HTTP' === $this->mode) {
                    return new Google\Service\Gmail\Message(['id' => 'invented']);
                }
                try {
                    $result = parent::execute($request, $expectedClass);
                } catch (Throwable $error) {
                    if ('swallow' === $this->mode) {
                        return new Google\Service\Gmail\Message(['id' => 'invented']);
                    }
                    throw $error;
                }
                if ('error after success' === $this->mode) {
                    throw new TypeError('TRACE-POST-ACCEPTANCE');
                }

                return new Google\Service\Gmail\Message(['id' => []]);
            }
        };
        $sdk->setHttpClient($http);
        $sdk->setAccessToken($original->getAccessToken());
        $this->assertSame($http, $sdk->getHttpClient());
        try {
            (new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message());
            $this->fail('SDK results cannot replace observed valid acceptance.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame('no HTTP' === $mode ? 0 : 200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount('no HTTP' === $mode ? 0 : 1, $wire);
    }

    public static function sdkCustomizations(): iterable
    {
        foreach (['swallow', 'no HTTP', 'invalid result', 'error after success'] as $mode) {
            yield [$mode];
        }
    }

    private function sdk(array $responses, array &$wire): array
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = static function (RequestInterface $request, array $options) use (&$wire, $response) {
                $wire[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

                return $response instanceof Closure ? $response($request) : $response;
            };
        }
        $stack = HandlerStack::create(new MockHandler($queue));
        $http  = new Client(['handler' => $stack]);
        $sdk   = new GoogleClient();
        $sdk->setHttpClient($http);
        $sdk->setAccessToken(['access_token' => 'fixture-access', 'created' => \time(), 'expires_in' => 3600]);
        $this->assertSame($http, $sdk->getHttpClient(), 'The fixture must own the HTTP client before sending.');

        return [$sdk, $http, $stack];
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public int $result = 0;

            public array $failed = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->completed;
                $this->result = $evt->getResult();
                $this->failed = $evt->getFailedRecipients();
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setCc('copy@safe.test')->setBcc('blind@safe.test');
    }
}
