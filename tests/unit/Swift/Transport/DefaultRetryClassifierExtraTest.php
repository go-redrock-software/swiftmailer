<?php

class Swift_Transport_DefaultRetryClassifierExtraTest extends PHPUnit\Framework\TestCase
{
    private Swift_Transport_DefaultRetryClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new Swift_Transport_DefaultRetryClassifier();
    }

    public function testImplementsRetryClassifierInterface(): void
    {
        $this->assertInstanceOf(Swift_Transport_RetryClassifier::class, $this->classifier);
    }

    // --- SMTP Retryable Codes ---

    public function testSmtp421IsRetryable(): void
    {
        $e = new Swift_TransportException('Service not available', 421);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testSmtp450IsRetryable(): void
    {
        $e = new Swift_TransportException('Mailbox unavailable', 450);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testSmtp451IsRetryable(): void
    {
        $e = new Swift_TransportException('Local error', 451);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testSmtp452IsRetryable(): void
    {
        $e = new Swift_TransportException('Insufficient storage', 452);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    // --- HTTP Retryable Codes ---

    public function testHttp429IsRetryable(): void
    {
        $e = new Swift_TransportException('Too Many Requests', 429);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testHttp502IsRetryable(): void
    {
        $e = new Swift_TransportException('Bad Gateway', 502);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testHttp503IsRetryable(): void
    {
        $e = new Swift_TransportException('Service Unavailable', 503);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testHttp504IsRetryable(): void
    {
        $e = new Swift_TransportException('Gateway Timeout', 504);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    // --- Code 0 (Connection level) ---

    public function testCode0IsRetryable(): void
    {
        $e = new Swift_TransportException('Unknown error', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithConnectionTimeout(): void
    {
        $e = new Swift_TransportException('Connection timed out', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithConnectionRefused(): void
    {
        $e = new Swift_TransportException('Connection refused', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithConnectionReset(): void
    {
        $e = new Swift_TransportException('Connection reset by peer', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithBrokenPipe(): void
    {
        $e = new Swift_TransportException('Broken pipe', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithStreamSocketError(): void
    {
        $e = new Swift_TransportException('stream_socket_client(): unable to connect', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithRateLimitMessage(): void
    {
        $e = new Swift_TransportException('API error: rate limit exceeded', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithTryAgainMessage(): void
    {
        $e = new Swift_TransportException('Please try again later', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCode0WithConnectionEstablished(): void
    {
        $e = new Swift_TransportException('Connection could not be established with host smtp.example.com', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    // --- SMTP Permanent Codes ---

    public function testSmtp500IsPermanent(): void
    {
        $e = new Swift_TransportException('Syntax error, command unrecognized', 500);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp501IsPermanent(): void
    {
        $e = new Swift_TransportException('Syntax error in parameters', 501);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp530IsPermanent(): void
    {
        $e = new Swift_TransportException('Authentication required', 530);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp535IsPermanent(): void
    {
        $e = new Swift_TransportException('Authentication failed', 535);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp550IsPermanent(): void
    {
        $e = new Swift_TransportException('Mailbox not found', 550);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp551IsPermanent(): void
    {
        $e = new Swift_TransportException('User not local', 551);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp552IsPermanent(): void
    {
        $e = new Swift_TransportException('Message size exceeds limit', 552);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp553IsPermanent(): void
    {
        $e = new Swift_TransportException('Invalid address', 553);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testSmtp554IsPermanent(): void
    {
        $e = new Swift_TransportException('Transaction failed', 554);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testHttp401IsPermanent(): void
    {
        $e = new Swift_TransportException('Unauthorized', 401);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testHttp403IsPermanent(): void
    {
        $e = new Swift_TransportException('Forbidden', 403);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    // --- Permanent Message Patterns ---

    public function testAuthFailedMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Authentication failed for user@example.com', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testAuthRequiredMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Authentication required', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testInvalidApiKeyMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Invalid API key provided', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testUnauthorizedMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Unauthorized access', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testForbiddenMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Forbidden resource', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testMailboxNotFoundMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Mailbox not found: user@example.com', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testUserUnknownMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('User unknown in virtual mailbox table', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testRelayAccessDeniedMessageIsPermanent(): void
    {
        $e = new Swift_TransportException('Relay access denied', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    // --- Case Insensitivity ---

    public function testCaseInsensitiveRetryablePattern(): void
    {
        $e = new Swift_TransportException('CONNECTION TIMED OUT', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testCaseInsensitivePermanentPattern(): void
    {
        $e = new Swift_TransportException('AUTHENTICATION FAILED', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testMixedCaseRetryablePattern(): void
    {
        $e = new Swift_TransportException('Connection Timed Out', 0);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testMixedCasePermanentPattern(): void
    {
        $e = new Swift_TransportException('Authentication Failed', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    // --- Priority: Permanent patterns override retryable codes ---

    public function testPermanentPatternOverridesRetryableCode0(): void
    {
        $e = new Swift_TransportException('Authentication failed', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testPermanentPatternOverridesRetryableCode421(): void
    {
        $e = new Swift_TransportException('Authentication failed', 421);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    // --- HTTP 500 with retryable message pattern ---

    public function testHttp500WithInternalServerErrorIsRetryable(): void
    {
        // HTTP 500 normally is SMTP permanent, but "internal server error" message overrides
        $e = new Swift_TransportException('Internal server error', 500);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testHttp500WithServiceUnavailableIsRetryable(): void
    {
        $e = new Swift_TransportException('Service unavailable', 500);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testHttp500WithTemporarilyUnavailableIsRetryable(): void
    {
        $e = new Swift_TransportException('Temporarily unavailable', 500);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    // --- Unknown codes in 4xx range ---

    public function testUnknown4xxCodeIsRetryable(): void
    {
        $e = new Swift_TransportException('Some error', 499);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testUnknown4xxCode400IsRetryable(): void
    {
        $e = new Swift_TransportException('Bad request', 400);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testUnknown4xxCode410IsRetryable(): void
    {
        $e = new Swift_TransportException('Gone', 410);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    // --- Codes outside defined ranges ---

    public function testCode600IsPermanent(): void
    {
        $e = new Swift_TransportException('Unknown code', 600);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testCode200IsPermanent(): void
    {
        $e = new Swift_TransportException('Weird success error', 200);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testCode300IsPermanent(): void
    {
        $e = new Swift_TransportException('Redirect-like error', 300);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testNegativeCodeIsPermanent(): void
    {
        $e = new Swift_TransportException('Negative code', -1);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    // --- Retryable message patterns with non-zero code ---

    public function testTooManyRequestsMessageWithCode200(): void
    {
        $e = new Swift_TransportException('Too many requests', 200);
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    public function testConnectionRefusedWithCode500(): void
    {
        $e = new Swift_TransportException('Connection refused', 500);
        $this->assertTrue($this->classifier->isRetryable($e));
    }
}
