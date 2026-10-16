<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_PoolRetryPeriodTest extends TestCase
{
    /** @dataProvider publicApis */
    public function testPublicPeriodConfiguration(string $class, bool $convenience): void
    {
        $backend = new Swift_RetryPeriodBackend();
        $pool    = $convenience ? new $class([$backend]) : new $class();
        $this->assertSame(60, $pool->getRetryPeriod());
        $custom = $convenience ? new $class([$backend], 15) : new $class(15);
        $this->assertSame(15, $custom->getRetryPeriod());
        $this->assertSame($custom, $custom->setRetryPeriod(0));
        $this->assertSame(0, $custom->getRetryPeriod());
        try {
            $custom->setRetryPeriod(-1);
            $this->fail('Negative periods must be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame(0, $custom->getRetryPeriod());
        }
        $this->expectException(InvalidArgumentException::class);
        if ($convenience) {
            new $class([$backend], -1);
        } else {
            new $class(-1);
        }
    }

    public static function publicApis(): iterable
    {
        yield [Swift_Transport_LoadBalancedTransport::class, false];
        yield [Swift_Transport_FailoverTransport::class, false];
        yield [Swift_LoadBalancedTransport::class, true];
        yield [Swift_FailoverTransport::class, true];
    }

    /** @dataProvider callers */
    public function testCooldownCannotBeBypassedByAutomaticStartup(string $kind, string $caller): void
    {
        $backend           = new Swift_RetryPeriodBackend();
        $backend->failures = 1;
        $pool              = $this->pool($kind, [$backend], 15);
        $sender            = match ($caller) {
            'direct' => $pool, 'mailer' => new Swift_Mailer($pool), 'retry' => new Swift_Transport_RetryTransport($pool, 2, 0)
        };
        $this->failedSend($sender);
        $this->assertSame(1, $backend->sends);
        $pool->now = 1014.999;
        $pool->start();
        $this->assertFalse($pool->isStarted());
        $this->assertFalse($pool->ping());
        $this->failedSend($sender);
        $this->assertSame(1, $backend->sends);
        $this->assertSame(0, $backend->pings);
        $pool->now = 1015.0;
        $this->assertSame(3, $sender->send($this->message()));
        $this->assertSame(2, $backend->sends);
        $this->assertSame(1, $backend->starts);
        $pool->start();
        $pool->start();
        $this->assertCount(1, $pool->getTransports());
    }

    public static function callers(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach (['direct', 'mailer', 'retry'] as $caller) {
                yield [$kind, $caller];
            }
        }
    }

    /** @dataProvider kinds */
    public function testDefaultCooldownExpiresAfterSixtySeconds(string $kind): void
    {
        $backend           = new Swift_RetryPeriodBackend();
        $backend->failures = 1;
        $pool              = $this->pool($kind, [$backend], 60);
        $this->failedSend($pool);
        $pool->now = 1059.999;
        $this->failedSend($pool);
        $this->assertSame(1, $backend->sends);
        $pool->now = 1060.0;
        $this->assertSame(3, $pool->send($this->message()));
    }

    /** @dataProvider kinds */
    public function testZeroPeriodIsBoundedPerOperationAndPreservesWeights(string $kind): void
    {
        $first            = new Swift_RetryPeriodBackend();
        $first->failures  = 100;
        $second           = new Swift_RetryPeriodBackend();
        $second->failures = 100;
        $pool             = $this->pool($kind, [$first, $first, $second], 0);
        for ($i = 1; $i <= 3; ++$i) {
            $this->failedSend($pool);
            $this->assertSame($i, $first->sends);
            $this->assertSame($i, $second->sends);
            $this->assertSame($i, $first->stops);
            $this->assertCount(3, $pool->getTransports());
        }
        $first->failures = $second->failures = 0;
        $pool->start();
        $pool->start();
        $this->assertCount(3, $pool->getTransports());
        $this->assertCount(2, \array_filter($pool->getTransports(), static fn ($backend): bool => $backend === $first));
        $this->assertSame(3, $pool->send($this->message()));
    }

    /** @dataProvider kinds */
    public function testRepeatedFailureStartsANewDeadline(string $kind): void
    {
        $backend           = new Swift_RetryPeriodBackend();
        $backend->failures = 2;
        $pool              = $this->pool($kind, [$backend], 5);
        $this->failedSend($pool);
        $pool->now = 1005.0;
        $this->failedSend($pool);
        $this->assertSame(2, $backend->sends);
        $pool->now = 1009.999;
        $this->failedSend($pool);
        $this->assertSame(2, $backend->sends);
        $pool->now = 1010.0;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(3, $backend->sends);
    }

    /** @dataProvider kinds */
    public function testFailedPingAndStartupUseTheSameCooldown(string $kind): void
    {
        $backend          = new Swift_RetryPeriodBackend();
        $backend->healthy = false;
        $pool             = $this->pool($kind, [$backend], 10);
        $this->assertFalse($pool->ping());
        $this->assertSame(1, $backend->pings);
        $backend->healthy = true;
        $pool->now        = 1009.999;
        $this->assertFalse($pool->ping());
        $this->assertSame(1, $backend->pings);
        $pool->now = 1010.0;
        $this->assertTrue($pool->ping());
        $this->assertSame(2, $backend->pings);
        $backend->started       = false;
        $backend->startFailures = 1;
        $this->failedSend($pool);
        $this->assertSame(0, $backend->sends);
        $pool->now = 1019.999;
        $this->failedSend($pool);
        $this->assertSame(1, $backend->starts);
        $pool->now = 1020.0;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(2, $backend->starts);
    }

    /** @dataProvider adjustedPeriods */
    public function testChangingPeriodAppliesToOriginalFailureTime(string $kind, int $period): void
    {
        $backend           = new Swift_RetryPeriodBackend();
        $backend->failures = 1;
        $pool              = $this->pool($kind, [$backend], 15);
        $this->failedSend($pool);
        $pool->now = 1004.0;
        $pool->setRetryPeriod($period);
        $pool->now = 1000.0 + $period - 0.001;
        $this->failedSend($pool);
        $this->assertSame(1, $backend->sends);
        $pool->now = 1000.0 + $period;
        $this->assertSame(3, $pool->send($this->message()));
    }

    public static function adjustedPeriods(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach ([5, 30] as $period) {
                yield [$kind, $period];
            }
        }
    }

    /** @dataProvider kinds */
    public function testPartialRecoveryKeepsHealthySelectionAndRoundRobinOrder(string $kind): void
    {
        $first           = new Swift_RetryPeriodBackend();
        $first->failures = 1;
        $second          = new Swift_RetryPeriodBackend();
        $pool            = $this->pool($kind, [$first, $second], 10);
        $this->assertSame(3, $pool->send($this->message()));
        $pool->now = 1009.0;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(1, $first->sends);
        $pool->now = 1010.0;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame($second, $pool->getLastUsedTransport());
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame('failover' === $kind ? $second : $first, $pool->getLastUsedTransport());
        $this->assertCount(2, $pool->getTransports());
    }

    /** @dataProvider kinds */
    public function testPoolReplacementClearsOldFailureTimes(string $kind): void
    {
        $old           = new Swift_RetryPeriodBackend();
        $old->failures = 1;
        $replacement   = new Swift_RetryPeriodBackend();
        $pool          = $this->pool($kind, [$old], 15);
        $this->failedSend($pool);
        $pool->setTransports([$replacement]);
        $this->assertSame(3, $pool->send($this->message()));
        $pool->now = 2000.0;
        $pool->start();
        $this->assertSame([$replacement], $pool->getTransports());
        $pool->setTransports([$old]);
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(2, $old->sends);
    }

    /** @dataProvider kinds */
    public function testTerminalFailuresAreNotQuarantined(string $kind): void
    {
        $backend         = new Swift_RetryPeriodBackend();
        $failure         = new Swift_TransportException('fixture outer error', 503, new Swift_Transport_NonRetryableException('fixture terminal failure'));
        $backend->onSend = static function () use ($failure): never {
            throw $failure;
        };
        $pool = $this->pool($kind, [$backend], 60);
        $this->assertSame($failure, $this->failedSend($pool));
        $this->assertSame(0, $backend->stops);
        $backend->onSend = null;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(2, $backend->sends);
    }

    /** @dataProvider policyRecovery */
    public function testAutomaticRecoveryPreservesRecipientPolicy(string $kind, string $policy, bool $explicit): void
    {
        $wire    = [];
        $mock    = new MockHandler([new Response(503, ['Content-Type' => 'application/json'], '{"message":"service unavailable"}'), new Response(200, ['Content-Type' => 'application/json'], '{"id":"fixture-success"}')]);
        $handler = HandlerStack::create(static function ($request, $options) use (&$wire, $mock) {
            $wire[] = \json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

            return $mock($request, $options);
        });
        $backend = new Swift_Transport_Api_ResendTransport('fixture-key', new Client(['handler' => $handler]));
        $pool    = $this->pool($kind, [$backend], 5);
        $this->failedSend($pool);
        $pool->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['to@safe.test']), 'catch-all' => new Swift_Plugins_AllowlistPlugin(['to@safe.test'], 'sink@safe.test'), 'redirect' => new Swift_Plugins_RedirectingPlugin('sink@safe.test')
        });
        $message   = $this->message();
        $original  = $message->toString();
        $pool->now = 1005.0;
        $failed    = [];
        $this->assertSame('catch-all' === $policy ? 2 : 1, $pool->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
        $recipients = \array_merge($wire[1]['to'] ?? [], $wire[1]['cc'] ?? [], $wire[1]['bcc'] ?? []);
        \sort($recipients);
        $this->assertSame(match ($policy) {
            'allowlist' => ['to@safe.test'], 'catch-all' => ['sink@safe.test <sink@safe.test>', 'to@safe.test'], 'redirect' => ['sink@safe.test']
        }, $recipients);
        $this->assertSame($original, $message->toString());
        $this->assertSame([], $failed);
        $this->assertNull($backend->activeEnvelope);
    }

    public static function policyRecovery(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    yield [$kind, $policy, $explicit];
                }
            }
        }
    }

    /** @dataProvider kinds */
    public function testDifferentBackendsKeepIndependentFailureDeadlines(string $kind): void
    {
        $first           = new Swift_RetryPeriodBackend();
        $first->failures = 1;
        $second          = new Swift_RetryPeriodBackend();
        $pool            = $this->pool($kind, [$first, $second], 10);
        $this->assertSame(3, $pool->send($this->message()));
        $second->failures = 1;
        $pool->now        = 1005.0;
        $this->failedSend($pool);
        $pool->now = 1009.999;
        $this->failedSend($pool);
        $this->assertSame(1, $first->sends);
        $this->assertSame(2, $second->sends);
        $pool->now = 1010.0;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(2, $first->sends);
        $this->assertSame(2, $second->sends);
        $pool->now = 1014.999;
        $pool->ping();
        $this->assertSame(0, $second->pings);
        $pool->now       = 1015.0;
        $first->failures = 1;
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame($second, $pool->getLastUsedTransport());
        $this->assertSame(3, $second->sends);
    }

    /** @dataProvider kinds */
    public function testZeroPeriodCanReleaseAnAlreadyCoolingBackend(string $kind): void
    {
        $backend           = new Swift_RetryPeriodBackend();
        $backend->failures = 1;
        $pool              = $this->pool($kind, [$backend], 60);
        $this->failedSend($pool);
        $pool->setRetryPeriod(0);
        $pool->start();
        $this->assertTrue($pool->isStarted());
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(2, $backend->sends);
    }

    /** @dataProvider cleanupCallbacks */
    public function testTerminalCleanupRetainsSamePoolRecoveryAndRotation(string $kind, string $callback): void
    {
        $retired           = new Swift_RetryPeriodBackend();
        $retired->failures = 1;
        $first             = new Swift_RetryPeriodBackend();
        $second            = new Swift_RetryPeriodBackend();
        $third             = new Swift_RetryPeriodBackend();
        $pool              = $this->pool($kind, [$retired, $first, $second, $third], 'start' === $callback ? 0 : 60);
        $this->assertSame(3, $pool->send($this->message()));
        if ('roundrobin' === $kind) {
            $pool->send($this->message());
            $pool->send($this->message());
        }
        $terminal        = new Swift_Transport_NonRetryableException('fixture terminal cleanup');
        $first->failures = 1;
        $first->onStop   = function () use ($pool, $callback, $terminal): never {
            if ('start' === $callback) {
                $pool->start();
            } else {
                $this->assertSame(3, $pool->send($this->message()));
            }
            throw $terminal;
        };
        $this->assertSame($terminal, $this->failedSend($pool));
        $this->assertCount(4, $pool->getTransports());
        foreach ([$retired, $first, $second, $third] as $backend) {
            $this->assertCount(1, \array_filter($pool->getTransports(), static fn ($candidate): bool => $candidate === $backend));
        }
        $first->onStop = null;
    }

    public static function cleanupCallbacks(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach (['start', 'send'] as $callback) {
                yield [$kind, $callback];
            }
        }
    }

    /** @dataProvider kinds */
    public function testCooldownStartsAtFailureBeforeSlowCleanup(string $kind): void
    {
        $backend           = new Swift_RetryPeriodBackend();
        $backend->failures = 1;
        $pool              = $this->pool($kind, [$backend], 10);
        $backend->onStop   = static function () use ($pool): void {
            $pool->now += 10.0;
        };
        $this->failedSend($pool);
        $this->assertSame(1010.0, $pool->now);
        $this->assertSame(3, $pool->send($this->message()));
        $this->assertSame(2, $backend->sends);
    }

    public static function kinds(): iterable
    {
        yield ['roundrobin'];
        yield ['failover'];
    }

    private function pool(string $kind, array $backends, int $period): Swift_Transport_LoadBalancedTransport
    {
        $pool = 'failover' === $kind ? new Swift_RetryPeriodFailover($period) : new Swift_RetryPeriodRoundRobin($period);
        $pool->setTransports($backends);

        return $pool;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'fixture body'))->setFrom('author@safe.test')->setTo('to@safe.test')->setCc('copy@outside.test')->setBcc('blind@outside.test');
    }

    private function failedSend(object $sender): Swift_TransportException
    {
        try {
            $sender->send($this->message());
            $this->fail('The backend is failing or still cooling down.');
        } catch (Swift_TransportException $error) {
            return $error;
        }
    }
}

