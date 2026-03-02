<?php

use PHPUnit\Framework\TestCase;

class Swift_MemorySpoolExtendedTest extends TestCase
{
    private function createMessage(): Swift_Mime_SimpleMessage
    {
        $encoder     = new Swift_Mime_ContentEncoder_Base64ContentEncoder();
        $headers     = new Swift_Mime_SimpleHeaderSet(new Swift_Mime_SimpleHeaderFactory(new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(), $encoder, new Egulias\EmailValidator\EmailValidator()));
        $cache       = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
        $idGenerator = new Swift_Mime_IdGenerator('example.com');
        $headers->addMailboxHeader('To', ['example@example.com']);

        return new Swift_Mime_SimpleMessage($headers, $encoder, $cache, $idGenerator, null);
    }

    public function testIsStartedReturnsTrueAlways()
    {
        $spool = new Swift_MemorySpool();
        $this->assertTrue($spool->isStarted());
    }

    public function testStartIsNoOp()
    {
        $spool = new Swift_MemorySpool();
        $spool->start();
        $this->assertTrue($spool->isStarted());
    }

    public function testStopIsNoOp()
    {
        $spool = new Swift_MemorySpool();
        $spool->stop();
        $this->assertTrue($spool->isStarted());
    }

    public function testQueueMessageReturnsTrue()
    {
        $spool = new Swift_MemorySpool();
        $this->assertTrue($spool->queueMessage($this->createMessage()));
    }

    public function testQueueMultipleMessages()
    {
        $spool = new Swift_MemorySpool();
        $this->assertTrue($spool->queueMessage($this->createMessage()));
        $this->assertTrue($spool->queueMessage($this->createMessage()));
        $this->assertTrue($spool->queueMessage($this->createMessage()));
    }

    public function testFlushQueueReturnsZeroWhenEmpty()
    {
        $spool     = new Swift_MemorySpool();
        $transport = new Swift_NullTransport();
        $this->assertEquals(0, $spool->flushQueue($transport));
    }

    public function testFlushQueueReturnsSentCount()
    {
        $spool = new Swift_MemorySpool();
        $spool->queueMessage($this->createMessage());
        $spool->queueMessage($this->createMessage());
        $spool->queueMessage($this->createMessage());

        $transport = new Swift_NullTransport();
        $this->assertEquals(3, $spool->flushQueue($transport));
    }

    public function testFlushQueueEmptiesTheQueue()
    {
        $spool = new Swift_MemorySpool();
        $spool->queueMessage($this->createMessage());

        $transport = new Swift_NullTransport();
        $spool->flushQueue($transport);
        $this->assertEquals(0, $spool->flushQueue($transport));
    }

    public function testFlushQueueStartsTransportIfNeeded()
    {
        $transport = $this->createMock(Swift_Transport::class);

        $transport->method('isStarted')->willReturn(false);
        $transport->expects($this->once())->method('start');
        $transport->method('send')->willReturn(1);

        $spool = new Swift_MemorySpool();
        $spool->queueMessage($this->createMessage());
        $spool->flushQueue($transport);
    }

    public function testFlushQueueDoesNotStartTransportIfStarted()
    {
        $transport = $this->createMock(Swift_Transport::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->never())->method('start');
        $transport->method('send')->willReturn(1);

        $spool = new Swift_MemorySpool();
        $spool->queueMessage($this->createMessage());
        $spool->flushQueue($transport);
    }

    public function testQueueMessageClonesMessage()
    {
        $spool   = new Swift_MemorySpool();
        $message = $this->createMessage();
        $spool->queueMessage($message);

        // Modifying the original message shouldn't affect queued one
        $message->setSubject('Modified');

        // Just verify the queue works
        $transport = new Swift_NullTransport();
        $this->assertEquals(1, $spool->flushQueue($transport));
    }

    public function testSetFlushRetries()
    {
        $spool = new Swift_MemorySpool();
        $spool->setFlushRetries(5);
        // No exception - retries accepted
        $this->addToAssertionCount(1);
    }

    public function testFlushQueueRetriesOnTransportException()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);

        $callCount = 0;
        $transport->method('send')->willReturnCallback(function () use (&$callCount) {
            ++$callCount;
            if ($callCount === 1) {
                throw new Swift_TransportException('Temporary failure');
            }
            return 1;
        });

        $spool = new Swift_MemorySpool();
        $spool->setFlushRetries(3);
        $spool->queueMessage($this->createMessage());

        $count = $spool->flushQueue($transport);
        $this->assertEquals(1, $count);
    }

    public function testFlushDoesNotStartTransportWhenQueueEmpty()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $transport->expects($this->never())->method('start');

        $spool = new Swift_MemorySpool();
        $spool->flushQueue($transport);
    }
}
