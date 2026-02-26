<?php

namespace Swift\Integration;

use PHPUnit\Framework\TestCase;

class DsnTransportFactoryIntegrationTest extends TestCase
{
    public function testNullTransportFromDsn(): void
    {
        $factory   = new \Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString('null://default');

        $this->assertInstanceOf(\Swift_Transport_NullTransport::class, $transport);

        // Actually send through it
        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('DSN Test')
            ->setBody('Hello');

        $count = $transport->send($message);
        $this->assertEquals(1, $count);
    }

    public function testFailoverFromDsn(): void
    {
        $factory   = new \Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString('failover(null://default null://default)');

        $this->assertInstanceOf(\Swift_Transport_FailoverTransport::class, $transport);

        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('Failover Test')
            ->setBody('Hello');

        $count = $transport->send($message);
        $this->assertEquals(1, $count);
    }

    public function testRoundRobinFromDsn(): void
    {
        $factory   = new \Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString('roundrobin(null://default null://default)');

        $this->assertInstanceOf(\Swift_Transport_LoadBalancedTransport::class, $transport);

        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('RoundRobin Test')
            ->setBody('Hello');

        $count = $transport->send($message);
        $this->assertEquals(1, $count);
    }
}
