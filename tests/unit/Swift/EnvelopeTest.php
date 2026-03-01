<?php

class Swift_EnvelopeTest extends PHPUnit\Framework\TestCase
{
    public function testConstructorSetsProperties()
    {
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com', 'cc@example.com']);

        $this->assertSame('sender@example.com', $envelope->getSender());
        $this->assertSame(['to@example.com', 'cc@example.com'], $envelope->getRecipients());
    }

    public function testConstructorRejectsEmptySender()
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Envelope('', ['to@example.com']);
    }

    public function testConstructorRejectsEmptyRecipients()
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Envelope('sender@example.com', []);
    }

    public function testConstructorRejectsNonStringRecipient()
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Envelope('sender@example.com', [123]);
    }

    public function testFromMessageUsesReturnPathAsSender()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => 'bounce@example.com',
            'getSender'     => ['sender@example.com' => 'Sender'],
            'getFrom'       => ['from@example.com' => 'From'],
            'getTo'         => ['to@example.com' => 'To'],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame('bounce@example.com', $envelope->getSender());
        $this->assertSame(['to@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageUsesSenderHeaderWhenNoReturnPath()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => ['sender@example.com' => 'Sender'],
            'getFrom'       => ['from@example.com' => 'From'],
            'getTo'         => ['to@example.com' => 'To'],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame('sender@example.com', $envelope->getSender());
    }

    public function testFromMessageUsesFromWhenNoSenderOrReturnPath()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => ['from@example.com' => 'From'],
            'getTo'         => ['to@example.com' => 'To'],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame('from@example.com', $envelope->getSender());
    }

    public function testFromMessageMergesToCcBcc()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => ['from@example.com' => null],
            'getTo'         => ['to@example.com' => null],
            'getCc'         => ['cc@example.com' => null],
            'getBcc'        => ['bcc@example.com' => null],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame(['to@example.com', 'cc@example.com', 'bcc@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageThrowsWhenNoSenderDeterminable()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => null,
            'getTo'         => ['to@example.com' => null],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $this->expectException(Swift_SwiftException::class);
        Swift_Envelope::fromMessage($message);
    }

    public function testFromMessageThrowsWhenNoRecipients()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => ['from@example.com' => null],
            'getTo'         => [],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $this->expectException(Swift_SwiftException::class);
        Swift_Envelope::fromMessage($message);
    }

    public function testIsImmutable()
    {
        $recipients = ['to@example.com'];
        $envelope   = new Swift_Envelope('sender@example.com', $recipients);

        // Modifying the original array must not affect the envelope
        $recipients[] = 'other@example.com';
        $this->assertCount(1, $envelope->getRecipients());
    }
}
