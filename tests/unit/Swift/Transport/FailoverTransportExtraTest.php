<?php

class Swift_Transport_FailoverTransportExtraTest extends PHPUnit\Framework\TestCase
{
    public function testImplementsSwiftTransport(): void
    {
        $transport = new Swift_Transport_FailoverTransport();
        $this->assertInstanceOf(Swift_Transport::class, $transport);
    }

    public function testExtendsLoadBalancedTransport(): void
    {
        $transport = new Swift_Transport_FailoverTransport();
        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
    }

    public function testSetAndGetTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $this->assertCount(2, $transport->getTransports());
    }

    public function testIsStartedWhenTransportsExist(): void
    {
        $t1        = $this->createMock(Swift_Transport::class);
        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1]);
        $this->assertTrue($transport->isStarted());
    }

    public function testIsNotStartedWhenNoTransports(): void
    {
        $transport = new Swift_Transport_FailoverTransport();
        $this->assertFalse($transport->isStarted());
    }

    public function testSendUsesFirstTransport(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(1);
        $t2->expects($this->never())->method('send');

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $this->assertSame(1, $transport->send($message));
    }

    public function testSendFailsOverToSecondTransport(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willThrowException(new Swift_TransportException('t1 down'));
        $t2->method('isStarted')->willReturn(true);
        $t2->method('send')->willReturn(1);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $this->assertSame(1, $transport->send($message));
    }

    public function testSendKeepsUsingFirstTransportAfterSuccess(): void
    {
        $msg1 = new Swift_Message();
        $msg1->setTo(['to@example.com' => 'To']);
        $msg2 = new Swift_Message();
        $msg2->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(1);
        $t2->expects($this->never())->method('send');

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $transport->send($msg1);
        $transport->send($msg2);
    }

    public function testSendThrowsWhenAllTransportsFail(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willThrowException(new Swift_TransportException('t1 down'));
        $t2->method('isStarted')->willReturn(true);
        $t2->method('send')->willThrowException(new Swift_TransportException('t2 down'));

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $this->expectException(Swift_TransportException::class);
        $transport->send($message);
    }

    public function testAfterFailoverDoesNotRetryFailedTransport(): void
    {
        $msg1 = new Swift_Message();
        $msg1->setTo(['to@example.com' => 'To']);
        $msg2 = new Swift_Message();
        $msg2->setTo(['to@example.com' => 'To']);

        $t1SendCount = 0;
        $t1          = $this->createMock(Swift_Transport::class);
        $t2          = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturnCallback(function () use (&$t1SendCount) {
            ++$t1SendCount;
            throw new Swift_TransportException('t1 down');
        });

        $t2->method('isStarted')->willReturn(true);
        $t2->method('send')->willReturn(1);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $transport->send($msg1); // t1 fails -> t2 succeeds
        $transport->send($msg2); // Should use t2 directly

        // t1 should only be tried once
        $this->assertSame(1, $t1SendCount);
    }

    public function testStopStopsAllTransports(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->expects($this->once())->method('stop');
        $t2->expects($this->once())->method('stop');

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);
        $transport->stop();
    }

    public function testRegisterPluginOnAllTransports(): void
    {
        $t1     = $this->createMock(Swift_Transport::class);
        $t2     = $this->createMock(Swift_Transport::class);
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $t1->expects($this->once())->method('registerPlugin')->with($plugin);
        $t2->expects($this->once())->method('registerPlugin')->with($plugin);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);
        $transport->registerPlugin($plugin);
    }

    public function testPingReturnsTrueWhenFirstPingSucceeds(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('ping')->willReturn(true);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $this->assertTrue($transport->ping());
    }

    public function testPingFailsOverToSecond(): void
    {
        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('ping')->willReturn(false);
        $t2->method('ping')->willReturn(true);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        $this->assertTrue($transport->ping());
    }

    public function testSendStartsTransportIfNotStarted(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(false);
        $t1->expects($this->once())->method('start');
        $t1->method('send')->willReturn(1);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1]);
        $transport->send($message);
    }

    public function testSendWithThreeTransportsFailover(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);
        $t3 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willThrowException(new Swift_TransportException('t1'));
        $t2->method('isStarted')->willReturn(true);
        $t2->method('send')->willThrowException(new Swift_TransportException('t2'));
        $t3->method('isStarted')->willReturn(true);
        $t3->method('send')->willReturn(1);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2, $t3]);

        $this->assertSame(1, $transport->send($message));
    }

    public function testIsNotStartedWhenAllTransportsDead(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t2 = $this->createMock(Swift_Transport::class);

        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willThrowException(new Swift_TransportException('t1'));
        $t2->method('isStarted')->willReturn(true);
        $t2->method('send')->willThrowException(new Swift_TransportException('t2'));

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        try {
            $transport->send($message);
        } catch (Swift_TransportException) {
        }

        $this->assertFalse($transport->isStarted());
    }

    public function testRestartAfterAllDead(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $sendCount = 0;
        $t1        = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturnCallback(function () use (&$sendCount) {
            ++$sendCount;
            if (1 === $sendCount) {
                throw new Swift_TransportException('first fail');
            }

            return 1;
        });

        $t2 = $this->createMock(Swift_Transport::class);
        $t2->method('isStarted')->willReturn(true);
        $t2->method('send')->willThrowException(new Swift_TransportException('always fail'));

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1, $t2]);

        try {
            $transport->send($message);
        } catch (Swift_TransportException) {
        }

        $this->assertFalse($transport->isStarted());

        // Restart
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testSendReturnValueFromSuccessfulTransport(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(42);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1]);

        $this->assertSame(42, $transport->send($message));
    }

    public function testGetLastUsedTransportReturnsNull(): void
    {
        $transport = new Swift_Transport_FailoverTransport();
        $this->assertNull($transport->getLastUsedTransport());
    }

    public function testGetLastUsedTransportAfterSend(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willReturn(1);

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1]);
        $transport->send($message);

        $this->assertSame($t1, $transport->getLastUsedTransport());
    }

    public function testSendWithSingleFailingTransportThrows(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $t1 = $this->createMock(Swift_Transport::class);
        $t1->method('isStarted')->willReturn(true);
        $t1->method('send')->willThrowException(new Swift_TransportException('down'));

        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports([$t1]);

        $this->expectException(Swift_TransportException::class);
        $transport->send($message);
    }
}
