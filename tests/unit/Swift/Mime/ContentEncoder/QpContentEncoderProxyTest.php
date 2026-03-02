<?php

class Swift_Mime_ContentEncoder_QpContentEncoderProxyTest extends PHPUnit\Framework\TestCase
{
    private function createProxy(?string $charset = 'utf-8'): Swift_Mime_ContentEncoder_QpContentEncoderProxy
    {
        $safeEncoder  = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        return new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, $charset);
    }

    public function testNameIsQuotedPrintable()
    {
        $proxy = $this->createProxy();
        $this->assertEquals('quoted-printable', $proxy->getName());
    }

    public function testUtf8CharsetUsesNativeEncoder()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $nativeEncoder->expects($this->once())
            ->method('encodeString')
            ->with('test', 0, 0)
            ->willReturn('test');

        $safeEncoder->expects($this->never())
            ->method('encodeString');

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, 'utf-8');
        $this->assertEquals('test', $proxy->encodeString('test'));
    }

    public function testNonUtf8CharsetUsesSafeEncoder()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $safeEncoder->expects($this->once())
            ->method('encodeString')
            ->with('test', 0, 0)
            ->willReturn('test');

        $nativeEncoder->expects($this->never())
            ->method('encodeString');

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, 'iso-8859-1');
        $this->assertEquals('test', $proxy->encodeString('test'));
    }

    public function testCharsetChangedSwitchesEncoder()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $safeEncoder->expects($this->once())
            ->method('charsetChanged')
            ->with('iso-8859-1');

        $safeEncoder->expects($this->once())
            ->method('encodeString')
            ->willReturn('result');

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, 'utf-8');
        $proxy->charsetChanged('iso-8859-1');
        $proxy->encodeString('test');
    }

    public function testEncodeByteStreamDelegatesToCorrectEncoder()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $nativeEncoder->expects($this->once())
            ->method('encodeByteStream')
            ->with($os, $is, 0, 0);

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, 'utf-8');
        $proxy->encodeByteStream($os, $is);
    }

    public function testEncodeByteStreamUseSafeForNonUtf8()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $os = $this->createMock(Swift_OutputByteStream::class);
        $is = $this->createMock(Swift_InputByteStream::class);

        $safeEncoder->expects($this->once())
            ->method('encodeByteStream')
            ->with($os, $is, 0, 0);

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, 'iso-8859-1');
        $proxy->encodeByteStream($os, $is);
    }

    public function testImplementsContentEncoderInterface()
    {
        $proxy = $this->createProxy();
        $this->assertInstanceOf(Swift_Mime_ContentEncoder::class, $proxy);
    }

    public function testCloneCreatesCopiesOfEncoders()
    {
        $proxy  = $this->createProxy();
        $cloned = clone $proxy;
        // Should not be the same instance
        $this->assertNotSame($proxy, $cloned);
        $this->assertEquals('quoted-printable', $cloned->getName());
    }

    public function testEncodeStringPassesFirstLineOffsetAndMaxLength()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $nativeEncoder->expects($this->once())
            ->method('encodeString')
            ->with('test', 10, 76)
            ->willReturn('test');

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, 'utf-8');
        $proxy->encodeString('test', 10, 76);
    }

    public function testNullCharsetUsesSafeEncoder()
    {
        $safeEncoder   = $this->createMock(Swift_Mime_ContentEncoder_QpContentEncoder::class);
        $nativeEncoder = $this->createMock(Swift_Mime_ContentEncoder_NativeQpContentEncoder::class);

        $safeEncoder->expects($this->once())
            ->method('encodeString')
            ->willReturn('test');

        $proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safeEncoder, $nativeEncoder, null);
        $proxy->encodeString('test');
    }
}
