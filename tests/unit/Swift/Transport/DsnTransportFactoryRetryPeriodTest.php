<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_DsnTransportFactoryRetryPeriodTest extends TestCase
{
    /** @dataProvider periods */
    public function testWrapperPeriodIsAppliedWithoutChangingInnerDsns(string $kind, string $value, int $expected): void
    {
        $pool = (new Swift_Transport_DsnTransportFactory())->fromDsnString($kind.'(null://default?retries=1&retry_delay=0 null://default)?retry_period='.$value);
        $this->assertSame($expected, $pool->getRetryPeriod());
        $this->assertCount(2, $pool->getTransports());
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $pool->getTransports()[0]);
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $pool->getTransports()[0]->getInnerTransport());
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $pool->getTransports()[1]);
    }

    public static function periods(): iterable
    {
        foreach (['failover', 'roundrobin'] as $kind) {
            foreach (['0' => 0, '15' => 15, '00015' => 15, '%31%35' => 15, (string) PHP_INT_MAX => PHP_INT_MAX] as $value => $expected) {
                yield [$kind, (string) $value, $expected];
            }
        }
    }

    /** @dataProvider wrappers */
    public function testDefaultAndOuterRetryWrapper(string $kind): void
    {
        $factory = new Swift_Transport_DsnTransportFactory();
        $this->assertSame(60, $factory->fromDsnString($kind.'(null://default null://default)')->getRetryPeriod());
        $outer = $factory->fromDsnString('retry('.$kind.'(null://default null://default)?retry_period=7)');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $outer);
        $this->assertSame(7, $outer->getInnerTransport()->getRetryPeriod());
    }

    /** @dataProvider invalidQueries */
    public function testInvalidPoolOptionsAreRejectedBeforeInnerConstruction(string $query): void
    {
        $dsn = 'roundrobin(unknown://PRIVATE-DSN-KEY@default null://default)?'.$query;
        try {
            (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
            $this->fail('Invalid pool options must be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('retry_period', $error->getMessage());
            $this->assertStringNotContainsString('PRIVATE-DSN-KEY', $error->getMessage());
        }
    }

    public static function invalidQueries(): iterable
    {
        foreach (['retry_period=', 'retry_period=-1', 'retry_period=1.5', 'retry_period=1e3', 'retry_period=abc', 'retry_period=+1', 'retry_period=%201', 'retry_period=9999999999999999999999999999', 'retry_period[]=1', 'retry_period=1&retry_period=2', 'retry_period=1&retry%5fperiod=2', 'retry_period=15&', 'other=15', 'retry.period=15', 'retry_period=15junk', 'retry_period=15)', 'retry_period=15#fragment', 'retry_period=%00'] as $query) {
            yield [$query];
        }
    }

    /** @dataProvider wrappers */
    public function testMetadataKeepsFunctionQuerySeparateFromInnerQuery(string $kind): void
    {
        $configuration = Swift_Dsn_Configuration::parse($kind.'(null://first?retries=2 null://second)?retry_period=15');
        $this->assertSame(['retry_period' => '15'], $configuration->getParameters());
        $this->assertSame('15', $configuration->getParameter('retry_period'));
        $this->assertSame('fallback', $configuration->getParameter('missing', 'fallback'));
        $this->assertNull($configuration->getParameter('missing'));
        $this->assertSame('2', $configuration->getDsn(0)->getParameter('retries'));
        $this->assertNull($configuration->getDsn(0)->getParameter('retry_period'));
        $this->assertSame(2, $configuration->countDsn());
    }

    /** @dataProvider wrappers */
    public function testInvalidOptionTraceRedactsCredentialBearingDsn(string $kind): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new Swift_Transport_DsnTransportFactory())->fromDsnString($kind.'(null://PRIVATE-DSN-KEY@default)?retry_period=-1');
                $this->fail('The invalid option must throw.');
            } catch (InvalidArgumentException $error) {
                $trace = \json_encode($error->getTrace(), \JSON_PARTIAL_OUTPUT_ON_ERROR);
                $this->assertStringNotContainsString('PRIVATE-DSN-KEY', $trace);
                $this->assertStringNotContainsString('PRIVATE-DSN-KEY', $error->getMessage());
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /** @dataProvider conveniencePools */
    public function testInvalidConstructorPeriodRedactsBackendCredentials(string $class): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            $backend = (new Swift_Transport_DsnTransportFactory())->fromDsnString('resend://PRIVATE-CONSTRUCTOR-KEY@default');
            try {
                new $class([$backend], -1);
                $this->fail('The invalid period must throw.');
            } catch (InvalidArgumentException $error) {
                $trace = \json_encode($error->getTrace(), \JSON_PARTIAL_OUTPUT_ON_ERROR);
                $this->assertStringNotContainsString('PRIVATE-CONSTRUCTOR-KEY', $trace);
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function conveniencePools(): iterable
    {
        yield [Swift_LoadBalancedTransport::class];
        yield [Swift_FailoverTransport::class];
    }

    /** @dataProvider wrappers */
    public function testEncodedCredentialParenthesesArePreserved(string $kind): void
    {
        $pool = (new Swift_Transport_DsnTransportFactory())->fromDsnString($kind.'(resend://fixture%28key%29@default null://default)?retry_period=4');
        $this->assertSame('fixture(key)', $pool->getTransports()[0]->apiKey);
        $this->assertSame(4, $pool->getRetryPeriod());
    }

    public function testPlainMetadataDoesNotPromoteLeafQueryToWrapper(): void
    {
        $config = Swift_Dsn_Configuration::parse('smtp://fixture:secret@safe.test?retries=2');
        $this->assertSame([], $config->getParameters());
        $this->assertSame('2', $config->getFirst()->getParameter('retries'));
    }

    /** @dataProvider malformedWrappers */
    public function testMalformedWrapperTailDoesNotLeakCredentials(string $dsn): void
    {
        try {
            (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
            $this->fail('Trailing wrapper garbage must not be accepted.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringNotContainsString('PRIVATE-DSN-KEY', $error->getMessage());
        }
    }

    public static function malformedWrappers(): iterable
    {
        foreach (['roundrobin(null://PRIVATE-DSN-KEY@default))', 'roundrobin(null://PRIVATE-DSN-KEY@default)?retry_period=15 garbage', 'roundrobin(null://PRIVATE-DSN-KEY@default)?retry_period=15)garbage', 'retry(roundrobin(null://PRIVATE-DSN-KEY@default)?retry_period=15)?retry_period=3'] as $dsn) {
            yield [$dsn];
        }
    }

    public static function wrappers(): iterable
    {
        yield ['roundrobin'];
        yield ['failover'];
    }
}
