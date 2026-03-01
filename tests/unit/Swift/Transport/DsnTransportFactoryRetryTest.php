<?php

class Swift_Transport_DsnTransportFactoryRetryTest extends PHPUnit\Framework\TestCase
{
    private Swift_Transport_DsnTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new Swift_Transport_DsnTransportFactory();
    }

    public function testRetryWrapperCreatesRetryTransport()
    {
        $transport = $this->factory->fromDsnString('retry(null://default)');

        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport->getInnerTransport());
    }

    public function testRetryWrapperWithNestedFailover()
    {
        $transport = $this->factory->fromDsnString('retry(failover(null://default null://default))');

        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
        $this->assertInstanceOf(Swift_Transport_FailoverTransport::class, $transport->getInnerTransport());
    }

    public function testRetryQueryParametersOnInnerDsn()
    {
        $transport = $this->factory->fromDsnString('null://default?retries=5&retry_delay=2000');

        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport->getInnerTransport());
    }

    public function testRetryQueryParametersDefaultValues()
    {
        // Without retry params, no wrapper should be added
        $transport = $this->factory->fromDsnString('null://default');

        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);
        $this->assertNotInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }

    public function testRetryWrapperWithSmtpDsn()
    {
        $transport = $this->factory->fromDsnString('retry(smtp://user:pass@smtp.example.com:587)');

        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);
    }
}
