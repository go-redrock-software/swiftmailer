<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_PoolCompatibilityTest extends TestCase
{
    /** @dataProvider cloneOrders */
    public function testQuarantinedCloneRecoversIndependently(string $class, int $weight, bool $copyFirst): void
    {
        $backend = new Swift_CompatibilityBackend();
        $slots   = \array_fill(0, $weight, $backend);
        $pool    = new $class($slots, 10);
        $this->assertFalse($pool->ping());
        $copy             = clone $pool;
        $pool->now        = $copy->now = 10;
        $backend->healthy = true;
        [$first, $second] = $copyFirst ? [$copy, $pool] : [$pool, $copy];
        $first->start();
        $second->start();
        foreach ([$pool, $copy] as $candidate) {
            $this->assertSame($slots, $candidate->getTransports(), 'Cloning keeps caller-owned backends and weighted slots.');
            $this->assertTrue($candidate->ping());
            $envelope = new Swift_Envelope('sender@example.test', ['recipient@example.test']);
            $this->assertSame(1, $candidate->send(new Swift_Message('Clone recovery'), $failed, $envelope));
            $this->assertSame($envelope, $backend->lastEnvelope);
        }
    }

    public static function cloneOrders(): iterable
    {
        foreach (self::poolClasses() as $class) {
            foreach ([1, 2] as $weight) {
                foreach ([false, true] as $copyFirst) {
                    yield $class.' / weight '.$weight.' / copy first '.(int) $copyFirst => [$class, $weight, $copyFirst];
                }
            }
        }
    }

    /** @dataProvider cloneDeadlines */
    public function testHealthyClonesKeepTheirOwnFailureDeadline(string $class, int $weight): void
    {
        $backend = new Swift_CompatibilityBackend();
        $slots   = \array_fill(0, $weight, $backend);
        $pool    = new $class($slots, 10);
        $copy    = clone $pool;
        $this->assertFalse($pool->ping());
        $copy->now = 5;
        $this->assertFalse($copy->ping());
        $backend->healthy = true;
        $pool->now        = $copy->now = 10;
        $this->assertTrue($pool->ping(), 'A later failure in a clone must not extend the original cooldown.');
        $before = $backend->pings;
        $this->assertFalse($copy->ping(), 'The clone retains its own later deadline.');
        $this->assertSame($before, $backend->pings, 'A cooling backend must not receive a probe.');
        $copy->now = 15;
        $this->assertTrue($copy->ping());
        $this->assertSame($slots, $copy->getTransports());
    }

    public static function cloneDeadlines(): iterable
    {
        foreach (self::poolClasses() as $class) {
            foreach ([1, 2] as $weight) {
                yield $class.' / weight '.$weight => [$class, $weight];
            }
        }
    }

    /** @dataProvider legacyConstructors */
    public function testLegacySubclassDoesNotNeedTheFormerlyEmptyParentConstructor(string $class, int $weight): void
    {
        $backend = new Swift_CompatibilityBackend();
        $slots   = \array_fill(0, $weight, $backend);
        $pool    = new $class($slots);
        $this->assertFalse($pool->ping());
        $this->assertSame($slots, $pool->getTransports());
        $backend->healthy = true;
        $pool->setRetryPeriod(0);
        $this->assertTrue($pool->ping());
        $this->assertSame($slots, $pool->getTransports());
    }

    /** @dataProvider legacyConstructors */
    public function testLegacySubclassSetterRetainsImmediateManualRestart(string $class, int $weight): void
    {
        $backend = new Swift_CompatibilityBackend();
        $slots   = \array_fill(0, $weight, $backend);
        $pool    = new $class([]);
        $pool->setTransports($slots);
        $this->assertFalse($pool->ping());
        $backend->healthy = true;
        $pool->start();
        $this->assertTrue($pool->ping());
        $this->assertSame($slots, $pool->getTransports());
    }

    /** @dataProvider legacyConstructors */
    public function testLegacySubclassMayRetireThroughItsProtectedHookBeforeStartup(string $class, int $weight): void
    {
        $backend = new Swift_CompatibilityBackend();
        $slots   = \array_fill(0, $weight, $backend);
        $pool    = new $class($slots);
        $pool->retireCurrent();
        $backend->healthy = true;
        $pool->start();
        $this->assertTrue($pool->ping());
        $this->assertSame($slots, $pool->getTransports());
    }

    public static function legacyConstructors(): iterable
    {
        foreach ([Swift_CompatibilityLegacyLoadPool::class, Swift_CompatibilityLegacyFailPool::class] as $class) {
            foreach ([1, 2] as $weight) {
                yield $class.' / weight '.$weight => [$class, $weight];
            }
        }
    }

    /** @dataProvider healthyPools */
    public function testHealthyCloneRetainsBackendIdentity(string $class): void
    {
        $backend          = new Swift_CompatibilityBackend();
        $backend->healthy = true;
        $pool             = new $class([$backend]);
        $copy             = clone $pool;
        $this->assertTrue($pool->ping());
        $this->assertTrue($copy->ping());
        $this->assertSame([$backend], $pool->getTransports());
        $this->assertSame([$backend], $copy->getTransports());
    }

    /** @dataProvider healthyPools */
    public function testRecoveredBackendCanBeReleasedByALegacySubclass(string $class): void
    {
        $backend   = new Swift_CompatibilityBackend();
        $reference = WeakReference::create($backend);
        $pool      = new $class([$backend], 10);
        $this->assertFalse($pool->ping());
        $pool->now = 10;
        $pool->start();
        $pool->clearOwnedTransports();
        unset($backend);
        $this->assertNull($reference->get(), 'Recovered failure metadata must not retain a backend discarded by its owner.');
    }

    public static function healthyPools(): iterable
    {
        yield [Swift_CompatibilityLoadPool::class];
        yield [Swift_CompatibilityFailPool::class];
    }

    /** @dataProvider legacyDefaultCallers */
    public function testUnconfiguredPoolsKeepLegacyManualRecovery(string $class, bool $lowLevel, string $caller): void
    {
        $backend = new Swift_CompatibilityBackend();
        $pool    = $lowLevel ? new $class() : new $class([$backend]);
        if ($lowLevel) {
            $pool->setTransports([$backend]);
        }
        $this->assertFalse($pool->ping());
        $pool->now        = 1000;
        $backend->healthy = true;
        $this->assertFalse($pool->ping(), 'An omitted cooldown must not silently enable timed recovery.');
        $before = $backend->pings;
        if ('manual' === $caller) {
            $pool->start();
            $this->assertTrue($pool->ping(), 'Existing explicit start() must immediately restore failed backends.');
        } else {
            $sender = 'mailer' === $caller ? new Swift_Mailer($pool) : new Swift_Transport_RetryTransport($pool, 1, 0);
            $this->assertSame(1, $sender->send(new Swift_Message('Legacy startup')));
        }
        $this->assertGreaterThanOrEqual($before, $backend->pings);
        $pool->start();
        $pool->start();
        $this->assertSame([$backend], $pool->getTransports(), 'Repeated legacy starts must not duplicate restored slots.');
    }

    public static function legacyDefaultCallers(): iterable
    {
        foreach ([[Swift_CompatibilityLoadPool::class, false], [Swift_CompatibilityFailPool::class, false], [Swift_CompatibilityBareLoadPool::class, true], [Swift_CompatibilityBareFailPool::class, true]] as [$class, $lowLevel]) {
            foreach (['manual', 'mailer', 'retry'] as $caller) {
                yield $class.' / '.$caller => [$class, $lowLevel, $caller];
            }
        }
    }

    /** @dataProvider configuredDefaults */
    public function testExplicitSetterEnablesTheCooldownWithoutChangingItsDeadline(string $class, bool $lowLevel): void
    {
        $backend = new Swift_CompatibilityBackend();
        $pool    = $lowLevel ? new $class() : new $class([$backend]);
        if ($lowLevel) {
            $pool->setTransports([$backend]);
        }
        $this->assertSame(60, $pool->getRetryPeriod());
        $pool->setRetryPeriod(60);
        $this->assertFalse($pool->ping());
        $backend->healthy = true;
        $pool->now        = 59.999;
        $pool->start();
        $this->assertFalse($pool->ping());
        $pool->now = 60;
        $this->assertTrue($pool->ping());
    }

    public static function configuredDefaults(): iterable
    {
        yield [Swift_CompatibilityLoadPool::class, false];
        yield [Swift_CompatibilityFailPool::class, false];
        yield [Swift_CompatibilityBareLoadPool::class, true];
        yield [Swift_CompatibilityBareFailPool::class, true];
    }

    /** @dataProvider factoryModes */
    public function testFactoryOnlyEnablesCooldownForAnExplicitPoolOption(string $kind, ?int $period): void
    {
        $dsn     = $kind.'(null://default)'.(null === $period ? '' : '?retry_period='.$period);
        $pool    = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
        $backend = new Swift_CompatibilityBackend();
        $pool->setTransports([$backend]);
        $this->assertFalse($pool->ping());
        $backend->healthy = true;
        $pool->start();
        $this->assertSame(60 !== $period, $pool->ping());
    }

    public static function factoryModes(): iterable
    {
        foreach (['failover', 'roundrobin'] as $kind) {
            foreach ([null, 0, 60] as $period) {
                yield $kind.' / '.(null === $period ? 'legacy' : $period) => [$kind, $period];
            }
        }
    }

    /** @dataProvider serializedPools */
    public function testOlderSerializedPoolsRetainTheirRecoveryMode(string $source, string $encoded): void
    {
        $pool             = \unserialize(\base64_decode($encoded, true), ['allowed_classes' => [Swift_CompatibilityLoadPool::class, Swift_CompatibilityFailPool::class, Swift_CompatibilityBackend::class, SplObjectStorage::class]]);
        $backend          = $pool->getTransports()[0];
        $backend->healthy = true;
        if ('baseline' === $source) {
            $pool->now = 1000;
            $this->assertFalse($pool->ping());
            $pool->start();
            $this->assertTrue($pool->ping());
        } else {
            $pool->now = 9;
            $pool->start();
            $this->assertFalse($pool->ping());
            $copy      = clone $pool;
            $pool->now = $copy->now = 10;
            $pool->start();
            $copy->start();
            $this->assertTrue($pool->ping());
            $this->assertTrue($copy->ping());
        }
    }

    public static function serializedPools(): iterable
    {
        foreach (\json_decode(\file_get_contents(\dirname(__DIR__, 3).'/fixtures/pool-compatibility-serialized.json'), true, flags: \JSON_THROW_ON_ERROR) as $row) {
            yield $row['source'].' / '.$row['class'] => [$row['source'], $row['encoded']];
        }
    }

    /** @dataProvider oldManualPools */
    public function testLegacySerializedPoolCanOptIntoANewCooldown(string $encoded): void
    {
        $pool      = \unserialize(\base64_decode($encoded, true), ['allowed_classes' => [Swift_CompatibilityLoadPool::class, Swift_CompatibilityFailPool::class, Swift_CompatibilityBackend::class, SplObjectStorage::class]]);
        $pool->now = 1000;
        $pool->setRetryPeriod(10);
        $pool->getTransports()[0]->healthy = true;
        $pool->now                         = 1009;
        $pool->start();
        $this->assertFalse($pool->ping());
        $pool->now = 1010;
        $this->assertTrue($pool->ping());
    }

    public static function oldManualPools(): iterable
    {
        foreach (\json_decode(\file_get_contents(\dirname(__DIR__, 3).'/fixtures/pool-compatibility-serialized.json'), true, flags: \JSON_THROW_ON_ERROR) as $row) {
            if ('baseline' === $row['source']) {
                yield $row['class'] => [$row['encoded']];
            }
        }
    }

    private static function poolClasses(): array
    {
        return [Swift_CompatibilityLoadPool::class, Swift_CompatibilityFailPool::class, Swift_CompatibilityOwnCloneLoadPool::class, Swift_CompatibilityOwnCloneFailPool::class];
    }
}

