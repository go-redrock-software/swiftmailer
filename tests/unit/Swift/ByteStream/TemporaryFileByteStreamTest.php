<?php

class Swift_ByteStream_TemporaryFileByteStreamTest extends PHPUnit\Framework\TestCase
{
    public function testExtendsFileByteStream()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $this->assertInstanceOf(Swift_ByteStream_FileByteStream::class, $stream);
    }

    public function testCreatesTemporaryFile()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $this->assertFileExists($stream->getPath());
    }

    public function testGetContentReturnsWrittenData()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $stream->write('hello world');
        $stream->flushBuffers();
        $this->assertEquals('hello world', $stream->getContent());
    }

    public function testGetContentWithEmptyFile()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $this->assertEquals('', $stream->getContent());
    }

    public function testGetContentWithMultipleWrites()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $stream->write('foo');
        $stream->write('bar');
        $stream->flushBuffers();
        $this->assertEquals('foobar', $stream->getContent());
    }

    public function testDestructorRemovesFile()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $path   = $stream->getPath();
        $this->assertFileExists($path);
        unset($stream);
        $this->assertFileDoesNotExist($path);
    }

    public function testSleepThrowsBadMethodCallException()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $this->expectException(BadMethodCallException::class);
        $stream->__sleep();
    }

    public function testWakeupThrowsBadMethodCallException()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $this->expectException(BadMethodCallException::class);
        $stream->__wakeup();
    }

    public function testPathIsInSystemTempDir()
    {
        $stream  = new Swift_ByteStream_TemporaryFileByteStream();
        $tempDir = realpath(sys_get_temp_dir());
        $path    = realpath(dirname($stream->getPath()));
        $this->assertStringStartsWith($tempDir, $path);
    }

    public function testPathContainsFileByteStreamPrefix()
    {
        $stream   = new Swift_ByteStream_TemporaryFileByteStream();
        $basename = basename($stream->getPath());
        $this->assertStringStartsWith('FileByteStream', $basename);
    }

    public function testWriteBinaryData()
    {
        $stream = new Swift_ByteStream_TemporaryFileByteStream();
        $binary = "\x00\x01\x02\xff\xfe";
        $stream->write($binary);
        $stream->flushBuffers();
        $this->assertEquals($binary, $stream->getContent());
    }

    public function testMultipleInstancesHaveDifferentPaths()
    {
        $s1 = new Swift_ByteStream_TemporaryFileByteStream();
        $s2 = new Swift_ByteStream_TemporaryFileByteStream();
        $this->assertNotEquals($s1->getPath(), $s2->getPath());
    }
}
