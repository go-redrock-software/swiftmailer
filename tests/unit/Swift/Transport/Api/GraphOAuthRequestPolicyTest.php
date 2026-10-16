<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Http\Promise\FulfilledPromise;
use League\OAuth2\Client\Token\AccessToken;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAccessTokenProvider;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\Authentication\BaseBearerTokenAuthenticationProvider;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Kiota\Authentication\Oauth\OnBehalfOfContext;
use Microsoft\Kiota\Authentication\PhpLeagueAuthenticationProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_Api_GraphOAuthRequestPolicyTest extends TestCase
{
    /** @dataProvider initialRedirects */
    public function testInitialTokenRedirectCannotForwardCredentialsOrReachGraph(int $status, string $target, string $mode, string $kind, bool $callerRetry): void
    {
        $fixture   = $this->sdk($mode, [new Response($status, ['Location' => $target]), $this->tokenResponse()], [new Response(202)], $kind, $callerRetry);
        $snapshot  = $this->snapshot($fixture);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $observer  = $this->observe($transport);
        $message   = $this->message();
        $original  = $message->toString();
        $failed    = [];
        try {
            (new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0))->send($message, $failed);
            $this->fail('A redirected token exchange cannot be counted as accepted mail.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertSame($status, $error->getCode());
            foreach (['fixture-secret', 'fixture-code', 'fixture-assertion'] as $secret) {
                $this->assertStringNotContainsString($secret, $error->getMessage());
            }
        }
        $this->assertCount(1, $fixture->tokens);
        $this->assertSame('login.microsoftonline.com', $fixture->tokens[0]['request']->getUri()->getHost());
        $this->assertFalse($fixture->tokens[0]['options']['allow_redirects']);
        \parse_str($fixture->tokens[0]['body'], $params);
        $this->assertSame('fixture-secret', $params['client_secret']);
        if ('authorization code' === $mode) {
            $this->assertSame('fixture-code', $params['code']);
        }
        if ('on behalf of' === $mode) {
            $this->assertSame('fixture-assertion', $params['assertion']);
        }
        $this->assertSame([], $fixture->resources);
        $this->assertCount(4, $failed);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame($original, $message->toString());
        $this->assertSame($snapshot, $this->snapshot($fixture));
    }

    public static function initialRedirects(): iterable
    {
        foreach (self::redirects() as $label => [$status, $target, $callerRetry]) {
            foreach (['client credentials', 'authorization code', 'on behalf of'] as $mode) {
                foreach (['phpLeague', 'base bearer', 'Graph constructor'] as $kind) {
                    yield $label.' / '.$mode.' / '.$kind => [$status, $target, $mode, $kind, $callerRetry];
                }
            }
        }
    }

    /** @dataProvider redirects */
    public function testExpiredRefreshTokenRedirectCannotBeReplayed(int $status, string $target, bool $callerRetry): void
    {
        $fixture = $this->sdk('authorization code', [new Response($status, ['Location' => $target]), $this->tokenResponse()], [new Response(202)], 'phpLeague', $callerRetry, $this->cachedToken(false));
        $failed  = [];
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client), maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('A redirected refresh must not forward refresh credentials or retry.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertSame($status, $error->getCode());
        }
        $this->assertCount(1, $fixture->tokens);
        \parse_str($fixture->tokens[0]['body'], $params);
        $this->assertSame('refresh_token', $params['grant_type']);
        $this->assertSame('fixture-refresh', $params['refresh_token']);
        $this->assertSame([], $fixture->resources);
        $this->assertCount(4, $failed);
    }

    /** @dataProvider caeRedirects */
    public function testCaeCannotEraseTheRedirectMarkerOrSendAFallbackRequest(int $status, string $target, bool $callerRetry, bool $callback): void
    {
        $claims  = \base64_encode('{"access_token":{"fixture":["challenge"]}}');
        $fixture = $this->sdk('authorization code', [new Response($status, ['Location' => $target]), $this->tokenResponse()], [new Response(401, ['WWW-Authenticate' => 'Bearer claims="'.$claims.'"']), new Response(202)], 'phpLeague', $callerRetry, $this->cachedToken(true));
        $calls   = 0;
        if ($callback) {
            $fixture->context->setCAERedirectCallback(function ($received) use (&$calls, $fixture, $claims) {
                ++$calls;
                $this->assertSame(\base64_decode($claims), $received);

                return new FulfilledPromise($fixture->context);
            });
        }
        $failed = [];
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client), maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('CAE must not turn a token redirect into successful authentication or an automatic replay.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertSame($status, $error->getCode());
        }
        $this->assertCount(1, $fixture->tokens);
        $this->assertCount(1, $fixture->resources, 'Only the initial 401 resource request is allowed.');
        $this->assertFalse($fixture->tokens[0]['options']['allow_redirects']);
        $this->assertCount(4, $failed);
        if ($callback) {
            $this->assertSame(1, $calls);
        }
    }

    public static function redirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            foreach (['HTTPS origin' => 'https://other.safe.test/token', 'HTTP downgrade' => 'http://other.safe.test/token', 'relative' => '/secondary/token', 'loopback' => 'http://127.0.0.1:8765/token'] as $label => $target) {
                foreach ([false, true] as $callerRetry) {
                    yield $status.' / '.$label.' / retry '.(int) $callerRetry => [$status, $target, $callerRetry];
                }
            }
        }
    }

    public static function caeRedirects(): iterable
    {
        foreach (self::redirects() as $label => $values) {
            foreach ([false, true] as $callback) {
                yield $label.' / callback '.(int) $callback => [...$values, $callback];
            }
        }
    }

    /** @dataProvider successfulFlows */
    public function testSuccessfulAuthenticationAndCacheReuseMatchTheNativeSdk(string $mode, string $kind, string $state): void
    {
        $control = $this->successfulRun(false, $mode, $kind, $state);
        $actual  = $this->successfulRun(true, $mode, $kind, $state);
        $this->assertSame($control, $actual);
        $this->assertSame('fresh cache' === $state ? 0 : 1, $actual['tokens']);
        $this->assertSame(2, $actual['resources']);
    }

    public static function successfulFlows(): iterable
    {
        foreach (['client credentials', 'authorization code', 'on behalf of'] as $mode) {
            foreach (['phpLeague', 'base bearer', 'Graph constructor'] as $kind) {
                foreach (['initial', 'fresh cache'] as $state) {
                    yield $mode.' / '.$kind.' / '.$state => [$mode, $kind, $state];
                }
                if ('authorization code' === $mode) {
                    yield $mode.' / '.$kind.' / expired cache' => [$mode, $kind, 'expired cache'];
                }
            }
        }
    }

    private function successfulRun(bool $swift, string $mode, string $kind, string $state): array
    {
        $cached            = 'initial' === $state ? null : $this->cachedToken('fresh cache' === $state);
        $fixture           = $this->sdk($mode, [$this->tokenResponse()], [new Response(202), new Response(202)], $kind, false, $cached);
        $snapshot          = $this->snapshot($fixture);
        $transport         = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $fixture->callback = function () use ($fixture, $snapshot, $transport): void {
            $this->assertSame($snapshot, $this->snapshot($fixture), 'Token HTTP callbacks see original caller-owned configuration.');
            $this->assertSame($fixture->client, (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport));
        };
        for ($i = 0; $i < 2; ++$i) {
            if ($swift) {
                $this->assertSame(4, $transport->send($this->message()));
            } else {
                $fixture->client->me()->sendMail()->post(new Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody())->wait();
            }
        }
        $params = [];
        if ($fixture->tokens) {
            \parse_str($fixture->tokens[0]['body'], $params);
            \ksort($params);
            if ($swift) {
                $this->assertFalse($fixture->tokens[0]['options']['allow_redirects']);
            }
        }
        $cache   = $fixture->provider->getAccessTokenCache();
        $context = $fixture->provider->getTokenRequestContext();
        $token   = $cache->getAccessToken($context->getCacheKey());
        $this->assertNotNull($token);
        $this->assertSame($snapshot, $this->snapshot($fixture));

        return ['tokens' => \count($fixture->tokens), 'resources' => \count($fixture->resources), 'stats' => $fixture->stats, 'grant' => $params, 'access_token' => $token->getToken(), 'refresh_token' => $token->getRefreshToken(), 'scopes' => $fixture->provider->getScopes(), 'authorization' => \array_map(static fn ($entry) => $entry['request']->getHeaderLine('Authorization'), $fixture->resources)];
    }

    public function testSuccessfulCaeRefreshRetainsClaimsCacheAndRequestBody(): void
    {
        $claims    = \base64_encode('{"access_token":{"fixture":["challenge"]}}');
        $fixture   = $this->sdk('authorization code', [$this->tokenResponse('challenge-token')], [new Response(401, ['WWW-Authenticate' => 'Bearer claims="'.$claims.'"']), new Response(202), new Response(202)], 'phpLeague', false, $this->cachedToken(true));
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $this->assertSame(4, $transport->send($this->message()));
        $this->assertSame(4, $transport->send($this->message()));
        $this->assertCount(1, $fixture->tokens);
        $this->assertCount(3, $fixture->resources);
        \parse_str($fixture->tokens[0]['body'], $params);
        $this->assertSame('refresh_token', $params['grant_type']);
        $this->assertSame(\json_decode(\base64_decode($claims), true), \json_decode($params['claims'], true));
        $this->assertSame($fixture->resources[0]['body'], $fixture->resources[1]['body']);
        $this->assertSame('Bearer cached-access-token', $fixture->resources[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer challenge-token', $fixture->resources[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer challenge-token', $fixture->resources[2]['request']->getHeaderLine('Authorization'));
    }

    public function testCaeCallbackContextRemainsCurrentAcrossMultipleChallenges(): void
    {
        $control = $this->caeContextRun(false);
        $actual  = $this->caeContextRun(true);
        $this->assertSame($control, $actual);
    }

    private function caeContextRun(bool $swift): array
    {
        $claims    = \base64_encode('{"access_token":{"fixture":["challenge"]}}');
        $challenge = new Response(401, ['WWW-Authenticate' => 'Bearer claims="'.$claims.'"']);
        $resources = $swift ? [
            $challenge,
            Swift_GraphSdkFixture::json(201, ['id' => 'draft-id']),
            $challenge,
            Swift_GraphSdkFixture::json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=fixture', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]),
            Swift_GraphSdkFixture::json(201, ['nextExpectedRanges' => []]),
            new Response(202),
        ] : [$challenge, new Response(202), $challenge, new Response(202)];
        $denied   = new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant","error_description":"fixture refresh denied"}');
        $fixture  = $this->sdk('authorization code', [$denied, $this->tokenResponse('callback-token-one'), $denied, $this->tokenResponse('callback-token-two')], $resources, 'phpLeague', false, $this->cachedToken(true));
        $contexts = [];
        $fixture->context->setCAERedirectCallback(function ($received) use (&$contexts, $claims) {
            $this->assertSame(\base64_decode($claims), $received);
            $context = new AuthorizationCodeContext('fixture-tenant', 'fixture-client', 'fixture-secret', 'callback-code-'.\count($contexts), 'https://callback.safe.test/oauth');
            // Each replacement context must keep the next callback, as in normal SDK use.
            $contexts[] = $context;

            return new FulfilledPromise($context);
        });
        $callback = $fixture->context->getCAERedirectCallback();
        $fixture->context->setCAERedirectCallback(function ($received) use ($callback) {
            return $callback($received)->then(static function ($context) use ($callback) {
                $context->setCAERedirectCallback($callback);

                return $context;
            });
        });
        if ($swift) {
            $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
            $transport->setLargeAttachmentThreshold(16);
            $message = $this->message()->attach(new Swift_Attachment('PRIVATE-LARGE-ATTACHMENT', 'large.bin', 'application/octet-stream'));
            $this->assertSame(4, $transport->send($message));
        } else {
            for ($i = 0; $i < 2; ++$i) {
                $fixture->client->me()->sendMail()->post(new Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody())->wait();
            }
        }
        $this->assertCount(2, $contexts);
        $this->assertSame($contexts[1], $fixture->provider->getTokenRequestContext(), 'The latest CAE context remains current for the next send.');
        $current = $fixture->provider->getTokenRequestContext();
        $token   = $fixture->provider->getAccessTokenCache()->getAccessToken($current->getCacheKey());
        $this->assertSame('callback-token-two', $token->getToken());

        return ['tokens' => \count($fixture->tokens), 'access_token' => $token->getToken(), 'context_code' => $current->getParams()['code'], 'callbacks' => \count($contexts)];
    }

    public function testOrdinaryTokenErrorsRetainStatusAndCleanup(): void
    {
        $fixture   = $this->sdk('client credentials', [new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_client","error_description":"fixture authentication denied"}')], [new Response(202)]);
        $snapshot  = $this->snapshot($fixture);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $observer  = $this->observe($transport);
        $failed    = [];
        try {
            $transport->send($this->message(), $failed);
            $this->fail('Ordinary OAuth errors must remain failed sends.');
        } catch (Swift_TransportException $error) {
            $this->assertNull($error->getPrevious());
            $this->assertSame(400, $error->getCode());
        }
        $this->assertCount(1, $fixture->tokens);
        $this->assertSame([], $fixture->resources);
        $this->assertCount(4, $failed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame(1, $observer->completed);
        $this->assertSame($snapshot, $this->snapshot($fixture));
    }

    public function testCallerRetryMiddlewareStillRetriesAnOrdinaryTokenServerError(): void
    {
        $fixture = $this->sdk('client credentials', [new Response(503), $this->tokenResponse()], [new Response(202)], 'phpLeague', true);
        $this->assertSame(4, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()));
        $this->assertCount(2, $fixture->tokens);
        $this->assertSame($fixture->tokens[0]['body'], $fixture->tokens[1]['body']);
        foreach ($fixture->tokens as $request) {
            $this->assertFalse($request['options']['allow_redirects']);
        }
    }

    public function testNestedTokenFailureDoesNotPoisonTheOuterAuthentication(): void
    {
        $fixture           = $this->sdk('authorization code', [$this->tokenResponse('outer-token'), new Response(307, ['Location' => 'https://other.safe.test/nested']), $this->tokenResponse('unused')], [new Response(202)]);
        $snapshot          = $this->snapshot($fixture);
        $transport         = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $observer          = $this->observe($transport);
        $entered           = false;
        $fixture->callback = function () use ($fixture, $snapshot, $transport, &$entered): void {
            $this->assertSame($snapshot, $this->snapshot($fixture));
            if ($entered) {
                return;
            }
            $entered = true;
            $failed  = [];
            try {
                $transport->send($this->message(), $failed);
                $this->fail('A nested token redirect must fail separately.');
            } catch (Swift_TransportException $error) {
                $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
                $this->assertCount(4, $failed);
            }
        };
        $this->assertSame(4, $transport->send($this->message()));
        $this->assertCount(2, $fixture->tokens);
        $this->assertCount(1, $fixture->resources);
        $this->assertSame('Bearer outer-token', $fixture->resources[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(2, $observer->before);
        $this->assertSame(2, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
    }

    /** @dataProvider incompatibleProviders */
    public function testUnsupportedKnownProvidersFailBeforeTokenTraffic(string $kind, bool $cached): void
    {
        $fixture = $this->sdk('client credentials', [$this->tokenResponse()], [new Response(202)], 'phpLeague', false, $cached ? $this->cachedToken(true) : null);
        $context = $fixture->context;
        $cache   = $fixture->provider->getAccessTokenCache();
        $oauth   = $fixture->oauth;
        $token   = $fixture->provider;
        $auth    = $fixture->auth;
        $options = ['urlAccessToken' => 'https://login.microsoftonline.com/fixture/token', 'urlAuthorize' => 'https://login.microsoftonline.com/fixture/authorize', 'urlResourceOwnerDetails' => 'https://graph.microsoft.com/oidc/userinfo'];
        if ('non-cloneable OAuth' === $kind) {
            $oauth = new class($options, ['httpClient' => $fixture->tokenHttp]) extends League\OAuth2\Client\Provider\GenericProvider {
                private function __clone()
                {
                }
            };
        } elseif ('ignored HTTP setter' === $kind) {
            $oauth = new class($options, ['httpClient' => $fixture->tokenHttp]) extends League\OAuth2\Client\Provider\GenericProvider {
                public bool $ignore = false;

                public function setHttpClient(GuzzleHttp\ClientInterface $client)
                {
                    if (!$this->ignore) {
                        parent::setHttpClient($client);
                    }

                    return $this;
                }
            };
            $oauth->ignore = true;
        } elseif ('invalid HTTP getter' === $kind) {
            $oauth = new class($options, ['httpClient' => $fixture->tokenHttp]) extends League\OAuth2\Client\Provider\GenericProvider {
                public bool $invalid = false;

                public function getHttpClient()
                {
                    return $this->invalid ? new stdClass() : parent::getHttpClient();
                }
            };
            $oauth->invalid = true;
        } elseif ('invalid HTTP configuration' === $kind) {
            $http = $this->createMock(GuzzleHttp\ClientInterface::class);
            $http->method('getConfig')->willReturn(null);
            $http->expects($this->never())->method('send');
            $oauth->setHttpClient($http);
        }
        if ('non-cloneable token provider' === $kind) {
            $token = new class($context, [], Microsoft\Graph\Core\NationalCloud::GLOBAL, $cache, $oauth) extends GraphPhpLeagueAccessTokenProvider {
                private function __clone()
                {
                }
            };
        } elseif ('fixed OAuth getter' === $kind) {
            $token = new class($context, [], Microsoft\Graph\Core\NationalCloud::GLOBAL, $cache, $oauth) extends GraphPhpLeagueAccessTokenProvider {
                public League\OAuth2\Client\Provider\AbstractProvider $fixed;

                public function getOauthProvider(): League\OAuth2\Client\Provider\AbstractProvider
                {
                    return $this->fixed;
                }
            };
            $token->fixed = $oauth;
        } elseif ('unshared cache clone' === $kind) {
            $token = new class($context, [], Microsoft\Graph\Core\NationalCloud::GLOBAL, $cache, $oauth) extends GraphPhpLeagueAccessTokenProvider {
                public function __clone()
                {
                    (new ReflectionProperty(Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider::class, 'accessTokenCache'))->setValue($this, clone $this->getAccessTokenCache());
                }
            };
        } else {
            $token = new GraphPhpLeagueAccessTokenProvider($context, [], Microsoft\Graph\Core\NationalCloud::GLOBAL, $cache, $oauth);
        }
        if ('non-cloneable authentication' === $kind) {
            $auth = new class($token) extends BaseBearerTokenAuthenticationProvider {
                private function __clone()
                {
                }
            };
        } elseif ('fixed token getter' === $kind) {
            $auth = new class($token) extends BaseBearerTokenAuthenticationProvider {
                public Microsoft\Kiota\Abstractions\Authentication\AccessTokenProvider $fixed;

                public function getAccessTokenProvider(): Microsoft\Kiota\Abstractions\Authentication\AccessTokenProvider
                {
                    return $this->fixed;
                }
            };
            $auth->fixed = $token;
        } else {
            $auth = PhpLeagueAuthenticationProvider::createWithAccessTokenProvider($token);
        }
        $adapter = new GraphRequestAdapter($auth, $fixture->resourceHttp);
        $client  = GraphServiceClient::createWithRequestAdapter($adapter);
        $failed  = [];
        try {
            (new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($client), maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('Unsupported known providers cannot bypass the token policy.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertNull($error->getPrevious());
            $this->assertCount(4, $failed);
        }
        $this->assertSame([], $fixture->tokens);
        $this->assertSame([], $fixture->resources);
    }

    public static function incompatibleProviders(): iterable
    {
        foreach (['non-cloneable OAuth', 'ignored HTTP setter', 'invalid HTTP getter', 'invalid HTTP configuration', 'non-cloneable token provider', 'fixed OAuth getter', 'unshared cache clone', 'non-cloneable authentication', 'fixed token getter'] as $kind) {
            foreach ([false, true] as $cached) {
                yield $kind.' / cached '.(int) $cached => [$kind, $cached];
            }
        }
    }

    public function testCustomAuthenticationAndTokenProvidersRetainTheirOwnContract(): void
    {
        $custom = new class implements Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider {
            public int $calls = 0;

            public function authenticateRequest(Microsoft\Kiota\Abstractions\RequestInformation $request, array $additionalAuthenticationContext = []): Http\Promise\Promise
            {
                ++$this->calls;

                return new FulfilledPromise($request);
            }
        };
        $this->assertSame($custom, Swift_Transport_Api_GraphOAuthPolicy::scope($custom));
        $token = new class implements Microsoft\Kiota\Abstractions\Authentication\AccessTokenProvider {
            public function getAuthorizationTokenAsync(string $url, array $additionalAuthenticationContext = []): Http\Promise\Promise
            {
                return new FulfilledPromise('custom-cached-token');
            }

            public function getAllowedHostsValidator(): Microsoft\Kiota\Abstractions\Authentication\AllowedHostsValidator
            {
                return new Microsoft\Kiota\Abstractions\Authentication\AllowedHostsValidator();
            }
        };
        $auth = new BaseBearerTokenAuthenticationProvider($token);
        $this->assertSame($auth, Swift_Transport_Api_GraphOAuthPolicy::scope($auth));
        $this->assertTrue(\class_exists(Swift\Transport\Api\GraphOAuthPolicy::class));
        $requests = [];
        $http     = new Client(['handler' => GraphClientFactory::getDefaultHandlerStack(new MockHandler([
            function ($request, $options) use (&$requests) {
                $requests[] = [$request, $options];

                return new Response(202);
            },
        ]))]);
        $client = GraphServiceClient::createWithRequestAdapter(new GraphRequestAdapter($auth, $http));
        $this->assertSame(4, (new Swift_Transport_Api_MicrosoftGraphTransport($client))->send($this->message()));
        $this->assertCount(1, $requests);
        $this->assertSame('Bearer custom-cached-token', $requests[0][0]->getHeaderLine('Authorization'));
        $this->assertFalse($requests[0][1]['allow_redirects']);
    }

    public function testOriginalTokenHttpPolicyRemainsAvailableToTheCaller(): void
    {
        $fixture  = $this->sdk('client credentials', [new Response(307, ['Location' => 'https://other.safe.test/swift']), new Response(307, ['Location' => 'https://other.safe.test/caller']), $this->tokenResponse()], [new Response(202)]);
        $snapshot = $this->snapshot($fixture);
        try {
            (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message());
            $this->fail('Swift must reject its token redirect.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $token = $fixture->provider->getAuthorizationTokenAsync('https://graph.microsoft.com/v1.0/me')->wait();
        $this->assertSame('fixture-access-token', $token);
        $this->assertCount(3, $fixture->tokens);
        $this->assertSame('other.safe.test', $fixture->tokens[2]['request']->getUri()->getHost());
        $this->assertStringContainsString('fixture-secret', $fixture->tokens[2]['body']);
        $this->assertFalse($fixture->tokens[0]['options']['allow_redirects']);
        $this->assertIsArray($fixture->tokens[1]['options']['allow_redirects']);
        $this->assertSame(4, (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($this->message()), 'A fresh scope can use the caller cache after a prior redirect.');
        $this->assertCount(1, $fixture->resources);
        $this->assertSame($snapshot, $this->snapshot($fixture));
    }

    /** @dataProvider callbackErrors */
    public function testCallerCallbackExceptionsRetainTheirCauseAndCleanup(bool $programmerError): void
    {
        $fixture           = $this->sdk('client credentials', [$this->tokenResponse(), $this->tokenResponse(), $this->tokenResponse()], [new Response(202)]);
        $error             = $programmerError ? new TypeError('fixture callback type error') : new RuntimeException('fixture callback failure');
        $fixture->callback = static function () use ($error): never {
            throw $error;
        };
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $observer  = $this->observe($transport);
        $failed    = [];
        try {
            $sender = $programmerError ? new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0) : $transport;
            $sender->send($this->message(), $failed);
            $this->fail('Callback failures must remain transport failures.');
        } catch (Swift_TransportException $failure) {
            $this->assertNull($failure->getPrevious());
            $this->assertSame($programmerError, Swift_Transport_RetryPolicy::isTerminalFailure($failure));
        }
        $this->assertCount(1, $fixture->tokens);
        $this->assertSame([], $fixture->resources);
        $this->assertCount(4, $failed);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
    }

    public static function callbackErrors(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @dataProvider policies */
    public function testTokenRedirectRetainsPolicyAndEnvelopeCleanup(string $policy, bool $explicit): void
    {
        $fixture   = $this->sdk('client credentials', [new Response(307, ['Location' => 'https://other.safe.test/token'])], [new Response(202)]);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $plugin    = match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['*@safe.test']), 'catch-all' => new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catch@safe.test'), 'redirect' => new Swift_Plugins_RedirectingPlugin('sink@safe.test')
        };
        $transport->registerPlugin($plugin);
        $observer = $this->observe($transport);
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        try {
            $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('Policies cannot allow a redirected token exchange.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(match ($policy) {
            'allowlist' => 2, 'catch-all' => 3, 'redirect' => 1
        }, $failed);
        $this->assertCount(1, $fixture->tokens);
        $this->assertSame([], $fixture->resources);
        $this->assertSame($original, $message->toString());
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
    }

    public static function policies(): iterable
    {
        foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
            foreach ([false, true] as $explicit) {
                yield $policy.' / envelope '.(int) $explicit => [$policy, $explicit];
            }
        }
    }

    private function sdk(string $mode, array $tokenResponses, array $resourceResponses, string $kind = 'phpLeague', bool $callerRetry = false, ?AccessToken $cached = null): object
    {
        $context = match ($mode) {
            'client credentials' => new ClientCredentialContext('fixture-tenant', 'fixture-client', 'fixture-secret'),
            'authorization code' => new AuthorizationCodeContext('fixture-tenant', 'fixture-client', 'fixture-secret', 'fixture-code', 'https://callback.safe.test/oauth'),
            'on behalf of'       => new OnBehalfOfContext('fixture-tenant', 'fixture-client', 'fixture-secret', 'fixture-assertion'),
        };
        if ('Graph constructor' === $kind) {
            $auth     = new GraphPhpLeagueAuthenticationProvider($context);
            $provider = $auth->getAccessTokenProvider();
        } else {
            $provider = new GraphPhpLeagueAccessTokenProvider($context);
            $auth     = 'base bearer' === $kind ? new BaseBearerTokenAuthenticationProvider($provider) : PhpLeagueAuthenticationProvider::createWithAccessTokenProvider($provider);
        }
        if ($cached) {
            $context->setCacheKey($cached);
            $provider->getAccessTokenCache()->persistAccessToken($context->getCacheKey(), $cached);
        }
        $fixture    = (object) ['context' => $context, 'auth' => $auth, 'provider' => $provider, 'oauth' => $provider->getOauthProvider(), 'tokens' => [], 'resources' => [], 'stats' => 0, 'callback' => null];
        $tokenStack = HandlerStack::create($this->handler($tokenResponses, $fixture, 'tokens'));
        if ($callerRetry) {
            $tokenStack->push(Middleware::retry(static fn (int $retries, $request, $response, $error): bool => $retries < 1 && (null !== $error || null !== $response && ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400 || 503 === $response->getStatusCode())), static fn (): int => 0), 'fixture_retry');
        }
        $config             = $fixture->oauth->getHttpClient()->getConfig();
        $config['handler']  = $tokenStack;
        $config['on_stats'] = static function () use ($fixture): void {
            ++$fixture->stats;
            if ($fixture->callback) {
                ($fixture->callback)();
            }
        };
        $fixture->tokenHttp  = new Client($config);
        $fixture->tokenStack = $tokenStack;
        $fixture->oauth->setHttpClient($fixture->tokenHttp);
        $fixture->resourceStack = GraphClientFactory::getDefaultHandlerStack($this->handler($resourceResponses, $fixture, 'resources'));
        $fixture->resourceHttp  = new Client(['handler' => $fixture->resourceStack]);
        $fixture->adapter       = new GraphRequestAdapter($auth, $fixture->resourceHttp);
        $fixture->client        = GraphServiceClient::createWithRequestAdapter($fixture->adapter);

        return $fixture;
    }

    private function handler(array $responses, object $fixture, string $key): MockHandler
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = static function (RequestInterface $request, array $options) use ($fixture, $key, $response) {
                $fixture->{$key}[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

                return $response;
            };
        }

        return new MockHandler($queue);
    }

    private function snapshot(object $fixture): array
    {
        return [$fixture->client->getRequestAdapter(), $fixture->auth->getAccessTokenProvider(), $fixture->provider->getOauthProvider(), $fixture->provider->getAccessTokenCache(), $fixture->oauth->getHttpClient(), $fixture->tokenHttp->getConfig(), (string) $fixture->tokenStack, $fixture->resourceHttp->getConfig(), (string) $fixture->resourceStack];
    }

    private function cachedToken(bool $fresh): AccessToken
    {
        return new AccessToken(['access_token' => 'cached-access-token', 'refresh_token' => 'fixture-refresh', 'expires' => \time() + ($fresh ? 3600 : -100)]);
    }

    private function tokenResponse(string $token = 'fixture-access-token'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], \json_encode(['access_token' => $token, 'refresh_token' => 'refreshed-fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']));
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('PRIVATE-OAUTH-SUBJECT'))->setFrom('sender@safe.test')->setTo(['allowed@safe.test', 'blocked@outside.test'])->setCc('copy@safe.test')->setBcc('blind@outside.test')->setBody('PRIVATE-OAUTH-BODY');
    }

    private function observe(Swift_Transport $transport): object
    {
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

        return $observer;
    }
}
