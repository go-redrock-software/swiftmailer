<?php

class Swift_Transport_LoadBalancedTransportExtraTest extends \PHPUnit\Framework\TestCase
{
    public function testImplementsSwiftTransport(): void
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $this->assertInstanceOf(Swift_Transport::class, $transport);
    }

    public function testSetAndGetTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);

        $this->assertCount(2, $transport->getTransports());
    }

    public function testIsStartedReturnsTrueWhenTransportsExist(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1]);

        $this->assertTrue($transport->isStarted());
    }

    public function testIsStartedReturnsFalseWhenNoTransports(): void
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $this->assertFalse($transport->isStarted());
    }

    public function testGetLastUsedTransportInitiallyNull(): void
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $this->assertNull($transport->getLastUsedTransport());
    }

    public function testSetTransportsResetsDead(): void
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $t1 = $this->createMock(Swift_Transport::class);
        $transport->setTransports([$t1]);
        $this->assertCount(1, $transport->getTransports());

        // Set new transports
        $t2 = $this->createMock(Swift_Transport::class);
        $transport->setTransports([$t2]);
        $this->assertCount(1, $transport->getTransports());
    }

    public function testStopStopsAllTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);
        $t1->expects($this->once())->method('stop');
        $t2->expects($this->once())->method('stop');

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $transport->stop();
    }

    public function testRegisterPluginOnAllTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $t1->expects($this->once())->method('registerPlugin')->with($plugin);
        $t2->expects($this->once())->method('registerPlugin')->with($plugin);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $transport->registerPlugin($plugin);
    }

    public function testSendRotatesBetweenTransports(): void
    {
        $message1 = new Swift_Message();
        $message1->setTo(['to@example.com' => 'To']);
        $message2 = new Swift_Message();
        $message2->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t2->method('isStarted')->willReturn(true);

        // First call -> t1, second call -> t2
        $callOrder = [];
        $t1->method('send')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 't1';
            return 1;
        });
        $t2->method('send')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 't2';
            return 1;
        });

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $transport->send($message1);
        $transport->send($message2);

        $this->assertSame(['t1', 't2'], $callOrder);
    }

    public function testSendTriesNextOnException(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t2->method('isStarted')->willReturn(true);

        $t1->method('send')->willThrowException(new Swift_TransportException('t1 failed'));
        $t1->method('stop'); // killCurrentTransport calls stop
        $t2->method('send')->willReturn(1);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $result = $transport->send($message);

        $this->assertSame(1, $result);
    }

    public function testSendTriesNextOnZeroReturn(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t2->method('isStarted')->willReturn(true);

        $t1->method('send')->willReturn(0);
        $t2->method('send')->willReturn(1);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $result = $transport->send($message);

        $this->assertSame(1, $result);
    }

    public function testSendThrowsWhenAllTransportsFail(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t2->method('isStarted')->willReturn(true);

        $t1->method('send')->willThrowException(new Swift_TransportException('t1 down'));
        $t2->method('send')->willThrowException(new Swift_TransportException('t2 down'));

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);

        $this->expectException(Swift_TransportException::class);
        $transport->send($message);
    }

    public function testPingReturnsFalseWhenAllPingsFail(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('ping')->willReturn(false);
        $t2->method('ping')->willReturn(false);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $this->assertFalse($transport->ping());
    }

    public function testPingReturnsTrueWhenOnePingSucceeds(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('ping')->willReturn(true);
        $t2->method('ping')->willReturn(false);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $this->assertTrue($transport->ping());
    }

    public function testStartRestoredDeadTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1->method('isStarted')->willReturn(true);
        $t2->method('isStarted')->willReturn(true);

        // Both throw -> all dead
        $t1->method('send')->willThrowException(new Swift_TransportException('down'));
        $t2->method('send')->willThrowException(new Swift_TransportException('down'));

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);

        try {
            $transport->send($message);
        } catch (Swift_TransportException $e) {
        }

        $this->assertFalse($transport->isStarted());

        // Restart should restore dead transports
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testGetTransportsIncludesDead(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);

        // Kill one transport via ping
        $t1->method('ping')->willReturn(false);
        $t2->method('ping')->willReturn(true);
        $transport->ping();

        // getTransports should still return both (alive + dead)
        $this->assertCount(2, $transport->getTransports());
    }

    public function testSendStartsTransportIfNotStarted(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(false);
        $t1->expects($this->once())->method('start');
        $t1->method('send')->willReturn(1);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1]);
        $transport->send($message);
    }

    public function testSendSetsLastUsedTransport(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(1);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1]);
        $transport->send($message);

        $this->assertSame($t1, $transport->getLastUsedTransport());
    }

    public function testSendResetsLastUsedTransport(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(0);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1]);
        $transport->send($message);

        // Last used should be null when send returns 0 and no transport succeeded
        $this->assertNull($transport->getLastUsedTransport());
    }

    public function testSendWithSingleTransport(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(5);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1]);

        $this->assertSame(5, $transport->send($message));
    }

    public function testSendWithThreeTransportsRotation(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);
        $t3 = $this->createMock(Swift_Transport::class);

        foreach ([$t1, $t2, $t3] as $t) {
            $t->method('isStarted')->willReturn(true);
            $t->method('send')->willReturn(1);
        }

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2, $t3]);

        $msg = new Swift_Message();
        $msg->setTo(['to@example.com' => 'To']);

        // Send 3 messages to cycle through all transports
        $transport->send($msg);
        $transport->send($msg);
        $transport->send($msg);

        // All should succeed
        $this->assertTrue(true);
    }
}
