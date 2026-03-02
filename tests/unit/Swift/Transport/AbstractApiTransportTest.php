<?php

class Swift_Transport_AbstractApiTransportTest extends \PHPUnit\Framework\TestCase
{
    private $eventDispatcherMock;

    protected function setUp(): void
    {
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);
    }

    private function createConcreteTransport(): Swift_Transport_AbstractApiTransport
    {
        $dispatcher = $this->eventDispatcherMock;

        return new class($dispatcher) extends Swift_Transport_AbstractApiTransport {
            public function __construct(Swift_Events_EventDispatcher $dispatcher)
            {
                $this->eventDispatcher = $dispatcher;
            }

            public function start(): void
            {
                $this->started = true;
            }

            public function ping(): bool
            {
                return true;
            }

            public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
            {
                return 1;
            }

            protected function getApiConnection(): mixed
            {
                return null;
            }
        };
    }

    public function testIsNotStartedByDefault(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertFalse($transport->isStarted());
    }

    public function testStartSetsStarted(): void
    {
        $transport = $this->createConcreteTransport();
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testRegisterPluginDelegatesToEventDispatcher(): void
    {
        $transport = $this->createConcreteTransport();
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $this->eventDispatcherMock->expects($this->once())
            ->method('bindEventListener')
            ->with($plugin);

        $transport->registerPlugin($plugin);
    }

    public function testStopSetsNotStarted(): void
    {
        $transport = $this->createConcreteTransport();
        $transport->start();
        $this->assertTrue($transport->isStarted());

        $evt = $this->createMock(Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport->stop();
        $this->assertFalse($transport->isStarted());
    }

    public function testStopDispatchesEvent(): void
    {
        $transport = $this->createConcreteTransport();
        $transport->start();

        $evt = $this->createMock(Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->expects($this->once())
            ->method('createTransportChangeEvent')
            ->willReturn($evt);
        $this->eventDispatcherMock->expects($this->once())
            ->method('dispatchEvent')
            ->with($evt, 'beforeTransportStopped');

        $transport->stop();
    }

    public function testStopCancelledByBubble(): void
    {
        $transport = $this->createConcreteTransport();
        $transport->start();

        $evt = $this->createMock(Swift_Events_TransportChangeEvent::class);
        $evt->method('bubbleCancelled')->willReturn(true);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport->stop();
        // stop() returns early before setting started=false when bubble is cancelled
        $this->assertTrue($transport->isStarted());
    }

    public function testStopWhenNotStartedDoesNothing(): void
    {
        $transport = $this->createConcreteTransport();
        // Not started, so stop should be no-op (no event dispatched because started is false)
        $this->eventDispatcherMock->expects($this->never())
            ->method('createTransportChangeEvent');

        $transport->stop();
        $this->assertFalse($transport->isStarted());
    }

    public function testSleepThrowsBadMethodCallException(): void
    {
        $transport = $this->createConcreteTransport();
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot serialize');
        $transport->__sleep();
    }

    public function testWakeupThrowsBadMethodCallException(): void
    {
        $transport = $this->createConcreteTransport();
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot unserialize');
        $transport->__wakeup();
    }

    public function testImplementsSwiftTransport(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertInstanceOf(Swift_Transport::class, $transport);
    }

    public function testPingReturnsTrue(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertTrue($transport->ping());
    }

    public function testSendReturnsOne(): void
    {
        $transport = $this->createConcreteTransport();
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);
        $this->assertSame(1, $transport->send($message));
    }

    public function testThrowExceptionDispatchesEvent(): void
    {
        $transport = $this->createConcreteTransport();
        $exception = new Swift_TransportException('Test error');

        $evt = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')
            ->willReturn($evt);
        $this->eventDispatcherMock->expects($this->once())
            ->method('dispatchEvent')
            ->with($evt, 'exceptionThrown');

        $reflection = new \ReflectionMethod($transport, 'throwException');
        $reflection->setAccessible(true);

        $this->expectException(Swift_TransportException::class);
        $reflection->invoke($transport, $exception);
    }

    public function testThrowExceptionCancelledByBubble(): void
    {
        $transport = $this->createConcreteTransport();
        $exception = new Swift_TransportException('Test error');

        $evt = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $evt->method('bubbleCancelled')->willReturn(true);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')
            ->willReturn($evt);

        $reflection = new \ReflectionMethod($transport, 'throwException');
        $reflection->setAccessible(true);

        // Should NOT throw when bubble is cancelled
        $reflection->invoke($transport, $exception);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testThrowExceptionWithNullEvent(): void
    {
        $transport = $this->createConcreteTransport();
        $exception = new Swift_TransportException('Test error');

        $this->eventDispatcherMock->method('createTransportExceptionEvent')
            ->willReturn(null);

        $reflection = new \ReflectionMethod($transport, 'throwException');
        $reflection->setAccessible(true);

        $this->expectException(Swift_TransportException::class);
        $reflection->invoke($transport, $exception);
    }

    public function testEventDispatcherPropertyIsAccessible(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame($this->eventDispatcherMock, $transport->eventDispatcher);
    }

    public function testStartedPropertyIsAccessible(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertFalse($transport->started);
        $transport->start();
        $this->assertTrue($transport->started);
    }
}
