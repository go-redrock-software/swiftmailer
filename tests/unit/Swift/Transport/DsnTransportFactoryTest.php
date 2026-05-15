<?php

class Swift_Transport_DsnTransportFactoryTest extends PHPUnit\Framework\TestCase
{
    private Swift_Transport_DsnTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new Swift_Transport_DsnTransportFactory();
    }

    public function testCreateNullTransport(): void
    {
        $transport = $this->factory->fromDsnString('null://default');
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);
    }

    public function testCreateFailoverTransport(): void
    {
        $transport = $this->factory->fromDsnString('failover(null://default null://default)');
        $this->assertInstanceOf(Swift_Transport_FailoverTransport::class, $transport);
    }

    public function testCreateRoundRobinTransport(): void
    {
        $transport = $this->factory->fromDsnString('roundrobin(null://default null://default)');
        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
    }

    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported DSN scheme');
        $this->factory->fromDsnString('unknown://default');
    }

    public function testSmtpUtf8DisabledViaDsn(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?smtputf8=false');

        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);

        // When smtputf8=false, the address encoder should be IdnAddressEncoder (not Auto)
        $encoder = $this->getAddressEncoder($transport);
        $this->assertInstanceOf(Swift_AddressEncoder_IdnAddressEncoder::class, $encoder);
    }

    public function testSmtpUtf8EnabledByDefaultInDsn(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587');

        // Default should use AutoAddressEncoder
        $encoder = $this->getAddressEncoder($transport);
        $this->assertInstanceOf(Swift_AddressEncoder_AutoAddressEncoder::class, $encoder);
    }

    public function testSmtpVerifyPeerFalse(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?verify_peer=false');

        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);

        $options = $transport->getStreamOptions();
        $this->assertFalse($options['ssl']['verify_peer']);
        $this->assertFalse($options['ssl']['verify_peer_name']);
    }

    public function testSmtpPeerFingerprint(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?peer_fingerprint=abc123');

        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);

        $options = $transport->getStreamOptions();
        $this->assertSame('abc123', $options['ssl']['peer_fingerprint']);
    }

    public function testSmtpSourceIp(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?source_ip=192.168.1.1');

        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
        $this->assertSame('192.168.1.1', $transport->getSourceIp());
    }

    private function getAddressEncoder(Swift_SmtpTransport $transport): Swift_AddressEncoder
    {
        return $transport->getAddressEncoder();
    }
}
