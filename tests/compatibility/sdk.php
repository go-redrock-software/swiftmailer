<?php

/*
 * Native SDK regression contracts, runnable with production dependencies only.
 * Every HTTP boundary is synthetic; no provider credentials or network are used.
 */

$source   = $argv[1] ?? \dirname(__DIR__, 2);
$selected = $argv[2] ?? null;
if (!\is_file($source.'/vendor/autoload.php')) {
    \fwrite(STDERR, "SDK compatibility requires an installed source checkout.\n");
    exit(64);
}
require $source.'/vendor/autoload.php';
require __DIR__.'/../unit/Swift/GraphSdkFixture.php';
require __DIR__.'/../unit/Swift/SesSdkFixture.php';

function swiftSdkCheck(bool $condition, string $contract): void
{
    if (!$condition) {
        throw new RuntimeException($contract);
    }
}

function swiftSdkMessage(): Swift_Message
{
    return (new Swift_Message('Compatibility', 'Body'))
        ->setFrom('sender@example.test')
        ->setTo('to@example.test')
        ->setCc('copy@example.test')
        ->setBcc('blind@example.test');
}

function swiftSdkHttp(int $status, string $body, array &$wire): GuzzleHttp\Client
{
    $handler = static function (Psr\Http\Message\RequestInterface $request, array $options) use ($status, $body, &$wire) {
        $wire[] = ['request' => $request, 'options' => $options, 'body' => (string) $request->getBody()];
        \swiftSdkCheck(1 === \count($wire), 'One wire request per logical operation.');

        return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], $body));
    };

    return new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create($handler)]);
}

function swiftSdkGoogle(GuzzleHttp\Client $http): Google\Client
{
    $client = new Google\Client();
    $client->setAccessToken(['access_token' => 'fixture-bearer', 'created' => \time(), 'expires_in' => 3600]);
    $client->setHttpClient($http);

    return $client;
}

