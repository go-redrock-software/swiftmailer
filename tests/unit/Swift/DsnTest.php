<?php
/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 *
 */

use Nyholm\Dsn\Configuration\Dsn;
use PHPUnit\Framework\TestCase;

class Swift_DsnTest extends TestCase
{
    private Swift_Dsn $swiftDsn;

    private $dsn;

    protected function setUp(): void
    {
        // Mock the Dsn object
        $this->dsn = $this->createMock(Dsn::class);

        // Define return values for the Dsn object
        $this->dsn->method('getScheme')->willReturn('smtp');
        $this->dsn->method('getUser')->willReturn('user');
        $this->dsn->method('getPassword')->willReturn('pass');
        $this->dsn->method('getHost')->willReturn('localhost');
        $this->dsn->method('getPort')->willReturn(25);

        $this->dsn->method('getParameters')->willReturn(['param1' => 'value1']);

        $this->swiftDsn = new Swift_Dsn($this->dsn);
    }

    public function testGetScheme(): void
    {
        $this->assertEquals('smtp', $this->swiftDsn->getScheme());
    }

    public function testGetUser(): void
    {
        $this->assertEquals('user', $this->swiftDsn->getUser());
    }

    public function testGetPassword(): void
    {
        $this->assertEquals('pass', $this->swiftDsn->getPassword());
    }

    public function testGetHost(): void
    {
        $this->assertEquals('localhost', $this->swiftDsn->getHost());
    }

    public function testGetPort(): void
    {
        $this->assertEquals(25, $this->swiftDsn->getPort());
    }

    public function testGetParameters(): void
    {
        $this->assertEquals(['param1' => 'value1'], $this->swiftDsn->getParameters());
    }

    public function testGetParameter(): void
    {
        $this->assertEquals('value1', $this->swiftDsn->getParameter('param1'));
        $this->assertNull($this->swiftDsn->getParameter('nonExistParam'));
    }

    /**
     * @dataProvider transportClassProvider
     */
    public function testGetTransportClass(string $scheme, string $expectedClass): void
    {
        $dsn = $this->createMock(Dsn::class);
        $dsn->method('getScheme')->willReturn($scheme);
        $dsn->method('getUser')->willReturn('user');
        $dsn->method('getPassword')->willReturn('pass');
        $dsn->method('getHost')->willReturn('host');
        $dsn->method('getPort')->willReturn(443);
        $dsn->method('getParameters')->willReturn([]);

        $swiftDsn = new Swift_Dsn($dsn);
        $this->assertEquals($expectedClass, $swiftDsn->getTransportClass());
    }

    public function transportClassProvider(): array
    {
        return [
            ['microsoft-graph', Swift_Transport_Api_MicrosoftGraphTransport::class],
            ['gmail+api', Swift_Transport_Api_GoogleTransport::class],
            ['gmail+smtp', Swift_Transport_EsmtpTransport::class],
            ['amazon+api', Swift_Transport_Api_AmazonSesApiTransport::class],
            ['amazon+http', Swift_Transport_Api_AmazonSesHttpTransport::class],
            ['azure', Swift_Transport_Api_AzureTransport::class],
            ['brevo', Swift_Transport_Api_BrevoTransport::class],
            ['infobip', Swift_Transport_Api_InfoBipTransport::class],
            ['mailpace', Swift_Transport_Api_MailPaceTransport::class],
            ['mailchimp', Swift_Transport_Api_MailChimpTransport::class],
            ['mailersend', Swift_Transport_Api_MailerSendTransport::class],
            ['mailgun', Swift_Transport_Api_MailGunTransport::class],
            ['mailjet', Swift_Transport_Api_MailJetTransport::class],
            ['postmark', Swift_Transport_Api_PostMarkTransport::class],
            ['resend', Swift_Transport_Api_ResendTransport::class],
            ['scaleway', Swift_Transport_Api_ScalewayTransport::class],
            ['sendgrid', Swift_Transport_Api_SendgridTransport::class],
        ];
    }
}
