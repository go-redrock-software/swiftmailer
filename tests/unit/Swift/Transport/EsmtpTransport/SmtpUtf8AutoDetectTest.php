<?php

class Swift_Transport_EsmtpTransport_SmtpUtf8AutoDetectTest extends \PHPUnit\Framework\TestCase
{
    public function testAutoEncoderIsSetAfterCapabilityParsing()
    {
        // This test verifies that after doHeloCommand() parses capabilities
        // containing SMTPUTF8, the AutoAddressEncoder is switched to UTF-8 mode.

        $buf = $this->createMock(Swift_Transport_IoBuffer::class);
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $autoEncoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // Pass the auto encoder to the transport
        $transport = new Swift_Transport_EsmtpTransport(
            $buf,
            [new Swift_Transport_Esmtp_SmtpUtf8Handler()],
            $dispatcher,
            'localhost',
            $autoEncoder,
        );

        // Before connection, UTF-8 should not be available
        $this->assertFalse($autoEncoder->isSmtpUtf8Available());
    }

    public function testAutoEncoderDefaultsToIdn()
    {
        $buf = $this->createMock(Swift_Transport_IoBuffer::class);
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $autoEncoder = new Swift_AddressEncoder_AutoAddressEncoder();

        $transport = new Swift_Transport_EsmtpTransport(
            $buf,
            [],
            $dispatcher,
            'localhost',
            $autoEncoder,
        );

        // IDN encoding should work
        $this->assertSame('user@example.com', $autoEncoder->encodeString('user@example.com'));

        // Non-ASCII local-part should fail
        $this->expectException(Swift_AddressEncoderException::class);
        $autoEncoder->encodeString('dørmi@example.com');
    }
}
