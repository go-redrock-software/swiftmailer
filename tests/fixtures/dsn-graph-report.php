<?php

$implementations = \json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
\spl_autoload_register(static function (string $class) use ($implementations): void {
    if (isset($implementations[$class])) {
        require $implementations[$class];
    }
});
require $argv[2];
$input     = \json_decode(\stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$resources = 0;
$tokens    = 0;
$failure   = static function ($request) use ($input) {
    if ('error' === $input['kind']) {
        throw new TypeError('TRACE-PHP-ERROR');
    }
    if ('network' === $input['kind']) {
        throw new GuzzleHttp\Exception\ConnectException('TRACE-NETWORK', $request);
    }
    if ('cae' === $input['kind']) {
        return new GuzzleHttp\Psr7\Response(401, ['WWW-Authenticate' => 'Bearer claims="'.\base64_encode('{"access_token":{"policy":"TRACE-CLAIMS"}}').'"']);
    }
    $body = 'token' === $input['phase'] ? ['error' => $input['status'] >= 500 ? 'server_error' : 'invalid_client', 'error_description' => 'TRACE-TOKEN-DIAGNOSTIC'] : ['error' => ['code' => 'FixtureError', 'message' => 'TRACE-RESOURCE-DIAGNOSTIC']];

    return new GuzzleHttp\Psr7\Response($input['status'], ['Content-Type' => 'application/json', 'Location' => 'https://other.safe.test/TRACE-DESTINATION'], \json_encode($body, JSON_THROW_ON_ERROR));
};
$resourceHandler = static function ($request) use (&$resources, $failure) {
    ++$resources;

    return GuzzleHttp\Promise\Create::promiseFor($failure($request));
};
$tokenHandler = static function ($request) use (&$tokens, $input, $failure) {
    ++$tokens;

    return GuzzleHttp\Promise\Create::promiseFor('token' === $input['phase'] ? $failure($request) : new GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"access_token":"TRACE-ACCESS","expires_in":3600}'));
};
$resourceHttp = new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create($resourceHandler), 'http_errors' => $input['http_errors']]);
$tokenHttp    = new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create($tokenHandler), 'http_errors' => $input['http_errors']]);
$snapshot     = [$resourceHttp->getConfig(), $tokenHttp->getConfig()];
$factory      = new Swift_Transport_DsnTransportFactory(graphHttpClient: $resourceHttp, graphTokenHttpClient: $tokenHttp);
$guard        = false;
$transport    = null;
$observer     = null;
$message      = null;
$original     = null;
$failed       = [];
try {
    $dsn        = $input['dsn'] ?? ('bearer' === $input['auth'] ? 'microsoft-graph://TRACE-TOKEN@default' : 'microsoft-graph://TRACE-CLIENT:TRACE-SECRET@default?tenant_id=fixture-tenant&user_id=sender%40safe.test');
    $transport  = $factory->fromDsnString($dsn);
    $client     = (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
    $adapter    = $client->getRequestAdapter();
    $configured = (new ReflectionProperty(Microsoft\Kiota\Http\GuzzleRequestAdapter::class, 'guzzleClient'))->getValue($adapter);
    $handler    = new ReflectionProperty(GuzzleHttp\HandlerStack::class, 'handler');
    $auth       = (new ReflectionProperty(Microsoft\Kiota\Http\GuzzleRequestAdapter::class, 'authenticationProvider'))->getValue($adapter);
    $guard      = Microsoft\Graph\GraphServiceClient::class                                                  === $client::class && Microsoft\Graph\GraphRequestAdapter::class === $adapter::class
                                                                                                                                && $configured instanceof GuzzleHttp\Client && $handler->getValue($configured->getConfig('handler')) === $resourceHandler
                                                                                                                                && ('bearer' === $input['auth'] || $auth->getAccessTokenProvider()->getOauthProvider()->getHttpClient() === $tokenHttp);
    if (!$guard) {
        echo \json_encode(['guard' => false]);
        exit;
    }
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
    $message  = (new Swift_Message('fixture', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setCc('copy@safe.test')->setBcc('blind@safe.test');
    $original = $message->toString();
    $sender   = $input['retry'] ? new Swift_Transport_RetryTransport($transport, 2, 0) : $transport;
    $count    = $sender->send($message, $failed);
    echo \json_encode(['guard' => true, 'exception' => false, 'count' => $count]);
} catch (Throwable $error) {
    $leaks = [];
    foreach (['message' => $error->getMessage(), 'string' => (string) $error, 'trace' => $error->getTraceAsString(), 'object' => \print_r($error, true)] as $name => $view) {
        $leaks[$name] = \str_contains($view, 'TRACE-');
    }
    echo \json_encode(['guard' => $guard || 'construct' === $input['phase'], 'exception' => true, 'invalid' => $error instanceof InvalidArgumentException, 'swift' => $error instanceof Swift_TransportException, 'terminal' => Swift_Transport_RetryPolicy::isTerminalFailure($error), 'retryable' => $error instanceof Swift_TransportException && (new Swift_Transport_DefaultRetryClassifier())->isRetryable($error), 'code' => $error->getCode(), 'previous' => null !== $error->getPrevious(), 'leaks' => $leaks, 'resources' => $resources, 'tokens' => $tokens, 'failed' => $failed, 'events' => null === $observer ? [] : [$observer->before, $observer->completed, $observer->result], 'restored' => null === $message || $original === $message->toString(), 'ownership' => $snapshot === [$resourceHttp->getConfig(), $tokenHttp->getConfig()]], JSON_THROW_ON_ERROR);
}
