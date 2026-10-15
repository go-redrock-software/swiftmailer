<?php

use GuzzleHttp\ClientInterface;
use Microsoft\Kiota\Abstractions\RequestAdapter;
use Microsoft\Kiota\Http\GuzzleRequestAdapter;

/**
 * Isolates the HTTP policy of an already configured Kiota Guzzle adapter.
 *
 * Kiota exposes neither an HTTP-client accessor nor a scoped replacement hook.
 * Keep that compatibility dependency here and fail closed if it changes. Cloning
 * retains authentication, serialization, telemetry, CAE and the configured URL.
 */
class Swift_Transport_Api_GraphRequestPolicy
{
    public static function scope(RequestAdapter $adapter): RequestAdapter
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
        $property->setValue($copy, new Swift_Transport_Api_NoRedirectClient($http));

        return $copy;
    }
}
