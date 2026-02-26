<?php

class Swift_Events_SentMessageEventTest extends \PHPUnit\Framework\TestCase
{
    public function testGetSentMessage()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'x']);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);

        $this->assertSame($sentMessage, $event->getSentMessage());
        $this->assertSame($transport, $event->getSource());
        $this->assertSame($transport, $event->getTransport());
    }
}
