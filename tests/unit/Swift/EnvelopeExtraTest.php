<?php

class Swift_EnvelopeExtraTest extends \PHPUnit\Framework\TestCase
{
    public function testGetSenderReturnsSender(): void
    {
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com']);
        $this->assertSame('sender@example.com', $envelope->getSender());
    }

    public function testGetRecipientsReturnsRecipients(): void
    {
        $envelope = new Swift_Envelope('sender@example.com', ['a@example.com', 'b@example.com']);
        $this->assertSame(['a@example.com', 'b@example.com'], $envelope->getRecipients());
    }

    public function testRecipientsAreReindexed(): void
    {
        $envelope = new Swift_Envelope('sender@example.com', [5 => 'a@example.com', 10 => 'b@example.com']);
        $this->assertSame([0 => 'a@example.com', 1 => 'b@example.com'], $envelope->getRecipients());
    }

    public function testSingleRecipient(): void
    {
        $envelope = new Swift_Envelope('sender@example.com', ['only@example.com']);
        $this->assertCount(1, $envelope->getRecipients());
    }

    public function testManyRecipients(): void
    {
        $recipients = [];
        for ($i = 0; $i < 100; ++$i) {
            $recipients[] = "user{$i}@example.com";
        }
        $envelope = new Swift_Envelope('sender@example.com', $recipients);
        $this->assertCount(100, $envelope->getRecipients());
    }

    public function testEmptySenderThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sender address must not be empty');
        new Swift_Envelope('', ['to@example.com']);
    }

    public function testEmptyRecipientsArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one recipient');
        new Swift_Envelope('sender@example.com', []);
    }

    public function testNonStringRecipientThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');
        new Swift_Envelope('sender@example.com', [42]);
    }

    public function testNullRecipientThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Envelope('sender@example.com', [null]);
    }

    public function testBoolRecipientThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Envelope('sender@example.com', [true]);
    }

    public function testFromMessageWithReturnPath(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => 'bounce@example.com',
            'getSender' => ['sender@example.com' => 'Sender'],
            'getFrom' => ['from@example.com' => 'From'],
            'getTo' => ['to@example.com' => 'To'],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame('bounce@example.com', $envelope->getSender());
    }

    public function testFromMessageWithSenderHeader(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => ['sender@example.com' => 'Sender'],
            'getFrom' => ['from@example.com' => 'From'],
            'getTo' => ['to@example.com' => 'To'],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame('sender@example.com', $envelope->getSender());
    }

    public function testFromMessageWithFromOnly(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => ['from@example.com' => 'From'],
            'getTo' => ['to@example.com' => 'To'],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame('from@example.com', $envelope->getSender());
    }

    public function testFromMessageMergesAllRecipientTypes(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => ['from@example.com' => null],
            'getTo' => ['to@example.com' => null],
            'getCc' => ['cc@example.com' => null],
            'getBcc' => ['bcc@example.com' => null],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame(['to@example.com', 'cc@example.com', 'bcc@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageThrowsWhenNoSender(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => null,
            'getTo' => ['to@example.com' => null],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Cannot determine envelope sender');
        Swift_Envelope::fromMessage($message);
    }

    public function testFromMessageThrowsWhenNoRecipients(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => ['from@example.com' => null],
            'getTo' => [],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Cannot determine envelope recipients');
        Swift_Envelope::fromMessage($message);
    }

    public function testIsReadonly(): void
    {
        $ref = new ReflectionClass(Swift_Envelope::class);
        $this->assertTrue($ref->isReadOnly());
    }

    public function testFromMessageWithOnlyCc(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => ['from@example.com' => null],
            'getTo' => [],
            'getCc' => ['cc@example.com' => null],
            'getBcc' => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame(['cc@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageWithOnlyBcc(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => ['from@example.com' => null],
            'getTo' => [],
            'getCc' => [],
            'getBcc' => ['bcc@example.com' => null],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame(['bcc@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageWithMultipleOfEachType(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender' => null,
            'getFrom' => ['from@example.com' => null],
            'getTo' => ['to1@example.com' => null, 'to2@example.com' => null],
            'getCc' => ['cc1@example.com' => null, 'cc2@example.com' => null],
            'getBcc' => ['bcc1@example.com' => null],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertCount(5, $envelope->getRecipients());
    }

    public function testFromMessageWithEmptyReturnPathFallsToSender(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => '',
            'getSender' => ['sender@example.com' => 'Sender'],
            'getFrom' => ['from@example.com' => 'From'],
            'getTo' => ['to@example.com' => 'To'],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame('sender@example.com', $envelope->getSender());
    }

    public function testFromMessageWithEmptySenderFallsToFrom(): void
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => '',
            'getSender' => [],
            'getFrom' => ['from@example.com' => 'From'],
            'getTo' => ['to@example.com' => 'To'],
            'getCc' => [],
            'getBcc' => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);
        $this->assertSame('from@example.com', $envelope->getSender());
    }
}
