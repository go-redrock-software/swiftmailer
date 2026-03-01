<?php

class Swift_SentMessage_CloneWithTest extends PHPUnit\Framework\TestCase
{
    public function testCloneWithModifiedMessageId()
    {
        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test');
        $transport = $this->createMock(Swift_Transport::class);

        $original = new Swift_SentMessage($message, $transport, [
            'message_id' => 'original-id',
            'recipients' => 1,
        ]);

        $cloned = $original->withMessageId('new-id');

        $this->assertSame('original-id', $original->getMessageId());
        $this->assertSame('new-id', $cloned->getMessageId());
        $this->assertSame($message, $cloned->getOriginalMessage());
    }

    public function testCloneWithModifiedRecipientCount()
    {
        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test');
        $transport = $this->createMock(Swift_Transport::class);

        $original = new Swift_SentMessage($message, $transport, [
            'message_id' => 'abc-123',
            'recipients' => 1,
        ]);

        $cloned = $original->withRecipientCount(5);

        $this->assertSame(1, $original->getRecipientCount());
        $this->assertSame(5, $cloned->getRecipientCount());
        $this->assertSame('abc-123', $cloned->getMessageId());
    }

    public function testClonePreservesOtherProperties()
    {
        $message   = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $transport = $this->createMock(Swift_Transport::class);

        $original = new Swift_SentMessage($message, $transport, [
            'message_id'        => 'id-1',
            'recipients'        => 3,
            'debug'             => ['status' => 200],
            'failed_recipients' => ['x@y.com'],
        ]);

        $cloned = $original->withMessageId('id-2');

        $this->assertSame($transport, $cloned->getTransport());
        $this->assertSame(3, $cloned->getRecipientCount());
        $this->assertSame(['status' => 200], $cloned->getDebug());
        $this->assertSame(['x@y.com'], $cloned->getFailedRecipients());
    }
}
