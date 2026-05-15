<?php

class Swift_Mime_ContentEncoder_NativeQpContentEncoderTest extends PHPUnit\Framework\TestCase
{
    public function testNameIsQuotedPrintable()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $this->assertEquals('quoted-printable', $encoder->getName());
    }

    public function testDefaultCharsetIsUtf8()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $this->assertEquals('simple ascii', $encoder->encodeString('simple ascii'));
    }

    public function testExplicitUtf8Charset()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder('utf-8');
        $this->assertIsString($encoder->encodeString('test'));
    }

    public function testEncodeStringWithAsciiOnly()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString('Hello World');
        $this->assertEquals('Hello World', $result);
    }

    public function testEncodeStringWithSpecialChars()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString('=');
        $this->assertEquals('=3D', $result);
    }

    public function testEncodeStringWithHighBitChars()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString("\xC3\xA9"); // e-acute in utf-8
        $this->assertStringContainsString('=C3=A9', $result);
    }

    public function testEncodeStringThrowsForNonUtf8Charset()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder('iso-8859-1');
        $this->expectException(RuntimeException::class);
        $encoder->encodeString('test');
    }

    public function testEncodeByteStreamThrowsForNonUtf8Charset()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder('iso-8859-1');

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $this->expectException(RuntimeException::class);
        $encoder->encodeByteStream($os, $is);
    }

    public function testCharsetChangedUpdatesCharset()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $encoder->charsetChanged('iso-8859-1');

        $this->expectException(RuntimeException::class);
        $encoder->encodeString('test');
    }

    public function testCharsetChangedBackToUtf8Works()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $encoder->charsetChanged('iso-8859-1');
        $encoder->charsetChanged('utf-8');

        $this->assertEquals('test', $encoder->encodeString('test'));
    }

    public function testEncodeByteStreamWithUtf8()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->exactly(2))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('Hello', false);

        $is->expects($this->once())
            ->method('write')
            ->with('Hello');

        $encoder->encodeByteStream($os, $is);
    }

    public function testEncodeByteStreamReadsUntilFalse()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $os->expects($this->exactly(3))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('part1', 'part2', false);

        $is->expects($this->once())
            ->method('write');

        $encoder->encodeByteStream($os, $is);
    }

    public function testEncodeStringWithEmptyString()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString('');
        $this->assertEquals('', $result);
    }

    public function testEncodeStringTrailingSpace()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString('test ');
        $this->assertStringEndsWith('=20', $result);
    }

    public function testEncodeStringTrailingTab()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString("test\t");
        $this->assertStringEndsWith('=09', $result);
    }

    public function testEncodeStringWithCRLF()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString("line1\r\nline2");
        $this->assertStringContainsString("\r\n", $result);
    }

    public function testImplementsContentEncoderInterface()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $this->assertInstanceOf(Swift_Mime_ContentEncoder::class, $encoder);
    }

    public function testEncodeStringFirstLineOffsetIgnored()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString('test', 10, 0);
        $this->assertIsString($result);
    }

    public function testEncodeStringWithNumbers()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        $result  = $encoder->encodeString('12345');
        $this->assertEquals('12345', $result);
    }

    public function testNullCharsetDefaultsToUtf8()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder(null);
        $this->assertEquals('test', $encoder->encodeString('test'));
    }

    public function testStandardizeTrailingTabIsEncoded()
    {
        $encoder = new Swift_Mime_ContentEncoder_NativeQpContentEncoder();
        // A string ending with a tab should have it encoded as =09
        $result = $encoder->encodeString("test\t");
        $this->assertStringEndsWith('=09', $result);
    }
}