$checks = [
    'pool metadata' => static function (): void {
        $configuration = new Swift_Dsn_Configuration('failover(null://default)?retry_period=15');
        $transport     = (new Swift_Transport_DsnTransportFactory())->fromDsnString('failover(null://default)?retry_period=15');
        \swiftSdkCheck('15' === $configuration->getParameter('retry_period'), 'Nyholm function parameters survive parsing.');
        \swiftSdkCheck($transport instanceof Swift_Transport_FailoverTransport && 15 === $transport->getRetryPeriod(), 'Factory applies explicit pool periods.');
    },
    'SMTP zero credentials' => static function (): void {
        $transport = (new Swift_Transport_DsnTransportFactory())->fromDsnString('smtp://0:0@localhost');
        \swiftSdkCheck('0' === $transport->getUsername() && '0' === $transport->getPassword(), 'Literal zero credentials survive DSN construction.');
        \swiftSdkCheck(!$transport->isStarted(), 'DSN construction does not open SMTP.');
    },
    'Sendgrid tracking zero' => static function (): void {
        $transport = (new Swift_Transport_DsnTransportFactory())->fromDsnString('sendgrid://fixture@default?click_tracking=0&open_tracking=0');
        \swiftSdkCheck(false === $transport->getClickTracking() && false === $transport->getOpenTracking(), 'False-valued provider options remain false.');
    },
    'Gmail MIME send' => static function (): void {
        $wire     = [];
        $http     = \swiftSdkHttp(200, '{"id":"fixture-id"}', $wire);
        $client   = \swiftSdkGoogle($http);
        $message  = \swiftSdkMessage()->attach(new Swift_Attachment("fixture\0\xff", 'file.bin'));
        $original = $message->toString();
        \swiftSdkCheck(3 === (new Swift_Transport_Api_GoogleTransport($client))->send($message, $failed), 'Native Gmail accepts all delivery fields.');
        \swiftSdkCheck([] === $failed && 1 === \count($wire), 'Gmail emits one accepted request.');
        $payload = \json_decode($wire[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        \swiftSdkCheck($original === Swift\base64url_decode($payload['raw']), 'Gmail transmits complete MIME exactly once.');
        \swiftSdkCheck('https://gmail.googleapis.com/gmail/v1/users/me/messages/send' === (string) $wire[0]['request']->getUri(), 'Gmail uses its fixed send endpoint.');
        \swiftSdkCheck(false === $wire[0]['options']['allow_redirects'] && $http === $client->getHttpClient(), 'Scoped Gmail HTTP preserves caller ownership.');
        \swiftSdkCheck($original === $message->toString(), 'Gmail preserves the caller message.');
    },
];
foreach (['Api' => 'SES model MIME send', 'Http' => 'SES array MIME send'] as $kind => $name) {
    $checks[$name] = static function () use ($kind): void {
        $fixture  = new SesSdkFixture([], false, new AsyncAws\Core\Credentials\Credentials('fixture-key', 'fixture-secret'));
        $message  = \swiftSdkMessage()->attach(new Swift_Attachment("fixture\0\xff", 'file.bin'));
        $original = $message->toString();
        $class    = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        \swiftSdkCheck(3 === (new $class($fixture->client))->send($message, $failed), 'SES counts every submitted delivery field.');
        \swiftSdkCheck([] === $failed && 1 === \count($fixture->requests), 'SES accepts exactly one wire request.');
        $payload = $fixture->payload();
        $raw     = \base64_decode($payload['Content']['Raw']['Data'], true);
        \swiftSdkCheck(\is_string($raw) && 1 === \substr_count($raw, \base64_encode("fixture\0\xff")), 'SES preserves attachment bytes exactly once.');
        \swiftSdkCheck(!\preg_match('/^Bcc:/mi', $raw), 'SES raw MIME never exposes blind recipients.');
        \swiftSdkCheck(['to@example.test'] === $payload['Destination']['ToAddresses'] && ['copy@example.test'] === $payload['Destination']['CcAddresses'] && ['blind@example.test'] === $payload['Destination']['BccAddresses'], 'SES retains separate delivery audiences.');
        if ('Http' === $kind) {
            \swiftSdkCheck('fixture-id' === $message->getHeaders()->get('X-SES-Message-ID')->getFieldBody(), 'SES array transport exposes its accepted provider message ID.');
            $message->getHeaders()->removeAll('X-SES-Message-ID');
        }
        \swiftSdkCheck($original === $message->toString(), 'SES preserves caller MIME apart from its documented provider ID.');
    };
}
$checks += [
    'HTTP derived repeated envelope' => static function (): void {
        $wire      = [];
        $message   = \swiftSdkMessage()->setCc('to@example.test');
        $original  = $message->toString();
        $transport = new Swift_Transport_Api_ResendTransport('fixture-key', \swiftSdkHttp(200, '{"id":"accepted"}', $wire));
        \swiftSdkCheck(2 === $transport->send($message, $failed, Swift_Envelope::fromMessage($message)), 'Equivalent HTTP envelope deduplicates its audience.');
        \swiftSdkCheck(1 === \count($wire) && [] === $failed && $original === $message->toString(), 'Equivalent HTTP envelope preserves one request and caller MIME.');
    },
    'Gmail derived repeated envelope' => static function (): void {
        $wire      = [];
        $message   = \swiftSdkMessage()->setCc('to@example.test');
        $original  = $message->toString();
        $transport = new Swift_Transport_Api_GoogleTransport(\swiftSdkGoogle(\swiftSdkHttp(200, '{"id":"accepted"}', $wire)));
        \swiftSdkCheck($transport->send($message, $failed, Swift_Envelope::fromMessage($message)) > 0, 'Equivalent Gmail envelopes remain deliverable.');
        \swiftSdkCheck(1 === \count($wire) && [] === $failed && $original === $message->toString(), 'Equivalent Gmail envelope preserves one request and caller MIME.');
    },
    'Graph derived repeated envelope' => static function (): void {
        $message  = \swiftSdkMessage()->setCc('to@example.test');
        $original = $message->toString();
        $fixture  = new Swift_GraphSdkFixture();
        \swiftSdkCheck((new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($message, $failed, Swift_Envelope::fromMessage($message)) > 0, 'Equivalent Graph envelopes remain deliverable.');
        \swiftSdkCheck(1 === \count($fixture->requests) && [] === $failed && $original === $message->toString(), 'Equivalent Graph envelope preserves one request and caller MIME.');
    },
    'Graph native send' => static function (): void {
        $fixture  = new Swift_GraphSdkFixture();
        $message  = \swiftSdkMessage();
        $original = $message->toString();
        \swiftSdkCheck(3 === (new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client))->send($message, $failed), 'Native Graph counts delivery recipients.');
        \swiftSdkCheck(['POST /v1.0/me/sendMail'] === $fixture->paths() && [] === $failed, 'Graph submits one native sendMail request.');
        $payload = $fixture->payload()['Message'];
        \swiftSdkCheck(1 === \count($payload['toRecipients']) && 1 === \count($payload['ccRecipients']) && 1 === \count($payload['bccRecipients']), 'Graph retains every recipient field.');
        \swiftSdkCheck($original === $message->toString(), 'Graph preserves caller MIME.');
    },
    'Graph bearer DSN send' => static function (): void {
        $wire    = [];
        $http    = \swiftSdkHttp(202, '', $wire);
        $factory = new Swift_Transport_DsnTransportFactory(graphHttpClient: $http);
        \swiftSdkCheck(3 === $factory->fromDsnString('microsoft-graph://fixture-bearer@default')->send(\swiftSdkMessage(), $failed), 'Bearer DSN constructs a working native Graph client.');
        \swiftSdkCheck(1 === \count($wire) && [] === $failed, 'Bearer Graph sends one request.');
        \swiftSdkCheck('https://graph.microsoft.com/v1.0/me/sendMail' === (string) $wire[0]['request']->getUri() && 'Bearer fixture-bearer' === $wire[0]['request']->getHeaderLine('Authorization'), 'Bearer Graph retains its fixed endpoint and explicit token.');
    },
    'Graph application DSN send' => static function (): void {
        $resource  = $tokens = [];
        $factory   = new Swift_Transport_DsnTransportFactory(graphHttpClient: \swiftSdkHttp(202, '', $resource), graphTokenHttpClient: \swiftSdkHttp(200, '{"access_token":"fixture-bearer","expires_in":3600,"token_type":"Bearer"}', $tokens));
        $transport = $factory->fromDsnString('microsoft-graph://fixture-client:fixture-secret@default?tenant_id=fixture-tenant&user_id=mailbox');
        \swiftSdkCheck(3 === $transport->send(\swiftSdkMessage(), $failed), 'Application DSN constructs a working native Graph client.');
        \swiftSdkCheck(1 === \count($tokens) && 1 === \count($resource) && [] === $failed, 'Application Graph exchanges one token and sends once.');
        \swiftSdkCheck('https://login.microsoftonline.com/fixture-tenant/oauth2/v2.0/token' === (string) $tokens[0]['request']->getUri(), 'Application Graph retains its configured tenant.');
        \swiftSdkCheck('Bearer fixture-bearer' === $resource[0]['request']->getHeaderLine('Authorization'), 'Graph uses the exchanged application token.');
    },
];
if (null !== $selected && !isset($checks[$selected])) {
    \fwrite(STDERR, "Unknown SDK compatibility case.\n");
    exit(64);
}
$passed = true;
foreach ($checks as $name => $check) {
    if (null !== $selected && $selected !== $name) {
        continue;
    }
    try {
        $check();
        $result = ['case' => $name, 'passed' => true];
    } catch (Throwable $error) {
        $passed = false;
        // Never serialize SDK objects, requests or exception graphs into CI output.
        $result = ['case' => $name, 'passed' => false, 'error_type' => $error::class];
    }
    echo 'SWIFT_COMPAT_RESULT=', \json_encode($result, JSON_THROW_ON_ERROR), "\n";
}
exit($passed ? 0 : 1);
