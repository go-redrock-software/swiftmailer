<?php

class Swift_Transport_DsnTransportFactoryExtraTest extends PHPUnit\Framework\TestCase
{
    private Swift_Transport_DsnTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new Swift_Transport_DsnTransportFactory();
    }

    // --- Null Transport ---

    public function testCreateNullTransport(): void
    {
        $transport = $this->factory->fromDsnString('null://default');
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);
    }

    public function testNullTransportIsStarted(): void
    {
        $transport = $this->factory->fromDsnString('null://default');
        $this->assertTrue($transport->isStarted());
    }

    // --- Sendmail Transport ---

    public function testCreateSendmailTransport(): void
    {
        $transport = $this->factory->fromDsnString('sendmail://default');
        $this->assertInstanceOf(Swift_SendmailTransport::class, $transport);
    }

    public function testSendmailTransportWithCustomCommand(): void
    {
        $transport = $this->factory->fromDsnString('sendmail://default?command=/usr/sbin/sendmail+-oi+-t');
        $this->assertInstanceOf(Swift_SendmailTransport::class, $transport);
    }

    // --- SMTP Transports ---

    public function testCreateSmtpTransport(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
    }

    public function testCreateSmtpTlsTransport(): void
    {
        $transport = $this->factory->fromDsnString('smtp+tls://user:pass@smtp.example.com:587');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
    }

    public function testCreateSmtpSslTransport(): void
    {
        $transport = $this->factory->fromDsnString('smtp+ssl://user:pass@smtp.example.com:465');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
    }

    public function testSmtpTransportDefaultPort587(): void
    {
        $transport = $this->factory->fromDsnString('smtp+tls://user:pass@smtp.example.com');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
    }

    public function testSmtpSslDefaultPort465(): void
    {
        $transport = $this->factory->fromDsnString('smtp+ssl://user:pass@smtp.example.com');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
    }

    // --- Meta-transport wrappers ---

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

    public function testCreateRetryTransport(): void
    {
        $transport = $this->factory->fromDsnString('retry(null://default)');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }

    public function testRetryTransportWrapsInnerTransport(): void
    {
        $transport = $this->factory->fromDsnString('retry(null://default)');
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport->getInnerTransport());
    }

    public function testRetryWithNestedFailover(): void
    {
        $transport = $this->factory->fromDsnString('retry(failover(null://default null://default))');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
        $this->assertInstanceOf(Swift_Transport_FailoverTransport::class, $transport->getInnerTransport());
    }

    public function testRetryWithNestedRoundRobin(): void
    {
        $transport = $this->factory->fromDsnString('retry(roundrobin(null://default null://default))');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport->getInnerTransport());
    }

    // --- Retry query parameters ---

    public function testRetryQueryParametersCreateRetryWrapper(): void
    {
        $transport = $this->factory->fromDsnString('null://default?retries=5&retry_delay=2000');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport->getInnerTransport());
    }

    public function testRetryQueryParamZeroDoesNotWrap(): void
    {
        $transport = $this->factory->fromDsnString('null://default?retries=0');
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);
        $this->assertNotInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }

    public function testNoRetryParamDoesNotWrap(): void
    {
        $transport = $this->factory->fromDsnString('null://default');
        $this->assertNotInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }

    // --- Invalid DSN ---

    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported DSN scheme');
        $this->factory->fromDsnString('unknown://default');
    }

    // --- SMTP features ---

    public function testSmtpUtf8DisabledViaDsn(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?smtputf8=false');
        $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);
        $this->assertInstanceOf(Swift_AddressEncoder_IdnAddressEncoder::class, $transport->getAddressEncoder());
    }

    public function testSmtpUtf8EnabledByDefault(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587');
        $this->assertInstanceOf(Swift_AddressEncoder_AutoAddressEncoder::class, $transport->getAddressEncoder());
    }

    // --- API transports via factory ---

    public function testCreateSendgridTransport(): void
    {
        $transport = $this->factory->fromDsnString('sendgrid://SG.test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_SendgridTransport::class, $transport);
    }

    public function testCreatePostmarkTransport(): void
    {
        $transport = $this->factory->fromDsnString('postmark://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_PostMarkTransport::class, $transport);
    }

    public function testCreateBrevoTransport(): void
    {
        $transport = $this->factory->fromDsnString('brevo://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_BrevoTransport::class, $transport);
    }

    public function testCreateResendTransport(): void
    {
        $transport = $this->factory->fromDsnString('resend://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_ResendTransport::class, $transport);
    }

    public function testCreateMailpaceTransport(): void
    {
        $transport = $this->factory->fromDsnString('mailpace://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_MailPaceTransport::class, $transport);
    }

    public function testCreateMailchimpTransport(): void
    {
        $transport = $this->factory->fromDsnString('mailchimp://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_MailChimpTransport::class, $transport);
    }

    public function testCreateMailersendTransport(): void
    {
        $transport = $this->factory->fromDsnString('mailersend://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_MailerSendTransport::class, $transport);
    }

    public function testCreateAhaSendTransport(): void
    {
        $transport = $this->factory->fromDsnString('ahasend://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_AhaSendTransport::class, $transport);
    }

    public function testCreateMailomatTransport(): void
    {
        $transport = $this->factory->fromDsnString('mailomat://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_MailomatTransport::class, $transport);
    }

    public function testCreateSweegoTransport(): void
    {
        $transport = $this->factory->fromDsnString('sweego://test-key@default');
        $this->assertInstanceOf(Swift_Transport_Api_SweegoTransport::class, $transport);
    }

    // --- Failover with different transports ---

    public function testFailoverWithThreeTransports(): void
    {
        $transport = $this->factory->fromDsnString('failover(null://default null://default null://default)');
        $this->assertInstanceOf(Swift_Transport_FailoverTransport::class, $transport);
        $this->assertCount(3, $transport->getTransports());
    }

    public function testRoundRobinWithThreeTransports(): void
    {
        $transport = $this->factory->fromDsnString('roundrobin(null://default null://default null://default)');
        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
        $this->assertCount(3, $transport->getTransports());
    }

    // --- Retry with SMTP transport ---

    public function testRetryWrapperWithSmtp(): void
    {
        $transport = $this->factory->fromDsnString('retry(smtp://user:pass@smtp.example.com:587)');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }

    public function testSmtpRetryQueryParams(): void
    {
        $transport = $this->factory->fromDsnString('smtp://user:pass@smtp.example.com:587?retries=2&retry_delay=500');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }
}
