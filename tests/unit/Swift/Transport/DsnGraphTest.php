<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Http\GuzzleRequestAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_DsnGraphTest extends TestCase
{
    /** @dataProvider bearerDsns */
    public function testBearerDsnUsesFixedGraphEndpointAndCallerOwnedHttp(string $encoded, string $token, ?string $mailbox): void
    {
        $wire                     = [];
        $tokens                   = [];
        [$http, $stack]           = $this->http([new Response(202)], $wire);
        [$tokenHttp, $tokenStack] = $this->http([], $tokens);
        $snapshot                 = [$http->getConfig(), (string) $stack, $tokenHttp->getConfig(), (string) $tokenStack];
        $transport                = $this->create('microsoft-graph://'.$encoded.'@default'.(null === $mailbox ? '' : '?user_id='.\rawurlencode($mailbox)), $http, $tokenHttp);
        $client                   = $this->client($transport, $http);
        $this->assertSame([], $wire);
        $this->assertSame([], $tokens);
        $this->assertSame($mailbox, $transport->getSendingAccountUserId());
        $this->assertSame(null === $mailbox, $transport->isUsingMeEndpoint());
        $this->assertInstanceOf(Swift_Events_SimpleEventDispatcher::class, $transport->eventDispatcher);
        $message  = $this->message();
        $original = $message->toString();
        $this->assertSame(3, $transport->send($message, $failed, Swift_Envelope::fromMessage($message)));
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
        $this->assertCount(1, $wire);
        $this->assertSame([], $tokens);
        $this->assertSame('https://graph.microsoft.com/v1.0/'.(null === $mailbox ? 'me' : 'users/'.\rawurlencode($mailbox)).'/sendMail', (string) $wire[0]['request']->getUri());
        $this->assertSame('Bearer '.$token, $wire[0]['request']->getHeaderLine('Authorization'));
        $this->assertFalse($wire[0]['options']['allow_redirects']);
        $payload = \json_decode($wire[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('blind@safe.test', $payload['Message']['bccRecipients'][0]['emailAddress']['address']);
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack, $tokenHttp->getConfig(), (string) $tokenStack]);
        $this->assertSame($client, (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport));
    }

    public static function bearerDsns(): iterable
    {
        foreach (['fixture-access' => 'fixture-access', '0' => '0', '%30' => '0', 'TOKEN%2B%2F%3D' => 'TOKEN+/=', 'eyJ.test_-~token' => 'eyJ.test_-~token'] as $encoded => $token) {
            foreach ([null, 'sender@safe.test', '0'] as $mailbox) {
                yield [(string) $encoded, $token, $mailbox];
            }
        }
    }

    /** @dataProvider applicationDsns */
    public function testApplicationCredentialsPreserveFieldsCacheAndLaterRenewal(string $userinfo, string $tenant, string $mailbox, string $id, string $secret): void
    {
        $wire                     = [];
        $tokens                   = [];
        [$http, $stack]           = $this->http([new Response(202), new Response(202), new Response(202)], $wire);
        [$tokenHttp, $tokenStack] = $this->http([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"first-access","expires_in":3600,"token_type":"Bearer"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"second-access","expires_in":3600,"token_type":"Bearer"}'),
        ], $tokens);
        $snapshot  = [$http->getConfig(), (string) $stack, $tokenHttp->getConfig(), (string) $tokenStack];
        $transport = $this->create('microsoft-graph://'.$userinfo.'@default?tenant_id='.\rawurlencode($tenant).'&user_id='.\rawurlencode($mailbox), $http, $tokenHttp);
        $client    = $this->client($transport, $http);
        $auth      = (new ReflectionProperty(GuzzleRequestAdapter::class, 'authenticationProvider'))->getValue($client->getRequestAdapter());
        $provider  = $auth->getAccessTokenProvider();
        $this->assertTrue($tokenHttp === $provider->getOauthProvider()->getHttpClient());
        $this->assertSame(['graph.microsoft.com'], $provider->getAllowedHostsValidator()->getAllowedHosts());
        $this->assertSame(['https://graph.microsoft.com/.default'], $provider->getScopes());
        $context = $provider->getTokenRequestContext();
        $this->assertSame($tenant, $context->getTenantId());
        $this->assertSame($id, $context->getClientId());
        $this->assertSame(['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials'], $context->getParams());
        $this->assertSame(['client_id' => $id, 'client_secret' => $secret, 'refresh_token' => '0', 'grant_type' => 'refresh_token'], $context->getRefreshTokenParams('0'));
        $this->assertSame([], $wire);
        $this->assertSame([], $tokens);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(1, $tokens);
        $key = $context->getCacheKey();
        $this->assertNotNull($key);
        $provider->getAccessTokenCache()->persistAccessToken($key, new AccessToken(['access_token' => 'expired', 'expires' => \time() - 10]));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(2, $tokens);
        $this->assertCount(3, $wire);
        foreach ($tokens as $entry) {
            $this->assertSame('https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token', (string) $entry['request']->getUri());
            \parse_str($entry['body'], $form);
            $this->assertEquals(['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default'], $form);
            $this->assertFalse($entry['options']['allow_redirects']);
        }
        foreach ($wire as $index => $entry) {
            $this->assertSame('https://graph.microsoft.com/v1.0/users/'.\rawurlencode($mailbox).'/sendMail', (string) $entry['request']->getUri());
            $this->assertSame('Bearer '.(2 === $index ? 'second-access' : 'first-access'), $entry['request']->getHeaderLine('Authorization'));
            $this->assertStringNotContainsString($secret, $entry['body']);
        }
        $this->assertTrue($tokenHttp === $provider->getOauthProvider()->getHttpClient());
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack, $tokenHttp->getConfig(), (string) $tokenStack]);
    }

    public static function applicationDsns(): iterable
    {
        yield ['client:secret', 'fixture-tenant', 'sender@safe.test', 'client', 'secret'];
        yield ['0:0', '0', '0', '0', '0'];
        yield ['0:secret', 'fixture-tenant', 'sender@safe.test', '0', 'secret'];
        yield ['client:0', 'fixture-tenant', 'sender@safe.test', 'client', '0'];
        yield ['%30:%30', 'fixture-tenant', '0', '0', '0'];
        yield ['client:with%20space', 'fixture-tenant', 'sender@safe.test', 'client', 'with space'];
        yield ['client%40id:secret%2B%2F%3D', 'tenant.safe.test', 'sender+alias@safe.test', 'client@id', 'secret+/='];
    }

    public function testUnresolvedNativeCaeChallengeDoesNotRepeatResourceRequests(): void
    {
        $wire        = [];
        $tokens      = [];
        $challenge   = new Response(401, ['WWW-Authenticate' => 'Bearer claims="'.\base64_encode('{"access_token":{"policy":"fixture-policy"}}').'"']);
        [$http]      = $this->http([$challenge, $challenge, $challenge], $wire);
        [$tokenHttp] = $this->http([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"fixture-access","expires_in":3600}')], $tokens);
        $transport   = $this->create('microsoft-graph://client:secret@default?tenant_id=fixture-tenant&user_id=sender%40safe.test', $http, $tokenHttp);
        $this->client($transport, $http);
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($this->message(), $failed);
            $this->fail('A missing CAE callback cannot satisfy the authentication challenge.');
        } catch (Swift_TransportException $error) {
            $this->assertSame(0, $error->getCode());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $wire);
        $this->assertCount(1, $tokens);
    }

    /** @dataProvider invalidDsns */
    public function testInvalidGraphAuthenticationAndOptionsRejectBeforeWire(string $dsn): void
    {
        $wire        = [];
        $tokens      = [];
        [$http]      = $this->http([], $wire);
        [$tokenHttp] = $this->http([], $tokens);
        try {
            (new Swift_Transport_DsnTransportFactory(graphHttpClient: $http, graphTokenHttpClient: $tokenHttp))->fromDsnString($dsn);
            $this->fail('Invalid Graph options or authentication must not construct an operational transport.');
        } catch (Throwable $error) {
            $this->assertInstanceOf(InvalidArgumentException::class, $error);
            $this->assertStringNotContainsString('PRIVATE-', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame([], $wire);
        $this->assertSame([], $tokens);
    }

    public static function invalidDsns(): iterable
    {
        foreach (['', ':', ':PRIVATE-SECRET', 'PRIVATE-TOKEN:', 'PRIVATE-ID:PRIVATE-SECRET', 'PRIVATE-TOKEN%20invalid', 'PRIVATE-TOKEN%3Binjection', 'PRIVATE-TOKEN%3Aopaque', 'PRIVATE-TOKEN%25opaque', 'PRIVATE-TOKEN%5Copaque', 'PRIVATE-TOKEN%0D%0AX-Test:secret'] as $userinfo) {
            yield ['microsoft-graph://'.$userinfo.'@default'];
        }
        foreach (['PRIVATE-ID:PRIVATE-SECRET', 'PRIVATE-ID:', ':PRIVATE-SECRET', 'PRIVATE-ID:%00', 'PRIVATE-ID:%0A'] as $userinfo) {
            foreach (['tenant_id=fixture-tenant', 'user_id=sender%40safe.test', 'tenant_id=&user_id=sender%40safe.test', 'tenant_id=fixture-tenant&user_id=', 'tenant_id=fixture-tenant&user_id=sender%40safe.test'] as $options) {
                if ('PRIVATE-ID:PRIVATE-SECRET' === $userinfo && 'tenant_id=fixture-tenant&user_id=sender%40safe.test' === $options) {
                    continue;
                }
                yield ['microsoft-graph://'.$userinfo.'@default?'.$options];
            }
        }
        foreach (['tenant_id=fixture-tenant', 'scope=Mail.Send', 'scopes=Mail.Send', 'cloud=china', 'region=us', 'refresh_token=PRIVATE-REFRESH', 'session_token=PRIVATE-SESSION', 'timeout=5', 'auth_mode=login', 'click_tracking=true', 'endpoint=https%3A%2F%2Fother.safe.test', 'token_uri=https%3A%2F%2Fother.safe.test', 'base_url=https%3A%2F%2Fother.safe.test', 'user.id=sender%40safe.test', 'user_id[]=sender%40safe.test', 'user_id=one&user_id=two', '%20user_id=one', 'user_id%00bad=one', 'retries[]=1', 'retry_delay=0&retry_delay=1'] as $options) {
            yield ['microsoft-graph://PRIVATE-TOKEN@default?'.$options];
        }
        foreach (['', '..', 'a/b', 'a?b', 'a#b', 'a\\b', 'a b', 'a%2Fb', 'https://login.other.safe.test', '../other'] as $tenant) {
            yield ['microsoft-graph://PRIVATE-ID:PRIVATE-SECRET@default?tenant_id='.\rawurlencode($tenant).'&user_id=sender%40safe.test'];
        }
        foreach (['other.safe.test', 'graph.microsoft.com', 'default:443', 'default:8443', 'default/path', 'default#PRIVATE-FRAGMENT', 'default/#PRIVATE-FRAGMENT'] as $host) {
            yield ['microsoft-graph://PRIVATE-TOKEN@'.$host];
        }
        foreach (['tenant_id[]=fixture', 'tenant_id=one&tenant_id=two', 'tenant.id=fixture', '%20tenant_id=fixture', 'tenant_id%00bad=fixture'] as $option) {
            yield ['microsoft-graph://PRIVATE-ID:PRIVATE-SECRET@default?'.$option.'&user_id=sender%40safe.test'];
        }
        foreach (['smtp', 'sendgrid', 'amazon+api', 'gmail+api', 'null'] as $scheme) {
            yield [$scheme.'://PRIVATE-ID:PRIVATE-SECRET@default?tenant_id=fixture-tenant'];
            yield [$scheme.'://PRIVATE-ID:PRIVATE-SECRET@default?user_id=sender%40safe.test'];
        }
    }

    /** @dataProvider draftModes */
    public function testConstructedGraphClientUsesRealDraftAndUploadFlow(string $dsn, string $token, string $prefix, bool $application, bool $httpAuthentication): void
    {
        $wire   = [];
        $tokens = [];
        [$http] = $this->http([
            Swift_GraphSdkFixture::json(201, ['id' => 'draft-id']),
            Swift_GraphSdkFixture::json(201, ['id' => 'small-id']),
            Swift_GraphSdkFixture::json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=PRIVATE-UPLOAD', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]),
            new Response(201, ['Location' => 'https://outlook.office.com/attachments/fixture-id']), new Response(202),
        ], $wire, $httpAuthentication ? ['auth' => ['fixture-user', 'PRIVATE-HTTP-PASSWORD'], 'headers' => ['Authorization' => 'Bearer PRIVATE-HTTP-BEARER', 'X-Fixture' => 'kept']] : []);
        [$tokenHttp] = $this->http([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"sdk-access","expires_in":3600}')], $tokens);
        $snapshot    = $http->getConfig();
        $transport   = $this->create($dsn, $http, $tokenHttp);
        $this->client($transport, $http);
        $transport->setLargeAttachmentThreshold(16);
        $small   = "tiny\0\xff";
        $large   = \str_repeat('x', 32);
        $message = $this->message()->attach(new Swift_Attachment($small, 'small.bin', 'application/octet-stream'))->attach(new Swift_Attachment($large, 'large.bin', 'application/octet-stream'));
        $this->assertSame(3, $transport->send($message, $failed));
        $this->assertSame([], $failed);
        $this->assertCount(5, $wire);
        $this->assertCount($application ? 1 : 0, $tokens);
        $this->assertSame($prefix.'/messages', $wire[0]['request']->getUri()->getPath());
        $this->assertSame($prefix.'/messages/draft-id/send', $wire[4]['request']->getUri()->getPath());
        $this->assertSame('https://uploads.safe.test/session?token=PRIVATE-UPLOAD', (string) $wire[3]['request']->getUri());
        $this->assertSame($large, $wire[3]['body']);
        $this->assertFalse($wire[3]['request']->hasHeader('Authorization'));
        $attachment = \json_decode($wire[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(\base64_encode($small), $attachment['contentBytes']);
        foreach ([0, 1, 2, 4] as $index) {
            $this->assertSame('Bearer '.$token, $wire[$index]['request']->getHeaderLine('Authorization'));
        }
        foreach ($wire as $entry) {
            $this->assertFalse($entry['options']['allow_redirects']);
        }
        $this->assertSame($snapshot, $http->getConfig());
        if ($httpAuthentication) {
            foreach ($wire as $entry) {
                $this->assertSame('kept', $entry['request']->getHeaderLine('X-Fixture'));
            }
        }
    }

    public static function draftModes(): iterable
    {
        foreach (self::authenticationModes() as $mode) {
            foreach ([false, true] as $defaults) {
                yield [...$mode, $defaults];
            }
        }
    }

    public static function authenticationModes(): iterable
    {
        yield 'bearer' => ['microsoft-graph://fixture-access@default', 'fixture-access', '/v1.0/me', false];
        yield 'application' => ['microsoft-graph://client:secret@default?tenant_id=fixture-tenant&user_id=sender%40safe.test', 'sdk-access', '/v1.0/users/sender%40safe.test', true];
    }

    /** @dataProvider calendarModes */
    public function testConstructedGraphClientRetainsCalendarOperations(string $dsn, string $token, string $prefix, bool $application, string $operation): void
    {
        $wire   = [];
        $tokens = [];
        [$http] = $this->http([Swift_GraphSdkFixture::json(200, ['value' => 'create' === $operation ? [] : [['id' => 'event-id']]]),
            'cancel' === $operation ? new Response(202) : Swift_GraphSdkFixture::json('create' === $operation ? 201 : 200, ['id' => 'event-id'])], $wire);
        [$tokenHttp] = $this->http([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"sdk-access","expires_in":3600}')], $tokens);
        $transport   = $this->create($dsn, $http, $tokenHttp);
        $this->client($transport, $http);
        $transport->enableCalendarEventConversion();
        $ics = \implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:'.('cancel' === $operation ? 'CANCEL' : 'REQUEST'), 'BEGIN:VEVENT', 'UID:fixture-invite', 'DTSTART:20261102T090000Z', 'DTEND:20261102T100000Z', 'SUMMARY:fixture', 'ATTENDEE:mailto:to@safe.test', 'END:VEVENT', 'END:VCALENDAR', '']);
        $this->assertSame(4, $transport->send($this->message()->attach(new Swift_Attachment($ics, 'invite.ics', 'text/calendar')), $failed));
        $this->assertSame([], $failed);
        $this->assertCount(2, $wire);
        $this->assertCount($application ? 1 : 0, $tokens);
        $this->assertSame('GET', $wire[0]['request']->getMethod());
        $this->assertSame($prefix.'/events', $wire[0]['request']->getUri()->getPath());
        $this->assertSame('update' === $operation ? 'PATCH' : 'POST', $wire[1]['request']->getMethod());
        $this->assertSame($prefix.'/events'.('create' === $operation ? '' : '/event-id'.('cancel' === $operation ? '/cancel' : '')), $wire[1]['request']->getUri()->getPath());
        foreach ($wire as $entry) {
            $this->assertSame('Bearer '.$token, $entry['request']->getHeaderLine('Authorization'));
        }
    }

    public static function calendarModes(): iterable
    {
        foreach (self::authenticationModes() as $mode) {
            foreach (['create', 'update', 'cancel'] as $operation) {
                yield [...$mode, $operation];
            }
        }
    }

    public function testNativeCaeCallbackStillPublishesFreshContextAndCachedToken(): void
    {
        $wire        = [];
        $tokens      = [];
        $claims      = '{"access_token":{"policy":"fixture-policy"}}';
        [$http]      = $this->http([new Response(401, ['WWW-Authenticate' => 'Bearer claims="'.\base64_encode($claims).'"']), new Response(202), new Response(202)], $wire);
        [$tokenHttp] = $this->http([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"first-access","expires_in":3600}'), new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"second-access","expires_in":3600}')], $tokens);
        $transport   = $this->create('microsoft-graph://client:secret@default?tenant_id=fixture-tenant&user_id=sender%40safe.test', $http, $tokenHttp);
        $client      = $this->client($transport, $http);
        $auth        = (new ReflectionProperty(GuzzleRequestAdapter::class, 'authenticationProvider'))->getValue($client->getRequestAdapter());
        $provider    = $auth->getAccessTokenProvider();
        $next        = new Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext('next-tenant', 'next-client', 'next-secret');
        $seen        = [];
        $provider->getTokenRequestContext()->setCAERedirectCallback(static function (string $value) use ($next, &$seen) {
            $seen[] = $value;

            return new Http\Promise\FulfilledPromise($next);
        });
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame([$claims], $seen);
        $this->assertSame($next, $provider->getTokenRequestContext());
        $this->assertCount(2, $tokens);
        $this->assertCount(3, $wire);
        $this->assertSame('https://login.microsoftonline.com/next-tenant/oauth2/v2.0/token', (string) $tokens[1]['request']->getUri());
        \parse_str($tokens[1]['body'], $form);
        $this->assertSame('next-client', $form['client_id']);
        $this->assertSame('next-secret', $form['client_secret']);
        $this->assertSame('https://graph.microsoft.com/.default', $form['scope']);
        $this->assertSame('Bearer first-access', $wire[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer second-access', $wire[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer second-access', $wire[2]['request']->getHeaderLine('Authorization'));
        $this->assertSame($wire[0]['body'], $wire[1]['body']);
    }

    public function testFixedBearerChallengeRequiresANewExternalTokenWithoutReplay(): void
    {
        $wire        = [];
        $tokens      = [];
        $challenge   = new Response(401, ['WWW-Authenticate' => 'Bearer claims="fixture-claims"']);
        [$http]      = $this->http([$challenge, $challenge, $challenge], $wire);
        [$tokenHttp] = $this->http([], $tokens);
        $transport   = $this->create('microsoft-graph://fixture-access@default', $http, $tokenHttp);
        $this->client($transport, $http);
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($this->message());
            $this->fail('Fixed bearer tokens cannot renew after a claims challenge.');
        } catch (Swift_TransportException $error) {
            $this->assertSame(401, $error->getCode());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $wire);
        $this->assertSame([], $tokens);
    }

    /** @dataProvider wrapperModes */
    public function testDsnDeliveryWrappersCannotReplayAmbiguousAcceptance(string $dsn, string $token, string $prefix, bool $application, string $wrapper): void
    {
        $wire        = [];
        $tokens      = [];
        [$http]      = $this->http([new Response(202, ['Content-Type' => 'application/json'], '{}')], $wire);
        [$tokenHttp] = $this->http([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"sdk-access","expires_in":3600}')], $tokens);
        $configured  = 'query' === $wrapper ? $dsn.(\str_contains($dsn, '?') ? '&' : '?').'retries=2&retry_delay=0'
            : $wrapper.'('.$dsn.(\in_array($wrapper, ['failover', 'roundrobin'], true) ? ' null://default' : '').')';
        $sender = (new Swift_Transport_DsnTransportFactory(graphHttpClient: $http, graphTokenHttpClient: $tokenHttp))->fromDsnString($configured);
        $native = $sender instanceof Swift_Transport_RetryTransport ? $sender->getInnerTransport() : $sender->getTransports()[0];
        $this->client($native, $http);
        try {
            $sender->send($this->message(), $failed);
            $this->fail('Ambiguous acceptance cannot reach a retry or pool fallback.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(202, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $wire);
        $this->assertCount($application ? 1 : 0, $tokens);
        $this->assertEqualsCanonicalizing(['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
    }

    public static function wrapperModes(): iterable
    {
        foreach (self::authenticationModes() as $mode) {
            foreach (['query', 'retry', 'failover', 'roundrobin'] as $wrapper) {
                yield [...$mode, $wrapper];
            }
        }
    }

    /** @dataProvider policyModes */
    public function testConstructedGraphTransportRetainsRecipientPoliciesEventsAndEnvelopes(string $dsn, string $token, string $prefix, bool $application, string $policy, bool $explicit, bool $failure): void
    {
        $wire        = [];
        $tokens      = [];
        [$http]      = $this->http([$failure ? new Response(307, ['Location' => 'https://other.safe.test/collect']) : new Response(202)], $wire);
        [$tokenHttp] = $this->http([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"sdk-access","expires_in":3600}')], $tokens);
        $transport   = $this->create($dsn, $http, $tokenHttp);
        $this->client($transport, $http);
        $transport->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['*@safe.test']), 'catch-all' => new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catch@safe.test'), default => new Swift_Plugins_RedirectingPlugin('sink@safe.test')
        });
        $observer = new class implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public int $result = 0;

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->completed;
                $this->result = $event->getResult();
            }
        };
        $transport->registerPlugin($observer);
        $message  = $this->message()->setTo(['to@safe.test', 'blocked@outside.test'])->setBcc('blind@outside.test');
        $original = $message->toString();
        $expected = match ($policy) {
            'allowlist' => 2,'catch-all' => 3,default => 1
        };
        if ($failure) {
            try {
                $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
                $this->fail('Redirects must remain terminal.');
            } catch (Swift_Transport_NonRetryableException $error) {
                $this->assertSame(307, $error->getCode());
                $this->assertCount($expected, $failed);
            }
        } else {
            $this->assertSame($expected, $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
            $this->assertSame([], $failed);
        }
        $this->assertSame($original, $message->toString());
        $this->assertCount(1, $wire);
        $this->assertStringNotContainsString('@outside.test', $wire[0]['body']);
        $this->assertSame([1, 1, $failure ? Swift_Events_SendEvent::RESULT_FAILED : Swift_Events_SendEvent::RESULT_SUCCESS], [$observer->before, $observer->completed, $observer->result]);
    }

    public static function policyModes(): iterable
    {
        foreach (self::authenticationModes() as $mode) {
            foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    foreach ([false, true] as $failure) {
                        yield [...$mode, $policy, $explicit, $failure];
                    }
                }
            }
        }
    }

    public function testCompatibleGuzzleInterfaceUsesItsConfiguredHandlerWithoutMutatingIt(): void
    {
        $wire           = [];
        [$http, $stack] = $this->http([new Response(202)], $wire);
        $compatible     = new Swift_Transport_Api_NoRedirectClient($http);
        $snapshot       = [$http->getConfig(), (string) $stack, $compatible->getConfig()];
        $transport      = (new Swift_Transport_DsnTransportFactory(graphHttpClient: $compatible))->fromDsnString('microsoft-graph://0@default');
        $this->client($transport, $http);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame('Bearer 0', $wire[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack, $compatible->getConfig()]);
    }

    /** @dataProvider badResourceClients */
    public function testUnsupportedClientConfigurationHasSafeConstructionFailure(mixed $configuration): void
    {
        $http = new class($configuration) extends Client {
            public function __construct(private mixed $fixtureConfiguration)
            {
                parent::__construct();
            }

            public function getConfig(?string $option = null)
            {
                if ('throw' === $this->fixtureConfiguration) {
                    throw new TypeError('PRIVATE-HTTP-CLIENT');
                }

                return $this->fixtureConfiguration;
            }
        };
        try {
            (new Swift_Transport_DsnTransportFactory(graphHttpClient: $http))->fromDsnString('microsoft-graph://PRIVATE-TOKEN@default');
            $this->fail('Unsupported HTTP configuration must fail before any request.');
        } catch (Throwable $error) {
            $this->assertInstanceOf(InvalidArgumentException::class, $error);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('PRIVATE-', $error->getMessage());
        }
    }

    public static function badResourceClients(): iterable
    {
        yield ['PRIVATE-CONFIG'];
        yield [[]];
        yield [['handler' => false]];
        yield ['throw'];
    }

    /** @dataProvider defaultAuthentication */
    public function testDefaultSdkClientsHaveExplicitGlobalAuthenticationWithoutAmbientCredentials(bool $application): void
    {
        $names    = ['AZURE_TENANT_ID', 'AZURE_CLIENT_ID', 'AZURE_CLIENT_SECRET'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = \getenv($name);
            \putenv($name.'=PRIVATE-AMBIENT');
        }
        try {
            $dsn       = $application ? 'microsoft-graph://client:secret@default?tenant_id=fixture-tenant&user_id=sender%40safe.test' : 'microsoft-graph://0@default';
            $transport = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
            $client    = (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
            $adapter   = $client->getRequestAdapter();
            $this->assertSame(GraphServiceClient::class, $client::class);
            $this->assertSame('https://graph.microsoft.com/v1.0', $adapter->getBaseUrl());
            $auth = (new ReflectionProperty(GuzzleRequestAdapter::class, 'authenticationProvider'))->getValue($adapter);
            if ($application) {
                $provider = $auth->getAccessTokenProvider();
                $this->assertSame('fixture-tenant', $provider->getTokenRequestContext()->getTenantId());
                $this->assertSame('client', $provider->getTokenRequestContext()->getClientId());
                $this->assertSame('https://login.microsoftonline.com/fixture-tenant/oauth2/v2.0/token', $provider->getOauthProvider()->getBaseAccessTokenUrl([]));
            } else {
                $request = new Microsoft\Kiota\Abstractions\RequestInformation();
                $request->setUri('https://graph.microsoft.com/v1.0/me/sendMail');
                $auth->authenticateRequest($request)->wait();
                $this->assertSame(['Bearer 0'], $request->getHeaders()->get('Authorization'));
            }
        } finally {
            foreach ($previous as $name => $value) {
                \putenv(false === $value ? $name : $name.'='.$value);
            }
        }
    }

    public static function defaultAuthentication(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @dataProvider bearerDestinations */
    public function testExplicitBearerProviderCannotAuthenticateOtherOrigins(string $uri, bool $authorized): void
    {
        $wire        = [];
        $tokens      = [];
        [$http]      = $this->http([], $wire);
        [$tokenHttp] = $this->http([], $tokens);
        $transport   = $this->create('microsoft-graph://0@default', $http, $tokenHttp);
        $client      = $this->client($transport, $http);
        $auth        = (new ReflectionProperty(GuzzleRequestAdapter::class, 'authenticationProvider'))->getValue($client->getRequestAdapter());
        $request     = new Microsoft\Kiota\Abstractions\RequestInformation();
        $request->setUri($uri);
        $request->addHeader('Authorization', 'Bearer old-token');
        $this->assertSame($request, $auth->authenticateRequest($request)->wait());
        $this->assertSame($authorized ? ['Bearer 0'] : [], $request->getHeaders()->get('Authorization'));
        $this->assertSame([], $wire);
        $this->assertSame([], $tokens);
    }

    public static function bearerDestinations(): iterable
    {
        yield ['https://graph.microsoft.com/v1.0/me/sendMail', true];
        yield ['https://GRAPH.microsoft.com:443/v1.0/me/sendMail', true];
        foreach (['http://graph.microsoft.com/v1.0/me/sendMail', 'https://graph.microsoft.com:8443/v1.0/me/sendMail', 'https://user:secret@graph.microsoft.com/v1.0/me/sendMail', 'https://other.safe.test/path', 'https://uploads.safe.test/session?token=opaque', 'https://graph.microsoft.us/v1.0/me/sendMail', 'https://canary.graph.microsoft.com/v1.0/me/sendMail'] as $uri) {
            yield [$uri, false];
        }
    }

    private function create(string $dsn, Client $http, Client $tokenHttp): Swift_Transport_Api_MicrosoftGraphTransport
    {
        try {
            $transport = (new Swift_Transport_DsnTransportFactory(graphHttpClient: $http, graphTokenHttpClient: $tokenHttp))->fromDsnString($dsn);
        } catch (Throwable $error) {
            $this->fail('Valid explicit Graph DSN construction failed with '.$error::class);
        }
        $this->assertInstanceOf(Swift_Transport_Api_MicrosoftGraphTransport::class, $transport);

        return $transport;
    }

    private function client(Swift_Transport_Api_MicrosoftGraphTransport $transport, Client $http): GraphServiceClient
    {
        $client = (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
        $this->assertSame(GraphServiceClient::class, $client::class);
        $this->assertSame(GraphRequestAdapter::class, $client->getRequestAdapter()::class);
        $this->assertSame('https://graph.microsoft.com/v1.0', $client->getRequestAdapter()->getBaseUrl());
        $configured = (new ReflectionProperty(GuzzleRequestAdapter::class, 'guzzleClient'))->getValue($client->getRequestAdapter());
        $this->assertInstanceOf(Client::class, $configured);
        $this->assertFalse($configured->getConfig('http_errors'));
        $this->assertNotSame($http, $configured);
        $this->assertSame($http->getConfig('timeout'), $configured->getConfig('timeout'));
        $this->assertNotSame($http->getConfig('handler'), $configured->getConfig('handler'));
        $handler = new ReflectionProperty(HandlerStack::class, 'handler');
        $this->assertSame($handler->getValue($http->getConfig('handler')), $handler->getValue($configured->getConfig('handler')));

        return $client;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setCc('copy@safe.test')->setBcc('blind@safe.test');
    }

    private function http(array $responses, array &$wire, array $configuration = []): array
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = static function (RequestInterface $request, array $options) use (&$wire, $response) {
                $wire[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

                return $response;
            };
        }
        $stack = HandlerStack::create(new MockHandler($queue));

        return [new Client(\array_replace(['handler' => $stack, 'timeout' => 17], $configuration)), $stack];
    }
}