trait Swift_RetryPeriodClock
{
    public float $now = 1000.0;

    protected function getCurrentTime(): float
    {
        return $this->now;
    }
}
class Swift_RetryPeriodRoundRobin extends Swift_Transport_LoadBalancedTransport
{
    use Swift_RetryPeriodClock;
}
class Swift_RetryPeriodFailover extends Swift_Transport_FailoverTransport
{
    use Swift_RetryPeriodClock;
}
class Swift_RetryPeriodBackend implements Swift_Transport
{
    public bool $started = true;

    public bool $healthy = true;

    public int $failures = 0;

    public int $startFailures = 0;

    public int $sends = 0;

    public int $starts = 0;

    public int $stops = 0;

    public int $pings = 0;

    public ?Closure $onSend = null;

    public ?Closure $onStop = null;

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function start(): void
    {
        ++$this->starts;
        if ($this->startFailures > 0) {
            --$this->startFailures;
            throw new Swift_TransportException('fixture startup failure', 503);
        } $this->started = true;
    }

    public function stop(): void
    {
        ++$this->stops;
        if (null !== $this->onStop) {
            ($this->onStop)();
        } $this->started = false;
    }

    public function ping(): bool
    {
        ++$this->pings;

        return $this->healthy;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        ++$this->sends;
        if (!$this->started) {
            $this->start();
        }
        if (null !== $this->onSend) {
            ($this->onSend)($message, $failedRecipients, $envelope);
        }
        if ($this->failures > 0) {
            --$this->failures;
            throw new Swift_TransportException('fixture backend failure', 503);
        }

        return null !== $envelope ? \count($envelope->getRecipients()) : \count($message->getTo() ?? []) + \count($message->getCc() ?? []) + \count($message->getBcc() ?? []);
    }

    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
    }
}
