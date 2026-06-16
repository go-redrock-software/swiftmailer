<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use Nyholm\Dsn\Configuration\Dsn;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests guarding the Swift_Dsn scheme -> transport class map.
 *
 * These catch "map drift": a provider transport class is added under
 * lib/classes/Swift/Transport/Api/ but never wired into a DSN scheme, or the
 * reverse -- a map entry pointing at a class that no longer exists or is not a
 * transport. The per-provider unit tests cannot catch this because they
 * instantiate each transport directly and never go through the DSN map.
 */
class Swift_Transport_DsnTransportMapContractTest extends TestCase
{
    /**
     * Every concrete API transport class on disk must be reachable via at least
     * one DSN scheme. Forgetting to wire a new provider is the most likely
     * regression, so this fails by naming the unwired class.
     *
     * @dataProvider apiTransportClassProvider
     */
    public function testEveryApiTransportIsReachableViaDsn(string $class): void
    {
        $this->assertContains(
            $class,
            \array_values(self::transportClassMap()),
            \sprintf('Transport class %s is not wired into any Swift_Dsn scheme. Add it to Swift_Dsn::TRANSPORT_CLASS_MAP.', $class),
        );
    }

    /**
     * Every class referenced by the map must exist and actually be a transport.
     *
     * @dataProvider schemeProvider
     */
    public function testEveryMappedSchemeResolvesToATransport(string $scheme, string $class): void
    {
        $this->assertTrue(
            \class_exists($class),
            \sprintf('Scheme "%s" maps to non-existent class %s.', $scheme, $class),
        );
        $this->assertTrue(
            \is_a($class, Swift_Transport::class, true),
            \sprintf('Scheme "%s" maps to %s, which does not implement Swift_Transport.', $scheme, $class),
        );
    }

    /**
     * The public accessor must resolve every mapped scheme back to its class.
     *
     * @dataProvider schemeProvider
     */
    public function testGetTransportClassResolvesEveryScheme(string $scheme, string $class): void
    {
        $this->assertSame($class, $this->makeDsn($scheme)->getTransportClass());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function apiTransportClassProvider(): array
    {
        $dir   = \dirname(__DIR__, 4).'/lib/classes/Swift/Transport/Api';
        $cases = [];

        foreach (\glob($dir.'/*.php') as $file) {
            $class = 'Swift_Transport_Api_'.\basename($file, '.php');

            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(Swift_Transport::class)) {
                continue;
            }

            $cases[$class] = [$class];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function schemeProvider(): array
    {
        $cases = [];
        foreach (self::transportClassMap() as $scheme => $class) {
            $cases[$scheme] = [$scheme, $class];
        }

        return $cases;
    }

    /**
     * @return array<string, class-string>
     */
    private static function transportClassMap(): array
    {
        return (new ReflectionClass(Swift_Dsn::class))->getConstant('TRANSPORT_CLASS_MAP');
    }

    private function makeDsn(string $scheme): Swift_Dsn
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn($scheme);
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn('default');
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        return new Swift_Dsn($dsn);
    }
}
