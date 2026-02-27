<?php

class Swift_AddressEncoder_AutoAddressEncoderTest extends \PHPUnit\Framework\TestCase
{
    public function testDelegatestoIdnByDefault()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // ASCII address — both encoders handle it the same
        $result = $encoder->encodeString('user@example.com');
        $this->assertSame('user@example.com', $result);
    }

    public function testIdnEncodesInternationalizedDomain()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // IDN domain — IdnAddressEncoder converts to punycode
        $result = $encoder->encodeString('user@dømæne.dk');
        $this->assertSame('user@xn--dmne-woa0i.dk', $result);
    }

    public function testIdnThrowsOnNonAsciiLocalPart()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // Non-ASCII local-part without SMTPUTF8 — IdnAddressEncoder throws
        $this->expectException(Swift_AddressEncoderException::class);
        $encoder->encodeString('dørmi@example.com');
    }

    public function testUtf8ModeAllowsNonAsciiLocalPart()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();
        $encoder->setSmtpUtf8Available(true);

        // Non-ASCII local-part with SMTPUTF8 — passes through verbatim
        $result = $encoder->encodeString('dørmi@dømæne.dk');
        $this->assertSame('dørmi@dømæne.dk', $result);
    }

    public function testResetToIdnMode()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();
        $encoder->setSmtpUtf8Available(true);
        $encoder->setSmtpUtf8Available(false);

        // Back to IDN mode — non-ASCII local-part throws
        $this->expectException(Swift_AddressEncoderException::class);
        $encoder->encodeString('dørmi@example.com');
    }

    public function testIsSmtpUtf8Available()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        $this->assertFalse($encoder->isSmtpUtf8Available());

        $encoder->setSmtpUtf8Available(true);
        $this->assertTrue($encoder->isSmtpUtf8Available());
    }
}
