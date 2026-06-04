<?php

use PHPUnit\Framework\TestCase;

class Swift_FileSpoolTest extends TestCase
{
    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = \sys_get_temp_dir().'/swiftmailer_filespool_test_'.\bin2hex(\random_bytes(8));
        \mkdir($this->spoolDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up all files in spool directory
        if (\is_dir($this->spoolDir)) {
            foreach (new DirectoryIterator($this->spoolDir) as $file) {
                if (!$file->isDot()) {
                    \unlink($file->getRealPath());
                }
            }
            \rmdir($this->spoolDir);
        }
    }

    private function createMessage(): Swift_Mime_SimpleMessage
    {
        $encoder     = new Swift_Mime_ContentEncoder_Base64ContentEncoder();
        $headers     = new Swift_Mime_SimpleHeaderSet(new Swift_Mime_SimpleHeaderFactory(new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(), $encoder, new Egulias\EmailValidator\EmailValidator()));
        $cache       = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
        $idGenerator = new Swift_Mime_IdGenerator('example.com');
        $headers->addMailboxHeader('To', ['test@example.com']);

        return new Swift_Mime_SimpleMessage($headers, $encoder, $cache, $idGenerator, null);
    }

    public function testQueueAndFlushRoundTrip(): void
    {
        $spool   = new Swift_FileSpool($this->spoolDir);
        $message = $this->createMessage();
        $message->setSubject('Round-trip test');

        $spool->queueMessage($message);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Swift_Mime_SimpleMessage::class))
            ->willReturn(1);

        $count = $spool->flushQueue($transport);
        $this->assertSame(1, $count);
    }

    public function testMaliciousClassRejection(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Write a serialized stdClass directly — this is NOT in the allowlist
        $malicious = \serialize(new stdClass());
        \file_put_contents($this->spoolDir.'/malicious.message', $malicious);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->never())->method('send');

        $count = $spool->flushQueue($transport);
        $this->assertSame(0, $count);

        // The .sending file should have been cleaned up
        $this->assertEmpty(
            \glob($this->spoolDir.'/*.sending'),
            'Malicious .sending file should be cleaned up',
        );
    }

    public function testCorruptFileDoesNotCrashQueue(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Write garbage to a .message file
        \file_put_contents($this->spoolDir.'/corrupt.message', 'not-valid-serialized-data');

        // Queue a legitimate message
        $message = $this->createMessage();
        $message->setSubject('Legitimate message');
        $spool->queueMessage($message);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);

        // The corrupt file will fail instanceof check and be skipped;
        // the legitimate message should still be sent
        $transport->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Swift_Mime_SimpleMessage::class))
            ->willReturn(1);

        $count = $spool->flushQueue($transport);
        $this->assertSame(1, $count);
    }

    public function testMessageLimitRespected(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);
        $spool->setMessageLimit(2);

        for ($i = 0; $i < 5; ++$i) {
            $msg = $this->createMessage();
            $msg->setSubject("Message $i");
            $spool->queueMessage($msg);
        }

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(1);

        $count = $spool->flushQueue($transport);
        $this->assertSame(2, $count);
    }

    public function testTimeLimitRespected(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);
        // Use -1 so the condition (time() - start) >= -1 is immediately true.
        // A zero time limit is treated as "no limit" since 0 is falsy in PHP.
        $spool->setTimeLimit(-1);

        for ($i = 0; $i < 3; ++$i) {
            $msg = $this->createMessage();
            $msg->setSubject("Message $i");
            $spool->queueMessage($msg);
        }

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(1);

        $count = $spool->flushQueue($transport);
        // After sending the first message, the time limit check triggers and breaks
        $this->assertSame(1, $count);
    }

    public function testByteStreamGadgetClassIsBlocked(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Craft a serialized TemporaryFileByteStream with an attacker-controlled path.
        // This class has a __destruct() that calls unlink() on its path — an
        // arbitrary file deletion gadget. It must NOT be in the allowlist.
        $targetFile = $this->spoolDir.'/should_not_be_deleted.txt';
        \file_put_contents($targetFile, 'important data');

        // Hand-craft a serialized TemporaryFileByteStream pointing at our target file.
        // Format: O:<len>:"<class>":<props>:{s:<len>:"<prop>";s:<len>:"<val>";}
        // FileByteStream stores path in a private property (mangled name includes class).
        $className  = 'Swift_ByteStream_TemporaryFileByteStream';
        $parentName = 'Swift_ByteStream_FileByteStream';
        // PHP private property name mangling: \0ClassName\0propertyName
        $pathProp = "\0".$parentName."\0path";
        $payload  = \serialize(new stdClass()); // dummy, we'll replace with crafted string
        $payload  = 'O:'.\strlen($className).':"'.$className.'":1:{s:'.\strlen($pathProp).':"'.$pathProp.'";s:'.\strlen($targetFile).':"'.$targetFile.'";}';
        \file_put_contents($this->spoolDir.'/gadget.message', $payload);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->never())->method('send');

        $spool->flushQueue($transport);

        // The target file must still exist — the gadget class was blocked
        $this->assertFileExists($targetFile, 'TemporaryFileByteStream gadget should not delete arbitrary files');
    }

    public function testTransportExceptionDoesNotCrashQueue(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Queue two messages
        for ($i = 0; $i < 2; ++$i) {
            $msg = $this->createMessage();
            $msg->setSubject("Message $i");
            $spool->queueMessage($msg);
        }

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);

        // First call throws, second call succeeds
        $transport->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Connection lost')),
                1,
            );

        $count = $spool->flushQueue($transport);

        // The second message should still have been sent
        $this->assertSame(1, $count);

        // No orphaned .sending files
        $this->assertEmpty(
            \glob($this->spoolDir.'/*.sending'),
            'All .sending files should be cleaned up even after transport exception',
        );
    }

    public function testSendingFileCleanedUpOnUnserializeException(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Queue a legitimate message that should still send
        $msg = $this->createMessage();
        $msg->setSubject('Legitimate');
        $spool->queueMessage($msg);

        // Craft a serialized object whose __wakeup() throws.
        // Use a class NOT in the allowlist wrapped inside an allowlisted
        // container. Since the outer class becomes __PHP_Incomplete_Class,
        // the instanceof check catches it. But we also want to test
        // the try/catch path with a truly corrupt serialized string that
        // triggers an exception rather than just returning false.
        // PHP's unserialize with a truncated object can throw.
        // Simplest: a valid-looking but truncated serialized string.
        $truncated = 'O:14:"Swift_Message":1:{s:4:"test";s:100:"';
        \file_put_contents($this->spoolDir.'/truncated.message', $truncated);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(1);

        $count = $spool->flushQueue($transport);

        // Legitimate message should still send
        $this->assertSame(1, $count);

        // All .sending files cleaned up
        $this->assertEmpty(
            \glob($this->spoolDir.'/*.sending'),
            'All .sending files should be cleaned up after deserialization failure',
        );
    }

    public function testConstructorCreatesDirectory(): void
    {
        $newDir = $this->spoolDir.'/subdir_'.\bin2hex(\random_bytes(4));
        $this->assertDirectoryDoesNotExist($newDir);

        $spool = new Swift_FileSpool($newDir);

        $this->assertDirectoryExists($newDir);

        // Clean up
        \rmdir($newDir);
    }

    public function testIsStartedReturnsTrue(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        $this->assertTrue($spool->isStarted());
    }

    public function testStartAndStopAreNoOps(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // These should not throw
        $spool->start();
        $spool->stop();

        $this->assertTrue($spool->isStarted());
    }

    public function testSetRetryLimit(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);
        $spool->setRetryLimit(5);

        // Verify it doesn't throw and can still queue messages
        $msg = $this->createMessage();
        $msg->setSubject('Retry limit test');
        $result = $spool->queueMessage($msg);
        $this->assertTrue($result);
    }

    public function testRecoverRenamesStaleFiles(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Create a .message.sending file that is stale
        $sendingFile = $this->spoolDir.'/stale.message.sending';
        $messageFile = $this->spoolDir.'/stale.message';
        \file_put_contents($sendingFile, 'test data');

        // Use timeout=-1 so the condition (time() - ctime) > -1 is always true
        $spool->recover(-1);

        $this->assertFileDoesNotExist($sendingFile);
        $this->assertFileExists($messageFile);

        // Clean up
        @\unlink($messageFile);
    }

    public function testFlushQueueStartsTransportWhenNotStarted(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        // Queue a message
        $msg = $this->createMessage();
        $msg->setSubject('Start transport test');
        $spool->queueMessage($msg);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(false);
        $transport->expects($this->once())->method('start');
        $transport->method('send')->willReturn(1);

        $count = $spool->flushQueue($transport);
        $this->assertSame(1, $count);
    }

    public function testHmacSignedMessageRoundTrip(): void
    {
        $key   = \bin2hex(\random_bytes(32));
        $spool = new Swift_FileSpool($this->spoolDir, $key);

        $msg = $this->createMessage();
        $msg->setSubject('HMAC round-trip');
        $spool->queueMessage($msg);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Swift_Mime_SimpleMessage::class))
            ->willReturn(1);

        $count = $spool->flushQueue($transport);
        $this->assertSame(1, $count);
    }

    public function testHmacTamperedMessageIsSkipped(): void
    {
        $key   = \bin2hex(\random_bytes(32));
        $spool = new Swift_FileSpool($this->spoolDir, $key);

        $msg = $this->createMessage();
        $msg->setSubject('Tampered');
        $spool->queueMessage($msg);

        // Tamper with the serialized payload after the HMAC line
        $files = \glob($this->spoolDir.'/*.message');
        $this->assertCount(1, $files);
        $contents   = \file_get_contents($files[0]);
        $newlinePos = \strpos($contents, "\n");
        $hmac       = \substr($contents, 0, $newlinePos);
        \file_put_contents($files[0], $hmac."\n".'tampered-payload');

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->never())->method('send');

        $count = $spool->flushQueue($transport);
        $this->assertSame(0, $count);
    }

    public function testHmacWrongKeyMessageIsSkipped(): void
    {
        $key1 = \bin2hex(\random_bytes(32));
        $key2 = \bin2hex(\random_bytes(32));

        $spool = new Swift_FileSpool($this->spoolDir, $key1);
        $msg   = $this->createMessage();
        $msg->setSubject('Wrong key');
        $spool->queueMessage($msg);

        // Flush with a different key
        $spool->setSigningKey($key2);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->never())->method('send');

        $count = $spool->flushQueue($transport);
        $this->assertSame(0, $count);
    }

    public function testNoSigningKeyBackwardsCompatible(): void
    {
        $spool = new Swift_FileSpool($this->spoolDir);

        $msg = $this->createMessage();
        $msg->setSubject('No HMAC');
        $spool->queueMessage($msg);

        $transport = $this->createMock(Swift_Transport::class);
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Swift_Mime_SimpleMessage::class))
            ->willReturn(1);

        $count = $spool->flushQueue($transport);
        $this->assertSame(1, $count);
    }

    public function testQueueMessageRetryLimitExhausted(): void
    {
        // Use a subclass that always returns 'x' to force collisions
        $spool = new class($this->spoolDir) extends Swift_FileSpool {
            protected function getRandomString($count)
            {
                return 'x';
            }
        };
        $spool->setRetryLimit(2);

        // The first iteration tries 'x.message', second tries 'xx.message'
        \file_put_contents($this->spoolDir.'/x.message', 'existing');
        \file_put_contents($this->spoolDir.'/xx.message', 'existing');

        $msg = $this->createMessage();

        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('Unable to create a file');

        $spool->queueMessage($msg);
    }
}
