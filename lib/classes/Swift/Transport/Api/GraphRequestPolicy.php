<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Abstractions\BaseRequestBuilder;
use Microsoft\Kiota\Abstractions\RequestAdapter;
use Microsoft\Kiota\Authentication\Oauth\ContinuousAccessEvaluationException;
use Microsoft\Kiota\Http\GuzzleRequestAdapter;

/**
 * Isolates the HTTP policy of an already configured Kiota Guzzle adapter.
 *
 * Kiota exposes neither an HTTP-client accessor nor a scoped replacement hook.
 * Keep that compatibility dependency here and fail closed if it changes. Cloning
 * retains authentication, serialization, telemetry, CAE and the configured URL.
 * Clone the service client as well: its constructor resets global registries.
 */
class Swift_Transport_Api_GraphRequestPolicy
{
    /** Return safe failure metadata without retaining SDK/client exception graphs. */
    public static function detachedFailure(#[SensitiveParameter] Throwable $error): Swift_TransportException
    {
        $terminal = Swift_Transport_RetryPolicy::isTerminalFailure($error);
        try {
            $code                  = self::failureCode($error);
            $authenticationFailure = \in_array($code, [0, 400], true) && self::isAuthenticationFailure($error);
        } catch (Throwable) {
            $terminal              = true;
            $code                  = 0;
            $authenticationFailure = false;
        }
        $class  = $terminal ? Swift_Transport_NonRetryableException::class : Swift_TransportException::class;
        $detail = $authenticationFailure ? '; authentication failed' : (500 === $code ? '; internal server error' : '');

        return new $class('Failed to send email through Microsoft Graph (status '.$code.$detail.').', $code);
    }

    private static function isAuthenticationFailure(#[SensitiveParameter] Throwable $error): bool
    {
        for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof ContinuousAccessEvaluationException) {
                return true;
            }
            if (!$cause instanceof IdentityProviderException) {
                continue;
            }
            $body = $cause->getResponseBody();
            if (\is_array($body) && \in_array($body['error'] ?? null, ['invalid_request', 'invalid_client', 'invalid_grant', 'unauthorized_client', 'unsupported_grant_type', 'invalid_scope'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function failureCode(#[SensitiveParameter] Throwable $error): int
    {
        // Kiota stores HTTP status separately from Exception::getCode(). Prefer
        // response metadata; keep explicit redirect markers ahead of their causes.
        for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
            $status = null;
            if ($cause instanceof Swift_Transport_NonRetryableException) {
                $status = $cause->getCode();
            } elseif ($cause instanceof ApiException) {
                $status = $cause->getResponseStatusCode();
            } elseif ($cause instanceof RequestException && null !== ($response = $cause->getResponse())) {
                $status = $response->getStatusCode();
            }
            if (\is_int($status) && $status >= 100 && $status <= 599) {
                return $status;
            }
        }
        for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
            $code = (int) $cause->getCode();
            if (0 !== $code) {
                return $code;
            }
        }

        return 0;
    }

    public static function scopeClient(#[SensitiveParameter] GraphServiceClient $client, ?Swift_Transport_Api_GraphAcceptancePolicy $acceptance = null): GraphServiceClient
    {
        if (!(new ReflectionObject($client))->isCloneable()) {
            throw new Swift_Transport_NonRetryableException('The Graph client must support cloning for isolated HTTP requests.');
        }
        $adapter = $client->getRequestAdapter();
        self::assertAdapter($client, $adapter);
        $scoped = self::scope($adapter, $acceptance);
        $copy   = clone $client;
        self::replace(self::adapterProperty(), $copy, $scoped);
        if ($copy->getRequestAdapter() !== $scoped) {
            throw new Swift_Transport_NonRetryableException('The Graph client must honor its scoped request adapter.');
        }

        return $copy;
    }

    public static function assertAdapter(#[SensitiveParameter] BaseRequestBuilder $builder, #[SensitiveParameter] RequestAdapter $adapter): void
    {
        $property = self::adapterProperty();
        if (!$property->isInitialized($builder) || $property->getValue($builder) !== $adapter) {
            throw new Swift_Transport_NonRetryableException('The Graph request builder must use the scoped adapter.');
        }
    }

    private static function adapterProperty(): ReflectionProperty
    {
        $reflection = new ReflectionClass(BaseRequestBuilder::class);
        if (!$reflection->hasProperty('requestAdapter')) {
            throw new Swift_Transport_NonRetryableException('The Graph request-builder adapter configuration is unsupported.');
        }

        return $reflection->getProperty('requestAdapter');
    }

    private static function replace(ReflectionProperty $property, #[SensitiveParameter] object $copy, #[SensitiveParameter] object $replacement): void
    {
        $type = $property->getType();
        if ($property->isReadOnly() || $property->isStatic() || (null !== $type && (!$type instanceof ReflectionNamedType || !\is_a($replacement, $type->getName())))) {
            throw new Swift_Transport_NonRetryableException('The Graph SDK configuration cannot accept the scoped request policy.');
        }
        $property->setValue($copy, $replacement);
    }

    public static function scope(#[SensitiveParameter] RequestAdapter $adapter, ?Swift_Transport_Api_GraphAcceptancePolicy $acceptance = null): RequestAdapter
    {
        if (!$adapter instanceof GuzzleRequestAdapter || !(new ReflectionObject($adapter))->isCloneable()) {
            throw new Swift_Transport_NonRetryableException('Graph requires a cloneable Kiota Guzzle adapter for isolated HTTP requests.');
        }
        $reflection = new ReflectionClass(GuzzleRequestAdapter::class);
        if (!$reflection->hasProperty('guzzleClient')) {
            throw new Swift_Transport_NonRetryableException('The Kiota adapter HTTP configuration is unsupported.');
        }
        $property = $reflection->getProperty('guzzleClient');
        if (!$property->isInitialized($adapter) || $property->isReadOnly() || $property->isStatic()) {
            throw new Swift_Transport_NonRetryableException('The Kiota adapter must expose initialized, replaceable HTTP configuration.');
        }
        $http = $property->getValue($adapter);
        if (!$http instanceof ClientInterface) {
            throw new Swift_Transport_NonRetryableException('The Kiota adapter must use a Guzzle-compatible HTTP client.');
        }
        $copy = clone $adapter;
        self::replace($property, $copy, new Swift_Transport_Api_NoRedirectClient($http, null !== $acceptance ? $acceptance->validateRequest(...) : null, null !== $acceptance ? $acceptance->validateResponse(...) : null));
        if (!$reflection->hasProperty('authenticationProvider')) {
            throw new Swift_Transport_NonRetryableException('The Kiota adapter authentication configuration is unsupported.');
        }
        $authentication = $reflection->getProperty('authenticationProvider');
        if (!$authentication->isInitialized($adapter)) {
            throw new Swift_Transport_NonRetryableException('The Kiota adapter authentication provider must be initialized.');
        }
        $provider = $authentication->getValue($adapter);
        if (!$provider instanceof Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider) {
            throw new Swift_Transport_NonRetryableException('The Kiota adapter must use a compatible authentication provider.');
        }
        $scopedProvider = Swift_Transport_Api_GraphOAuthPolicy::scope($provider);
        if ($scopedProvider !== $provider) {
            self::replace($authentication, $copy, $scopedProvider);
        }

        return $copy;
    }
}
