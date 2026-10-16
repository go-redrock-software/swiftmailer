<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Promise\FulfilledPromise;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\Token\AccessToken;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAccessTokenProvider;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\Core\NationalCloud;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeCertificateContext;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Kiota\Authentication\Oauth\OnBehalfOfContext;
use Microsoft\Kiota\Authentication\Oauth\ProviderFactory;
use Microsoft\Kiota\Authentication\Oauth\TokenRequestContext;
use Microsoft\Kiota\Authentication\PhpLeagueAuthenticationProvider;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_GraphOAuthGrantPolicyTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            \unlink($file);
        }
    }

    /** @dataProvider transitions */
    public function testFallbackUsesCurrentContextAndPublishesCoherentCallerState(string $kind, bool $tenantChange, bool $basic): void
    {
        $old    = $this->codeContext('old');
        $tenant = $tenantChange ? 'new-tenant' : 'fixture-tenant';
        $new    = match ($kind) {
            'code'               => $this->codeContext('new', $tenant),
            'client credentials' => new ClientCredentialContext($tenant, 'new-client', 'new-secret'),
            'on behalf of'       => new OnBehalfOfContext($tenant, 'new-client', 'new-secret', 'new-assertion'),
        };
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture       = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202), new Response(202)], true, $basic);
        $http          = $fixture->oauth->getHttpClient();
        $factory       = $fixture->oauth->getGrantFactory();
        $configuration = $http->getConfig();
        $transport     = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(2, $fixture->tokens);
        $wire = $fixture->tokens[1];
        $this->assertSame('https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token', (string) $wire['request']->getUri());
        $params = $wire['params'];
        if ($basic) {
            $this->assertSame('Basic '.\base64_encode('new-client:new-secret'), $wire['request']->getHeaderLine('Authorization'));
        } else {
            $this->assertSame('new-client', $params['client_id']);
            $this->assertSame('new-secret', $params['client_secret']);
        }
        $this->assertSame($new->getGrantType(), $params['grant_type']);
        $this->assertSame('https://graph.microsoft.com/.default', $params['scope']);
        if ('code' === $kind) {
            $this->assertSame('new-code', $params['code']);
            $this->assertSame('https://callback.safe.test/new', $params['redirect_uri']);
            $this->assertSame('new-verifier', $params['code_verifier']);
            $claims = \json_decode($params['claims'], true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame(['new'], $claims['access_token']['fixture']);
            $this->assertSame(['cp1'], $claims['access_token']['xms_cc']['values']);
        } else {
            foreach (['code', 'redirect_uri', 'code_verifier', 'claims', 'refresh_token'] as $key) {
                $this->assertArrayNotHasKey($key, $params);
            }
            if ('on behalf of' === $kind) {
                $this->assertSame('new-assertion', $params['assertion']);
            }
        }
        $this->assertSame($new, $fixture->provider->getTokenRequestContext());
        $this->assertSame('https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token', $fixture->oauth->getBaseAccessTokenUrl([]));
        $this->assertSame($http, $fixture->oauth->getHttpClient());
        $this->assertSame($factory, $fixture->oauth->getGrantFactory());
        $this->assertSame($configuration, $http->getConfig());
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(2, $fixture->tokens, 'The newly published context must reuse the native cache.');
    }

    public static function transitions(): iterable
    {
        foreach (['code', 'client credentials', 'on behalf of'] as $kind) {
            foreach ([false, true] as $tenant) {
                foreach ([false, true] as $basic) {
                    yield "$kind tenant ".(int) $tenant.' basic '.(int) $basic => [$kind, $tenant, $basic];
                }
            }
        }
    }

    /** @dataProvider certificateFlows */
    public function testCertificateRefreshKeepsJwtAndCanonicalAssertionType(bool $cae): void
    {
        $context   = $this->certificateContext();
        $resources = $cae ? [$this->challenge(), new Response(202)] : [new Response(202)];
        $fixture   = $this->sdk($context, [$this->token()], $resources, $cae);
        $assertion = $context->getParams()['client_assertion'];
        $this->assertSame(3, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $this->assertCount(1, $fixture->tokens);
        $params = $fixture->tokens[0]['params'];
        $this->assertSame('refresh_token', $params['grant_type']);
        $this->assertSame('fixture-refresh', $params['refresh_token']);
        $this->assertSame($assertion, $params['client_assertion']);
        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $params['client_assertion_type']);
        $this->assertFalse($fixture->tokens[0]['options']['allow_redirects']);
        if ($cae) {
            $claims = \json_decode($params['claims'], true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame(['challenge'], $claims['access_token']['fixture']);
        }
    }

    public static function certificateFlows(): iterable
    {
        yield 'expired cache' => [false];
        yield 'CAE' => [true];
    }

    public function testRepeatedChallengesDuringDraftUploadUseEachNewContextAndEndpoint(): void
    {
        $old    = $this->codeContext('old');
        $first  = $this->codeContext('first', 'first-tenant');
        $second = $this->codeContext('second', 'second-tenant');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($first));
        $first->setCAERedirectCallback(static fn () => new FulfilledPromise($second));
        $denied  = new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}');
        $fixture = $this->sdk($old, [$denied, $this->token(), $denied, $this->token()], [
            $this->challenge(), Swift_GraphSdkFixture::json(201, ['id'        => 'draft-id']),
            $this->challenge(), Swift_GraphSdkFixture::json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=fixture', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]),
            new Response(201, ['Location' => 'https://outlook.office.com/attachments/fixture-id']), new Response(202), new Response(202),
        ]);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $transport->setLargeAttachmentThreshold(16);
        $this->assertSame(3, $transport->send($this->message()->attach(new Swift_Attachment('large attachment fixture', 'large.bin'))));
        $this->assertCount(4, $fixture->tokens);
        $this->assertSame('first-code', $fixture->tokens[1]['params']['code']);
        $this->assertSame('second-code', $fixture->tokens[3]['params']['code']);
        $this->assertStringContainsString('/first-tenant/', (string) $fixture->tokens[1]['request']->getUri());
        $this->assertStringContainsString('/first-tenant/', (string) $fixture->tokens[2]['request']->getUri());
        $this->assertStringContainsString('/second-tenant/', (string) $fixture->tokens[3]['request']->getUri());
        $this->assertSame($second, $fixture->provider->getTokenRequestContext());
        $this->assertStringContainsString('/second-tenant/', $fixture->oauth->getBaseAccessTokenUrl([]));
        $this->assertStringContainsString('/second-tenant/', $fixture->oauth->getBaseAuthorizationUrl([]));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(4, $fixture->tokens);
    }

    /** @dataProvider endpointCases */
    public function testTenantChangesKeepConfiguredOriginPrefixAndQuery(string $base): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new', 'new-tenant');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)], true, false, $base);
        // A configured query is retained when the endpoint path changes.
        foreach (['urlAccessToken', 'urlAuthorize'] as $name) {
            $property = new ReflectionProperty(League\OAuth2\Client\Provider\GenericProvider::class, $name);
            $property->setValue($fixture->oauth, $property->getValue($fixture->oauth).'?fixture=preserved');
        }
        $this->assertSame(3, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $expected = $base.'/new-tenant/oauth2/v2.0/token?fixture=preserved';
        $this->assertSame($expected, (string) $fixture->tokens[1]['request']->getUri());
        $this->assertSame($expected, $fixture->oauth->getBaseAccessTokenUrl([]));
    }

    public static function endpointCases(): iterable
    {
        yield ['https://login.microsoftonline.com'];
        yield ['https://login.microsoftonline.us'];
        yield ['https://login.chinacloudapi.cn'];
        yield ['https://login.example.test/proxy/prefix'];
    }

    /** @dataProvider unsupportedTenants */
    public function testUnsupportedTenantTransitionCannotDispatchOrPublish(string $tenant, bool $customEndpoint): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new', $tenant);
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        if ($customEndpoint) {
            (new ReflectionProperty(League\OAuth2\Client\Provider\GenericProvider::class, 'urlAccessToken'))->setValue($fixture->oauth, 'https://login.example.test/opaque/token');
        }
        $before = $fixture->oauth->getBaseAccessTokenUrl([]);
        $failed = [];
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client), maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('Unsupported context authority changes must fail before the fallback request.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $fixture->tokens);
        $this->assertCount(1, $fixture->resources);
        $this->assertSame($old, $fixture->provider->getTokenRequestContext());
        $this->assertSame($before, $fixture->oauth->getBaseAccessTokenUrl([]));
        $this->assertCount(3, $failed);
    }

    public static function unsupportedTenants(): iterable
    {
        yield ['new-tenant', true];
        yield ['../other-tenant', false];
        yield ['new?tenant', false];
    }

    /** @dataProvider fallbackRedirects */
    public function testReturnedContextRedirectStaysPermanentWithoutPublishing(string $kind, int $status, bool $retry): void
    {
        $old       = 'certificate refresh' === $kind ? $this->certificateContext() : $this->codeContext('old');
        $new       = $this->codeContext('new', 'new-tenant');
        $responses = [new Response($status, ['Location' => 'https://other.safe.test/token']), $this->token()];
        if ('fallback' === $kind) {
            $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
            \array_unshift($responses, new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'));
        }
        $fixture = $this->sdk($old, $responses, [$this->challenge(), new Response(202)], true, false, 'https://login.microsoftonline.com', $retry);
        $before  = $fixture->oauth->getBaseAccessTokenUrl([]);
        $failed  = [];
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client), maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('A returned-context token redirect must stay terminal.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertSame($status, $error->getCode());
        }
        $this->assertCount('fallback' === $kind ? 2 : 1, $fixture->tokens);
        $this->assertCount(1, $fixture->resources);
        $this->assertSame($old, $fixture->provider->getTokenRequestContext());
        $this->assertSame($before, $fixture->oauth->getBaseAccessTokenUrl([]));
        $this->assertCount(3, $failed);
    }

    public static function fallbackRedirects(): iterable
    {
        foreach (['fallback', 'certificate refresh'] as $kind) {
            foreach ([301, 302, 303, 307, 308] as $status) {
                foreach ([false, true] as $retry) {
                    yield "$kind $status retry ".(int) $retry => [$kind, $status, $retry];
                }
            }
        }
    }

    /** @dataProvider customPreparations */
    public function testCustomProviderMustHonorGrantPreparationBeforeDispatch(bool $cooperate, bool $tamper): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        $custom  = new class(['urlAccessToken' => $fixture->oauth->getBaseAccessTokenUrl([]), 'urlAuthorize' => $fixture->oauth->getBaseAuthorizationUrl([]), 'urlResourceOwnerDetails' => 'https://graph.microsoft.com/oidc/userinfo'], ['httpClient' => $fixture->oauth->getHttpClient()]) extends League\OAuth2\Client\Provider\GenericProvider {
            public bool $cooperate = true;

            public function getAccessToken($grant, array $options = [])
            {
                if ('refresh_token' === $grant || $this->cooperate) {
                    $options['fixture_extension'] = 'preserved';

                    return parent::getAccessToken($grant, $options);
                }

                return $this->createAccessToken($this->getParsedResponse($this->getAccessTokenRequest($options)), new League\OAuth2\Client\Grant\AuthorizationCode());
            }
        };
        $custom->cooperate = $cooperate;
        if ($tamper) {
            $custom->getHttpClient()->getConfig('handler')->push(GuzzleHttp\Middleware::mapRequest(static function ($request) {
                \parse_str((string) $request->getBody(), $params);
                if ('authorization_code' === ($params['grant_type'] ?? null)) {
                    $params['code'] = 'old-code';

                    return $request->withBody(GuzzleHttp\Psr7\Utils::streamFor(\http_build_query($params)));
                }

                return $request;
            }));
        }
        (new ReflectionProperty(Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider::class, 'oauthProvider'))->setValue($fixture->provider, $custom);
        $fixture->oauth = $custom;
        $transport      = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        if ($cooperate && !$tamper) {
            $this->assertSame(3, $transport->send($this->message()));
            $this->assertSame('new-code', $fixture->tokens[1]['params']['code']);
            $this->assertSame('preserved', $fixture->tokens[1]['params']['fixture_extension']);
        } else {
            try {
                (new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0))->send($this->message());
                $this->fail('Unprepared or changed fallback parameters must not reach HTTP.');
            } catch (Swift_TransportException $error) {
                $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            }
            $this->assertCount(1, $fixture->tokens);
            $this->assertCount(1, $fixture->resources);
            $this->assertSame($old, $fixture->provider->getTokenRequestContext());
        }
    }

    public static function customPreparations(): iterable
    {
        yield 'cooperating' => [true, false];
        yield 'bypassed' => [false, false];
        yield 'middleware tampering' => [true, true];
    }

    public function testConfiguredPkceDefaultsAreKeptWhenContextDoesNotOverrideThem(): void
    {
        $old = new AuthorizationCodeContext('fixture-tenant', 'old-client', 'old-secret', 'old-code', 'https://callback.safe.test/old');
        $new = new AuthorizationCodeContext('fixture-tenant', 'new-client', 'new-secret', 'new-code', 'https://callback.safe.test/new');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        $fixture->oauth->setPkceCode('configured-verifier');
        $this->assertSame(3, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $this->assertSame('configured-verifier', $fixture->tokens[1]['params']['code_verifier']);
        $this->assertSame('configured-verifier', $fixture->oauth->getPkceCode());
    }

    public function testSuccessfulReentrantContextDoesNotGetOverwrittenByOuterCompletion(): void
    {
        $old       = $this->codeContext('old');
        $outer     = $this->codeContext('outer', 'outer-tenant');
        $inner     = $this->codeContext('inner', 'inner-tenant');
        $callbacks = 0;
        $old->setCAERedirectCallback(static function () use (&$callbacks, $outer, $inner) {
            return new FulfilledPromise(0 === $callbacks++ ? $outer : $inner);
        });
        $denied           = new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}');
        $fixture          = $this->sdk($old, [$denied, $this->token(), $denied, $this->token()], [$this->challenge(), $this->challenge(), new Response(202), new Response(202), new Response(202)]);
        $transport        = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $entered          = false;
        $fixture->onToken = function ($request, array $params) use ($transport, &$entered): void {
            if (!$entered && 'outer-code' === ($params['code'] ?? null)) {
                $entered = true;
                $this->assertSame(3, $transport->send($this->message()));
            }
        };
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertTrue($entered);
        $this->assertSame(2, $callbacks);
        $this->assertSame($inner, $fixture->provider->getTokenRequestContext());
        $this->assertStringContainsString('/inner-tenant/', $fixture->oauth->getBaseAccessTokenUrl([]));
        $this->assertStringContainsString('/inner-tenant/', $fixture->oauth->getBaseAuthorizationUrl([]));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(4, $fixture->tokens);
    }

    /** @dataProvider certificateTransitions */
    public function testSecretAndCertificateContextsDoNotKeepObsoleteCredentialFields(bool $toCertificate): void
    {
        $certificate = $this->certificateContext();
        $secret      = $this->codeContext('secret');
        $old         = $toCertificate ? $secret : $certificate;
        $new         = $toCertificate ? $certificate : $secret;
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        $this->assertSame(3, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $params = $fixture->tokens[1]['params'];
        foreach ($new->getParams() as $key => $value) {
            if ('claims' !== $key) {
                $this->assertSame($value, $params[$key]);
            }
        }
        if ($toCertificate) {
            $this->assertArrayNotHasKey('client_secret', $params);
            $this->assertArrayNotHasKey('code_verifier', $params);
        } else {
            $this->assertArrayNotHasKey('client_assertion', $params);
            $this->assertArrayNotHasKey('client_assertion_type', $params);
        }
        $this->assertSame($new, $fixture->provider->getTokenRequestContext());
    }

    public static function certificateTransitions(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @dataProvider tokenEncodings */
    public function testNativeProviderEncodingsPreserveCurrentContext(string $encoding): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        if ('json' === $encoding) {
            $fixture->oauth->setOptionProvider(new class implements League\OAuth2\Client\OptionProvider\OptionProviderInterface {
                public function getAccessTokenOptions($method, array $params)
                {
                    return ['headers' => ['Content-Type' => 'application/json'], 'body' => \json_encode($params, \JSON_THROW_ON_ERROR)];
                }
            });
        } else {
            $custom = new class(['urlAccessToken' => $fixture->oauth->getBaseAccessTokenUrl([]), 'urlAuthorize' => $fixture->oauth->getBaseAuthorizationUrl(), 'urlResourceOwnerDetails' => 'https://graph.microsoft.com/oidc/userinfo'], ['httpClient' => $fixture->oauth->getHttpClient()]) extends League\OAuth2\Client\Provider\GenericProvider {
                protected function getAccessTokenMethod()
                {
                    return self::METHOD_GET;
                }
            };
            (new ReflectionProperty(Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider::class, 'oauthProvider'))->setValue($fixture->provider, $custom);
            $fixture->oauth = $custom;
        }
        $this->assertSame(3, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $request = $fixture->tokens[1]['request'];
        if ('json' === $encoding) {
            $params = \json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } else {
            $this->assertSame('GET', $request->getMethod());
            \parse_str($request->getUri()->getQuery(), $params);
        }
        $this->assertSame('new-code', $params['code']);
        $this->assertSame('new-client', $params['client_id']);
        $this->assertSame('new-secret', $params['client_secret']);
        $this->assertSame($new, $fixture->provider->getTokenRequestContext());
    }

    public static function tokenEncodings(): iterable
    {
        yield ['json'];
        yield ['query'];
    }

    /** @dataProvider ambiguousRequests */
    public function testAmbiguousOrUnverifiableFallbackCannotReachHttp(string $kind): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        $fixture->oauth->getHttpClient()->getConfig('handler')->push(GuzzleHttp\Middleware::mapRequest(static function ($request) use ($kind) {
            $body = (string) $request->getBody();
            if (!\str_contains($body, 'grant_type=authorization_code')) {
                return $request;
            }

            return match ($kind) {
                'duplicate body'           => $request->withBody(GuzzleHttp\Psr7\Utils::streamFor('code=old-code&'.$body)),
                'duplicate bracket key'    => $request->withBody(GuzzleHttp\Psr7\Utils::streamFor('code[]=old-code&'.$body)),
                'duplicate normalized key' => $request->withBody(GuzzleHttp\Psr7\Utils::streamFor('client.id=old-client&'.$body)),
                'duplicate encoded key'    => $request->withBody(GuzzleHttp\Psr7\Utils::streamFor('%63ode=old-code&'.$body)),
                'duplicate query'          => $request->withUri($request->getUri()->withQuery('code=old-code&code=new-code')),
                'conflicting query'        => $request->withUri($request->getUri()->withQuery('code=old-code')),
                'lowercase stale basic'    => $request->withHeader('Authorization', 'basic '.\base64_encode('old-client:old-secret')),
                'mixed case stale basic'   => $request->withHeader('Authorization', "bAsIc\t".\base64_encode('old-client:old-secret')),
                'malformed basic'          => $request->withHeader('Authorization', 'Basic invalid!!'),
                'nonseekable'              => $request->withBody(new GuzzleHttp\Psr7\NoSeekStream(GuzzleHttp\Psr7\Utils::streamFor($body))),
            };
        }));
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client), maxRetries: 2, baseDelayMs: 0))->send($this->message());
            $this->fail('An ambiguous fallback must not dispatch credentials or replay the send.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $fixture->tokens);
        $this->assertCount(1, $fixture->resources);
        $this->assertSame($old, $fixture->provider->getTokenRequestContext());
    }

    public static function ambiguousRequests(): iterable
    {
        foreach (['duplicate body', 'duplicate encoded key', 'duplicate bracket key', 'duplicate normalized key', 'duplicate query', 'conflicting query', 'malformed basic', 'lowercase stale basic', 'mixed case stale basic', 'nonseekable'] as $kind) {
            yield [$kind];
        }
    }

    /** @dataProvider ordinaryFailures */
    public function testValidatedFallbackRetainsOAuthStatusAndClassification(int $status): void
    {
        $errors = [];
        foreach ([false, true] as $scoped) {
            $old = $this->codeContext('old');
            $new = $this->codeContext('new', 'new-tenant');
            $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
            $denied  = new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}');
            $fixture = $this->sdk($old, [$denied, new Response($status, ['Content-Type' => 'application/json'], '{"error":"fixture_error","error_description":"fixture OAuth failure"}')], [$this->challenge()]);
            try {
                if ($scoped) {
                    (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message());
                } else {
                    $fixture->provider->getAuthorizationTokenAsync('https://graph.microsoft.com/v1.0/me/sendMail', ['claims' => \base64_encode('{"access_token":{"fixture":["challenge"]}}')])->wait();
                }
                $this->fail('The OAuth failure must propagate.');
            } catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException|Swift_TransportException $error) {
                $errors[] = $scoped ? $error : new Swift_TransportException($error->getMessage(), $error->getCode(), $error);
            }
            $this->assertCount(2, $fixture->tokens);
            $this->assertSame($new, $fixture->provider->getTokenRequestContext());
        }
        $classifier = new Swift_Transport_DefaultRetryClassifier();
        $this->assertSame(0, $errors[0]->getCode());
        $this->assertSame($status, $errors[1]->getCode());
        $this->assertSame($classifier->isRetryable($errors[0]), $classifier->isRetryable($errors[1]));
    }

    public static function ordinaryFailures(): iterable
    {
        yield [400];
        yield [503];
    }

    /** @dataProvider successfulPolicies */
    public function testSuccessfulFallbackRetainsPoliciesEnvelopeAndEvents(string $policy, bool $explicit): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture   = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)]);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $transport->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['to@safe.test']),
            'catch-all' => new Swift_Plugins_AllowlistPlugin(['to@safe.test'], 'sink@safe.test'),
            'redirect'  => new Swift_Plugins_RedirectingPlugin('sink@safe.test'),
        });
        $observer = new class implements Swift_Events_SendListener {
            public array $events = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                $this->events[] = 'before';
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                $this->events[] = $event->getResult();
            }
        };
        $transport->registerPlugin($observer);
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        $this->assertSame('catch-all' === $policy ? 2 : 1, $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
        $this->assertSame([], $failed);
        $this->assertSame(['before', Swift_Events_SendEvent::RESULT_SUCCESS], $observer->events);
        $this->assertSame($original, $message->toString());
        $this->assertCount(2, $fixture->tokens);
        $this->assertCount(2, $fixture->resources);
        $this->assertSame($new, $fixture->provider->getTokenRequestContext());
        $payload    = (new Microsoft\Kiota\Serialization\Json\JsonParseNodeFactory())->getRootParseNode('application/json', GuzzleHttp\Psr7\Utils::streamFor($fixture->resourceBodies[1]))->getObjectValue([Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody::class, 'createFromDiscriminatorValue']);
        $recipients = [];
        foreach ([$payload->getMessage()->getToRecipients(), $payload->getMessage()->getCcRecipients(), $payload->getMessage()->getBccRecipients()] as $group) {
            foreach ($group ?? [] as $recipient) {
                $recipients[] = $recipient->getEmailAddress()->getAddress();
            }
        }
        \sort($recipients);
        $this->assertSame(match ($policy) {
            'allowlist' => ['to@safe.test'], 'catch-all' => ['sink@safe.test', 'to@safe.test'], 'redirect' => ['sink@safe.test'],
        }, $recipients);
    }

    public static function successfulPolicies(): iterable
    {
        foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
            foreach ([false, true] as $explicit) {
                yield [$policy, $explicit];
            }
        }
    }

    public function testCaseInsensitiveBasicSchemeKeepsCurrentCredentials(): void
    {
        $old = $this->codeContext('old');
        $new = $this->codeContext('new');
        $old->setCAERedirectCallback(static fn () => new FulfilledPromise($new));
        $fixture = $this->sdk($old, [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'), $this->token()], [$this->challenge(), new Response(202)], true, true);
        $fixture->oauth->getHttpClient()->getConfig('handler')->push(GuzzleHttp\Middleware::mapRequest(static function ($request) {
            return $request->withHeader('Authorization', 'basic '.\substr($request->getHeaderLine('Authorization'), 6));
        }));
        $this->assertSame(3, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $this->assertSame('basic '.\base64_encode('new-client:new-secret'), $fixture->tokens[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame($new, $fixture->provider->getTokenRequestContext());
    }

    private function codeContext(string $version, string $tenant = 'fixture-tenant'): AuthorizationCodeContext
    {
        $context = new AuthorizationCodeContext($tenant, $version.'-client', $version.'-secret', $version.'-code', 'https://callback.safe.test/'.$version, [
            'code_verifier' => $version.'-verifier', 'claims' => \json_encode(['access_token' => ['fixture' => [$version]]]),
        ]);
        $context->setCAEEnabled(true);

        return $context;
    }

    private function certificateContext(): AuthorizationCodeCertificateContext
    {
        $key         = \openssl_pkey_new(['private_key_bits' => 2048]);
        $csr         = \openssl_csr_new(['commonName' => 'fixture.invalid'], $key);
        $certificate = \openssl_csr_sign($csr, null, $key, 1);
        $certFile    = \tempnam(\sys_get_temp_dir(), 'swiftmailer-62-cert-');
        $keyFile     = \tempnam(\sys_get_temp_dir(), 'swiftmailer-62-key-');
        $this->files = [$certFile, $keyFile];
        \openssl_x509_export_to_file($certificate, $certFile);
        \openssl_pkey_export_to_file($key, $keyFile);
        $context = new AuthorizationCodeCertificateContext('fixture-tenant', 'fixture-client', 'fixture-code', 'https://callback.safe.test/oauth', $certFile, $keyFile);
        $context->setCAEEnabled(true);

        return $context;
    }

    private function sdk(TokenRequestContext $context, array $tokens, array $resources, bool $fresh = true, bool $basic = false, string $tokenBase = 'https://login.microsoftonline.com', bool $retry = false): object
    {
        $oauth    = ProviderFactory::create($context, $basic ? ['optionProvider' => new HttpBasicAuthOptionProvider()] : [], $tokenBase);
        $provider = new GraphPhpLeagueAccessTokenProvider($context, ['https://graph.microsoft.com/.default'], NationalCloud::GLOBAL, null, $oauth);
        $cached   = new AccessToken(['access_token' => 'fixture-cached-token', 'refresh_token' => 'fixture-refresh', 'expires' => \time() + ($fresh ? 3600 : -100)]);
        $context->setCacheKey($cached);
        $provider->getAccessTokenCache()->persistAccessToken($context->getCacheKey(), $cached);
        $fixture    = (object) ['provider' => $provider, 'oauth' => $oauth, 'tokens' => [], 'resources' => [], 'resourceBodies' => [], 'onToken' => null];
        $tokenStack = HandlerStack::create(new MockHandler(\array_map(static fn ($response) => static function ($request, $options) use ($fixture, $response) {
            \parse_str((string) $request->getBody(), $params);
            $fixture->tokens[] = ['request' => $request, 'params' => $params, 'options' => $options];
            if (null !== $fixture->onToken) {
                ($fixture->onToken)($request, $params);
            }

            return $response;
        }, $tokens)));
        if ($retry) {
            $tokenStack->push(GuzzleHttp\Middleware::retry(static fn (int $count, $request, $response, $error): bool => $count < 1 && (null !== $error || null !== $response && $response->getStatusCode() >= 300 && $response->getStatusCode() < 400), static fn (): int => 0));
        }
        $oauth->setHttpClient(new Client(['handler' => $tokenStack]));
        $resourceStack = GraphClientFactory::getDefaultHandlerStack(new MockHandler(\array_map(static fn ($response) => static function ($request, $options) use ($fixture, $response) {
            $fixture->resources[]      = $request;
            $fixture->resourceBodies[] = (string) $request->getBody();

            return $response;
        }, $resources)));
        $auth            = PhpLeagueAuthenticationProvider::createWithAccessTokenProvider($provider);
        $fixture->client = GraphServiceClient::createWithRequestAdapter(new GraphRequestAdapter($auth, new Client(['handler' => $resourceStack])));

        return $fixture;
    }

    private function challenge(): Response
    {
        return new Response(401, ['WWW-Authenticate' => 'Bearer claims="'.\base64_encode('{"access_token":{"fixture":["challenge"]}}').'"']);
    }

    private function token(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"fixture-new-token","refresh_token":"fixture-new-refresh","expires_in":3600,"token_type":"Bearer"}');
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'fixture body'))->setFrom('author@safe.test')->setTo('to@safe.test')->setCc('cc@safe.test')->setBcc('blind@safe.test');
    }
}
