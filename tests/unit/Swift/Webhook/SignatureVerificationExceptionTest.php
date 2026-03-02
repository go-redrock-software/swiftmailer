<?php

class Swift_Webhook_SignatureVerificationExceptionTest extends PHPUnit\Framework\TestCase
{
    public function testExceptionMessageContainsProviderName()
    {
        $exception = new Swift_Webhook_SignatureVerificationException('sendgrid');
        $this->assertStringContainsString('sendgrid', $exception->getMessage());
    }

    public function testExceptionExtendsRuntimeException()
    {
        $exception = new Swift_Webhook_SignatureVerificationException('test');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testExceptionMessageContainsVerificationFailed()
    {
        $exception = new Swift_Webhook_SignatureVerificationException('mailgun');
        $this->assertStringContainsString('verification failed', $exception->getMessage());
    }

    public function testDifferentProviderNames()
    {
        $providers = ['sendgrid', 'mailgun', 'postmark', 'brevo', 'resend'];
        foreach ($providers as $provider) {
            $exception = new Swift_Webhook_SignatureVerificationException($provider);
            $this->assertStringContainsString($provider, $exception->getMessage());
        }
    }

    public function testExceptionCodeIsZeroByDefault()
    {
        $exception = new Swift_Webhook_SignatureVerificationException('test');
        $this->assertSame(0, $exception->getCode());
    }

    public function testExceptionIsThrowable()
    {
        $exception = new Swift_Webhook_SignatureVerificationException('test');
        $this->assertInstanceOf(Throwable::class, $exception);
    }

    public function testExceptionCanBeCaught()
    {
        $caught = false;
        try {
            throw new Swift_Webhook_SignatureVerificationException('mailgun');
        } catch (RuntimeException $e) {
            $caught = true;
            $this->assertStringContainsString('mailgun', $e->getMessage());
        }
        $this->assertTrue($caught);
    }

    public function testExceptionMessageIsNotEmpty()
    {
        $exception = new Swift_Webhook_SignatureVerificationException('');
        $this->assertNotEmpty($exception->getMessage());
    }
}