class Swift_CompatibilityBackend implements Swift_Transport
{
    public bool $healthy = false;

    public int $pings = 0;

    public ?Swift_Envelope $lastEnvelope = null;

    public function isStarted()
    {
        return true;
    }

    public function start()
    {
    }

    public function stop()
    {
    }

    public function ping()
    {
        ++$this->pings;

        return $this->healthy;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null)
    {
        $this->lastEnvelope = $envelope;

        return $this->healthy ? 1 : 0;
    }

    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
    }
}

class Swift_CompatibilityLoadPool extends Swift_LoadBalancedTransport
{
    public float $now = 0;

    protected function getCurrentTime(): float
    {
        return $this->now;
    }

    public function clearOwnedTransports(): void
    {
        $this->transports = [];
    }
}
class Swift_CompatibilityFailPool extends Swift_FailoverTransport
{
    public float $now = 0;

    protected function getCurrentTime(): float
    {
        return $this->now;
    }

    public function clearOwnedTransports(): void
    {
        $this->transports = [];
    }
}
class Swift_CompatibilityOwnCloneLoadPool extends Swift_CompatibilityLoadPool
{
    public int $clones = 0;

    public function __clone()
    {
        ++$this->clones;
    }
}
class Swift_CompatibilityOwnCloneFailPool extends Swift_CompatibilityFailPool
{
    public int $clones = 0;

    public function __clone()
    {
        ++$this->clones;
    }
}
class Swift_CompatibilityLegacyLoadPool extends Swift_Transport_LoadBalancedTransport
{
    public function __construct(array $transports)
    {
        $this->transports = $transports;
    }

    public function retireCurrent(): void
    {
        $this->killCurrentTransport();
    }
}
class Swift_CompatibilityLegacyFailPool extends Swift_Transport_FailoverTransport
{
    public function __construct(array $transports)
    {
        $this->transports = $transports;
    }

    public function retireCurrent(): void
    {
        $this->killCurrentTransport();
    }
}

class Swift_CompatibilityBareLoadPool extends Swift_Transport_LoadBalancedTransport
{
    public float $now = 0;

    protected function getCurrentTime(): float
    {
        return $this->now;
    }
}
class Swift_CompatibilityBareFailPool extends Swift_Transport_FailoverTransport
{
    public float $now = 0;

    protected function getCurrentTime(): float
    {
        return $this->now;
    }
}
