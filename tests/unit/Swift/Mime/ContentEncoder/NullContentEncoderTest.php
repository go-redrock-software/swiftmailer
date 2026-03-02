<?php

class Swift_Mime_ContentEncoder_NullContentEncoderTest extends PHPUnit\Framework\TestCase
{
    public function testNameIsReturnedFromConstructor()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $this->assertEquals('7bit', $encoder->getName());
    }

    public function testNameCanBe8Bit()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('8bit');
        $this->assertEquals('8bit', $encoder->getName());
    }

    public function testEncodeStringReturnsStringUnchanged()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $this->assertEquals('foo bar', $encoder->encodeString('foo bar'));
    }

    public function testEncodeStringWithEmptyString()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $this->assertEquals('', $encoder->encodeString(''));
    }

    public function testEncodeStringIgnoresFirstLineOffset()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $this->assertEquals('test', $encoder->encodeString('test', 10));
    }

    public function testEncodeStringIgnoresMaxLineLength()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $this->assertEquals('test', $encoder->encodeString('test', 0, 76));
    }

    public function testEncodeStringWithBinaryData()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('8bit');
        $binary  = "\x00\x01\x02\xff";
        $this->assertEquals($binary, $encoder->encodeString($binary));
    }

    public function testEncodeStringWithUtf8Content()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('8bit');
        $utf8    = "Héllo wörld";
        $this->assertEquals($utf8, $encoder->encodeString($utf8));
    }

    public function testEncodeByteStreamCopiesDataWithoutModification()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->exactly(2))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('hello world', false);

        $is->expects($this->once())
            ->method('write')
            ->with('hello world');

        $encoder->encodeByteStream($os, $is);
    }

    public function testEncodeByteStreamWithMultipleReads()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->exactly(3))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('chunk1', 'chunk2', false);

        $is->expects($this->exactly(2))
            ->method('write')
            ->willReturnCallback(function ($data) {
                static $calls = 0;
                match (++$calls) {
                    1 => self::assertEquals('chunk1', $data),
                    2 => self::assertEquals('chunk2', $data),
                };
            });

        $encoder->encodeByteStream($os, $is);
    }

    public function testEncodeByteStreamWithEmptyStream()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->once())
            ->method('read')
            ->with(8192)
            ->willReturn(false);

        $is->expects($this->never())
            ->method('write');

        $encoder->encodeByteStream($os, $is);
    }

    public function testCharsetChangedIsNoOp()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        // Should not throw
        $encoder->charsetChanged('iso-8859-1');
        $this->assertEquals('7bit', $encoder->getName());
    }

    public function testImplementsContentEncoderInterface()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $this->assertInstanceOf(Swift_Mime_ContentEncoder::class, $encoder);
    }

    public function testEncodeStringWithNewlines()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $input   = "line1\r\nline2\r\nline3";
        $this->assertEquals($input, $encoder->encodeString($input));
    }

    public function testEncodeStringWithLongLine()
    {
        $encoder = new Swift_Mime_ContentEncoder_NullContentEncoder('7bit');
        $long    = str_repeat('a', 1000);
        $this->assertEquals($long, $encoder->encodeString($long));
    }
}
