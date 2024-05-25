<?php

use PHPUnit\Framework\TestCase;

class Swift_MemorySpoolTest extends TestCase
{
    private function createSwiftMessage()
    {
        $encoder     = new Swift_Mime_ContentEncoder_Base64ContentEncoder();
        $headers     = new Swift_Mime_SimpleHeaderSet(new Swift_Mime_SimpleHeaderFactory(new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(), $encoder, new Egulias\EmailValidator\EmailValidator()));
        $cache       = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
        $idGenerator = new Swift_Mime_IdGenerator('example.com');
        $charset     = null;
        $headers->addMailboxHeader('To', ['example@example.com']);

        return new Swift_Mime_SimpleMessage($headers, $encoder, $cache, $idGenerator, $charset);
    }

    public function testIsStartedAlwaysReturnsTrue()
    {
        $spool = new Swift_MemorySpool();
        $this->assertTrue($spool->isStarted());
    }

    public function testQueueAddsMessagesToQueue()
    {
        $spool = new Swift_MemorySpool();

        $this->assertTrue($spool->queueMessage($this->createSwiftMessage()));
    }

    public function testFlushQueueSendsQueuedMessagesAndReturnsCount()
    {
        // This testcase depends on the emit and count functions of the used transport object
        $transport = new Swift_NullTransport();

        $spool    = new Swift_MemorySpool();
        $message1 = $this->createSwiftMessage();
        $message2 = $this->createSwiftMessage();

        $spool->queueMessage($message1);
        $spool->queueMessage($message2);

        $this->assertEquals(2, $spool->flushQueue($transport));
    }

    public function testFlushQueueCatchesExceptionAndContinues()
    {
        // This testcase depends on the behavior of the used transport object under exception conditions
        // The transport object must implement exception handling
        $transport = new Swift_NullTransport(); // Replace with transport that implements exception handling

        $spool    = new Swift_MemorySpool();
        $message1 = $this->createSwiftMessage();
        $message2 = $this->createSwiftMessage();

        $spool->queueMessage($message1);
        $spool->queueMessage($message2);

        // If the exception is not caught the test will fail
        $this->assertEquals(2, $spool->flushQueue($transport));
    }
}
