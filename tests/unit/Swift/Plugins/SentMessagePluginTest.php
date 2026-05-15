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

    public function testImplementsSentMessageListener()
    {
        $plugin = new Swift_Plugins_SentMessagePlugin();
        $this->assertInstanceOf(Swift_Events_SentMessageListener::class, $plugin);
    }

    public function testResetThenCaptureAgainWorks()
    {
        $plugin      = new Swift_Plugins_SentMessagePlugin();
        $transport   = $this->createMock(Swift_Transport::class);
        $message     = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'id-1']);

        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage));
        $plugin->reset();

        $sentMessage2 = new Swift_SentMessage($message, $transport, ['message_id' => 'id-2']);
        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage2));

        $this->assertSame($sentMessage2, $plugin->getLastSentMessage());
        $this->assertCount(1, $plugin->getSentMessages());
    }

    public function testGetLastSentMessageReturnsLatest()
    {
        $plugin    = new Swift_Plugins_SentMessagePlugin();
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())->setTo(['a@b.com' => 'A']);

        for ($i = 1; $i <= 10; ++$i) {
            $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'id-'.$i]);
            $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage));
        }

        $this->assertSame('id-10', $plugin->getLastSentMessage()->getMessageId());
        $this->assertCount(10, $plugin->getSentMessages());
    }

    public function testGetSentMessagesPreservesOrder()
    {
        $plugin    = new Swift_Plugins_SentMessagePlugin();
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())->setTo(['a@b.com' => 'A']);

        $sentMsg1 = new Swift_SentMessage($message, $transport, ['message_id' => 'first']);
        $sentMsg2 = new Swift_SentMessage($message, $transport, ['message_id' => 'second']);

        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMsg1));
        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMsg2));

        $messages = $plugin->getSentMessages();
        $this->assertSame('first', $messages[0]->getMessageId());
        $this->assertSame('second', $messages[1]->getMessageId());
    }

    public function testSentMessagePluginRespectsMaxMessages()
    {
        $plugin    = new Swift_Plugins_SentMessagePlugin(3);
        $transport = $this->createMock(Swift_Transport::class);
        $message   = (new Swift_Message())->setTo(['a@b.com' => 'A']);

        for ($i = 1; $i <= 5; ++$i) {
            $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'id-'.$i]);
            $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage));
        }

        $this->assertCount(3, $plugin->getSentMessages());
        $this->assertSame('id-3', $plugin->getSentMessages()[0]->getMessageId());
        $this->assertSame('id-5', $plugin->getLastSentMessage()->getMessageId());
    }

    public function testResetOnEmptyPluginIsNoOp()
    {
        $plugin = new Swift_Plugins_SentMessagePlugin();
        $plugin->reset();
        $this->assertNull($plugin->getLastSentMessage());
        $this->assertEquals([], $plugin->getSentMessages());
    }
}
