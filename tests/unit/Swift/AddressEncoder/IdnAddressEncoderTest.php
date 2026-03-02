<?php

class Swift_AddressEncoder_IdnAddressEncoderTest extends PHPUnit\Framework\TestCase
{
    private Swift_AddressEncoder_IdnAddressEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new Swift_AddressEncoder_IdnAddressEncoder();
    }

    public function testImplementsAddressEncoderInterface()
    {
        $this->assertInstanceOf(Swift_AddressEncoder::class, $this->encoder);
    }

    public function testAsciiAddressIsUnchanged()
    {
        $this->assertEquals('user@example.com', $this->encoder->encodeString('user@example.com'));
    }

    public function testInternationalizedDomainIsConvertedToPunycode()
    {
        $result = $this->encoder->encodeString('user@dømæne.dk');
        $this->assertEquals('user@xn--dmne-woa0i.dk', $result);
    }

    public function testNonAsciiLocalPartThrowsException()
    {
        $this->expectException(Swift_AddressEncoderException::class);
        $this->encoder->encodeString('dørmi@example.com');
    }

    public function testAddressWithoutAtSignIsReturnedUnchanged()
    {
        $this->assertEquals('justlocalpart', $this->encoder->encodeString('justlocalpart'));
    }

    public function testAsciiDomainIsNotConverted()
    {
        $result = $this->encoder->encodeString('test@ascii-domain.com');
        $this->assertEquals('test@ascii-domain.com', $result);
    }

    public function testEmptyStringIsReturnedAsIs()
    {
        $this->assertEquals('', $this->encoder->encodeString(''));
    }

    public function testAddressWithMultipleAtSignsUsesLast()
    {
        // strrpos finds the last @
        $result = $this->encoder->encodeString('user@name@example.com');
        $this->assertEquals('user@name@example.com', $result);
    }

    public function testGermanUmlautDomain()
    {
        $result = $this->encoder->encodeString('user@münchen.de');
        $this->assertStringStartsWith('user@', $result);
        $this->assertStringContainsString('xn--', $result);
    }

    public function testChineseDomain()
    {
        $result = $this->encoder->encodeString('user@中文.com');
        $this->assertStringStartsWith('user@', $result);
        $this->assertStringContainsString('xn--', $result);
    }

    public function testSpecialCharsInLocalPartAreAccepted()
    {
        $result = $this->encoder->encodeString('user+tag@example.com');
        $this->assertEquals('user+tag@example.com', $result);
    }

    public function testDotsInLocalPartAreAccepted()
    {
        $result = $this->encoder->encodeString('user.name@example.com');
        $this->assertEquals('user.name@example.com', $result);
    }

    public function testNumericLocalPartIsAccepted()
    {
        $result = $this->encoder->encodeString('12345@example.com');
        $this->assertEquals('12345@example.com', $result);
    }

    public function testSubdomainIsHandled()
    {
        $result = $this->encoder->encodeString('user@sub.example.com');
        $this->assertEquals('user@sub.example.com', $result);
    }
}
