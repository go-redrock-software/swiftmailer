<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_SendgridTrackingTest extends TestCase
{
    public function testUnconfiguredTransportPreservesProviderRequest(): void
    {
        $history   = [];
        $transport = $this->transport($history);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertArrayNotHasKey('tracking_settings', $this->payload($history[0]));
    }

    /** @dataProvider settings */
    public function testNullableSettingsReachTheirExactWireFields(?bool $click, ?bool $text, ?bool $open): void
    {
        $history   = [];
        $transport = $this->transport($history);
        $this->assertNull($transport->getClickTracking());
        $this->assertNull($transport->getClickTrackingText());
        $this->assertNull($transport->getOpenTracking());
        $this->assertSame($transport, $transport->setClickTracking($click));
        $this->assertSame($transport, $transport->setClickTrackingText($text));
        $this->assertSame($transport, $transport->setOpenTracking($open));
        $this->assertSame($click, $transport->getClickTracking());
        $this->assertSame($text, $transport->getClickTrackingText());
        $this->assertSame($open, $transport->getOpenTracking());
        $message  = $this->message();
        $original = $message->toString();
        $client   = $transport->httpClient;
        $this->assertSame(3, $transport->send($message));
        $this->assertSame($this->expected($click, $text, $open), $this->payload($history[0])['tracking_settings'] ?? []);
        $this->assertSame($original, $message->toString());
        $this->assertSame($client, $transport->httpClient);
        $this->assertSame('Bearer fixture-key', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertFalse($history[0]['options']['allow_redirects']);
    }

    public static function settings(): iterable
    {
        foreach ([null, false, true] as $click) {
            foreach ([null, false, true] as $text) {
                foreach ([null, false, true] as $open) {
                    yield [$click, $text, $open];
                }
            }
        }
    }

    public function testSettingsCanBeChangedAndResetOnReusedMessageAndTransport(): void
    {
        $history   = [];
        $transport = $this->transport($history, [new Response(202), new Response(202), new Response(202)]);
        $message   = $this->message();
        $transport->setClickTracking(true)->setClickTrackingText(false)->setOpenTracking(true);
        $transport->send($message);
        $transport->setClickTracking(false)->setClickTrackingText(true)->setOpenTracking(false);
        $transport->send($message);
        $transport->setClickTracking(null)->setClickTrackingText(null)->setOpenTracking(null);
        $transport->send($message);
        $this->assertSame($this->expected(true, false, true), $this->payload($history[0])['tracking_settings']);
        $this->assertSame($this->expected(false, true, false), $this->payload($history[1])['tracking_settings']);
        $this->assertArrayNotHasKey('tracking_settings', $this->payload($history[2]));
        $this->assertNull($transport->getClickTracking());
        $this->assertNull($transport->getClickTrackingText());
        $this->assertNull($transport->getOpenTracking());
    }

    public function testTrackingSettingsSurviveARealHttpRetryAndCanThenBeReset(): void
    {
        $history   = [];
        $transport = $this->transport($history, [new Response(503, ['Content-Type' => 'application/json'], '{"errors":[{"message":"temporarily unavailable"}]}'), new Response(202), new Response(202)]);
        $transport->setClickTracking(false)->setClickTrackingText(false)->setOpenTracking(false);
        $retry    = new Swift_Transport_RetryTransport($transport, 1, 0);
        $message  = $this->message();
        $original = $message->toString();
        $this->assertSame(3, $retry->send($message));
        $this->assertCount(2, $history);
        $this->assertSame($this->expected(false, false, false), $this->payload($history[0])['tracking_settings']);
        $this->assertSame($this->expected(false, false, false), $this->payload($history[1])['tracking_settings']);
        $transport->setClickTracking(null)->setClickTrackingText(null)->setOpenTracking(null);
        $this->assertSame(3, $transport->send($message));
        $this->assertArrayNotHasKey('tracking_settings', $this->payload($history[2]));
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);
    }

    /** @dataProvider dsnBooleans */
    public function testDsnBooleanOptionsAreAppliedToActualRequests(string $option, string $value, bool $expected): void
    {
        $transport             = (new Swift_Transport_DsnTransportFactory())->fromDsnString('sendgrid://fixture-key@default?'.$option.'='.$value);
        $history               = [];
        $transport->httpClient = $this->client($history);
        $this->assertSame(3, $transport->send($this->message()));
        $flags          = ['click_tracking' => null, 'click_tracking_text' => null, 'open_tracking' => null];
        $flags[$option] = $expected;
        $this->assertSame($this->expected(...\array_values($flags)), $this->payload($history[0])['tracking_settings']);
    }

    public static function dsnBooleans(): iterable
    {
        foreach (['click_tracking', 'click_tracking_text', 'open_tracking'] as $option) {
            foreach (['true' => true, 'false' => false, '1' => true, '0' => false, 'TRUE' => true, 'FALSE' => false, '%31' => true, '%30' => false] as $value => $expected) {
                yield [$option, (string) $value, $expected];
            }
        }
    }

    /** @dataProvider wrappers */
    public function testDsnOptionsStayOnTheirLeafInsideWrappers(string $wrapper): void
    {
        $leaf = 'sendgrid://fixture-key@default?click_tracking=0&click_tracking_text=1&open_tracking=false';
        $dsn  = match ($wrapper) {
            'leaf-retry'  => $leaf.'&retries=1&retry_delay=0',
            'outer-retry' => 'retry('.$leaf.')',
            default       => $wrapper.'('.$leaf.' null://default)?retry_period=15',
        };
        $transport           = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
        $backend             = $transport instanceof Swift_Transport_RetryTransport ? $transport->getInnerTransport() : $transport->getTransports()[0];
        $history             = [];
        $backend->httpClient = $this->client($history);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame($this->expected(false, true, false), $this->payload($history[0])['tracking_settings']);
        if ($transport instanceof Swift_Transport_LoadBalancedTransport) {
            $this->assertSame(15, $transport->getRetryPeriod());
            $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport->getTransports()[0]);
        }
    }

    public static function wrappers(): iterable
    {
        foreach (['leaf-retry', 'outer-retry', 'roundrobin', 'failover'] as $wrapper) {
            yield [$wrapper];
        }
    }

    /** @dataProvider invalidQueries */
    public function testInvalidOptionsFailWithoutExposingCredentials(string $query): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new Swift_Transport_DsnTransportFactory())->fromDsnString('sendgrid://PRIVATE-TRACKING-KEY@default?'.$query);
                $this->fail('Invalid tracking configuration must throw.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('tracking', $error->getMessage());
                $this->assertStringNotContainsString('PRIVATE-TRACKING-KEY', $error->getMessage());
                $this->assertStringNotContainsString('PRIVATE-TRACKING-KEY', \print_r(\array_slice($error->getTrace(), 0, 3), true));
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function invalidQueries(): iterable
    {
        foreach (['click_tracking=', 'click_tracking', 'click_tracking=2', 'click_tracking=yes', 'click_tracking=off', 'click_tracking=null', 'click_tracking=%20true', 'click_tracking=1.0', 'click_tracking[]=true', 'click_tracking[x]=false', 'click_tracking=0&click_tracking=1', 'open_tracking=0&open%5ftracking=1', 'click.tracking=0', 'click%20tracking=0', 'click_tracking%00=0', 'open_tracking=wat', 'click_tracking_text=wat'] as $query) {
            yield [$query];
        }
    }

    /** @dataProvider unsupportedSchemes */
    public function testTrackingOptionsRejectUnsupportedSchemes(string $scheme): void
    {
        try {
            (new Swift_Transport_DsnTransportFactory())->fromDsnString($scheme.'://PRIVATE-SCOPE-KEY@default?open_tracking=0');
            $this->fail('Unsupported schemes must reject SendGrid tracking options.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('SendGrid', $error->getMessage());
            $this->assertStringNotContainsString('PRIVATE-SCOPE-KEY', $error->getMessage());
        }
    }

    public static function unsupportedSchemes(): iterable
    {
        foreach (['smtp', 'sendgrid+smtp', 'resend', 'null', 'native'] as $scheme) {
            yield [$scheme];
        }
    }

    /** @dataProvider policies */
    public function testTrackingPreservesRecipientPoliciesAndEnvelopeCleanup(string $policy, bool $explicit): void
    {
        $history   = [];
        $transport = $this->transport($history);
        $transport->setClickTracking(false)->setOpenTracking(false);
        $listener = $this->createMock(Swift_Events_SendListener::class);
        $listener->expects($this->once())->method('beforeSendPerformed');
        $listener->expects($this->once())->method('sendPerformed');
        $transport->registerPlugin($listener);
        $transport->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['to@safe.test']),
            'catch-all' => new Swift_Plugins_AllowlistPlugin(['to@safe.test'], 'sink@safe.test'),
            'redirect'  => new Swift_Plugins_RedirectingPlugin('sink@safe.test'),
        });
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        $this->assertSame('catch-all' === $policy ? 2 : 1, $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
        $payload = $this->payload($history[0]);
        $this->assertSame($this->expected(false, null, false), $payload['tracking_settings']);
        $personalization = $payload['personalizations'][0];
        $recipients      = \array_column(\array_merge($personalization['to'], $personalization['cc'] ?? [], $personalization['bcc'] ?? []), 'email');
        \sort($recipients);
        $this->assertSame(match ($policy) {
            'allowlist' => ['to@safe.test'],
            'catch-all' => ['sink@safe.test', 'to@safe.test'],
            'redirect'  => ['sink@safe.test'],
        }, $recipients);
        $this->assertSame($original, $message->toString());
        $this->assertSame([], $failed);
        $this->assertNull($transport->activeEnvelope);
    }

    public static function policies(): iterable
    {
        foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
            foreach ([false, true] as $explicit) {
                yield [$policy, $explicit];
            }
        }
    }

    private function expected(?bool $click, ?bool $text, ?bool $open): array
    {
        $expected = [];
        if (null !== $click) {
            $expected['click_tracking']['enable'] = $click;
        }
        if (null !== $text) {
            $expected['click_tracking']['enable_text'] = $text;
        }
        if (null !== $open) {
            $expected['open_tracking']['enable'] = $open;
        }

        return $expected;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('Tracking controls'))
            ->setFrom('sender@safe.test')
            ->setTo('to@safe.test')->setCc('cc@safe.test')->setBcc('bcc@safe.test')
            ->setBody('Visit https://safe.test', 'text/plain')
            ->addPart('<a href="https://safe.test">Visit</a>', 'text/html');
    }

    private function transport(array &$history, array $responses = []): Swift_Transport_Api_SendgridTransport
    {
        return new Swift_Transport_Api_SendgridTransport('fixture-key', $this->client($history, $responses));
    }

    private function client(array &$history, array $responses = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses ?: [new Response(202)]));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function payload(array $entry): array
    {
        return \json_decode((string) $entry['request']->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
