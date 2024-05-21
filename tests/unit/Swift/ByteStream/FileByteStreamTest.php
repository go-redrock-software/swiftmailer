<?php
/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 *
 */

class Swift_ByteStream_FileByteStreamTest extends \PHPUnit\Framework\TestCase
{
    private $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'swift_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testFilePathCannotBeEmpty()
    {
        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('The path cannot be empty');
        new Swift_ByteStream_FileByteStream('');
    }

    public function testFileIsReadable()
    {
        $bs = new Swift_ByteStream_FileByteStream($this->tmpFile);
        $this->assertEquals($this->tmpFile, $bs->getPath());
    }

    public function testFileIsWritable()
    {
        $bs = new Swift_ByteStream_FileByteStream($this->tmpFile, true);
        $bs->write('test');
        $this->assertEquals('test', file_get_contents($this->tmpFile));
    }

    public function testReadFromNonReadableFileThrowsException()
    {
        file_put_contents($this->tmpFile, 'test');
        chmod($this->tmpFile, 0000);

        $bs = new Swift_ByteStream_FileByteStream($this->tmpFile);

        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('Unable to open file for reading ['.$this->tmpFile.']');
        $bs->read(4);
    }

    public function testWriteToNonWritableFileThrowsException()
    {
        file_put_contents($this->tmpFile, 'test');
        chmod($this->tmpFile, 0400);

        $bs = new Swift_ByteStream_FileByteStream($this->tmpFile, true);

        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('Unable to open file for writing ['.$this->tmpFile.']');
        $bs->write('test2');
    }

    public function testCopyReadStream()
    {
        file_put_contents($this->tmpFile, 'abcdef');

        // Create an instance of Swift_ByteStream_FileByteStream
        $bs = new Swift_ByteStream_FileByteStream($this->tmpFile);
        // Use reflection to call a private method
        $reflection = new \ReflectionClass('Swift_ByteStream_FileByteStream');
        $method = $reflection->getMethod('copyReadStream');
        $method2 = $reflection->getMethod('getReadHandle');
        $method2->setAccessible(true);
        $method2->invoke($bs);
        $method->setAccessible(true); // Allow access to the private method
        // Call the method and capture any possible exception
        try {
            $method->invoke($bs);
        } catch (Swift_IoException $e) {
            $this->fail('Exception thrown by copyReadStream: '.$e->getMessage());
        }

        $this->assertEquals('abcdef', $bs->read(8192), 'Read stream not copied correctly');
    }
}