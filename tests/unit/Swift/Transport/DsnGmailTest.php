<?php

use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_DsnGmailTest extends TestCase
{
    /** @dataProvider bearerTokens */
    public function testBearerDsnUsesTheExplicitTokenAndCallerOwnedHttp(string $encoded, string $token): void
    {
        $wire           = [];
        [$http, $stack] = $this->http([new Response(200, [], '{"id":"accepted"}')], $wire);
        $snapshot       = [$http->getConfig(), (string) $stack];
        try {
            $transport = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString('gmail+api://'.$encoded.'@default');
        } catch (Throwable $error) {
            $this->fail('Valid Gmail construction failed with '.$error::class);
        }
        $sdk = $this->client($transport, $http);
        $this->assertSame(GoogleClient::class, $sdk::class);
        $this->assertSame([Gmail::GMAIL_SEND], $sdk->getScopes());
        $this->assertFalse($sdk->isUsingApplicationDefaultCredentials());
        $this->assertSame('googleapis.com', $sdk->getConfig('universe_domain'));
        $this->assertSame(['access_token' => $token], $sdk->getAccessToken());
        $this->assertNull((new ReflectionProperty(GoogleClient::class, 'credentials'))->getValue($sdk));
        $this->assertSame([], $wire, 'Construction must not exchange credentials or contact Gmail.');
        $message  = $this->message()->attach(new Swift_Attachment("binary\0\xff", 'fixture.bin'));
        $original = $message->toString();
        $observer = $this->observe($transport);
        $this->assertSame(3, $transport->send($message, $failed, Swift_Envelope::fromMessage($message)));
        $this->assertSame([], $failed);
        $this->assertCount(1, $wire);
        $this->assertMail($wire[0], $token, $original);
        $this->assertSame([1, 1, Swift_Events_SendEvent::RESULT_SUCCESS], [$observer->before, $observer->completed, $observer->result]);
        $this->assertSame($http, $sdk->getHttpClient());
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack]);
        $this->assertSame($original, $message->toString());
    }

    public static function bearerTokens(): iterable
    {
        foreach (['fixture-access' => 'fixture-access', '0' => '0', '%30' => '0', 'TOKEN%2B%2F%3D' => 'TOKEN+/=', 'eyJ.test_-~token' => 'eyJ.test_-~token'] as $encoded => $token) {
            yield [(string) $encoded, $token];
        }
    }

    /** @dataProvider refreshCredentials */
    public function testRenewableOAuthPreservesFieldsCacheAndLaterRenewal(string $userinfo, string $encoded, string $id, string $secret, string $refresh): void
    {
        $wire           = [];
        [$http, $stack] = $this->http([
            new Response(200, [], '{"access_token":"first-access","expires_in":3600,"token_type":"Bearer"}'),
            new Response(200, [], '{"id":"first-mail"}'), new Response(200, [], '{"id":"cached-mail"}'),
            new Response(200, [], '{"access_token":"second-access","expires_in":3600,"token_type":"Bearer"}'), new Response(200, [], '{"id":"renewed-mail"}'),
        ], $wire);
        $snapshot = [$http->getConfig(), (string) $stack];
        try {
            $transport = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString('gmail+api://'.$userinfo.'@default?refresh_token='.$encoded);
        } catch (Throwable $error) {
            $this->fail('Valid Gmail construction failed with '.$error::class);
        }
        $sdk         = $this->client($transport, $http);
        $credentials = (new ReflectionProperty(GoogleClient::class, 'credentials'))->getValue($sdk);
        $this->assertInstanceOf(UserRefreshCredentials::class, $credentials);
        $this->assertSame(Swift_Transport_Api_GmailRefreshCredentials::class, $credentials::class);
        $this->assertSame([Gmail::GMAIL_SEND], $sdk->getScopes());
        $this->assertFalse($sdk->isUsingApplicationDefaultCredentials());
        $this->assertSame('googleapis.com', $sdk->getConfig('universe_domain'));
        $this->assertSame([], $wire);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(3, $wire, 'Two sends share one refresh through the SDK cache.');
        $sdk->getCache()->clear();
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(5, $wire, 'The explicit refresh provider survives token callbacks and cache renewal.');
        foreach ([0, 3] as $index) {
            $this->assertSame('POST', $wire[$index]['request']->getMethod());
            $this->assertSame('https://oauth2.googleapis.com/token', (string) $wire[$index]['request']->getUri());
            \parse_str($wire[$index]['body'], $actual);
            $this->assertEquals(['client_id' => $id, 'client_secret' => $secret, 'refresh_token' => $refresh, 'grant_type' => 'refresh_token'], $actual);
            $this->assertFalse($wire[$index]['options']['allow_redirects']);
        }
        $this->assertMail($wire[1], 'first-access');
        $this->assertMail($wire[2], 'first-access');
        $this->assertMail($wire[4], 'second-access');
        $this->assertSame($credentials, (new ReflectionProperty(GoogleClient::class, 'credentials'))->getValue($sdk));
        $this->assertSame($http, $sdk->getHttpClient());
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack]);
    }

    public static function refreshCredentials(): iterable
    {
        yield ['client:secret', 'refresh-token', 'client', 'secret', 'refresh-token'];
        yield ['0:0', '0', '0', '0', '0'];
        yield ['0:secret', 'refresh', '0', 'secret', 'refresh'];
        yield ['client:0', 'refresh', 'client', '0', 'refresh'];
        yield ['%30:%30', '%30', '0', '0', '0'];
        yield ['client%40id:secret%2B%2F%3D', 'refresh%2B%2F%3D%26%3A%40', 'client@id', 'secret+/=', 'refresh+/=&:@'];
        yield ['client:secret', 'opaque%3Brefresh_token%3Dsecond', 'client', 'secret', 'opaque;refresh_token=second'];
    }

    /** @dataProvider invalidDsns */
    public function testInvalidConfigurationsRejectBeforeRequestsWithoutExposingFactoryInputs(string $dsn): void
    {
        $wire     = [];
        [$http]   = $this->http([], $wire);
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString($dsn);
                $this->fail('Invalid Gmail DSN must reject.');
            } catch (Throwable $error) {
                $this->assertInstanceOf(InvalidArgumentException::class, $error);
                $frames = \array_filter($error->getTrace(), static fn (array $frame): bool => Swift_Transport_DsnTransportFactory::class === ($frame['class'] ?? null));
                $this->assertStringNotContainsString('PRIVATE', $error->getMessage().\print_r($frames, true));
                $this->assertNull($error->getPrevious());
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertSame([], $wire);
    }

    public static function invalidDsns(): iterable
    {
        foreach (['gmail+api://default', 'gmail+api://:PRIVATE@default', 'gmail+api://PRIVATE:@default', 'gmail+api://PRIVATE:secret@default', 'gmail+api://PRIVATE@default?refresh_token=token', 'gmail+api://:PRIVATE@default?refresh_token=token', 'gmail+api://PRIVATE:@default?refresh_token=token', 'gmail+api://PRIVATE@default?unknown=value', 'gmail+api://client:secret@default/path?refresh_token=PRIVATE', 'gmail+api://PRIVATE@default?verify_peer=0', 'gmail+api://PRIVATE@default?session_token=token', 'gmail+api://PRIVATE@default?region=us-east-1', 'gmail+api://PRIVATE@default?inbox_id=1'] as $dsn) {
            yield [$dsn];
        }
        foreach (['other.safe.test', '127.0.0.1', 'default:443', 'default/path', 'default/#PRIVATE', 'default#PRIVATE'] as $host) {
            yield ['gmail+api://PRIVATE@'.$host];
        }
        foreach (['', '%20', 'PRIVATE%0A', 'PRIVATE%0D', 'PRIVATE%00', 'PRIVATE%C3%A9', 'PRIVATE%09', 'PRIVATE%7F', 'a%3Ab', 'a%40b', 'ab%3Dcd'] as $token) {
            yield ['gmail+api://'.$token.'@default'];
        }
        foreach (['refresh_token=', 'refresh_token=%20', 'refresh_token=PRIVATE%0A', 'refresh_token=PRIVATE%C3%A9', 'refresh_token=a&refresh_token=PRIVATE', 'refresh_token=a&%72efresh_token=PRIVATE', 'refresh_token[]=PRIVATE', 'refresh.token=PRIVATE', 'refresh%20token=PRIVATE', 'refresh_token%00=PRIVATE', '%20refresh_token=PRIVATE'] as $query) {
            yield ['gmail+api://PRIVATE:secret@default?'.$query];
        }
        foreach (['PRIVATE%0A:secret', 'client:PRIVATE%0D', 'PRIVATE%00:secret', 'client:PRIVATE%C3%A9', 'PRIVATE%20:secret'] as $userinfo) {
            yield ['gmail+api://'.$userinfo.'@default?refresh_token=token'];
        }
        foreach (['null', 'smtp', 'amazon+api', 'microsoft-graph', 'sendgrid', 'gmail+smtp'] as $scheme) {
            yield [$scheme.'://PRIVATE:secret@default?refresh_token=token'];
        }
        if (\str_contains(\ini_get('arg_separator.input'), ';')) {
            yield ['gmail+api://PRIVATE:secret@default?refresh_token=a;refresh_token=b'];
        }
    }

    /** @dataProvider authModes */
    public function testAmbientGoogleSettingsCannotChooseCredentialsOrUniverse(bool $refresh): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'swift-gmail-');
        \file_put_contents($file, \json_encode(['type' => 'authorized_user', 'client_id' => 'ambient-client', 'client_secret' => 'ambient-secret', 'refresh_token' => 'ambient-refresh'], JSON_THROW_ON_ERROR));
        $values   = ['GOOGLE_APPLICATION_CREDENTIALS' => $file, 'GOOGLE_CLOUD_UNIVERSE_DOMAIN' => 'other.safe.test', 'GCE_METADATA_HOST' => 'metadata.safe.test'];
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = \getenv($name);
            \putenv($name.'='.$value);
        }
        try {
            $wire      = [];
            [$http]    = $this->http($refresh ? [new Response(200, [], '{"access_token":"explicit-access","expires_in":3600}'), new Response(200, [], '{"id":"id"}')] : [new Response(200, [], '{"id":"id"}')], $wire);
            $dsn       = $refresh ? 'gmail+api://explicit-client:explicit-secret@default?refresh_token=explicit-refresh' : 'gmail+api://explicit-access@default';
            $transport = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString($dsn);
            $sdk       = $this->client($transport, $http);
            $this->assertFalse($sdk->isUsingApplicationDefaultCredentials());
            $this->assertSame('googleapis.com', $sdk->getConfig('universe_domain'));
            $this->assertSame(3, $transport->send($this->message()));
            $this->assertCount($refresh ? 2 : 1, $wire);
            $this->assertMail($wire[$refresh ? 1 : 0], 'explicit-access');
            if ($refresh) {
                $this->assertStringContainsString('refresh_token=explicit-refresh', $wire[0]['body']);
                $this->assertStringNotContainsString('ambient-', $wire[0]['body']);
            }
            foreach ($values as $name => $value) {
                $this->assertSame($value, \getenv($name));
            }
        } finally {
            foreach ($original as $name => $value) {
                \putenv(false === $value ? $name : $name.'='.$value);
            } \unlink($file);
        }
    }

    public static function authModes(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @dataProvider redirects */
    public function testDsnAuthenticationRetainsPermanentRedirectPolicy(bool $refresh, bool $tokenRedirect, int $status): void
    {
        $wire      = [];
        $responses = [];
        if ($refresh && !$tokenRedirect) {
            $responses[] = new Response(200, [], '{"access_token":"access","expires_in":3600}');
        }
        $responses[] = new Response($status, ['Location' => 'https://other.safe.test/collect']);
        [$http]      = $this->http($responses, $wire);
        $dsn         = $refresh ? 'gmail+api://client:secret@default?refresh_token=refresh' : 'gmail+api://access@default';
        $transport   = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString('retry('.$dsn.')');
        $this->client($this->leaf($transport), $http);
        try {
            $transport->send($this->message(), $failed);
            $this->fail('Redirects must remain permanent.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame($status, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount($refresh && !$tokenRedirect ? 2 : 1, $wire);
        $this->assertCount(3, $failed);
    }

    public static function redirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield [false, false, $status];
            yield [true, false, $status];
            yield [true, true, $status];
        }
    }

    /** @dataProvider wrappers */
    public function testWrappersAndPublicHelperConstructOperationalGmail(string $wrapper): void
    {
        $wire      = [];
        [$http]    = $this->http([new Response(200, [], '{"id":"id"}')], $wire);
        $leaf      = 'gmail+api://access@default';
        $dsn       = 'leaf-retry' === $wrapper ? $leaf.'?retries=1&retry_delay=0' : ('retry' === $wrapper ? 'retry('.$leaf.')' : $wrapper.'('.$leaf.' null://default)');
        $transport = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString($dsn);
        $this->client($this->leaf($transport), $http);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(1, $wire);
        $mailer = Swift::createTransportFromDsn($leaf);
        $this->assertInstanceOf(Swift_Transport_Api_GoogleTransport::class, $mailer->getTransport());
        $client = (new ReflectionMethod($mailer->getTransport(), 'getApiConnection'))->invoke($mailer->getTransport());
        $this->assertSame(['access_token' => 'access'], $client->getAccessToken());
    }

    public static function wrappers(): iterable
    {
        foreach (['retry', 'leaf-retry', 'failover', 'roundrobin'] as $kind) {
            yield [$kind];
        }
    }

    /** @dataProvider acceptanceFailures */
    public function testConstructedSdkRetainsPermanentAcceptanceAcrossWrappers(bool $refresh, string $wrapper, string $failure): void
    {
        $wire      = [];
        $responses = $refresh ? [new Response(200, [], '{"access_token":"access","expires_in":3600}')] : [];
        if ('malformed' === $failure) {
            $responses[] = new Response(200, [], '{}');
        } else {
            $body = new GuzzleHttp\Psr7\PumpStream(static function () {
                throw new RuntimeException('fixture interrupted body');
            });
            $response    = new Response(200, [], $body);
            $responses[] = 'interrupted' === $failure ? $response : static function ($request) use ($response) {
                throw new GuzzleHttp\Exception\RequestException('fixture interrupted transfer', $request, $response);
            };
        }
        [$http]    = $this->http($responses, $wire);
        $dsn       = $refresh ? 'gmail+api://client:secret@default?refresh_token=refresh' : 'gmail+api://access@default';
        $dsn       = 'retry' === $wrapper ? 'retry('.$dsn.')' : $wrapper.'('.$dsn.' null://default)';
        $transport = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString($dsn);
        $this->client($this->leaf($transport), $http);
        try {
            $transport->send($this->message(), $failed);
            $this->fail('Invalid acceptance must not return zero or accepted recipients.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount($refresh ? 2 : 1, $wire);
        $this->assertCount(3, $failed);
    }

    public static function acceptanceFailures(): iterable
    {
        foreach ([false, true] as $refresh) {
            foreach (['retry', 'failover', 'roundrobin'] as $wrapper) {
                foreach (['malformed', 'interrupted', 'rejected'] as $failure) {
                    yield [$refresh, $wrapper, $failure];
                }
            }
        }
    }

    /** @dataProvider policyModes */
    public function testFactoryWiresRecipientPoliciesAndRestoresMessages(bool $refresh, bool $redirect, bool $explicit): void
    {
        $wire        = [];
        $responses   = $refresh ? [new Response(200, [], '{"access_token":"access","expires_in":3600}')] : [];
        $responses[] = new Response(200, [], '{"id":"id"}');
        [$http]      = $this->http($responses, $wire);
        $transport   = (new Swift_Transport_DsnTransportFactory(gmailHttpClient: $http))->fromDsnString($refresh ? 'gmail+api://client:secret@default?refresh_token=refresh' : 'gmail+api://access@default');
        $this->client($transport, $http);
        $transport->registerPlugin($redirect ? new Swift_Plugins_RedirectingPlugin('sink@safe.test') : new Swift_Plugins_AllowlistPlugin(['*@safe.test']));
        $message  = $this->message()->setTo(['to@safe.test', 'blocked@outside.test'])->setBcc('blind@outside.test');
        $original = $message->toString();
        $this->assertSame($redirect ? 1 : 2, $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
        $this->assertSame([], $failed);
        $mime = Swift\base64url_decode(\json_decode($wire[$refresh ? 1 : 0]['body'], true, flags: JSON_THROW_ON_ERROR)['raw']);
        $this->assertStringNotContainsString('@outside.test', $mime);
        $this->assertStringContainsString($redirect ? 'sink@safe.test' : 'to@safe.test', $mime);
        $this->assertSame($original, $message->toString());
    }

    public static function policyModes(): iterable
    {
        foreach ([false, true] as $refresh) {
            foreach ([false, true] as $redirect) {
                foreach ([false, true] as $explicit) {
                    yield [$refresh, $redirect, $explicit];
                }
            }
        }
    }

    public function testCredentialAdapterRetainsSdkResponseMetadataAndHandlerOptions(): void
    {
        $wire    = [];
        $results = [];
        foreach ([UserRefreshCredentials::class, Swift_Transport_Api_GmailRefreshCredentials::class] as $class) {
            $provider  = new $class([Gmail::GMAIL_SEND], ['client_id' => 'client', 'client_secret' => 'secret', 'refresh_token' => 'refresh']);
            $results[] = $provider->fetchAuthToken(static function ($request, $options) use (&$wire) {
                \parse_str((string) $request->getBody(), $form);
                $wire[] = [$form, $request->getHeaders(), $options];

                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"received","expires_in":67,"token_type":"Bearer"}');
            }, ['X-Fixture-Token' => 'preserved']);
            $this->assertSame('received', $provider->getLastReceivedToken()['access_token']);
        }
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($wire[0], $wire[1]);
        $this->assertSame(['preserved'], $wire[1][1]['X-Fixture-Token']);
    }

    public function testBadHttpInjectionDoesNotExposeItsValueInConstructorArguments(): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                new Swift_Transport_DsnTransportFactory(gmailHttpClient: 'PRIVATE-HTTP');
                $this->fail('Incorrect HTTP client type must reject.');
            } catch (TypeError $error) {
                $frames = \array_filter($error->getTrace(), static fn (array $frame): bool => Swift_Transport_DsnTransportFactory::class === ($frame['class'] ?? null));
                $this->assertStringNotContainsString('PRIVATE', $error->getMessage().\print_r($frames, true));
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testSubclassWithoutParentConstructorKeepsDefaultClientConstruction(): void
    {
        $factory = new class extends Swift_Transport_DsnTransportFactory {
            public function __construct()
            {
            }
        };
        $transport = $factory->fromDsnString('gmail+api://access@default');
        $this->assertInstanceOf(Swift_Transport_Api_GoogleTransport::class, $transport);
    }

    private function leaf(Swift_Transport $transport): Swift_Transport
    {
        if ($transport instanceof Swift_Transport_RetryTransport) {
            return $this->leaf((new ReflectionProperty($transport, 'innerTransport'))->getValue($transport));
        }
        if ($transport instanceof Swift_Transport_LoadBalancedTransport) {
            return $this->leaf($transport->getTransports()[0]);
        }

        return $transport;
    }

    private function client(Swift_Transport $transport, Client $http): GoogleClient
    {
        $this->assertInstanceOf(Swift_Transport_Api_GoogleTransport::class, $transport);
        $this->assertInstanceOf(Swift_Events_SimpleEventDispatcher::class, $transport->eventDispatcher);
        $client = (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
        $this->assertSame($http, $client->getHttpClient(), 'Verify fixture ownership before any wire operation.');

        return $client;
    }

    private function http(array $responses, array &$wire): array
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = static function (RequestInterface $request, array $options) use (&$wire, $response) {
                $wire[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

                return $response instanceof Closure ? $response($request) : $response;
            };
        }
        $stack = HandlerStack::create(new MockHandler($queue));

        return [new Client(['handler' => $stack, 'headers' => ['X-Fixture' => 'caller-owned'], 'allow_redirects' => true]), $stack];
    }

    private function assertMail(array $entry, string $token, ?string $mime = null): void
    {
        $this->assertSame('POST', $entry['request']->getMethod());
        $this->assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', (string) $entry['request']->getUri());
        $this->assertSame('Bearer '.$token, $entry['request']->getHeaderLine('Authorization'));
        $this->assertSame('caller-owned', $entry['request']->getHeaderLine('X-Fixture'));
        $this->assertFalse($entry['options']['allow_redirects']);
        if (null !== $mime) {
            $this->assertSame($mime, Swift\base64url_decode(\json_decode($entry['body'], true, flags: JSON_THROW_ON_ERROR)['raw']));
        }
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public int $result = 0;

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->completed;
                $this->result = $evt->getResult();
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
