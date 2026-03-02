<?php

class Swift_Events_SentMessageEventTest extends PHPUnit\Framework\TestCase
{
    public function testGetSentMessage()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'x']);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);

        $this->assertSame($sentMessage, $event->getSentMessage());
        $this->assertSame($transport, $event->getSource());
        $this->assertSame($transport, $event->getTransport());
    }

    public function testInheritsEventObject()
    {
        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
        $this->assertInstanceOf(Swift_Events_EventObject::class, $event);
    }

    public function testBubbleCancellation()
    {
        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
        $this->assertFalse($event->bubbleCancelled());
        $event->cancelBubble(true);
        $this->assertTrue($event->bubbleCancelled());
    }

    public function testGetTransportReturnsSameAsGetSource()
    {
        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
        $this->assertSame($event->getTransport(), $event->getSource());
    }

    public function testSentMessageWithMetadata()
    {
        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'test-id-123', 'status' => 'sent']);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
        $this->assertSame('test-id-123', $event->getSentMessage()->getMessageId());
    }
}
