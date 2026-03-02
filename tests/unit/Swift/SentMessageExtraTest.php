<?php

class Swift_SentMessageExtraTest extends \PHPUnit\Framework\TestCase
{
    public function testFromResultNamedConstructor(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = Swift_SentMessage::fromResult($message, $transport, [
            'message_id' => 'from-result-id',
            'recipients' => 5,
        ]);

        $this->assertSame('from-result-id', $sentMessage->getMessageId());
        $this->assertSame(5, $sentMessage->getRecipientCount());
    }

    public function testGetOriginalMessage(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);
        $this->assertSame($message, $sentMessage->getOriginalMessage());
    }

    public function testGetTransport(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);
        $this->assertSame($transport, $sentMessage->getTransport());
    }

    public function testDefaultMessageIdIsNull(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);
        $this->assertNull($sentMessage->getMessageId());
    }

    public function testDefaultRecipientCountIsZero(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);
        $this->assertSame(0, $sentMessage->getRecipientCount());
    }

    public function testDefaultDebugIsEmpty(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);
        $this->assertSame([], $sentMessage->getDebug());
    }

    public function testDefaultFailedRecipientsIsEmpty(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);
        $this->assertSame([], $sentMessage->getFailedRecipients());
    }

    public function testMessageIdFromResult(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'message_id' => 'xyz-789',
        ]);
        $this->assertSame('xyz-789', $sentMessage->getMessageId());
    }

    public function testRecipientCountFromResult(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'recipients' => 42,
        ]);
        $this->assertSame(42, $sentMessage->getRecipientCount());
    }

    public function testDebugFromResult(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'debug' => ['status' => 200, 'body' => 'OK'],
        ]);
        $this->assertSame(['status' => 200, 'body' => 'OK'], $sentMessage->getDebug());
    }

    public function testFailedRecipientsFromResult(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'failed_recipients' => ['bad@example.com'],
        ]);
        $this->assertSame(['bad@example.com'], $sentMessage->getFailedRecipients());
    }

    public function testAllResultFieldsTogether(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'message_id' => 'full-test',
            'recipients' => 3,
            'debug' => ['raw' => 'data'],
            'failed_recipients' => ['fail@example.com'],
        ]);

        $this->assertSame('full-test', $sentMessage->getMessageId());
        $this->assertSame(3, $sentMessage->getRecipientCount());
        $this->assertSame(['raw' => 'data'], $sentMessage->getDebug());
        $this->assertSame(['fail@example.com'], $sentMessage->getFailedRecipients());
    }

    public function testIsReadonly(): void
    {
        $ref = new ReflectionClass(Swift_SentMessage::class);
        $this->assertTrue($ref->isReadOnly());
    }

    public function testFromResultReturnsSameType(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = Swift_SentMessage::fromResult($message, $transport, []);
        $this->assertInstanceOf(Swift_SentMessage::class, $sentMessage);
    }

    public function testFromResultPreservesMessageReference(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = Swift_SentMessage::fromResult($message, $transport, []);
        $this->assertSame($message, $sentMessage->getOriginalMessage());
    }

    public function testFromResultPreservesTransportReference(): void
    {
        $message = (new Swift_Message())->setTo(['to@example.com' => 'To']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = Swift_SentMessage::fromResult($message, $transport, []);
        $this->assertSame($transport, $sentMessage->getTransport());
    }
}
