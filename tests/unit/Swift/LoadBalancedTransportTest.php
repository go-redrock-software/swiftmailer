<?php

use PHPUnit\Framework\TestCase;

class Swift_LoadBalancedTransportTest extends TestCase
{
    public function testConstructorSetsTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $transport = new Swift_LoadBalancedTransport([$t1, $t2]);

        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
        $this->assertCount(2, $transport->getTransports());
    }

    public function testConstructorWithNoTransports(): void
    {
        $transport = new Swift_LoadBalancedTransport();

        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
        $this->assertCount(0, $transport->getTransports());
    }
}
