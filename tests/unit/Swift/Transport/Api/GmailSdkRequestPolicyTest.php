<?php

use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_Api_GmailSdkRequestPolicyTest extends TestCase
{
    /** @dataProvider redirects */
    public function testRedirectDoesNotForwardOrReplayTheGmailRequest(int $status, string $target, bool $deferred, bool $explicit): void
    {
        $wire                 = [];
        [$sdk, $http, $stack] = $this->sdk([
            new Response($status, ['Location' => $target]),
            new Response(200, [], '{"id":"redirected-id"}'),
        ], $wire);
        $sdk->setDefer($deferred);
        $sdk->setConfig('retry', ['retries' => 2, 'initial_delay' => 0]);
        $sdk->setConfig('retry_map', [301 => 2, 302 => 2, 303 => 2, 307 => 2, 308 => 2]);
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        $observer  = $this->observe($transport);
        $message   = $this->message();
        $original  = $message->toString();
        $failed    = [];
        $retry     = new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0);
        try {
            $retry->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('A redirected Gmail request must fail without claiming accepted recipients.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame($status, $error->getCode());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertStringNotContainsString('fixture-access-token', $error->getMessage());
        }
        $this->assertCount(1, $wire);
        $this->assertSame('gmail.googleapis.com', $wire[0]['request']->getUri()->getHost());
        $this->assertSame('Bearer fixture-access-token', $wire[0]['request']->getHeaderLine('Authorization'));
        $this->assertFalse($wire[0]['options']['allow_redirects']);
        $this->assertEqualsCanonicalizing($this->recipients(), $failed);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertEqualsCanonicalizing($failed, $observer->failed);
        $this->assertSame($original, $message->toString());
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    public static function redirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            foreach ([
                'HTTPS origin'   => 'https://redirect-target.safe.test/collect',
                'HTTP downgrade' => 'http://redirect-target.safe.test/collect',
                'relative'       => '/secondary/send',
                'loopback'       => 'http://127.0.0.1:8765/collect',
            ] as $label => $target) {
                foreach ([false, true] as $deferred) {
                    foreach ([false, true] as $explicit) {
                        yield $status.' / '.$label.' / defer '.(int) $deferred.' / envelope '.(int) $explicit => [$status, $target, $deferred, $explicit];
                    }
                }
            }
        }
    }

    /** @dataProvider deferModes */
    public function testImmediateSendPreservesCallerDeferralAndMime(bool $deferred): void
    {
        $wire                 = [];
        [$sdk, $http, $stack] = $this->sdk([new Response(200, [], '{"id":"accepted-id"}')], $wire);
        $sdk->setDefer($deferred);
        $snapshot = $this->snapshot($sdk, $http, $stack);
        $message  = $this->message()->attach(new Swift_Attachment("binary\0\xff", 'fixture.bin', 'application/octet-stream'));
        $original = $message->toString();
        $failed   = [];
        $this->assertSame(4, (new Swift_Transport_Api_GoogleTransport($sdk))->send($message, $failed));
        $this->assertSame([], $failed);
        $this->assertCount(1, $wire);
        $payload = \json_decode($wire[0]['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame($original, Swift\base64url_decode($payload['raw']));
        $this->assertSame('Bearer fixture-access-token', $wire[0]['request']->getHeaderLine('Authorization'));
        $this->assertStringContainsString('fixture-application', $wire[0]['request']->getHeaderLine('User-Agent'));
        $this->assertSame($original, $message->toString());
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    public static function deferModes(): iterable
    {
        yield 'ordinary' => [false];
        yield 'caller uses deferred SDK operations' => [true];
    }

    /** @dataProvider deferModes */
    public function testRefreshCallbacksAndCacheMatchTheNativeSdkAcrossSends(bool $deferred): void
    {
        $control = $this->refreshRun(false, false);
        $actual  = $this->refreshRun(true, $deferred);
        $this->assertSame($control['destinations'], $actual['destinations']);
        $this->assertSame($control['callbacks'], $actual['callbacks']);
        $this->assertSame(3, $actual['requests'], 'One refresh and two Gmail sends share the token cache.');
        $this->assertSame(['client_id' => 'fixture-client-id', 'client_secret' => 'fixture-client-secret', 'grant_type' => 'refresh_token', 'refresh_token' => 'fixture-refresh-token'], $actual['refresh']);
    }

    private function refreshRun(bool $protected, bool $deferred): array
    {
        $wire                 = [];
        [$sdk, $http, $stack] = $this->sdk([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"refreshed-fixture-token","expires_in":3600,"token_type":"Bearer"}'),
            new Response(200, [], '{"id":"first-id"}'),
            new Response(200, [], '{"id":"second-id"}'),
        ], $wire, $this->expiredToken());
        $sdk->setConfig('client_id', 'fixture-client-id');
        $sdk->setConfig('client_secret', 'fixture-client-secret');
        $sdk->setDefer($deferred);
        $callbacks = [];
        $sdk->setConfig('token_callback', function ($key, $token) use (&$callbacks, $sdk, $http, $deferred): void {
            $callbacks[] = [$key, $token];
            $this->assertSame($http, $sdk->getHttpClient(), 'A refresh callback sees the caller SDK client.');
            $this->assertSame($deferred, $sdk->shouldDefer());
        });
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $transport = $protected ? new Swift_Transport_Api_GoogleTransport($sdk) : null;
        for ($i = 0; $i < 2; ++$i) {
            if ($transport) {
                $this->assertSame(4, $transport->send($this->message()));
            } else {
                $msg = new Google\Service\Gmail\Message();
                $msg->setRaw(Swift\getRawMessage($this->message()));
                $result = (new Google\Service\Gmail($sdk))->users_messages->send('me', $msg);
                $this->assertInstanceOf(Google\Service\Gmail\Message::class, $result);
            }
        }
        $this->assertCount(3, $wire);
        $this->assertNotEmpty($callbacks);
        foreach (\array_slice($wire, 1) as $mail) {
            $this->assertSame('Bearer refreshed-fixture-token', $mail['request']->getHeaderLine('Authorization'));
            $this->assertSame('gmail.googleapis.com', $mail['request']->getUri()->getHost());
        }
        if ($protected) {
            foreach ($wire as $request) {
                $this->assertFalse($request['options']['allow_redirects']);
            }
            $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
        }
        \parse_str($wire[0]['body'], $refresh);
        \ksort($refresh);

        return ['requests' => \count($wire), 'callbacks' => $callbacks, 'refresh' => $refresh, 'destinations' => \array_map(static fn ($entry): string => (string) $entry['request']->getUri(), $wire)];
    }

    /** @dataProvider tokenRedirects */
    public function testTokenExchangeRedirectCannotReachAnotherEndpoint(int $status, bool $deferred): void
    {
        $wire                 = [];
        [$sdk, $http, $stack] = $this->sdk([
            new Response($status, ['Location' => 'https://redirect-target.safe.test/token']),
            new Response(200, [], '{"access_token":"unexpected-token","expires_in":3600}'),
            new Response(200, [], '{"id":"unexpected-send"}'),
        ], $wire, $this->expiredToken());
        $sdk->setConfig('client_id', 'fixture-client-id');
        $sdk->setConfig('client_secret', 'fixture-client-secret');
        $sdk->setDefer($deferred);
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        $observer  = $this->observe($transport);
        $failed    = [];
        try {
            (new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('A redirected token exchange must stop the send.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame($status, $error->getCode());
        }
        $this->assertCount(1, $wire);
        $this->assertNotSame('gmail.googleapis.com', $wire[0]['request']->getUri()->getHost());
        $this->assertStringContainsString('refresh_token=fixture-refresh-token', $wire[0]['body']);
        $this->assertFalse($wire[0]['options']['allow_redirects']);
        $this->assertEqualsCanonicalizing($this->recipients(), $failed);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    public static function tokenRedirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            foreach ([false, true] as $deferred) {
                yield $status.' / defer '.(int) $deferred => [$status, $deferred];
            }
        }
    }

    public function testSdkRetrySettingsStillRetryAnOrdinaryServerError(): void
    {
        $wire  = [];
        [$sdk] = $this->sdk([new Response(503, [], '{"error":{"message":"fixture temporary failure"}}'), new Response(200, [], '{"id":"retried-id"}')], $wire);
        $sdk->setConfig('retry', ['retries' => 2, 'initial_delay' => 0]);
        $sdk->setConfig('retry_map', [503 => Google\Task\Runner::TASK_RETRY_ALWAYS]);
        $this->assertSame(4, (new Swift_Transport_Api_GoogleTransport($sdk))->send($this->message()));
        $this->assertCount(2, $wire);
        $this->assertSame('gmail.googleapis.com', $wire[1]['request']->getUri()->getHost());
        foreach ($wire as $entry) {
            $this->assertFalse($entry['options']['allow_redirects']);
        }
    }

    /** @dataProvider deferModes */
    public function testOrdinarySdkErrorRetainsZeroReturnAndCleanup(bool $deferred): void
    {
        $wire                 = [];
        [$sdk, $http, $stack] = $this->sdk([new Response(403, [], '{"error":{"message":"fixture forbidden"}}')], $wire);
        $sdk->setDefer($deferred);
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        $observer  = $this->observe($transport);
        $failed    = [];
        $this->assertSame(0, $transport->send($this->message(), $failed));
        $this->assertCount(1, $wire);
        $this->assertEqualsCanonicalizing($this->recipients(), $failed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame(1, $observer->completed);
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    /** @dataProvider policies */
    public function testPoliciesAndMatchingEnvelopesSurviveSdkSuccessAndRedirect(string $policy, bool $deferred, bool $explicit, bool $redirected): void
    {
        $wire                 = [];
        [$sdk, $http, $stack] = $this->sdk([$redirected ? new Response(307, ['Location' => 'https://redirect-target.safe.test/collect']) : new Response(200, [], '{"id":"policy-id"}')], $wire);
        $sdk->setDefer($deferred);
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        $transport->registerPlugin('redirect' === $policy ? new Swift_Plugins_RedirectingPlugin('sink@safe.test') : new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catchall' === $policy ? 'sink@safe.test' : null));
        $observer = $this->observe($transport);
        $message  = $this->message();
        $original = $message->toString();
        $expected = match ($policy) {
            'allowlist' => ['allowed@safe.test', 'copy@safe.test'],
            'catchall'  => ['allowed@safe.test', 'copy@safe.test', 'sink@safe.test'],
            'redirect'  => ['sink@safe.test'],
        };
        $failed = [];
        try {
            $count = $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->assertFalse($redirected);
            $this->assertSame(\count($expected), $count);
            $this->assertSame([], $failed);
            $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertTrue($redirected);
            $this->assertEqualsCanonicalizing($expected, $failed);
            $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        }
        $this->assertCount(1, $wire);
        $payload = \json_decode($wire[0]['body'], true, 512, \JSON_THROW_ON_ERROR);
        $mime    = Swift\base64url_decode($payload['raw']);
        $this->assertStringNotContainsString('blocked@outside.test', $mime);
        $this->assertStringNotContainsString('blind@outside.test', $mime);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame($original, $message->toString());
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    public static function policies(): iterable
    {
        foreach (['allowlist', 'catchall', 'redirect'] as $policy) {
            foreach ([false, true] as $deferred) {
                foreach ([false, true] as $explicit) {
                    foreach ([false, true] as $redirected) {
                        yield $policy.' / defer '.(int) $deferred.' / envelope '.(int) $explicit.' / redirect '.(int) $redirected => [$policy, $deferred, $explicit, $redirected];
                    }
                }
            }
        }
    }

    /** @dataProvider mimeKinds */
    public function testRepeatedSendsExportTheCompleteMimeExactlyOnce(string $kind): void
    {
        $wire    = [];
        [$sdk]   = $this->sdk([new Response(200, [], '{"id":"first"}'), new Response(200, [], '{"id":"second"}')], $wire);
        $message = $this->message();
        $bytes   = "binary\0\xff\r\nfixture";
        if ('stream' === $kind) {
            $stream = new Swift_ByteStream_ArrayByteStream($bytes);
            $message->attach(new Swift_Attachment($stream, 'stream.bin', 'application/octet-stream'));
        } elseif ('inline' === $kind) {
            $cid = $message->embed(new Swift_Image($bytes, 'inline.png', 'image/png'));
            $message->setBody('<img src="'.$cid.'">', 'text/html');
        } else {
            $message->addPart('<p>HTML alternative</p>', 'text/html');
            $message->attach(new Swift_Attachment($bytes, 'first.bin', 'application/octet-stream'));
            $message->attach(new Swift_Attachment('second fixture', 'second.txt', 'text/plain'));
        }
        $original  = $message->toString();
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        for ($i = 0; $i < 2; ++$i) {
            $this->assertSame(4, $transport->send($message));
            $payload = \json_decode($wire[$i]['body'], true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame($this->stableMime($original), $this->stableMime(Swift\base64url_decode($payload['raw'])));
            $this->assertSame($this->stableMime($original), $this->stableMime($message->toString()));
        }
        $this->assertCount(2, $wire);
    }

    public static function mimeKinds(): iterable
    {
        yield ['stream'];
        yield ['inline'];
        yield ['multipart'];
    }

    /** @dataProvider serviceAccountResponses */
    public function testServiceAccountAuthenticationRetainsTheScopedPolicy(bool $redirect): void
    {
        $wire             = [];
        [, $http, $stack] = $this->sdk([
            $redirect ? new Response(307, ['Location' => 'https://other.safe.test/token']) : new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"service-fixture-token","expires_in":3600,"token_type":"Bearer"}'),
            new Response(200, [], '{"id":"service-account-id"}'),
        ], $wire);
        $key = \openssl_pkey_new(['private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));
        $sdk = new GoogleClient();
        $sdk->setHttpClient($http);
        $sdk->setAuthConfig(['type' => 'service_account', 'client_id' => 'fixture-client-id', 'client_email' => 'fixture@service.safe.test', 'private_key' => $privateKey]);
        $sdk->addScope(Google\Service\Gmail::GMAIL_SEND);
        $sdk->setDefer(true);
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $failed    = [];
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        if ($redirect) {
            try {
                $transport->send($this->message(), $failed);
                $this->fail('Service-account token redirects must fail closed.');
            } catch (Swift_Transport_NonRetryableException $error) {
                $this->assertSame(307, $error->getCode());
                $this->assertEqualsCanonicalizing($this->recipients(), $failed);
                $this->assertCount(1, $wire);
            }
        } else {
            $this->assertSame(4, $transport->send($this->message(), $failed));
            $this->assertSame([], $failed);
            $this->assertCount(2, $wire);
            $this->assertSame('Bearer service-fixture-token', $wire[1]['request']->getHeaderLine('Authorization'));
            // Google's default token callback is bound to the original client.
            // Preserve that normal authentication update while isolating config.
            $this->assertSame('service-fixture-token', $sdk->getAccessToken()['access_token']);
            $snapshot[2] = $sdk->getAccessToken();
        }
        foreach ($wire as $request) {
            $this->assertFalse($request['options']['allow_redirects']);
        }
        $this->assertStringContainsString('assertion=', $wire[0]['body']);
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    public static function serviceAccountResponses(): iterable
    {
        yield 'successful JWT exchange' => [false];
        yield 'redirected JWT exchange' => [true];
    }

    /** @dataProvider serviceAccountResponses */
    public function testCallerHttpRetriesCannotConsumeRedirectsWithoutAnAuthMiddleware(bool $redirect): void
    {
        $wire             = [];
        [, $http, $stack] = $this->sdk([
            $redirect ? new Response(307, ['Location' => '/other']) : new Response(503),
            new Response(200, [], '{"id":"accepted-id"}'),
        ], $wire);
        $stack->push(GuzzleHttp\Middleware::retry(
            static fn (int $retries, $request, $response): bool => $retries < 1 && null !== $response && \in_array($response->getStatusCode(), [307, 503], true),
            static fn (): int => 0,
        ), 'caller_retry');
        $sdk = new GoogleClient();
        $sdk->setHttpClient($http);
        $snapshot  = $this->snapshot($sdk, $http, $stack);
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        if ($redirect) {
            try {
                $transport->send($this->message());
                $this->fail('HTTP retry middleware must not consume an unobserved redirect.');
            } catch (Swift_Transport_NonRetryableException) {
                $this->assertCount(1, $wire);
            }
        } else {
            $this->assertSame(4, $transport->send($this->message()));
            $this->assertCount(2, $wire);
        }
        $this->assertSame($snapshot, $this->snapshot($sdk, $http, $stack));
    }

    private function stableMime(string $mime): string
    {
        // Transient multipart/alternative children generate a fresh boundary on
        // each serialization. Compare every other byte, including the epilogue.
        $boundaries = [];

        return \preg_replace_callback('/_=_swift_[A-Za-z0-9_]+_=_/', static function (array $match) use (&$boundaries): string {
            return $boundaries[$match[0]] ??= 'fixture-boundary-'.\count($boundaries);
        }, $mime);
    }

    /** @dataProvider unsupportedClients */
    public function testUnsupportedSdkCustomizationFailsBeforeHttp(string $kind): void
    {
        $http = $this->createMock(GuzzleHttp\ClientInterface::class);
        $http->expects($this->never())->method('send');
        $http->expects($this->never())->method('request');
        $sdk = match ($kind) {
            'non-cloneable' => new class extends GoogleClient {
                private function __clone()
                {
                }
            },
            'ignores HTTP setter' => new class extends GoogleClient {
                public bool $ignore = false;

                public function setHttpClient(GuzzleHttp\ClientInterface $http)
                {
                    if (!$this->ignore) {
                        parent::setHttpClient($http);
                    }
                }
            },
            'ignores immediate execution' => new class extends GoogleClient {
                public function setDefer($defer)
                {
                    parent::setDefer(true);
                }
            },
            'returns deferred request' => new class extends GoogleClient {
                public function execute(RequestInterface $request, $expectedClass = null)
                {
                    return $request;
                }
            },
        };
        $sdk->setHttpClient($http);
        $sdk->setDefer(true);
        if ('ignores HTTP setter' === $kind) {
            $sdk->ignore = true;
        }
        $transport = new Swift_Transport_Api_GoogleTransport($sdk);
        $observer  = $this->observe($transport);
        $failed    = [];
        try {
            $transport->send($this->message(), $failed);
            $this->fail('Unsupported customizations must not report a successful send.');
        } catch (Swift_Transport_NonRetryableException) {
            $this->assertEqualsCanonicalizing($this->recipients(), $failed);
            $this->assertSame(1, $observer->completed);
            $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
            $this->assertSame($http, $sdk->getHttpClient());
            $this->assertTrue($sdk->shouldDefer());
        }
    }

    public static function unsupportedClients(): iterable
    {
        foreach (['non-cloneable', 'ignores HTTP setter', 'ignores immediate execution', 'returns deferred request'] as $kind) {
            yield [$kind];
        }
    }

    private function expiredToken(): array
    {
        return ['access_token' => 'expired-fixture-token', 'refresh_token' => 'fixture-refresh-token', 'created' => \time() - 4000, 'expires_in' => 3600];
    }

    private function sdk(array $responses, array &$wire, ?array $token = null): array
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = static function (RequestInterface $request, array $options) use (&$wire, $response) {
                $wire[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

                return $response;
            };
        }
        $stack = HandlerStack::create(new MockHandler($queue));
        $http  = new Client(['handler' => $stack, 'allow_redirects' => ['strict' => true, 'max' => 10, 'protocols' => ['http', 'https']]]);
        $sdk   = new GoogleClient(['application_name' => 'fixture-application']);
        $sdk->setHttpClient($http);
        $sdk->setScopes(['https://www.googleapis.com/auth/gmail.send']);
        $sdk->setAccessToken($token ?? ['access_token' => 'fixture-access-token', 'created' => \time(), 'expires_in' => 3600]);

        return [$sdk, $http, $stack];
    }

    private function snapshot(GoogleClient $sdk, Client $http, HandlerStack $stack): array
    {
        return [$sdk->getHttpClient(), $sdk->shouldDefer(), $sdk->getAccessToken(), $http->getConfig(), (string) $stack];
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
        return (new Swift_Message('PRIVATE-FIXTURE-SUBJECT'))
            ->setFrom('sender@safe.test')->setTo(['allowed@safe.test', 'blocked@outside.test'])
            ->setCc('copy@safe.test')->setBcc('blind@outside.test')->setBody('PRIVATE-FIXTURE-BODY');
    }

    private function recipients(): array
    {
        return ['allowed@safe.test', 'blocked@outside.test', 'copy@safe.test', 'blind@outside.test'];
    }
}
