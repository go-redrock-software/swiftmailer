<?php

class Swift_Events_FailedMessageEventTest extends \PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
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
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('fail');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception);

        $this->assertEquals([], $event->getFailedRecipients());
    }
}
