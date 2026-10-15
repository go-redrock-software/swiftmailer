<?php

use GuzzleHttp\ClientInterface;
use Http\Promise\Promise;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\Authentication\BaseBearerTokenAuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Authentication\Oauth\TokenRequestContext;
use Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider;
use Microsoft\Kiota\Authentication\PhpLeagueAuthenticationProvider;

/** Scopes the HTTP owner of the standard Kiota/PhpLeague token provider chain. */
class Swift_Transport_Api_GraphOAuthPolicy implements AuthenticationProvider
{
    private function __construct(
        #[SensitiveParameter] private AuthenticationProvider $provider,
        #[SensitiveParameter] private Swift_Transport_Api_NoRedirectClient $http,
        #[SensitiveParameter] private PhpLeagueAccessTokenProvider $sourceToken,
        #[SensitiveParameter] private PhpLeagueAccessTokenProvider $scopedToken,
        private ReflectionProperty $contextField,
        private ReflectionProperty $scopesField,
        #[SensitiveParameter] private TokenRequestContext $initialContext,
        private array $initialScopes,
    ) {
    }

    public static function scope(#[SensitiveParameter] AuthenticationProvider $provider): AuthenticationProvider
    {
        // Custom providers can own arbitrary network clients outside this chain.
        if (!$provider instanceof BaseBearerTokenAuthenticationProvider) {
            return $provider;
        }
        $token = $provider->getAccessTokenProvider();
        if (!$token instanceof PhpLeagueAccessTokenProvider) {
            return $provider;
        }
        $oauth = $token->getOauthProvider();
        foreach ([$provider, $token, $oauth] as $owner) {
            if (!(new ReflectionObject($owner))->isCloneable()) {
                throw new Swift_Transport_NonRetryableException('Graph OAuth providers must support cloning for isolated token requests.');
            }
        }
        $guard = self::httpPolicy($oauth->getHttpClient());
        // Validate even on cache hits: a later CAE challenge may need this client.
        $guard->getConfig();
        $oauthCopy = clone $oauth;
        $oauthCopy->setHttpClient($guard);
        if ($oauthCopy->getHttpClient() !== $guard) {
            throw new Swift_Transport_NonRetryableException('The Graph OAuth provider must honor its scoped HTTP client.');
        }
        $contextField = self::field(PhpLeagueAccessTokenProvider::class, 'tokenRequestContext', $token);
        $scopesField  = self::field(PhpLeagueAccessTokenProvider::class, 'scopes', $token);
        $context      = $token->getTokenRequestContext();
        $scopes       = $token->getScopes();
        if ($contextField->getValue($token) !== $context || $scopesField->getValue($token) !== $scopes) {
            throw new Swift_Transport_NonRetryableException('The Graph token provider must honor its authentication state.');
        }
        $tokenCopy = clone $token;
        self::replace(self::field(PhpLeagueAccessTokenProvider::class, 'oauthProvider', $tokenCopy), $tokenCopy, $oauthCopy);
        if ($tokenCopy->getOauthProvider() !== $oauthCopy || $tokenCopy->getAccessTokenCache() !== $token->getAccessTokenCache() || $tokenCopy->getTokenRequestContext() !== $context || $tokenCopy->getScopes() !== $scopes) {
            throw new Swift_Transport_NonRetryableException('The Graph token provider must retain its scoped OAuth provider and shared authentication state.');
        }
        $copy = clone $provider;
        self::replace(self::field(BaseBearerTokenAuthenticationProvider::class, 'accessTokenProvider', $copy), $copy, $tokenCopy);
        // PhpLeague declares another field with the same name as its parent.
        if ($copy instanceof PhpLeagueAuthenticationProvider) {
            self::replace(self::field(PhpLeagueAuthenticationProvider::class, 'accessTokenProvider', $copy), $copy, $tokenCopy);
        }
        if ($copy->getAccessTokenProvider() !== $tokenCopy) {
            throw new Swift_Transport_NonRetryableException('The Graph authentication provider must honor its scoped token provider.');
        }

        return new self($copy, $guard, $token, $tokenCopy, $contextField, $scopesField, $context, $scopes);
    }

    #[Override]
    public function authenticateRequest(RequestInformation $request, array $additionalAuthenticationContext = []): Promise
    {
        $this->http->assertNoRedirect();
        try {
            $promise = $this->provider->authenticateRequest($request, $additionalAuthenticationContext);
        } catch (Throwable $error) {
            $this->complete();
            throw $error;
        }

        return $promise->then(
            function (RequestInformation $result): RequestInformation {
                $this->complete();

                return $result;
            },
            function (Throwable $error): never {
                $this->complete();
                throw $error;
            },
        );
    }

    private function complete(): void
    {
        // Retain normal SDK state changes (derived scopes and a context returned
        // by a CAE callback). Do not overwrite a newer caller/reentrant update.
        $context = $this->contextField->getValue($this->scopedToken);
        $scopes  = $this->scopesField->getValue($this->scopedToken);
        if (!$context instanceof TokenRequestContext || !\is_array($scopes)) {
            throw new Swift_Transport_NonRetryableException('The Graph token provider returned unsupported authentication state.');
        }
        if ($this->contextField->getValue($this->sourceToken) === $this->initialContext) {
            $this->contextField->setValue($this->sourceToken, $context);
            $this->initialContext = $context;
        }
        if ($this->scopesField->getValue($this->sourceToken) === $this->initialScopes) {
            $this->scopesField->setValue($this->sourceToken, $scopes);
            $this->initialScopes = $scopes;
        }
        // CAE can replace the original exception or try a fallback token grant.
        // Keep a latched redirect terminal even when its cause was discarded.
        $this->http->assertNoRedirect();
    }

    /** Validate the result of the dependency's untyped HTTP-client getter. */
    private static function httpPolicy(#[SensitiveParameter] mixed $client): Swift_Transport_Api_NoRedirectClient
    {
        if (!$client instanceof ClientInterface) {
            throw new Swift_Transport_NonRetryableException('Graph OAuth requires a Guzzle-compatible token HTTP client.');
        }

        return new Swift_Transport_Api_NoRedirectClient($client);
    }

    private static function field(string $class, string $name, #[SensitiveParameter] object $owner): ReflectionProperty
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->hasProperty($name)) {
            throw new Swift_Transport_NonRetryableException('The Graph OAuth ownership configuration is unsupported.');
        }
        $property = $reflection->getProperty($name);
        if (!$property->isInitialized($owner) || $property->isReadOnly() || $property->isStatic()) {
            throw new Swift_Transport_NonRetryableException('Graph OAuth requires initialized, replaceable provider configuration.');
        }

        return $property;
    }

    private static function replace(ReflectionProperty $property, #[SensitiveParameter] object $copy, #[SensitiveParameter] object $replacement): void
    {
        $type = $property->getType();
        if (null !== $type && (!$type instanceof ReflectionNamedType || !\is_a($replacement, $type->getName()))) {
            throw new Swift_Transport_NonRetryableException('The Graph OAuth configuration cannot accept the scoped provider.');
        }
        $property->setValue($copy, $replacement);
    }
}
