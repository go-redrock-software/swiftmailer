<?php

class Swift_Events_FailedMessageEventTest extends PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C']);
        $exception = new Swift_TransportException('API error');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception, ['c@d.com']);

        $this->assertSame($message, $event->getMessage());
        $this->assertSame($exception, $event->getException());
        $this->assertEquals(['c@d.com'], $event->getFailedRecipients());
        $this->assertSame($transport, $event->getSource());
        $this->assertSame($transport, $event->getTransport());
    }

    public function testDefaultEmptyFailedRecipients()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('fail');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception);

        $this->assertEquals([], $event->getFailedRecipients());
    }

    public function testInheritsEventObject()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('error');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception);
        $this->assertInstanceOf(Swift_Events_EventObject::class, $event);
    }

    public function testBubbleCancellation()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('error');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception);
        $this->assertFalse($event->bubbleCancelled());
        $event->cancelBubble(true);
        $this->assertTrue($event->bubbleCancelled());
    }

    public function testGetTransportReturnsSameAsGetSource()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('error');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception);
        $this->assertSame($event->getTransport(), $event->getSource());
    }

    public function testMultipleFailedRecipients()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('error');

        $event = new Swift_Events_FailedMessageEvent(
            $transport, $message, $exception,
            ['a@b.com', 'c@d.com', 'e@f.com']
        );

        $this->assertCount(3, $event->getFailedRecipients());
        $this->assertEquals(['a@b.com', 'c@d.com', 'e@f.com'], $event->getFailedRecipients());
    }

    public function testExceptionMessagePreserved()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('Connection refused', 111);

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception);
        $this->assertSame('Connection refused', $event->getException()->getMessage());
        $this->assertSame(111, $event->getException()->getCode());
    }
}
