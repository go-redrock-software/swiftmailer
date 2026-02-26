<?php

class Swift_Transport_DsnTransportFactoryTest extends PHPUnit\Framework\TestCase
{
    private Swift_Transport_DsnTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new Swift_Transport_DsnTransportFactory();
    }

    public function testCreateNullTransport(): void
    {
        $transport = $this->factory->fromDsnString('null://default');
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);
    }

    public function testCreateFailoverTransport(): void
    {
        $transport = $this->factory->fromDsnString('failover(null://default null://default)');
        $this->assertInstanceOf(Swift_Transport_FailoverTransport::class, $transport);
    }

    public function testCreateRoundRobinTransport(): void
    {
        $transport = $this->factory->fromDsnString('roundrobin(null://default null://default)');
        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
    }

    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported DSN scheme');
        $this->factory->fromDsnString('unknown://default');
    }
}
