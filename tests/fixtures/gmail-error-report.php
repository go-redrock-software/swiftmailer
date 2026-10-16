<?php

// Resolve reflected (possibly mutated) classes before eager namespace aliases.
$implementations = \json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
\spl_autoload_register(static function (string $class) use ($implementations): void {
    if (isset($implementations[$class])) {
        require $implementations[$class];
    }
});
require $argv[2];
$input    = \json_decode(\stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$requests = 0;
$handler  = new GuzzleHttp\Handler\MockHandler([static function ($request) use ($input, &$requests) {
    ++$requests;
    if (\str_starts_with($input['scenario'], 'rejected-')) {
        $status = match ($input['scenario']) {
            'rejected-success' => 200, 'rejected-denied' => 403, 'rejected-server' => 503
        };
        throw new GuzzleHttp\Exception\RequestException('TRACE-INCOMPLETE-TRANSFER', $request, new GuzzleHttp\Psr7\Response($status, [], '{}'));
    }
    if (\str_contains($input['scenario'], 'type-error')) {
        throw new TypeError('TRACE-PHP-DIAGNOSTIC');
    }
    if ('wrapped-error' === $input['scenario']) {
        throw new RuntimeException('TRACE-WRAPPER', 0, new Error('TRACE-PHP-DIAGNOSTIC'));
    }
    $status = match ($input['scenario']) {
        'redirect301' => 301, 'redirect302' => 302, 'redirect303' => 303, 'redirect307', 'token-redirect' => 307, 'redirect308' => 308,
        'malformed'   => 200, 'denied' => 403, 'server' => 503,
    };

    return new GuzzleHttp\Psr7\Response($status, ['Location' => 'https://other.safe.test/'], 200 === $status ? '{}' : '{"error":{"message":"TRACE-PROVIDER-DIAGNOSTIC"}}');
}]);
$http = new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create($handler)]);
$sdk  = new Google\Client(['client_id' => 'TRACE-CLIENT', 'client_secret' => 'TRACE-SECRET']);
$sdk->setHttpClient($http);
$sdk->setAccessToken(['access_token' => 'TRACE-ACCESS', 'refresh_token' => 'TRACE-REFRESH', 'created' => \str_starts_with($input['scenario'], 'token-') ? \time() - 4000 : \time(), 'expires_in' => 3600]);
if ($sdk->getHttpClient() !== $http) {
    echo \json_encode(['guard' => false]);
    exit;
}
$transport = new Swift_Transport_Api_GoogleTransport($sdk);
$failed    = [];
try {
    $count = $transport->send((new Swift_Message('fixture', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test'), $failed);
    echo \json_encode(['guard' => true, 'count' => $count, 'exception' => false, 'requests' => $requests, 'failed' => $failed], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $leaks = [];
    foreach (['message' => $error->getMessage(), 'string' => (string) $error, 'trace' => $error->getTraceAsString(), 'object' => \print_r($error, true)] as $key => $value) {
        $leaks[$key] = \str_contains($value, 'TRACE-');
    }
    echo \json_encode(['guard' => true, 'exception' => true, 'swift' => $error instanceof Swift_TransportException, 'terminal' => Swift_Transport_RetryPolicy::isTerminalFailure($error), 'code' => $error->getCode(), 'previous' => null !== $error->getPrevious(), 'leaks' => $leaks, 'requests' => $requests, 'failed' => $failed], JSON_THROW_ON_ERROR);
}
