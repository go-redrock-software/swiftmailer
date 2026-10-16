<?php

$implementations = \json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
\spl_autoload_register(static function (string $class) use ($implementations): void {
    if (isset($implementations[$class])) {
        require $implementations[$class];
    }
});
require $argv[2];
$input   = \json_decode(\stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$context = match ($input['auth']) {
    'client' => new Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext('fixture-tenant', 'TRACE-CLIENT', 'TRACE-SECRET'),
    'code'   => new Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext('fixture-tenant', 'TRACE-CLIENT', 'TRACE-SECRET', 'TRACE-CODE', 'https://callback.safe.test/oauth'),
    'obo'    => new Microsoft\Kiota\Authentication\Oauth\OnBehalfOfContext('fixture-tenant', 'TRACE-CLIENT', 'TRACE-SECRET', 'TRACE-ASSERTION'),
};
$provider  = new Microsoft\Graph\Core\Authentication\GraphPhpLeagueAccessTokenProvider($context);
$tokens    = 0;
$resources = 0;
$json      = static fn (int $status, array $body) => new GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], \json_encode($body, JSON_THROW_ON_ERROR));
$failure   = static function ($request) use ($input) {
    if ('network' === $input['kind']) {
        throw new GuzzleHttp\Exception\ConnectException('TRACE-NETWORK-DIAGNOSTIC', $request);
    }
    if ('type-error' === $input['kind']) {
        throw new TypeError('TRACE-PHP-DIAGNOSTIC');
    }
    if ('wrapped-error' === $input['kind']) {
        throw new RuntimeException('TRACE-WRAPPER', 0, new Error('TRACE-PHP-DIAGNOSTIC'));
    }

    if ('body-error' === $input['kind']) {
        return new GuzzleHttp\Psr7\Response($input['code'], ['Content-Type' => 'application/json'], new GuzzleHttp\Psr7\PumpStream(static function () {
            throw new TypeError('TRACE-BODY-DIAGNOSTIC');
        }));
    }
    $body     = 'token' === $input['phase'] ? ['error' => $input['code'] >= 500 ? 'server_error' : 'invalid_client', 'error_description' => 'TRACE-PROVIDER-DIAGNOSTIC'] : ['error' => ['code' => 'FixtureFailure', 'message' => 'TRACE-PROVIDER-DIAGNOSTIC']];
    $response = new GuzzleHttp\Psr7\Response($input['code'], ['Content-Type' => 'application/json', 'Location' => 'https://other.safe.test/collect'], \json_encode($body, JSON_THROW_ON_ERROR));
    if ('async-rejected' === $input['kind']) {
        return GuzzleHttp\Promise\Create::rejectionFor(new GuzzleHttp\Exception\RequestException('TRACE-TRANSFER-DIAGNOSTIC', $request, $response));
    }
    if ('rejected' === $input['kind']) {
        throw new GuzzleHttp\Exception\RequestException('TRACE-TRANSFER-DIAGNOSTIC', $request, $response);
    }

    return $response;
};
$tokenCallback = static function ($request) use (&$tokens, $input, $failure, $json) {
    ++$tokens;

    return 'token' === $input['phase'] ? $failure($request) : $json(200, ['access_token' => 'TRACE-ACCESS', 'expires_in' => 3600, 'token_type' => 'Bearer']);
};
$tokenQueue = \array_fill(0, $input['retry'] && 'token' === $input['phase'] ? 3 : 1, $tokenCallback);
$tokenHttp  = new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($tokenQueue))]);
$provider->getOauthProvider()->setHttpClient($tokenHttp);
$message  = (new Swift_Message('fixture', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setCc('copy@safe.test')->setBcc('blind@safe.test');
$stage    = $input['stage'];
$before   = [];
$calendar = \str_starts_with($stage, 'event');
if ($calendar) {
    $method = 'event cancel' === $stage ? 'CANCEL' : 'REQUEST';
    $ics    = \implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:'.$method, 'BEGIN:VEVENT', 'UID:fixture-invite', 'DTSTART:20261102T090000Z', 'DTEND:20261102T100000Z', 'SUMMARY:fixture', 'ATTENDEE:mailto:to@safe.test', 'END:VEVENT', 'END:VCALENDAR', '']);
    $message->attach(new Swift_Attachment($ics, 'invite.ics', 'text/calendar'));
    if ('event lookup' !== $stage) {
        $before[] = $json(200, ['value' => 'event create' === $stage ? [] : [['id' => 'event-id']]]);
    }
} elseif ('sendMail' !== $stage) {
    $message->attach(new Swift_Attachment('fixture-large-attachment-bytes', 'large.bin', 'application/octet-stream'));
    $message->attach(new Swift_Attachment('tiny', 'small.txt', 'text/plain'));
    if ('draft' !== $stage) {
        $before[] = $json(201, ['id' => 'draft-id']);
    }
    if (\in_array($stage, ['upload session', 'upload chunk', 'draft send'], true)) {
        $before[] = $json(201, ['id' => 'small-attachment-id']);
    }
    if (\in_array($stage, ['upload chunk', 'draft send'], true)) {
        $before[] = $json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=TRACE-UPLOAD', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]);
    }
    if ('draft send' === $stage) {
        $before[] = $json(201, ['id' => 'large-attachment-id', 'nextExpectedRanges' => []]);
    }
}
$expectedResources = 'token' === $input['phase'] ? 0 : \count($before) + 1;
$queue             = [];
foreach ($before as $response) {
    $queue[] = static function () use (&$resources, $response) {
        ++$resources;

        return $response;
    };
}
for ($i = 0; $i < ($input['retry'] ? 3 : 1); ++$i) {
    $queue[] = static function ($request) use (&$resources, $failure) {
        ++$resources;

        return $failure($request);
    };
}
$resourceHttp = new GuzzleHttp\Client(['http_errors' => $input['http_errors'], 'handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler($queue))]);
$auth         = Microsoft\Kiota\Authentication\PhpLeagueAuthenticationProvider::createWithAccessTokenProvider($provider);
$adapter      = new Microsoft\Graph\GraphRequestAdapter($auth, $resourceHttp);
$client       = Microsoft\Graph\GraphServiceClient::createWithRequestAdapter($adapter);
if ((new ReflectionProperty(Microsoft\Kiota\Http\GuzzleRequestAdapter::class, 'guzzleClient'))->getValue($adapter) !== $resourceHttp || $provider->getOauthProvider()->getHttpClient() !== $tokenHttp) {
    echo \json_encode(['guard' => false]);
    exit;
}
$snapshot  = [$resourceHttp->getConfig(), $tokenHttp->getConfig(), $adapter->getBaseUrl()];
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
$transport->setLargeAttachmentThreshold(16);
if ($calendar) {
    $transport->enableCalendarEventConversion();
}
$observer = new class implements Swift_Events_SendListener {
    public int $before = 0;

    public int $completed = 0;

    public int $result = 0;

    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        ++$this->before;
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        ++$this->completed;
        $this->result = $evt->getResult();
    }
};
$transport->registerPlugin($observer);
$failed   = [];
$original = $message->toString();
try {
    $sender = $input['retry'] ? new Swift_Transport_RetryTransport($transport, 2, 0) : $transport;
    if (isset($input['wrapper'])) {
        if ('retry' === $input['wrapper']) {
            $sender = new Swift_Transport_RetryTransport($transport, 2, 0, new class implements Swift_Transport_RetryClassifier {
                public function isRetryable(Swift_TransportException $error): bool
                {
                    return true;
                }
            });
        } else {
            $sender = 'failover' === $input['wrapper'] ? new Swift_Transport_FailoverTransport() : new Swift_Transport_LoadBalancedTransport();
            $sender->setTransports([$transport, new Swift_Transport_NullTransport(new Swift_Events_SimpleEventDispatcher())]);
        }
    }
    $count = $sender->send($message, $failed);
    echo \json_encode(['guard' => true, 'exception' => false, 'count' => $count], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $leaks       = [];
    $credentials = [];
    foreach (['message' => $error->getMessage(), 'string' => (string) $error, 'trace' => $error->getTraceAsString(), 'object' => \print_r($error, true)] as $name => $view) {
        $leaks[$name]       = \str_contains($view, 'TRACE-');
        $credentials[$name] = \str_contains($view, 'TRACE-SECRET') || \str_contains($view, 'TRACE-ACCESS') || \str_contains($view, 'TRACE-ASSERTION') || \str_contains($view, 'TRACE-CODE') || \str_contains($view, 'TRACE-UPLOAD');
    }
    echo \json_encode(['guard' => true, 'exception' => true, 'swift' => $error instanceof Swift_TransportException, 'code' => $error->getCode(), 'terminal' => Swift_Transport_RetryPolicy::isTerminalFailure($error), 'retryable' => $error instanceof Swift_TransportException && (new Swift_Transport_DefaultRetryClassifier())->isRetryable($error), 'previous' => null !== $error->getPrevious(), 'leaks' => $leaks, 'credentials' => $credentials, 'tokens' => $tokens, 'resources' => $resources, 'expected_resources' => $expectedResources, 'failed' => $failed, 'events' => [$observer->before, $observer->completed, $observer->result], 'restored' => $message->toString() === $original, 'ownership' => $client->getRequestAdapter() === $adapter && $provider->getOauthProvider()->getHttpClient() === $tokenHttp && [$resourceHttp->getConfig(), $tokenHttp->getConfig(), $adapter->getBaseUrl()] === $snapshot], JSON_THROW_ON_ERROR);
}
