<?php

use GuzzleHttp\ClientInterface;
use Http\Promise\Promise;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\Authentication\BaseBearerTokenAuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestInformation;
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
        private ReflectionProperty $scopesField,
        private array $initialScopes,
        #[SensitiveParameter] private Swift_Transport_Api_GraphOAuthGrantPolicy $grants,
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
        $oauthCopy    = clone $oauth;
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
        $grants = new Swift_Transport_Api_GraphOAuthGrantPolicy($token, $tokenCopy, $oauth, $oauthCopy, $contextField);
        $guard  = self::httpPolicy($oauth->getHttpClient(), $grants->validateRequest(...));
        $guard->getConfig();
        $oauthCopy->setHttpClient($guard);
        if ($oauthCopy->getHttpClient() !== $guard) {
            throw new Swift_Transport_NonRetryableException('The Graph OAuth provider must honor its scoped HTTP client.');
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

        return new self($copy, $guard, $token, $tokenCopy, $scopesField, $scopes, $grants);
    }

    #[Override]
    public function authenticateRequest(RequestInformation $request, array $additionalAuthenticationContext = []): Promise
    {
        $this->http->assertNoRedirect();
        $this->grants->assertValid();
        $this->http->resetResponseStatus();
        try {
            $this->grants->begin($additionalAuthenticationContext);
            $promise = $this->provider->authenticateRequest($request, $additionalAuthenticationContext);
        } catch (Throwable $error) {
            $this->grants->onFailure($error);
            $this->complete();
            throw $this->withHttpStatus($error);
        }

        return $promise->then(
            function (RequestInformation $result): RequestInformation {
                $this->complete();

                return $result;
            },
            function (Throwable $error): never {
                $this->grants->onFailure($error);
                $this->complete();
                throw $this->withHttpStatus($error);
            },
        );
    }

    private function withHttpStatus(#[SensitiveParameter] Throwable $error): Throwable
    {
        $status = $this->http->getLastResponseStatus();

        // GenericProvider can replace the response with an exception coded 0.
        // Retain the real token endpoint status until the outer send detaches it.
        return null !== $status && $status >= 400 && $status <= 599
            ? new Swift_TransportException('Graph OAuth token request failed.', $status, $error)
            : $error;
    }

    private function complete(): void
    {
        $this->http->assertNoRedirect();
        $this->grants->assertValid();
        $this->grants->publish();
        $scopes = $this->scopesField->getValue($this->scopedToken);
        if (!\is_array($scopes)) {
            throw new Swift_Transport_NonRetryableException('The Graph token provider returned unsupported scopes.');
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
    private static function httpPolicy(#[SensitiveParameter] mixed $client, Closure $validator): Swift_Transport_Api_NoRedirectClient
    {
        if (!$client instanceof ClientInterface) {
            throw new Swift_Transport_NonRetryableException('Graph OAuth requires a Guzzle-compatible token HTTP client.');
        }

        return new Swift_Transport_Api_NoRedirectClient($client, $validator);
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
