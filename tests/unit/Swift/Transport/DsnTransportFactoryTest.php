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

        $encoder = $this->getAddressEncoder($transport);
        $this->assertInstanceOf(Swift_AddressEncoder_IdnAddressEncoder::class, $encoder);
    }

    public function testSmtpUtf8EnabledByDefaultInDsn(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587');

        $encoder = $this->getAddressEncoder($transport);
        $this->assertInstanceOf(Swift_AddressEncoder_AutoAddressEncoder::class, $encoder);
    }

    public function testNativeSchemeUsesSendmailTransport(): void
    {
        $transport = $this->factory->fromDsnString('native://default');
        $this->assertInstanceOf(Swift_SendmailTransport::class, $transport);
    }

    /**
     * @dataProvider providerSmtpSchemes
     */
    public function testProviderSmtpSchemesCreateSmtpTransport(string $dsn, string $expectedHost, int $expectedPort): void
    {
        $transport = $this->factory->fromDsnString($dsn);
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
        $this->assertSame($expectedHost, $transport->getHost());
        $this->assertSame($expectedPort, $transport->getPort());
    }

    public static function providerSmtpSchemes(): array
    {
        return [
            ['brevo+smtp://user:pass@default',   'smtp-relay.brevo.com',                587],
            ['sendgrid+smtp://user:pass@default', 'smtp.sendgrid.net',                   587],
            ['mailgun+smtp://user:pass@default',  'smtp.mailgun.org',                    587],
            ['postmark+smtp://user:pass@default', 'smtp.postmarkapp.com',                587],
            ['mailtrap+smtp://user:pass@default', 'live.smtp.mailtrap.io',               587],
            ['resend+smtp://user:pass@default',   'smtp.resend.com',                     465],
            ['amazon+smtp://user:pass@default',   'email-smtp.us-east-1.amazonaws.com',  587],
        ];
    }

    public function testProviderSmtpHostOverride(): void
    {
        $transport = $this->factory->fromDsnString('amazon+smtp://user:pass@email-smtp.eu-west-1.amazonaws.com:587');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
        $this->assertSame('email-smtp.eu-west-1.amazonaws.com', $transport->getHost());
    }

    public function testMailtrapSandboxScheme(): void
    {
        $transport = $this->factory->fromDsnString('mailtrap+sandbox://apikey123@12345');
        $this->assertInstanceOf(Swift_Transport_Api_MailtrapTransport::class, $transport);
    }

    public function testVerifyPeerFalseTriggersWarning(): void
    {
        $warning = null;
        \set_error_handler(function (int $errno, string $errstr) use (&$warning) {
            $warning = $errstr;

            return true;
        }, \E_USER_WARNING);

        try {
            $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?verify_peer=false');
        } finally {
            \restore_error_handler();
        }

        $this->assertNotNull($warning);
        $this->assertStringContainsString('verify_peer=false disables TLS certificate verification', $warning);
    }

    public function testUnknownDsnParameterTriggersWarning(): void
    {
        $warnings = [];
        \set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;

            return true;
        }, \E_USER_WARNING);

        try {
            $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?evil_param=value');
        } finally {
            \restore_error_handler();
        }

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('unknown DSN parameter "evil_param"', $warnings[0]);
    }

    private function getAddressEncoder(Swift_SmtpTransport $transport): Swift_AddressEncoder
    {
        return $transport->getAddressEncoder();
    }
}
