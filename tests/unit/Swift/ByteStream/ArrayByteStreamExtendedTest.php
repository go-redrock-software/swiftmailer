<?php

class Swift_ByteStream_ArrayByteStreamExtendedTest extends PHPUnit\Framework\TestCase
{
    public function testConstructWithStringPopulatesStream()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('hello');
        $this->assertEquals('hello', $stream->read(10));
    }

    public function testConstructWithArrayPopulatesStream()
    {
        $stream = new Swift_ByteStream_ArrayByteStream(['h', 'i']);
        $this->assertEquals('hi', $stream->read(10));
    }

    public function testConstructWithNullCreatesEmptyStream()
    {
        $stream = new Swift_ByteStream_ArrayByteStream(null);
        $this->assertFalse($stream->read(1));
    }

    public function testConstructWithNoArgCreatesEmptyStream()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $this->assertFalse($stream->read(1));
    }

    public function testWriteAppends()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('ab');
        $stream->write('cd');
        $this->assertEquals('abcd', $stream->read(10));
    }

    public function testWriteArrayInput()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $stream->write(['a', 'b', 'c']);
        $this->assertEquals('abc', $stream->read(10));
    }

    public function testReadReturnsRequestedLength()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abcdef');
        $this->assertEquals('abc', $stream->read(3));
    }

    public function testReadAdvancesPointer()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abcdef');
        $stream->read(2);
        $this->assertEquals('cd', $stream->read(2));
    }

    public function testReadReturnsFalseWhenExhausted()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('a');
        $stream->read(1);
        $this->assertFalse($stream->read(1));
    }

    public function testReadReturnsRemainingWhenLessAvailable()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('ab');
        $this->assertEquals('ab', $stream->read(100));
    }

    public function testSetReadPointer()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abcdef');
        $stream->setReadPointer(3);
        $this->assertEquals('def', $stream->read(10));
    }

    public function testSetReadPointerToStart()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abc');
        $stream->read(2);
        $stream->setReadPointer(0);
        $this->assertEquals('abc', $stream->read(10));
    }

    public function testSetReadPointerBeyondEndClampsToEnd()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abc');
        $stream->setReadPointer(100);
        $this->assertFalse($stream->read(1));
    }

    public function testSetReadPointerNegativeClampsToZero()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abc');
        $stream->setReadPointer(-5);
        $this->assertEquals('abc', $stream->read(10));
    }

    public function testFlushBuffersClearsData()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('data');
        $stream->flushBuffers();
        $this->assertFalse($stream->read(1));
    }

    public function testFlushBuffersResetsPointer()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('abc');
        $stream->read(2);
        $stream->flushBuffers();
        $stream->write('new');
        $this->assertEquals('new', $stream->read(10));
    }

    public function testBindMirrorsWrites()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->once())->method('write')->with('data');
        $stream->bind($mirror);
        $stream->write('data');
    }

    public function testUnbindStopsMirroring()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->never())->method('write');
        $stream->bind($mirror);
        $stream->unbind($mirror);
        $stream->write('data');
    }

    public function testFlushBuffersMirrorsToBinds()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->once())->method('flushBuffers');
        $stream->bind($mirror);
        $stream->flushBuffers();
    }

    public function testCommitIsNoOp()
    {
        $stream = new Swift_ByteStream_ArrayByteStream('data');
        $stream->commit();
        $this->assertEquals('data', $stream->read(10));
    }

    public function testImplementsInputAndOutputByteStream()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $this->assertInstanceOf(Swift_InputByteStream::class, $stream);
        $this->assertInstanceOf(Swift_OutputByteStream::class, $stream);
    }

    public function testWriteEmptyString()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $stream->write('');
        $this->assertFalse($stream->read(1));
    }

    public function testMultipleBinds()
    {
        $stream  = new Swift_ByteStream_ArrayByteStream();
        $mirror1 = $this->createMock(Swift_InputByteStream::class);
        $mirror2 = $this->createMock(Swift_InputByteStream::class);
        $mirror1->expects($this->once())->method('write')->with('data');
        $mirror2->expects($this->once())->method('write')->with('data');
        $stream->bind($mirror1);
        $stream->bind($mirror2);
        $stream->write('data');
    }

    public function testLargeWrite()
    {
        $stream = new Swift_ByteStream_ArrayByteStream();
        $data   = str_repeat('x', 10000);
        $stream->write($data);
        $result = '';
        while (false !== $chunk = $stream->read(1024)) {
            $result .= $chunk;
        }
        $this->assertEquals($data, $result);
    }

    public function testBinaryData()
    {
        $stream = new Swift_ByteStream_ArrayByteStream("\x00\x01\xFF");
        $this->assertEquals("\x00\x01\xFF", $stream->read(10));
    }
}
