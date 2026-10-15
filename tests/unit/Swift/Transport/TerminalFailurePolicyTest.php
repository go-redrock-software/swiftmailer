<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_TerminalFailurePolicyTest extends TestCase
{
    /** @dataProvider httpFailures */
    public function testTerminalHttpFailureCannotBeReplayed(string $wrapper, string $failure, bool $explicit): void
    {
        $counts   = (object) ['primary' => 0, 'secondary' => 0, 'classifications' => 0];
        $response = match ($failure) {
            'ambiguous' => new Response(202, ['Content-Type' => 'application/json'], '{'),
            'redirect'  => new Response(307, ['Location' => 'https://other.safe.test/forward']),
            'error'     => new RuntimeException('fixture wrapped error', 503, new TypeError('fixture programming error')),
        };
        $success = new Response(200, ['Content-Type' => 'application/json'], '{"id":"fixture-success"}');
        $make    = static function (string $name, array $responses) use ($counts): Swift_Transport_Api_ResendTransport {
            $mock    = new MockHandler($responses);
            $handler = HandlerStack::create(static function ($request, $options) use ($counts, $name, $mock) {
                ++$counts->$name;

                return $mock($request, $options);
            });

            return new Swift_Transport_Api_ResendTransport('fixture-key', new Client(['handler' => $handler]));
        };
        $first      = $make('primary', [$response, $success, $success]);
        $second     = $make('secondary', [$success, $success]);
        $classifier = $this->classifier($counts, true);
        $transport  = match ($wrapper) {
            'failover', 'roundrobin' => $this->pool($wrapper, [$first, $second]),
            'retry'                  => new Swift_Transport_RetryTransport($first, 2, 0),
            'retry custom'           => new Swift_Transport_RetryTransport($first, 2, 0, $classifier),
            'retry failover'         => new Swift_Transport_RetryTransport($this->pool('failover', [$first, $second]), 2, 0, $classifier),
            'retry roundrobin'       => new Swift_Transport_RetryTransport($this->pool('roundrobin', [$first, $second]), 2, 0, $classifier),
            'failover retry'         => $this->pool('failover', [new Swift_Transport_RetryTransport($first, 2, 0, $classifier), $second]),
            'roundrobin retry'       => $this->pool('roundrobin', [new Swift_Transport_RetryTransport($first, 2, 0, $classifier), $second]),
        };
        $observer = new class implements Swift_Events_SendListener, Swift_Events_SentMessageListener, Swift_Events_FailedMessageListener {
            public array $events = [];

            public ?Swift_TransportException $error = null;

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                $this->events[] = 'before';
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                $this->events[] = $event->getResult();
            }

            public function sentMessage(Swift_Events_SentMessageEvent $event): void
            {
                $this->events[] = 'sent';
            }

            public function failedMessage(Swift_Events_FailedMessageEvent $event): void
            {
                $this->events[] = 'failed';
                $this->error    = $event->getException();
            }
        };
        $transport->registerPlugin($observer);
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        try {
            $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('A terminal result must not be replayed on any sender.');
        } catch (Swift_TransportException $error) {
            $this->assertSame($observer->error, $error);
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertSame(1, $counts->primary);
        $this->assertSame(0, $counts->secondary);
        $this->assertSame(0, $counts->classifications);
        $this->assertSame(['before', 'failed', Swift_Events_SendEvent::RESULT_FAILED], $observer->events);
        $this->assertSame(['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
        $this->assertSame($original, $message->toString());
        $this->assertNull($first->activeEnvelope);
        $this->assertNull($second->activeEnvelope);
    }

    public static function httpFailures(): iterable
    {
        foreach (['failover', 'roundrobin', 'retry', 'retry custom', 'retry failover', 'retry roundrobin', 'failover retry', 'roundrobin retry'] as $wrapper) {
            foreach (['ambiguous', 'redirect', 'error'] as $failure) {
                foreach ([false, true] as $explicit) {
                    yield "$wrapper / $failure / envelope ".(int) $explicit => [$wrapper, $failure, $explicit];
                }
            }
        }
    }

    /** @dataProvider lifecycleFailures */
    public function testTerminalLifecycleFailureKeepsIdentityAndStopsOtherBackends(string $wrapper, string $kind, string $phase): void
    {
        $failure        = $this->failure($kind);
        $first          = new Swift_TerminalFailureFixture();
        $second         = new Swift_TerminalFailureFixture();
        $first->started = 'start' !== $phase;
        if ('stop' === $phase) {
            $first->onSend = static function (): never {
                throw new Swift_TransportException('fixture temporary failure', 503);
            };
        }
        $first->{'on'.\ucfirst($phase)} = static function () use ($failure): never {
            throw $failure;
        };
        $counts    = (object) ['classifications' => 0];
        $transport = \str_starts_with($wrapper, 'retry') ? new Swift_Transport_RetryTransport($first, 2, 0, 'retry custom' === $wrapper ? $this->classifier($counts, true) : null) : $this->pool($wrapper, [$first, $second]);
        try {
            $transport->send($this->message());
            $this->fail('The terminal lifecycle failure must propagate.');
        } catch (Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(0, $second->sends);
        $this->assertSame(0, $second->starts);
        $this->assertSame(0, $counts->classifications);
        $this->assertSame('stop' === $phase ? 1 : 0, $first->stops);
        if ($transport instanceof Swift_Transport_LoadBalancedTransport) {
            $this->assertSame([$second, $first], $transport->getTransports());
            $this->assertNull($transport->getLastUsedTransport());
        }
    }

    public static function lifecycleFailures(): iterable
    {
        foreach (['failover', 'roundrobin', 'retry', 'retry custom'] as $wrapper) {
            foreach (['marker', 'deep marker', 'wrapped error', 'raw error'] as $kind) {
                foreach (['start', 'send', 'stop'] as $phase) {
                    if ('stop' !== $phase || !\str_starts_with($wrapper, 'retry')) {
                        yield "$wrapper / $kind / $phase" => [$wrapper, $kind, $phase];
                    }
                }
            }
        }
    }

    /** @dataProvider restartFailures */
    public function testTerminalRetryRestartCannotBeSwallowed(string $kind, bool $custom): void
    {
        $failure       = $this->failure($kind);
        $first         = new Swift_TerminalFailureFixture();
        $first->onSend = static function () use ($first): never {
            $first->started = false;
            throw new Swift_TransportException('fixture temporary failure', 503);
        };
        $first->onStart = static function () use ($failure): never {
            throw $failure;
        };
        $counts    = (object) ['classifications' => 0];
        $transport = new class($first, 2, 0, $custom ? $this->classifier($counts, true) : null) extends Swift_Transport_RetryTransport {
            public int $backoffs = 0;

            protected function backoff(int $attempt): void
            {
                ++$this->backoffs;
            }
        };
        try {
            $transport->send($this->message());
            $this->fail('A terminal restart failure must end this delivery.');
        } catch (Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(1, $first->sends);
        $this->assertSame(1, $first->starts);
        $this->assertSame(1, $transport->backoffs);
        $this->assertSame($custom ? 1 : 0, $counts->classifications);
    }

    public static function restartFailures(): iterable
    {
        foreach (['marker', 'deep marker', 'wrapped error', 'raw error'] as $kind) {
            foreach ([false, true] as $custom) {
                yield "$kind / custom ".(int) $custom => [$kind, $custom];
            }
        }
    }

    /** @dataProvider ordinaryFailures */
    public function testOrdinaryBackendFailuresStillAllowFallback(string $wrapper, int $code): void
    {
        $first         = new Swift_TerminalFailureFixture();
        $first->onSend = static function () use ($code): never {
            throw new Swift_TransportException('fixture backend failure', $code);
        };
        $second    = new Swift_TerminalFailureFixture();
        $transport = $this->pool($wrapper, [$first, $second]);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $first->sends);
        $this->assertSame(1, $first->stops);
        $this->assertSame(1, $second->sends);
        $this->assertSame($second, $transport->getLastUsedTransport());
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $first->sends);
    }

    public static function ordinaryFailures(): iterable
    {
        foreach (['failover', 'roundrobin'] as $wrapper) {
            foreach ([0, 503, 535, 401] as $code) {
                yield "$wrapper / $code" => [$wrapper, $code];
            }
        }
    }

    /** @dataProvider classifierDecisions */
    public function testCustomClassifierStillDecidesOrdinaryFailures(bool $retry, int $code): void
    {
        $first         = new Swift_TerminalFailureFixture();
        $failure       = new Swift_TransportException('fixture ordinary failure', $code);
        $first->onSend = static function () use ($first, $failure): void {
            $first->onSend = null;
            throw $failure;
        };
        $counts    = (object) ['classifications' => 0];
        $transport = new Swift_Transport_RetryTransport($first, 2, 0, $this->classifier($counts, $retry));
        try {
            $this->assertSame(3, $transport->send($this->message()));
            $this->assertTrue($retry);
        } catch (Swift_TransportException $error) {
            $this->assertFalse($retry);
            $this->assertSame($failure, $error);
        }
        $this->assertSame($retry ? 2 : 1, $first->sends);
        $this->assertSame(1, $counts->classifications);
    }

    public static function classifierDecisions(): iterable
    {
        foreach ([false, true] as $retry) {
            foreach ([503, 535] as $code) {
                yield [$retry, $code];
            }
        }
    }

    /** @dataProvider reentrantPools */
    public function testCleanupCannotOverwriteReentrantPoolReplacement(string $wrapper, bool $sameRemaining): void
    {
        $first         = new Swift_TerminalFailureFixture();
        $second        = new Swift_TerminalFailureFixture();
        $replacement   = $sameRemaining ? $second : new Swift_TerminalFailureFixture();
        $transport     = $this->pool($wrapper, [$first, $second]);
        $failure       = new Swift_Transport_NonRetryableException('fixture terminal cleanup');
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture temporary failure', 503);
        };
        $first->onStop = static function () use ($transport, $replacement, $failure): never {
            $transport->setTransports([$replacement]);
            throw $failure;
        };
        try {
            $transport->send($this->message());
            $this->fail('Terminal cleanup must stop before another backend.');
        } catch (Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame([$replacement], $transport->getTransports());
        $this->assertSame(0, $second->sends);
        $this->assertSame(0, $replacement->sends);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $replacement->sends);
    }

    /** @dataProvider pools */
    public function testMessageSpecificTerminalFailureDoesNotKillBackendOrRetainLastSuccess(string $wrapper): void
    {
        $first     = new Swift_TerminalFailureFixture();
        $transport = $this->pool($wrapper, [$first]);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame($first, $transport->getLastUsedTransport());
        $failure       = new Swift_Transport_NonRetryableException('fixture message failure');
        $first->onSend = static function () use ($failure): never {
            throw $failure;
        };
        try {
            $transport->send($this->message());
            $this->fail('The message-specific failure must propagate.');
        } catch (Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertNull($transport->getLastUsedTransport());
        $this->assertSame([$first], $transport->getTransports());
        $this->assertSame(0, $first->stops);
        $first->onSend = null;
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame($first, $transport->getLastUsedTransport());
    }

    public static function pools(): iterable
    {
        yield ['failover'];
        yield ['roundrobin'];
    }

    /** @dataProvider pools */
    public function testReplacingPoolUsesOnlyNewBackends(string $wrapper): void
    {
        $first       = new Swift_TerminalFailureFixture();
        $replacement = new Swift_TerminalFailureFixture();
        $transport   = $this->pool($wrapper, [$first]);
        $this->assertSame(3, $transport->send($this->message()));
        $transport->setTransports([$replacement]);
        $this->assertSame($first, $transport->getLastUsedTransport());
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $first->sends);
        $this->assertSame(2, $replacement->sends);
        $this->assertSame($replacement, $transport->getLastUsedTransport());
    }

    /** @dataProvider pools */
    public function testReplacingPoolBeforePingChecksOnlyNewBackend(string $wrapper): void
    {
        $first       = new Swift_TerminalFailureFixture();
        $replacement = new Swift_TerminalFailureFixture();
        $transport   = $this->pool($wrapper, [$first]);
        $transport->send($this->message());
        $transport->setTransports([$replacement]);
        $this->assertTrue($transport->ping());
        $this->assertSame(0, $first->pings);
        $this->assertSame(1, $replacement->pings);
    }

    /** @dataProvider pools */
    public function testEmptyPoolCannotReuseCachedBackendAndCanBeReconfigured(string $wrapper): void
    {
        $first       = new Swift_TerminalFailureFixture();
        $replacement = new Swift_TerminalFailureFixture();
        $transport   = $this->pool($wrapper, [$first]);
        $transport->send($this->message());
        $transport->setTransports([]);
        $this->assertFalse($transport->ping());
        try {
            $transport->send($this->message());
            $this->fail('An empty pool must not use the old cached backend.');
        } catch (Swift_TransportException $error) {
            $this->assertNull($transport->getLastUsedTransport());
        }
        $transport->setTransports([$replacement]);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $first->sends);
        $this->assertSame(1, $replacement->sends);
    }

    /** @dataProvider pools */
    public function testReentrantPoolReplacementDuringTerminalSendTakesEffectOnNextSend(string $wrapper): void
    {
        $first         = new Swift_TerminalFailureFixture();
        $replacement   = new Swift_TerminalFailureFixture();
        $transport     = $this->pool($wrapper, [$first]);
        $failure       = new Swift_Transport_NonRetryableException('fixture terminal callback');
        $first->onSend = static function () use ($first, $transport, $replacement, $failure): never {
            $first->onSend = null;
            $transport->setTransports([$replacement]);
            throw $failure;
        };
        try {
            $transport->send($this->message());
            $this->fail('The terminal send must propagate.');
        } catch (Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame([$replacement], $transport->getTransports());
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $first->sends);
        $this->assertSame(1, $replacement->sends);
    }

    /** @dataProvider recipientPolicies */
    public function testTerminalFailuresRetainRecipientPoliciesAndRestoreMessage(string $wrapper, string $policy, bool $explicit): void
    {
        $requests = (object) ['primary' => [], 'secondary' => []];
        $make     = static function (string $name, Response $response) use ($requests): Swift_Transport_Api_ResendTransport {
            $handler = HandlerStack::create(static function ($request) use ($requests, $name, $response) {
                $requests->{$name}[] = \json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

                return GuzzleHttp\Promise\Create::promiseFor($response);
            });

            return new Swift_Transport_Api_ResendTransport('fixture-key', new Client(['handler' => $handler]));
        };
        $first     = $make('primary', new Response(202, ['Content-Type' => 'application/json'], '{'));
        $second    = $make('secondary', new Response(200, ['Content-Type' => 'application/json'], '{"id":"fixture-success"}'));
        $transport = 'retry custom' === $wrapper ? new Swift_Transport_RetryTransport($first, 2, 0, $this->classifier((object) ['classifications' => 0], true)) : $this->pool($wrapper, [$first, $second]);
        $transport->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['to@safe.test']),
            'catch-all' => new Swift_Plugins_AllowlistPlugin(['to@safe.test'], 'sink@safe.test'),
            'redirect'  => new Swift_Plugins_RedirectingPlugin('sink@safe.test'),
        });
        $message  = $this->message();
        $original = $message->toString();
        $failed   = [];
        try {
            $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('A recipient policy must not permit terminal replay.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $requests->primary);
        $this->assertSame([], $requests->secondary);
        $this->assertCount('catch-all' === $policy ? 2 : 1, $failed);
        $this->assertSame($original, $message->toString());
        $this->assertNull($first->activeEnvelope);
        $recipients = \array_merge($requests->primary[0]['to'] ?? [], $requests->primary[0]['cc'] ?? [], $requests->primary[0]['bcc'] ?? []);
        \sort($recipients);
        $this->assertSame(match ($policy) {
            'allowlist' => ['to@safe.test'], 'catch-all' => ['sink@safe.test <sink@safe.test>', 'to@safe.test'], 'redirect' => ['sink@safe.test'],
        }, $recipients);
    }

    public static function recipientPolicies(): iterable
    {
        foreach (['failover', 'roundrobin', 'retry custom'] as $wrapper) {
            foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    yield "$wrapper / $policy / envelope ".(int) $explicit => [$wrapper, $policy, $explicit];
                }
            }
        }
    }

    /** @dataProvider pools */
    public function testOrdinaryCleanupFailureStillAllowsFallback(string $wrapper): void
    {
        $first         = new Swift_TerminalFailureFixture();
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture temporary failure', 503);
        };
        $first->onStop = static function (): never {
            throw new RuntimeException('fixture cleanup failure');
        };
        $second    = new Swift_TerminalFailureFixture();
        $transport = $this->pool($wrapper, [$first, $second]);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame(1, $first->sends);
        $this->assertSame(1, $first->stops);
        $this->assertSame(1, $second->sends);
        $this->assertSame($second, $transport->getLastUsedTransport());
    }

    public static function reentrantPools(): iterable
    {
        foreach (['failover', 'roundrobin'] as $wrapper) {
            foreach ([false, true] as $sameRemaining) {
                yield "$wrapper / equal remaining ".(int) $sameRemaining => [$wrapper, $sameRemaining];
            }
        }
    }

    /** @dataProvider replacedAttempts */
    public function testOrdinaryFailureCannotRetireAReentrantlyConfiguredBackend(string $wrapper, string $phase): void
    {
        $first                          = new Swift_TerminalFailureFixture();
        $second                         = new Swift_TerminalFailureFixture();
        $replacement                    = new Swift_TerminalFailureFixture();
        $transport                      = $this->pool($wrapper, [$first, $second]);
        $first->started                 = 'start' !== $phase;
        $first->{'on'.\ucfirst($phase)} = static function () use ($transport, $replacement): never {
            $transport->setTransports([$replacement]);
            throw new Swift_TransportException('fixture old-backend failure', 503);
        };
        try {
            $sent = $transport->send($this->message());
        } catch (Swift_TransportException $error) {
            $this->fail('The old failure must not retire the configured replacement: '.$error->getMessage());
        }
        $this->assertSame(3, $sent);
        $this->assertSame(0, $replacement->stops);
        $this->assertSame(0, $first->stops);
        $this->assertSame(0, $second->sends);
        $this->assertSame(1, $replacement->sends);
        $this->assertSame([$replacement], $transport->getTransports());
        $this->assertSame($replacement, $transport->getLastUsedTransport());
    }

    public static function replacedAttempts(): iterable
    {
        foreach (['failover', 'roundrobin'] as $wrapper) {
            foreach (['start', 'send'] as $phase) {
                yield "$wrapper / $phase" => [$wrapper, $phase];
            }
        }
    }

    private function failure(string $kind): Throwable
    {
        return match ($kind) {
            'marker'        => new Swift_Transport_NonRetryableException('fixture terminal failure', 503),
            'deep marker'   => new Swift_TransportException('fixture outer failure', 0, new RuntimeException('fixture inner failure', 0, new Swift_Transport_NonRetryableException('fixture terminal cause', 503))),
            'wrapped error' => new Swift_TransportException('fixture outer failure', 503, new RuntimeException('fixture inner failure', 0, new TypeError('fixture programming error'))),
            'raw error'     => new TypeError('fixture programming error'),
        };
    }

    private function pool(string $kind, array $transports): Swift_Transport_LoadBalancedTransport
    {
        $transport = 'failover' === $kind ? new Swift_Transport_FailoverTransport() : new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports($transports);

        return $transport;
    }

    private function classifier(object $counts, bool $retry): Swift_Transport_RetryClassifier
    {
        return new class($counts, $retry) implements Swift_Transport_RetryClassifier {
            public function __construct(private object $counts, private bool $retry)
            {
            }

            public function isRetryable(Swift_TransportException $error): bool
            {
                ++$this->counts->classifications;

                return $this->retry;
            }
        };
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'fixture body'))->setFrom('author@safe.test')->setTo('to@safe.test')->setCc('copy@safe.test')->setBcc('blind@safe.test');
    }
}

/** An observable lifecycle boundary; successful sends preserve all three recipients. */
class Swift_TerminalFailureFixture implements Swift_Transport
{
    public bool $started = true;

    public int $starts = 0;

    public int $stops = 0;

    public int $sends = 0;

    public int $pings = 0;

    public ?Closure $onStart = null;

    public ?Closure $onStop = null;

    public ?Closure $onSend = null;

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function start(): void
    {
        ++$this->starts;
        if (null !== $this->onStart) {
            ($this->onStart)();
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

        return $this->started;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        ++$this->sends;
        if (!$this->started) {
            $this->start();
        }
        if (null !== $this->onSend) {
            ($this->onSend)();
        }

        return 3;
    }

    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
    }
}
