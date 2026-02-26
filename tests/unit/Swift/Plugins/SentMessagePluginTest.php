<?php

class Swift_Plugins_SentMessagePluginTest extends PHPUnit\Framework\TestCase
{
    public function testCapturesLastSentMessage()
    {
        $plugin = new Swift_Plugins_SentMessagePlugin();

        $this->assertNull($plugin->getLastSentMessage());
        $this->assertEquals([], $plugin->getSentMessages());

        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'id-1']);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
        $plugin->sentMessage($event);

        $this->assertSame($sentMessage, $plugin->getLastSentMessage());
        $this->assertCount(1, $plugin->getSentMessages());
    }

    public function testCapturesMultipleMessages()
    {
        $plugin    = new Swift_Plugins_SentMessagePlugin();
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())->setTo(['a@b.com' => 'A']);

        $sentMessage1 = new Swift_SentMessage($message, $transport, ['message_id' => 'id-1']);
        $sentMessage2 = new Swift_SentMessage($message, $transport, ['message_id' => 'id-2']);

        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage1));
        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage2));

        $this->assertSame($sentMessage2, $plugin->getLastSentMessage());
        $this->assertCount(2, $plugin->getSentMessages());
    }

    public function testReset()
    {
        $plugin      = new Swift_Plugins_SentMessagePlugin();
        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport);

        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage));
        $plugin->reset();

        $this->assertNull($plugin->getLastSentMessage());
        $this->assertEquals([], $plugin->getSentMessages());
    }
}
