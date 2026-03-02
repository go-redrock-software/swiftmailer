<?php

class Swift_Mime_ContentEncoder_RawContentEncoderTest extends PHPUnit\Framework\TestCase
{
    public function testNameIsRaw()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $this->assertEquals('raw', $encoder->getName());
    }

    public function testEncodeStringReturnsInputUnchanged()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $this->assertEquals('hello world', $encoder->encodeString('hello world'));
    }

    public function testEncodeStringWithEmptyString()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $this->assertEquals('', $encoder->encodeString(''));
    }

    public function testEncodeStringIgnoresOffsetAndLineLength()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $this->assertEquals('test', $encoder->encodeString('test', 10, 76));
    }

    public function testEncodeStringWithBinaryData()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $binary  = "\x00\x01\x02\x80\xff";
        $this->assertEquals($binary, $encoder->encodeString($binary));
    }

    public function testEncodeStringWithUtf8()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $utf8    = "Привет мир";
        $this->assertEquals($utf8, $encoder->encodeString($utf8));
    }

    public function testEncodeByteStreamCopiesData()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->exactly(2))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('raw data', false);

        $is->expects($this->once())
            ->method('write')
            ->with('raw data');

        $encoder->encodeByteStream($os, $is);
    }

    public function testEncodeByteStreamWithMultipleChunks()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->exactly(4))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('a', 'b', 'c', false);

        $is->expects($this->exactly(3))
            ->method('write');

        $encoder->encodeByteStream($os, $is);
    }

    public function testEncodeByteStreamWithEmptyStream()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->once())
            ->method('read')
            ->willReturn(false);

        $is->expects($this->never())
            ->method('write');

        $encoder->encodeByteStream($os, $is);
    }

    public function testCharsetChangedIsNoOp()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $encoder->charsetChanged('utf-8');
        $this->assertEquals('raw', $encoder->getName());
    }

    public function testImplementsContentEncoderInterface()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $this->assertInstanceOf(Swift_Mime_ContentEncoder::class, $encoder);
    }

    public function testEncodeStringPreservesNewlines()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $input   = "line1\r\nline2\nline3\rline4";
        $this->assertEquals($input, $encoder->encodeString($input));
    }

    public function testEncodeStringPreservesWhitespace()
    {
        $encoder = new Swift_Mime_ContentEncoder_RawContentEncoder();
        $input   = "  spaces\ttabs\t  mixed  ";
        $this->assertEquals($input, $encoder->encodeString($input));
    }
}
