<?php

class Swift_Plugins_ImpersonatePluginTest extends PHPUnit\Framework\TestCase
{
    public function testSenderIsReplacedInBeforeSend()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test')
            ->setReturnPath('original@example.com');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $this->assertSame('impersonate@example.com', $message->getReturnPath());
    }

    public function testOriginalReturnPathIsStoredInHeader()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test')
            ->setReturnPath('original@example.com');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $this->assertTrue($message->getHeaders()->has('X-Swift-Return-Path'));
    }

    public function testOriginalReturnPathIsRestoredAfterSend()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test')
            ->setReturnPath('original@example.com');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);
        $this->assertSame('impersonate@example.com', $message->getReturnPath());

        $plugin->sendPerformed($event);
        $this->assertSame('original@example.com', $message->getReturnPath());
    }

    public function testXSwiftReturnPathHeaderIsRemovedAfterSend()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test')
            ->setReturnPath('original@example.com');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);
        $plugin->sendPerformed($event);

        $this->assertFalse($message->getHeaders()->has('X-Swift-Return-Path'));
    }

    public function testSendPerformedWithoutBeforeSendIsNoop()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test')
            ->setReturnPath('original@example.com');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        // sendPerformed without prior beforeSendPerformed should not crash
        $plugin->sendPerformed($event);

        $this->assertSame('original@example.com', $message->getReturnPath());
    }

    public function testPluginImplementsSendListener()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('test@example.com');
        $this->assertInstanceOf(Swift_Events_SendListener::class, $plugin);
    }

    public function testMultipleMessagesCanBeImpersonated()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');
        $transport = $this->createMock(Swift_Transport::class);

        // First message
        $message1 = (new Swift_Message())
            ->setFrom(['from1@example.com'])
            ->setTo(['to1@example.com' => 'To1'])
            ->setSubject('Test 1')
            ->setReturnPath('return1@example.com');

        $event1 = new Swift_Events_SendEvent($transport, $message1);
        $plugin->beforeSendPerformed($event1);
        $this->assertSame('impersonate@example.com', $message1->getReturnPath());
        $plugin->sendPerformed($event1);
        $this->assertSame('return1@example.com', $message1->getReturnPath());

        // Second message
        $message2 = (new Swift_Message())
            ->setFrom(['from2@example.com'])
            ->setTo(['to2@example.com' => 'To2'])
            ->setSubject('Test 2')
            ->setReturnPath('return2@example.com');

        $event2 = new Swift_Events_SendEvent($transport, $message2);
        $plugin->beforeSendPerformed($event2);
        $this->assertSame('impersonate@example.com', $message2->getReturnPath());
        $plugin->sendPerformed($event2);
        $this->assertSame('return2@example.com', $message2->getReturnPath());
    }

    public function testNullReturnPathIsHandled()
    {
        $plugin = new Swift_Plugins_ImpersonatePlugin('impersonate@example.com');

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);
        $this->assertSame('impersonate@example.com', $message->getReturnPath());
    }
}
