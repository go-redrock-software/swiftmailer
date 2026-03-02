<?php

class Swift_Transport_NullTransportTest extends PHPUnit\Framework\TestCase
{
    private $eventDispatcherMock;

    private Swift_Transport_NullTransport $transport;

    protected function setUp(): void
    {
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);
        $this->transport           = new Swift_Transport_NullTransport($this->eventDispatcherMock);
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

    public function testSendCountsToRecipients(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(1, $result);
    }

    public function testSendCountsMultipleToRecipients(): void
    {
        $message = $this->createMessage();
        $message->setTo(['a@example.com' => 'A', 'b@example.com' => 'B']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(2, $result);
    }

    public function testSendCountsCcRecipients(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);
        $message->setCc(['cc@example.com' => 'CC']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(2, $result);
    }

    public function testSendCountsBccRecipients(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);
        $message->setBcc(['bcc@example.com' => 'BCC']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(2, $result);
    }

    public function testSendCountsAllRecipientTypes(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);
        $message->setCc(['cc@example.com' => 'CC']);
        $message->setBcc(['bcc@example.com' => 'BCC']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(3, $result);
    }

    public function testSendDispatchesBeforeSendPerformedEvent(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->expects($this->once())
            ->method('createSendEvent')
            ->willReturn($evt);

        $this->eventDispatcherMock->expects($this->exactly(2))
            ->method('dispatchEvent')
            ->with($evt, $this->logicalOr('beforeSendPerformed', 'sendPerformed'));

        $this->transport->send($message);
    }

    public function testSendReturnsZeroWhenBubbleCancelled(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(true);
        $evt->expects($this->once())->method('setResult')->with(Swift_Events_SendEvent::RESULT_FAILED);
        $evt->expects($this->once())->method('cancelBubble')->with(false);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message);
        $this->assertSame(0, $result);
    }

    public function testSendSetsResultSuccessWhenNotCancelled(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(false);
        $evt->expects($this->once())->method('setResult')->with(Swift_Events_SendEvent::RESULT_SUCCESS);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $this->transport->send($message);
    }

    public function testSendWithNullEvent(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $result = $this->transport->send($message);
        $this->assertSame(1, $result);
    }

    public function testSendUsesEnvelopeRecipientCountWhenProvided(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $envelope = new Swift_Envelope('sender@example.com', ['a@example.com', 'b@example.com', 'c@example.com']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message, $failures, $envelope);
        $this->assertSame(3, $result);
    }

    public function testSendWithEnvelopeReturnsEnvelopeCountNotMessageCount(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to1@example.com' => 'To1', 'to2@example.com' => 'To2']);

        $envelope = new Swift_Envelope('sender@example.com', ['only@example.com']);

        $evt = $this->createMock(Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);

        $result = $this->transport->send($message, $failures, $envelope);
        $this->assertSame(1, $result);
    }

    public function testRegisterPluginDelegatesToEventDispatcher(): void
    {
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $this->eventDispatcherMock->expects($this->once())
            ->method('bindEventListener')
            ->with($plugin);

        $this->transport->registerPlugin($plugin);
    }

    public function testSendWithNoRecipients(): void
    {
        $message = $this->createMessage();

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $result = $this->transport->send($message);
        $this->assertSame(0, $result);
    }

    public function testSendWithOnlyBccRecipients(): void
    {
        $message = $this->createMessage();
        $message->setBcc(['bcc1@example.com' => 'B1', 'bcc2@example.com' => 'B2']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $result = $this->transport->send($message);
        $this->assertSame(2, $result);
    }

    public function testSendWithLargeRecipientList(): void
    {
        $message = $this->createMessage();
        $to      = [];
        for ($i = 0; $i < 50; ++$i) {
            $to["user{$i}@example.com"] = "User {$i}";
        }
        $message->setTo($to);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $result = $this->transport->send($message);
        $this->assertSame(50, $result);
    }

    public function testFailedRecipientsAreNotModified(): void
    {
        $message = $this->createMessage();
        $message->setTo(['to@example.com' => 'To']);

        $this->eventDispatcherMock->method('createSendEvent')->willReturn(null);

        $failedRecipients = [];
        $this->transport->send($message, $failedRecipients);
        $this->assertSame([], $failedRecipients);
    }

    private function createMessage(): Swift_Mime_SimpleMessage
    {
        return new Swift_Message();
    }
}
