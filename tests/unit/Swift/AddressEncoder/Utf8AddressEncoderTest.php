<?php

class Swift_AddressEncoder_Utf8AddressEncoderTest extends PHPUnit\Framework\TestCase
{
    private Swift_AddressEncoder_Utf8AddressEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new Swift_AddressEncoder_Utf8AddressEncoder();
    }

    public function testImplementsAddressEncoderInterface()
    {
        $this->assertInstanceOf(Swift_AddressEncoder::class, $this->encoder);
    }

    public function testAsciiAddressReturnedVerbatim()
    {
        $this->assertEquals('user@example.com', $this->encoder->encodeString('user@example.com'));
    }

    public function testNonAsciiLocalPartReturnedVerbatim()
    {
        $address = 'dørmi@example.com';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }

    public function testNonAsciiDomainReturnedVerbatim()
    {
        $address = 'user@dømæne.dk';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }

    public function testFullyUnicodeAddressReturnedVerbatim()
    {
        $address = 'dørmi@dømæne.dk';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }

    public function testEmptyStringReturnedVerbatim()
    {
        $this->assertEquals('', $this->encoder->encodeString(''));
    }

    public function testChineseCharactersReturnedVerbatim()
    {
        $address = '用户@中文.com';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }

    public function testJapaneseCharactersReturnedVerbatim()
    {
        $address = 'ユーザー@example.jp';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }

    public function testGermanUmlautsReturnedVerbatim()
    {
        $address = 'müller@münchen.de';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }

    public function testNoAtSignReturnedVerbatim()
    {
        $this->assertEquals('noatsign', $this->encoder->encodeString('noatsign'));
    }

    public function testSpecialCharsReturnedVerbatim()
    {
        $address = 'user+tag@example.com';
        $this->assertEquals($address, $this->encoder->encodeString($address));
    }
}
