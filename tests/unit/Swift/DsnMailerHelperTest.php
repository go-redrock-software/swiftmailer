<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_DsnMailerHelperTest extends TestCase
{
    /** @dataProvider envelopes */
    public function testPublicHelperReturnsAMailerThatCanDeliverThroughNullTransport(bool $explicit): void
    {
        $mailer = Swift::createTransportFromDsn('null://default');
        $this->assertInstanceOf(Swift_Mailer::class, $mailer);
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $mailer->getTransport());
        $listener = $this->createMock(Swift_Events_SendListener::class);
        $listener->expects($this->once())->method('beforeSendPerformed');
        $listener->expects($this->once())->method('sendPerformed');
        $mailer->registerPlugin($listener);
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        $envelope = $explicit ? new Swift_Envelope('sender@safe.test', ['only@safe.test']) : null;
        $this->assertSame($explicit ? 1 : 3, $mailer->send($message, $failed, $envelope));
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function envelopes(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @dataProvider smtpDsns */
    public function testSmtpSettingsAreConfiguredWithoutStartingAConnection(string $dsn, string $host, int $port, string $encryption): void
    {
        $mailer = Swift::createTransportFromDsn($dsn);
        $this->assertInstanceOf(Swift_Mailer::class, $mailer);
        $transport = $mailer->getTransport();
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
        $this->assertSame($host, $transport->getHost());
        $this->assertSame($port, $transport->getPort());
        $this->assertSame($encryption, $transport->getEncryption());
        $this->assertSame('fixture-user', $transport->getUsername());
        $this->assertSame('fixture@password', $transport->getPassword());
        $this->assertFalse($transport->isStarted());
    }

    public static function smtpDsns(): iterable
    {
        yield ['smtp://fixture-user:fixture%40password@smtp.safe.test:2525', 'smtp.safe.test', 2525, ''];
        yield ['smtp+tls://fixture-user:fixture%40password@smtp.safe.test:587', 'smtp.safe.test', 587, 'tls'];
        yield ['smtp+ssl://fixture-user:fixture%40password@smtp.safe.test', 'smtp.safe.test', 465, 'ssl'];
        yield ['sendgrid+smtp://fixture-user:fixture%40password@default', 'smtp.sendgrid.net', 587, 'tls'];
    }

    /** @dataProvider decorators */
    public function testPublicHelperPreservesDecoratorsAndPoolSettings(string $dsn, string $outer, ?string $inner, ?int $period): void
    {
        $mailer = Swift::createTransportFromDsn($dsn);
        $this->assertInstanceOf(Swift_Mailer::class, $mailer);
        $transport = $mailer->getTransport();
        $this->assertInstanceOf($outer, $transport);
        if ($transport instanceof Swift_Transport_RetryTransport) {
            $transport = $transport->getInnerTransport();
            $this->assertInstanceOf($inner, $transport);
        }
        if ($transport instanceof Swift_Transport_LoadBalancedTransport) {
            $this->assertSame($period, $transport->getRetryPeriod());
            $this->assertCount(2, $transport->getTransports());
        }
        $this->assertSame(3, $mailer->send($this->message()));
    }

    public static function decorators(): iterable
    {
        yield ['retry(null://default)', Swift_Transport_RetryTransport::class, Swift_Transport_NullTransport::class, null];
        yield ['null://default?retries=2&retry_delay=0', Swift_Transport_RetryTransport::class, Swift_Transport_NullTransport::class, null];
        yield ['failover(null://default null://default)?retry_period=7', Swift_Transport_FailoverTransport::class, null, 7];
        yield ['roundrobin(null://default null://default)?retry_period=0', Swift_Transport_LoadBalancedTransport::class, null, 0];
        yield ['retry(failover(null://default null://default)?retry_period=9)', Swift_Transport_RetryTransport::class, Swift_Transport_FailoverTransport::class, 9];
        yield ['retry(roundrobin(null://default null://default)?retry_period=3)', Swift_Transport_RetryTransport::class, Swift_Transport_LoadBalancedTransport::class, 3];
    }

    /** @dataProvider policies */
    public function testConfiguredMailerRetainsTrackingRetriesRecipientPoliciesAndEvents(string $policy, bool $explicit): void
    {
        $mailer  = Swift::createTransportFromDsn('retry(failover(sendgrid://fixture-key@default?click_tracking=0&click_tracking_text=1&open_tracking=false&retries=2&retry_delay=0 null://default)?retry_period=9)');
        $pool    = $mailer->getTransport()->getInnerTransport();
        $backend = $pool->getTransports()[0]->getInnerTransport();
        $this->assertInstanceOf(Swift_Transport_Api_SendgridTransport::class, $backend);
        $history = [];
        $stack   = HandlerStack::create(new MockHandler([
            new Response(503, ['Content-Type' => 'application/json'], '{"errors":[{"message":"temporarily unavailable"}]}'),
            new Response(503, ['Content-Type' => 'application/json'], '{"errors":[{"message":"temporarily unavailable"}]}'),
            new Response(202),
        ]));
        $stack->push(Middleware::history($history));
        $backend->httpClient = new Client(['handler' => $stack]);
        $client              = $backend->httpClient;
        $mailer->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['to@safe.test']),
            'catch-all' => new Swift_Plugins_AllowlistPlugin(['to@safe.test'], 'sink@safe.test'),
            'redirect'  => new Swift_Plugins_RedirectingPlugin('sink@safe.test'),
        });
        $listener = $this->createMock(Swift_Events_SendListener::class);
        $listener->expects($this->exactly(3))->method('beforeSendPerformed');
        $listener->expects($this->exactly(3))->method('sendPerformed');
        $mailer->registerPlugin($listener);
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        $this->assertSame('catch-all' === $policy ? 2 : 1, $mailer->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
        $this->assertCount(3, $history);
        foreach ($history as $entry) {
            $payload = \json_decode((string) $entry['request']->getBody(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame(['click_tracking' => ['enable' => false, 'enable_text' => true], 'open_tracking' => ['enable' => false]], $payload['tracking_settings']);
            $personalization = $payload['personalizations'][0];
            $recipients      = \array_column(\array_merge($personalization['to'], $personalization['cc'] ?? [], $personalization['bcc'] ?? []), 'email');
            \sort($recipients);
            $this->assertSame(match ($policy) {
                'allowlist' => ['to@safe.test'],
                'catch-all' => ['sink@safe.test', 'to@safe.test'],
                'redirect'  => ['sink@safe.test'],
            }, $recipients);
            $this->assertSame('Bearer fixture-key', $entry['request']->getHeaderLine('Authorization'));
            $this->assertFalse($entry['options']['allow_redirects']);
        }
        $this->assertSame(9, $pool->getRetryPeriod());
        $this->assertSame($client, $backend->httpClient);
        $this->assertSame($original, $message->toString());
        $this->assertNull($backend->activeEnvelope);
    }

    public static function policies(): iterable
    {
        foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
            foreach ([false, true] as $explicit) {
                yield [$policy, $explicit];
            }
        }
    }

    /** @dataProvider rejectedDsns */
    public function testHelperPreservesFactoryValidationErrors(string $dsn): void
    {
        $expected = $this->rejection(static fn () => (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn));
        $actual   = $this->rejection(static fn () => Swift::createTransportFromDsn($dsn));
        $this->assertSame($expected::class, $actual::class);
        $this->assertSame($expected->getMessage(), $actual->getMessage());
    }

    public static function rejectedDsns(): iterable
    {
        foreach (['unknown://default', '', 'not a DSN', 'sendmail://default?command=/tmp/untrusted-mailer%20-bs', 'roundrobin(null://default)?retry_period=-1', 'roundrobin(null://default)?retry_period=1&retry_period=2', 'sendgrid://fixture-key@default?click_tracking=wat', 'smtp://default?open_tracking=0'] as $dsn) {
            yield [$dsn];
        }
    }

    /** @dataProvider privateDsns */
    public function testOwnFactoryRejectionTraceRedactsTheHelperArgument(string $kind): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            $dsn   = 'sendgrid' === $kind ? 'sendgrid://PRIVATE-HELPER-KEY@default?open_tracking=wat' : 'failover(sendgrid://PRIVATE-HELPER-KEY@default)?retry_period=-1';
            $error = $this->rejection(static fn () => Swift::createTransportFromDsn($dsn));
            $this->assertInstanceOf(InvalidArgumentException::class, $error);
            $frames = \array_values(\array_filter($error->getTrace(), static fn (array $frame): bool => Swift::class === ($frame['class'] ?? null) && 'createTransportFromDsn' === $frame['function']));
            $this->assertCount(1, $frames);
            $this->assertStringNotContainsString('PRIVATE-HELPER-KEY', \print_r($frames, true));
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function privateDsns(): iterable
    {
        yield ['sendgrid'];
        yield ['pool'];
    }

    public function testRepeatedCallsConstructIndependentMailersAndBackends(): void
    {
        $first  = Swift::createTransportFromDsn('failover(null://default null://default)?retry_period=4');
        $second = Swift::createTransportFromDsn('failover(null://default null://default)?retry_period=4');
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->getTransport(), $second->getTransport());
        foreach ($first->getTransport()->getTransports() as $index => $backend) {
            $this->assertNotSame($backend, $second->getTransport()->getTransports()[$index]);
        }
        $listener = $this->createMock(Swift_Events_SendListener::class);
        $listener->expects($this->once())->method('beforeSendPerformed');
        $listener->expects($this->once())->method('sendPerformed');
        $first->registerPlugin($listener);
        $this->assertSame(3, $first->send($this->message()));
        $this->assertSame(3, $second->send($this->message()));
    }

    private function rejection(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $error) {
            return $error;
        }
        $this->fail('The invalid configuration must throw.');
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('Public DSN helper'))
            ->setFrom('sender@safe.test')->setTo('to@safe.test')
            ->setCc('cc@safe.test')->setBcc('bcc@safe.test')
            ->setBody('Fixture message');
    }
}
