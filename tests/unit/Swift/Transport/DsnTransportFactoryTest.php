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

    private function getAddressEncoder(Swift_SmtpTransport $transport): Swift_AddressEncoder
    {
        return $transport->getAddressEncoder();
    }
}
