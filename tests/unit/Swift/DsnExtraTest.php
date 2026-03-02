<?php

use Nyholm\Dsn\Configuration\Dsn;

class Swift_DsnExtraTest extends \PHPUnit\Framework\TestCase
{
    public function testGetSchemeReturnsNullScheme(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('null');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn('default');
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertSame('null', $swiftDsn->getScheme());
    }

    public function testGetTransportClassForAllSchemes(): void
    {
        $schemes = [
            'null' => Swift_Transport_NullTransport::class,
            'smtp' => Swift_Transport_EsmtpTransport::class,
            'smtp+tls' => Swift_Transport_EsmtpTransport::class,
            'smtp+ssl' => Swift_Transport_EsmtpTransport::class,
            'sendgrid' => Swift_Transport_Api_SendgridTransport::class,
            'mailgun' => Swift_Transport_Api_MailGunTransport::class,
            'postmark' => Swift_Transport_Api_PostMarkTransport::class,
            'brevo' => Swift_Transport_Api_BrevoTransport::class,
            'resend' => Swift_Transport_Api_ResendTransport::class,
            'mailjet' => Swift_Transport_Api_MailJetTransport::class,
            'infobip' => Swift_Transport_Api_InfoBipTransport::class,
            'mailpace' => Swift_Transport_Api_MailPaceTransport::class,
            'mailchimp' => Swift_Transport_Api_MailChimpTransport::class,
            'mailersend' => Swift_Transport_Api_MailerSendTransport::class,
            'scaleway' => Swift_Transport_Api_ScalewayTransport::class,
            'azure' => Swift_Transport_Api_AzureTransport::class,
            'amazon+api' => Swift_Transport_Api_AmazonSesApiTransport::class,
            'amazon+http' => Swift_Transport_Api_AmazonSesHttpTransport::class,
            'gmail+api' => Swift_Transport_Api_GoogleTransport::class,
            'gmail+smtp' => Swift_Transport_EsmtpTransport::class,
            'microsoft-graph' => Swift_Transport_Api_MicrosoftGraphTransport::class,
            'ahasend' => Swift_Transport_Api_AhaSendTransport::class,
            'mailomat' => Swift_Transport_Api_MailomatTransport::class,
            'mailtrap' => Swift_Transport_Api_MailtrapTransport::class,
            'postal' => Swift_Transport_Api_PostalTransport::class,
            'sweego' => Swift_Transport_Api_SweegoTransport::class,
        ];

        foreach ($schemes as $scheme => $expectedClass) {
            $dsn = $this->createMock(Dsn::class);
            $dsn->method('getScheme')->willReturn($scheme);
            $dsn->method('getUser')->willReturn(null);
            $dsn->method('getPassword')->willReturn(null);
            $dsn->method('getHost')->willReturn('host');
            $dsn->method('getPort')->willReturn(null);
            $dsn->method('getParameters')->willReturn([]);

            $swiftDsn = new Swift_Dsn($dsn);
            $this->assertSame($expectedClass, $swiftDsn->getTransportClass(), "Failed for scheme: {$scheme}");
        }
    }

    public function testGetParameterReturnsNullForMissing(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('smtp');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn('host');
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn(['key' => 'val']);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertNull($swiftDsn->getParameter('nonexistent'));
        $this->assertSame('val', $swiftDsn->getParameter('key'));
    }

    public function testGetHostReturnsNull(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('null');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn(null);
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertNull($swiftDsn->getHost());
    }

    public function testGetPortReturnsNull(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('null');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn(null);
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertNull($swiftDsn->getPort());
    }

    public function testGetUserReturnsNull(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('null');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn(null);
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertNull($swiftDsn->getUser());
    }

    public function testGetPasswordReturnsNull(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('null');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn(null);
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertNull($swiftDsn->getPassword());
    }

    public function testGetParametersReturnsEmptyArray(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('null');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn(null);
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertSame([], $swiftDsn->getParameters());
    }

    public function testGetParametersWithMultipleParams(): void
    {
        $params = ['retries' => '3', 'retry_delay' => '1000', 'verify_peer' => 'false'];
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('smtp');
        $dsn->method('getUser')->willReturn('user');
        $dsn->method('getPassword')->willReturn('pass');
        $dsn->method('getHost')->willReturn('smtp.example.com');
        $dsn->method('getPort')->willReturn(587);
        $dsn->method('getParameters')->willReturn($params);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertSame($params, $swiftDsn->getParameters());
        $this->assertSame('3', $swiftDsn->getParameter('retries'));
        $this->assertSame('1000', $swiftDsn->getParameter('retry_delay'));
        $this->assertSame('false', $swiftDsn->getParameter('verify_peer'));
    }

    public function testUnsupportedSchemeThrowsInvalidArgumentException(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('ftp');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn('host');
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported DSN scheme "ftp"');
        $swiftDsn->getTransportClass();
    }

    public function testUnsupportedSchemeExceptionContainsSupportedList(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('imap');
        $dsn->method('getUser')->willReturn(null);
        $dsn->method('getPassword')->willReturn(null);
        $dsn->method('getHost')->willReturn('host');
        $dsn->method('getPort')->willReturn(null);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);

        try {
            $swiftDsn->getTransportClass();
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Supported:', $e->getMessage());
            $this->assertStringContainsString('smtp', $e->getMessage());
            $this->assertStringContainsString('sendgrid', $e->getMessage());
        }
    }

    public function testGetPortWithValue(): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn('smtp');
        $dsn->method('getUser')->willReturn('user');
        $dsn->method('getPassword')->willReturn('pass');
        $dsn->method('getHost')->willReturn('host');
        $dsn->method('getPort')->willReturn(2525);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertSame(2525, $swiftDsn->getPort());
    }
}
