<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_PoolLifecyclePolicyTest extends TestCase
{
    /** @dataProvider restoredPolicies */
    public function testRestoredBackendRetainsPoliciesEventsAndDispatcher(string $kind, string $policy, bool $explicit, bool $injected, string $result): void
    {
        $success = new Response(200, ['Content-Type' => 'application/json'], '{"id":"fixture-success"}');
        $denied  = new Response(503, ['Content-Type' => 'application/json'], '{"message":"service unavailable"}');
        $target  = 'terminal' === $result ? new Response(202, ['Content-Type' => 'application/json'], '{') : $success;
        $first   = $this->http([$denied, $target], $injected);
        $backup  = $this->http([$success, 'failover' === $kind ? $denied : $success], $injected);
        $pool    = $this->pool($kind, [$first->transport, $backup->transport]);
        $message = $this->message();
        $this->assertSame(4, $pool->send($message));
        $original = $message->toString();
        $pool->registerPlugin(match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin('cancel' === $result ? [] : ['allowed@safe.test']),
            'catch-all' => new Swift_Plugins_AllowlistPlugin(['allowed@safe.test'], 'sink@safe.test'),
            'redirect'  => new Swift_Plugins_RedirectingPlugin('sink@safe.test'),
        });
        $observer = new class implements Swift_Events_SendListener {
            public array $before = [];

            public array $after = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                $this->before[] = $event->getTransport();
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                $this->after[] = [$event->getTransport(), $event->getResult()];
            }
        };
        $pool->registerPlugin($observer);
        $pool->start();
        $pool->start();
        $envelope = $explicit ? Swift_Envelope::fromMessage($message) : null;
        $failed   = [];
        if ('roundrobin' === $kind && 'cancel' !== $result) {
            $this->assertSame('catch-all' === $policy ? 2 : 1, $pool->send($message, $failed, $envelope));
        }
        if ('cancel' === $result) {
            $this->assertSame(0, $pool->send($message, $failed, $envelope));
            $this->assertCount(1, $first->requests);
            $this->assertCount(1, $backup->requests);
        } elseif ('terminal' === $result) {
            try {
                (new Swift_Transport_RetryTransport($pool, 2, 0))->send($message, $failed, $envelope);
                $this->fail('The restored backend must retain terminal response handling.');
            } catch (Swift_TransportException $error) {
                $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            }
        } else {
            try {
                $sent = $pool->send($message, $failed, $envelope);
            } catch (Swift_TransportException $error) {
                $this->fail('The restored backend must accept the filtered message: '.$error->getMessage());
            }
            $this->assertSame('catch-all' === $policy ? 2 : 1, $sent);
        }
        if ('cancel' !== $result) {
            $this->assertCount(2, $first->requests);
            $recipients = \array_merge($first->requests[1]['to'] ?? [], $first->requests[1]['cc'] ?? [], $first->requests[1]['bcc'] ?? []);
            \sort($recipients);
            $this->assertSame(match ($policy) {
                'allowlist' => ['allowed@safe.test'], 'catch-all' => ['allowed@safe.test', 'sink@safe.test <sink@safe.test>'], 'redirect' => ['sink@safe.test'],
            }, $recipients);
            $this->assertCount(1, \array_filter($observer->before, static fn ($transport): bool => $transport === $first->transport));
            $primaryResults = \array_values(\array_filter($observer->after, static fn ($entry): bool => $entry[0] === $first->transport));
            $this->assertSame([[$first->transport, 'terminal' === $result ? Swift_Events_SendEvent::RESULT_FAILED : Swift_Events_SendEvent::RESULT_SUCCESS]], $primaryResults);
            foreach ($failed as $recipient) {
                $this->assertContains($recipient, 'catch-all' === $policy ? ['allowed@safe.test', 'sink@safe.test'] : ['redirect' === $policy ? 'sink@safe.test' : 'allowed@safe.test']);
            }
        }
        $this->assertSame($original, $message->toString());
        $this->assertNull($first->transport->activeEnvelope);
        $this->assertNull($backup->transport->activeEnvelope);
        if ($injected) {
            $this->assertSame($first->dispatcher, $first->transport->eventDispatcher);
            $this->assertSame($backup->dispatcher, $backup->transport->eventDispatcher);
        }
        $this->assertCount(2, $pool->getTransports());
    }

    public static function restoredPolicies(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    foreach ([false, true] as $injected) {
                        foreach (['success', 'terminal', 'cancel'] as $result) {
                            if ('cancel' === $result && 'allowlist' !== $policy) {
                                continue;
                            }
                            yield "$kind / $policy / envelope ".(int) $explicit.' / dispatcher '.(int) $injected." / $result" => [$kind, $policy, $explicit, $injected, $result];
                        }
                    }
                }
            }
        }
    }

    /** @dataProvider healthStates */
    public function testRoundRobinHealthChecksRetireOnlyFailedBackends(array $states): void
    {
        $backends = \array_map(static function (bool $health): Swift_PoolLifecycleFixture {
            $backend          = new Swift_PoolLifecycleFixture();
            $backend->healthy = $health;

            return $backend;
        }, $states);
        $pool = $this->pool('roundrobin', $backends);
        $this->assertSame(\in_array(true, $states, true), $pool->ping());
        foreach ($backends as $index => $backend) {
            $this->assertSame(1, $backend->pings);
            $this->assertSame($states[$index] ? 0 : 1, $backend->stops);
        }
        if (\in_array(true, $states, true)) {
            $healthy = \array_values(\array_filter($backends, static fn ($backend): bool => $backend->healthy));
            foreach ($healthy as $backend) {
                $this->assertSame(4, $pool->send($this->message()));
                $this->assertSame($backend, $pool->getLastUsedTransport());
            }
        }
        $this->assertCount(3, $pool->getTransports());
    }

    public static function healthStates(): iterable
    {
        for ($mask = 0; $mask < 8; ++$mask) {
            yield [[$mask & 1 ? true : false, $mask & 2 ? true : false, $mask & 4 ? true : false]];
        }
    }

    /** @dataProvider poolKinds */
    public function testRepeatedRestorationDoesNotGrowPool(string $kind, bool $weighted): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture temporary failure', 503);
        };
        $pool = $this->pool($kind, $weighted ? [$first, $backup, $backup] : [$first, $backup]);
        $this->assertSame(4, $pool->send($this->message()));
        $first->onSend = null;
        for ($i = 0; $i < 4; ++$i) {
            $pool->start();
            $this->assertCount($weighted ? 3 : 2, $pool->getTransports());
            $this->assertCount(1, \array_filter($pool->getTransports(), static fn ($backend): bool => $backend === $first));
            $this->assertSame($weighted ? 2 : 1, \count(\array_filter($pool->getTransports(), static fn ($backend): bool => $backend === $backup)));
        }
    }

    public static function poolKinds(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach ([false, true] as $weighted) {
                yield [$kind, $weighted];
            }
        }
    }

    public function testCachedFailoverBackendIsTheOneRetiredAfterRestoration(): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture primary failure', 503);
        };
        $pool = $this->pool('failover', [$first, $backup]);
        $this->assertSame(4, $pool->send($this->message()));
        $first->onSend = null;
        $pool->start();
        $backup->onSend = static function (): never {
            throw new Swift_TransportException('fixture backup failure', 503);
        };
        try {
            $sent = $pool->send($this->message());
        } catch (Swift_TransportException $error) {
            $this->fail('A failed cached backup must allow the restored primary: '.$error->getMessage());
        }
        $this->assertSame(4, $sent);
        $this->assertSame($first, $pool->getLastUsedTransport());
        $this->assertSame(1, $first->stops);
        $this->assertSame(1, $backup->stops);
        $this->assertSame(2, $first->sends);
        $this->assertSame(2, $backup->sends);
        $this->assertCount(2, $pool->getTransports());
    }

    /** @dataProvider wrappers */
    public function testPluginRegistrationIncludesDeadAndWeightedBackendsOnce(string $kind): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture primary failure', 503);
        };
        $pool = $this->pool($kind, [$first, $backup, $backup]);
        $pool->send($this->message());
        $plugin = new Swift_Plugins_AllowlistPlugin(['allowed@safe.test']);
        $pool->registerPlugin($plugin);
        $this->assertSame([$plugin], $first->plugins);
        $this->assertSame([$plugin], $backup->plugins);
        $pool->start();
        $pool->start();
        $other = new Swift_Plugins_RedirectingPlugin('sink@safe.test');
        $pool->registerPlugin($other);
        $this->assertSame([$plugin, $other], $first->plugins);
        $this->assertSame([$plugin, $other], $backup->plugins);
    }

    /** @dataProvider wrappers */
    public function testNestedRetryDecoratorForwardsPluginToInactiveBackend(string $kind): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture primary failure', 503);
        };
        $pool = $this->pool($kind, [$first, new Swift_Transport_RetryTransport($backup, 0, 0)]);
        $pool->send($this->message());
        $plugin = new Swift_Plugins_AllowlistPlugin(['allowed@safe.test']);
        (new Swift_Transport_RetryTransport($pool, 0, 0))->registerPlugin($plugin);
        $this->assertSame([$plugin], $first->plugins);
        $this->assertSame([$plugin], $backup->plugins);
    }

    /** @dataProvider wrappers */
    public function testReplacingPoolExcludesOldDeadBackendsFromRestorationAndRegistration(string $kind): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $replacement   = new Swift_PoolLifecycleFixture();
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture primary failure', 503);
        };
        $pool = $this->pool($kind, [$first, $backup]);
        $pool->send($this->message());
        $pool->setTransports([$replacement]);
        $plugin = new Swift_Plugins_AllowlistPlugin(['allowed@safe.test']);
        $pool->registerPlugin($plugin);
        $pool->start();
        $this->assertSame([], $first->plugins);
        $this->assertSame([], $backup->plugins);
        $this->assertSame([$plugin], $replacement->plugins);
        $this->assertSame([$replacement], $pool->getTransports());
        $this->assertSame(4, $pool->send($this->message()));
        $this->assertSame($replacement, $pool->getLastUsedTransport());
    }

    public function testPingCallbackCannotRetireItsReplacement(): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $second        = new Swift_PoolLifecycleFixture();
        $replacement   = new Swift_PoolLifecycleFixture();
        $pool          = $this->pool('roundrobin', [$first, $second]);
        $first->onPing = static function () use ($pool, $replacement): bool {
            $pool->setTransports([$replacement]);

            return false;
        };
        $this->assertTrue($pool->ping());
        $this->assertSame(0, $replacement->stops);
        $this->assertSame(1, $replacement->pings);
        $this->assertSame([$replacement], $pool->getTransports());
    }

    public static function wrappers(): iterable
    {
        yield ['roundrobin'];
        yield ['failover'];
    }

    /** @dataProvider terminalHealthErrors */
    public function testTerminalPingFailureDoesNotRetireAnyBackend(string $kind, bool $phpError): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $failure       = $phpError ? new TypeError('fixture ping error') : new Swift_Transport_NonRetryableException('fixture terminal ping');
        $first->onPing = static function () use ($failure): never {
            throw $failure;
        };
        $pool = $this->pool($kind, [$first, $backup]);
        try {
            $pool->ping();
            $this->fail('A terminal ping failure must propagate.');
        } catch (Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(0, $first->stops);
        $this->assertSame(0, $backup->stops);
        $this->assertSame(0, $backup->pings);
        $this->assertCount(2, $pool->getTransports());
    }

    public static function terminalHealthErrors(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach ([false, true] as $phpError) {
                yield [$kind, $phpError];
            }
        }
    }

    public function testFailoverSelectionUsesIdentityForEqualValuedBackends(): void
    {
        $first  = new Swift_PoolLifecycleFixture();
        $backup = new Swift_PoolLifecycleFixture();
        $third  = new Swift_PoolLifecycleFixture();
        $third->send($this->message());
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture primary failure', 503);
        };
        $pool = $this->pool('failover', [$first, $backup, $third]);
        $this->assertSame(4, $pool->send($this->message()));
        $this->assertEquals($backup, $third);
        $this->assertNotSame($backup, $third);
        $first->onSend = null;
        $pool->start();
        $this->assertSame(4, $pool->send($this->message()));
        $this->assertSame([$third, $first, $backup], $pool->getTransports());
        $backup->onSend = static function (): never {
            throw new Swift_TransportException('fixture backup failure', 503);
        };
        try {
            $sent = $pool->send($this->message());
        } catch (Swift_TransportException $error) {
            $this->fail('The next distinct healthy backend must remain configured: '.$error->getMessage());
        }
        $this->assertSame(4, $sent);
        $this->assertSame($third, $pool->getLastUsedTransport());
        $this->assertSame(2, $third->sends);
    }

    /** @dataProvider cleanupReplacements */
    public function testCompletedCleanupCannotPublishOldBackendIntoReplacementPool(string $kind, bool $sameRemaining, bool $throw): void
    {
        $first         = new Swift_PoolLifecycleFixture();
        $backup        = new Swift_PoolLifecycleFixture();
        $replacement   = $sameRemaining ? $backup : new Swift_PoolLifecycleFixture();
        $pool          = $this->pool($kind, [$first, $backup]);
        $first->onSend = static function (): never {
            throw new Swift_TransportException('fixture old backend failure', 503);
        };
        $first->onStop = static function () use ($pool, $replacement, $throw): void {
            $pool->setTransports([$replacement]);
            if ($throw) {
                throw new RuntimeException('fixture ordinary cleanup failure');
            }
        };
        $this->assertSame(4, $pool->send($this->message()));
        $this->assertSame([$replacement], $pool->getTransports());
        $pool->start();
        $this->assertSame([$replacement], $pool->getTransports());
        $plugin = new Swift_Plugins_AllowlistPlugin(['allowed@safe.test']);
        $pool->registerPlugin($plugin);
        $this->assertSame([], $first->plugins);
        $this->assertSame([$plugin], $replacement->plugins);
        $this->assertSame(4, $pool->send($this->message()));
        $this->assertSame($replacement, $pool->getLastUsedTransport());
        $this->assertSame(1, $first->sends);
    }

    public static function cleanupReplacements(): iterable
    {
        foreach (['roundrobin', 'failover'] as $kind) {
            foreach ([false, true] as $sameRemaining) {
                foreach ([false, true] as $throw) {
                    yield [$kind, $sameRemaining, $throw];
                }
            }
        }
    }

    private function pool(string $kind, array $transports): Swift_Transport_LoadBalancedTransport
    {
        // These scenarios explicitly test immediate manual restoration.
        $pool = 'failover' === $kind ? new Swift_Transport_FailoverTransport(0) : new Swift_Transport_LoadBalancedTransport(0);
        $pool->setTransports($transports);

        return $pool;
    }

    private function http(array $responses, bool $injected): object
    {
        $fixture = (object) ['requests' => [], 'dispatcher' => $injected ? new Swift_Events_SimpleEventDispatcher() : null];
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create(static function ($request, $options) use ($fixture, $mock) {
            $fixture->requests[] = \json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

            return $mock($request, $options);
        });
        $fixture->transport = new Swift_Transport_Api_ResendTransport('fixture-key', new Client(['handler' => $handler]), $fixture->dispatcher);

        return $fixture;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'fixture body'))->setFrom('author@safe.test')->setTo(['allowed@safe.test', 'blocked@outside.test'])->setCc('copy@outside.test')->setBcc('blind@outside.test');
    }
}

class Swift_PoolLifecycleFixture implements Swift_Transport
{
    public bool $started = true;

    public bool $healthy = true;

    public int $sends = 0;

    public int $stops = 0;

    public int $pings = 0;

    public array $plugins = [];

    public ?Closure $onSend = null;

    public ?Closure $onPing = null;

    public ?Closure $onStop = null;

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function stop(): void
    {
        ++$this->stops;
        if (null !== $this->onStop) {
            ($this->onStop)();
        }
        $this->started = false;
    }

    public function ping(): bool
    {
        ++$this->pings;

        return null === $this->onPing ? $this->healthy : ($this->onPing)();
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

        return 4;
    }

    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
        $this->plugins[] = $plugin;
    }
}
