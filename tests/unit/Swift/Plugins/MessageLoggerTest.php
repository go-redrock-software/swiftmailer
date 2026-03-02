<?php

class Swift_Plugins_MessageLoggerTest extends PHPUnit\Framework\TestCase
{
    public function testStartsWithEmptyMessageList()
    {
        $logger = new Swift_Plugins_MessageLogger();
        $this->assertEquals([], $logger->getMessages());
        $this->assertEquals(0, $logger->countMessages());
    }

    public function testBeforeSendPerformedCapturesMessage()
    {
        $logger = new Swift_Plugins_MessageLogger();

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $logger->beforeSendPerformed($event);

        $this->assertEquals(1, $logger->countMessages());
    }

    public function testMultipleMessagesAreCaptured()
    {
        $logger = new Swift_Plugins_MessageLogger();
        $transport = $this->createMock(Swift_Transport::class);

        for ($i = 0; $i < 5; ++$i) {
            $message = (new Swift_Message())
                ->setFrom(['from@example.com'])
                ->setTo(['to@example.com' => 'To'])
                ->setSubject('Test '.$i);

            $event = new Swift_Events_SendEvent($transport, $message);
            $logger->beforeSendPerformed($event);
        }

        $this->assertEquals(5, $logger->countMessages());
        $this->assertCount(5, $logger->getMessages());
    }

    public function testClearRemovesAllMessages()
    {
        $logger = new Swift_Plugins_MessageLogger();
        $transport = $this->createMock(Swift_Transport::class);

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test');

        $event = new Swift_Events_SendEvent($transport, $message);
        $logger->beforeSendPerformed($event);
        $this->assertEquals(1, $logger->countMessages());

        $logger->clear();
        $this->assertEquals(0, $logger->countMessages());
        $this->assertEquals([], $logger->getMessages());
    }

    public function testMessagesAreClonesNotOriginals()
    {
        $logger = new Swift_Plugins_MessageLogger();

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Original Subject');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);
        $logger->beforeSendPerformed($event);

        // Modify original message
        $message->setSubject('Modified Subject');

        // Logged message should still have original subject
        $captured = $logger->getMessages();
        $this->assertSame('Original Subject', $captured[0]->getSubject());
    }

    public function testSendPerformedIsNoop()
    {
        $logger = new Swift_Plugins_MessageLogger();

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        // sendPerformed should not change anything
        $logger->sendPerformed($event);
        $this->assertEquals(0, $logger->countMessages());
    }

    public function testImplementsSendListener()
    {
        $logger = new Swift_Plugins_MessageLogger();
        $this->assertInstanceOf(Swift_Events_SendListener::class, $logger);
    }

    public function testGetMessagesReturnsArrayOfMessages()
    {
        $logger = new Swift_Plugins_MessageLogger();
        $transport = $this->createMock(Swift_Transport::class);

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test');

        $event = new Swift_Events_SendEvent($transport, $message);
        $logger->beforeSendPerformed($event);

        $messages = $logger->getMessages();
        $this->assertIsArray($messages);
        $this->assertInstanceOf(Swift_Mime_SimpleMessage::class, $messages[0]);
    }

    public function testClearThenAddWorks()
    {
        $logger = new Swift_Plugins_MessageLogger();
        $transport = $this->createMock(Swift_Transport::class);

        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com' => 'To'])
            ->setSubject('Test');

        $event = new Swift_Events_SendEvent($transport, $message);

        $logger->beforeSendPerformed($event);
        $this->assertEquals(1, $logger->countMessages());

        $logger->clear();
        $this->assertEquals(0, $logger->countMessages());

        $logger->beforeSendPerformed($event);
        $this->assertEquals(1, $logger->countMessages());
    }
}
