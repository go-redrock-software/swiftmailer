<?php

class Swift_SentMessageTest extends \PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'message_id' => 'abc-123',
            'recipients' => 1,
            'debug' => ['status' => 200, 'response' => '{"ok":true}'],
        ]);

        $this->assertSame($message, $sentMessage->getOriginalMessage());
        $this->assertSame($transport, $sentMessage->getTransport());
        $this->assertEquals('abc-123', $sentMessage->getMessageId());
        $this->assertEquals(1, $sentMessage->getRecipientCount());
        $this->assertEquals(['status' => 200, 'response' => '{"ok":true}'], $sentMessage->getDebug());
        $this->assertEquals([], $sentMessage->getFailedRecipients());
    }

    public function testWithFailedRecipients()
    {
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A', 'c@d.com' => 'C']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'failed_recipients' => ['c@d.com'],
        ]);

        $this->assertEquals(['c@d.com'], $sentMessage->getFailedRecipients());
        $this->assertNull($sentMessage->getMessageId());
        $this->assertEquals(0, $sentMessage->getRecipientCount());
        $this->assertEquals([], $sentMessage->getDebug());
    }

    public function testDefaultsWithEmptyResult()
    {
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport);

        $this->assertNull($sentMessage->getMessageId());
        $this->assertEquals(0, $sentMessage->getRecipientCount());
        $this->assertEquals([], $sentMessage->getDebug());
        $this->assertEquals([], $sentMessage->getFailedRecipients());
    }
}
