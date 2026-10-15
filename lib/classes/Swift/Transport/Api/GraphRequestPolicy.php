<?php

use GuzzleHttp\ClientInterface;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\BaseRequestBuilder;
use Microsoft\Kiota\Abstractions\RequestAdapter;
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
    public static function scopeClient(#[SensitiveParameter] GraphServiceClient $client): GraphServiceClient
    {
        if (!(new ReflectionObject($client))->isCloneable()) {
            throw new Swift_Transport_NonRetryableException('The Graph client must support cloning for isolated HTTP requests.');
        }
        $adapter = $client->getRequestAdapter();
        self::assertAdapter($client, $adapter);
        $scoped = self::scope($adapter);
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

    private static function replace(ReflectionProperty $property, object $copy, object $replacement): void
    {
        $type = $property->getType();
        if ($property->isReadOnly() || $property->isStatic() || (null !== $type && (!$type instanceof ReflectionNamedType || !\is_a($replacement, $type->getName())))) {
            throw new Swift_Transport_NonRetryableException('The Graph SDK configuration cannot accept the scoped request policy.');
        }
        $property->setValue($copy, $replacement);
    }

    public static function scope(#[SensitiveParameter] RequestAdapter $adapter): RequestAdapter
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
        self::replace($property, $copy, new Swift_Transport_Api_NoRedirectClient($http));

        return $copy;
    }
}
