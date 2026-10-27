<?php

// Preload reflected implementations before Composer can load the originals, including mutation files.
foreach (\array_slice($argv, 1, 6) as $implementation) {
    require $implementation;
}
require $argv[7];
$input    = \json_decode(\stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$requests = 0;
$http     = new Symfony\Component\HttpClient\MockHttpClient(static function () use ($input, &$requests): Symfony\Component\HttpClient\Response\MockResponse {
    ++$requests;
    if ('network' === $input['scenario']) {
        throw new Symfony\Component\HttpClient\Exception\TransportException('TRACE-NETWORK-DIAGNOSTIC');
    }
    if ('type-error' === $input['scenario']) {
        throw new TypeError('TRACE-PHP-DIAGNOSTIC');
    }
    if ('wrapped-error' === $input['scenario']) {
        throw new RuntimeException('fixture wrapper', 0, new Error('TRACE-PHP-DIAGNOSTIC'));
    }
    $status = match ($input['scenario']) {
        'denied'      => 403, 'throttled' => 429, 'server500' => 500,
        'server502'   => 502, 'server503' => 503, 'server504' => 504,
        'unsupported' => 501, 'redirect' => 307, 'malformed' => 200,
    };

    return new Symfony\Component\HttpClient\Response\MockResponse(
        'malformed' === $input['scenario'] ? '{"MessageId":["invalid"]}' : '{"message":"TRACE-PROVIDER-DIAGNOSTIC"}',
        ['http_code' => $status, 'response_headers' => $status >= 400 ? ['x-amzn-errortype: FixtureFailure'] : ['Location: https://other.example.test']],
    );
});
if ($input['direct']) {
    $client = new AsyncAws\Ses\SesClient([
        'region'       => 'us-east-1', 'accessKeyId' => 'TRACE-ACCESS', 'accessKeySecret' => 'TRACE-SECRET',
        'sessionToken' => 'TRACE-TOKEN', 'endpoint' => 'https://email.us-east-1.amazonaws.com',
    ], new AsyncAws\Core\Credentials\Credentials('TRACE-ACCESS', 'TRACE-SECRET', 'TRACE-TOKEN'), $http);
    $class     = 'amazon+api' === $input['scheme'] ? Swift_Transport_Api_AmazonSesApiTransport::class : Swift_Transport_Api_AmazonSesHttpTransport::class;
    $transport = new $class($client);
} else {
    $transport = (new Swift_Transport_DsnTransportFactory($http))->fromDsnString($input['scheme'].'://TRACE-ACCESS:TRACE-SECRET@default?session_token=TRACE-TOKEN');
    $client    = (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
}
if ($http !== Swift_Transport_Api_SesRequestPolicy::read(AsyncAws\Core\AbstractApi::class, 'httpClient', $client)) {
    echo \json_encode(['guard' => false], JSON_THROW_ON_ERROR);
    exit(0);
}
try {
    $transport->send((new Swift_Message('fixture', 'body'))->setFrom('sender@example.test')->setTo('to@example.test'), $failed);
    echo \json_encode(['guard' => true, 'accepted' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $views = ['message' => $error->getMessage(), 'string' => (string) $error, 'trace' => $error->getTraceAsString(), 'object' => \print_r($error, true)];
    $leaks = [];
    foreach ($views as $name => $view) {
        $leaks[$name] = \str_contains($view, 'TRACE-');
    }
    echo \json_encode([
        'guard'     => true, 'accepted' => false, 'transport_exception' => $error instanceof Swift_TransportException,
        'terminal'  => Swift_Transport_RetryPolicy::isTerminalFailure($error),
        'retryable' => $error instanceof Swift_TransportException && (new Swift_Transport_DefaultRetryClassifier())->isRetryable($error),
        'code'      => $error->getCode(), 'previous' => null !== $error->getPrevious(),
        'leaks'     => $leaks, 'requests' => $requests, 'failed' => $failed,
    ], JSON_THROW_ON_ERROR);
}
