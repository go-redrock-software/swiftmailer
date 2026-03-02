<?php

class Swift_Transport_SpoolTransportTest extends \PHPUnit\Framework\TestCase
{
    private $eventDispatcherMock;

    private $spoolMock;

    private Swift_Transport_SpoolTransport $transport;

    protected function setUp(): void
    {
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);
        $this->spoolMock = $this->createMock(Swift_Spool::class);
        $this->transport = new Swift_Transport_SpoolTransport($this->eventDispatcherMock, $this->spoolMock);
    }

    public function testIsAlwaysStarted(): void
    {
        $this->assertTrue($this->transport->isStarted());
    }

    public function testStartDoesNothing(): void
    {
        $this->transport->start();
        $this->assertTrue($this->transport->isStarted());
    }

    public function testStopDoesNothing(): void
    {
        $this->transport->stop();
        $this->assertTrue($this->transport->isStarted());
    }

    public function testPingAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->transport->ping());
    }

    public function testImplementsSwiftTransport(): void
    {
        $this->assertInstanceOf(Swift_Transport::class, $this->transport);
    }

    public function testGetSpool(): void
    {
        $this->assertSame($this->spoolMock, $this->transport->getSpool());
    }

    public function testSetSpool(): void
    {
        $newSpool = $this->createMock(Swift_Spool::class);
        $result = $this->transport->setSpool($newSpool);

        $this->assertSame($newSpool, $this->transport->getSpool());
        $this->assertSame($this->transport, $result); // fluent interface
    }

    public function testSetSpoolReturnsSelf(): void
    {
        $newSpool = $this->createMock(Swift_Spool::class);
        $this->assertSame($this->transport, $this->transport->setSpool($newSpool));
    }

    public function testSendQueuesMessageToSpool(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $this->spoolMock->expects($this->once())
            ->method('queueMessage')
            ->with($message)
            ->willReturn(true);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $result = $this->transport->send($message);
        $this->assertSame(1, $result);
    }

    public function testSendDispatchesBeforeSendPerformedEvent(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->expects($this->once())
            ->method('createSendEvent')
            ->willReturn($evt);
        $this->eventDispatcherMock->expects($this->exactly(2))
            ->method('dispatchEvent');

        $this->spoolMock->method('queueMessage')->willReturn(true);

        $this->transport->send($message);
    }

    public function testSendReturnsZeroWhenBubbleCancelled(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(true);
        $evt->expects($this->once())->method('setResult')->with(Swift_Events_SendEvent::RESULT_FAILED);
        $evt->expects($this->once())->method('cancelBubble')->with(false);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(0, $result);
    }

    public function testSendSetsResultSpooledOnSuccess(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(false);
        $evt->expects($this->once())->method('setResult')->with(Swift_Events_SendEvent::RESULT_SPOOLED);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->spoolMock->method('queueMessage')->willReturn(true);

        $this->transport->send($message);
    }

    public function testSendSetsResultFailedWhenSpoolFails(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(false);
        $evt->expects($this->once())->method('setResult')->with(Swift_Events_SendEvent::RESULT_FAILED);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->spoolMock->method('queueMessage')->willReturn(false);

        $this->transport->send($message);
    }

    public function testSendWithNullEvent(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);
        $this->spoolMock->method('queueMessage')->willReturn(true);

        $result = $this->transport->send($message);
        $this->assertSame(1, $result);
    }

    public function testSendUsesEnvelopeRecipientCount(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);

        $envelope = new Swift_Envelope('sender@example.com', ['a@example.com', 'b@example.com', 'c@example.com']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);
        $this->spoolMock->method('queueMessage')->willReturn(true);

        $result = $this->transport->send($message, $failures, $envelope);
        $this->assertSame(3, $result);
    }

    public function testRegisterPluginDelegatesToEventDispatcher(): void
    {
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $this->eventDispatcherMock->expects($this->once())
            ->method('bindEventListener')
            ->with($plugin);

        $this->transport->registerPlugin($plugin);
    }

    public function testConstructorWithoutSpool(): void
    {
        $transport = new Swift_Transport_SpoolTransport($this->eventDispatcherMock);
        $this->assertNull($transport->getSpool());
    }

    public function testSpoolReceivesMessage(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to@example.com' => 'To']);
        $message->setFrom(['from@example.com' => 'From']);
        $message->setSubject('Test Subject');
        $message->setBody('Hello World');

        $this->spoolMock->expects($this->once())
            ->method('queueMessage')
            ->with($this->callback(function ($msg) {
                return $msg instanceof Swift_Mime_SimpleMessage
                    && 'Test Subject' === $msg->getSubject();
            }))
            ->willReturn(true);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $this->transport->send($message);
    }

    public function testSendWithEnvelopeOverridesCount(): void
    {
        $message = new Swift_Message();
        $message->setTo(['to1@example.com' => 'T1', 'to2@example.com' => 'T2']);

        $envelope = new Swift_Envelope('sender@example.com', ['only@example.com']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);
        $this->spoolMock->method('queueMessage')->willReturn(true);

        $result = $this->transport->send($message, $failures, $envelope);
        $this->assertSame(1, $result);
    }

    public function testSendAlwaysReturnsOneWithoutEnvelope(): void
    {
        $message = new Swift_Message();
        $message->setTo(['a@example.com' => 'A', 'b@example.com' => 'B', 'c@example.com' => 'C']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);
        $this->spoolMock->method('queueMessage')->willReturn(true);

        // SpoolTransport always returns 1 on success (not recipient count)
        $result = $this->transport->send($message);
        $this->assertSame(1, $result);
    }
}
