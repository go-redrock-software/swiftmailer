<?php

class Swift_Transport_DefaultRetryClassifierTest extends PHPUnit\Framework\TestCase
{
    private Swift_Transport_DefaultRetryClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new Swift_Transport_DefaultRetryClassifier();
    }

    /**
     * @dataProvider retryableExceptionsProvider
     */
    public function testRetryableExceptions(int $code, string $message): void
    {
        $e = new Swift_TransportException($message, $code);
        $this->assertTrue(
            $this->classifier->isRetryable($e),
            "Expected retryable for code={$code} message='{$message}'",
        );
    }

    public static function retryableExceptionsProvider(): array
    {
        return [
            'SMTP 421 service not available' => [421, 'Service not available'],
            'SMTP 450 mailbox busy'          => [450, 'Mailbox unavailable'],
            'SMTP 451 local error'           => [451, 'Local error in processing'],
            'SMTP 452 insufficient storage'  => [452, 'Insufficient system storage'],
            'HTTP 429 rate limit'            => [429, 'Too many requests'],
            'HTTP 500 server error'          => [500, 'Internal server error'],
            'HTTP 502 bad gateway'           => [502, 'Bad gateway'],
            'HTTP 503 service unavailable'   => [503, 'Service temporarily unavailable'],
            'HTTP 504 gateway timeout'       => [504, 'Gateway timeout'],
            'code 0 connection timeout'      => [0, 'Connection timed out'],
            'code 0 connection refused'      => [0, 'Connection refused'],
            'code 0 connection reset'        => [0, 'Connection reset by peer'],
            'code 0 stream error'            => [0, 'stream_socket_client(): unable to connect'],
            'code 0 broken pipe'             => [0, 'Broken pipe'],
            'code 0 generic'                 => [0, 'Some unknown error'],
            'rate limit in message'          => [0, 'API error: rate limit exceeded'],
            'try again in message'           => [0, 'Please try again later'],
            'connection established msg'     => [0, 'Connection could not be established with host smtp.example.com'],
        ];
    }

    /**
     * @dataProvider permanentExceptionsProvider
     */
    public function testPermanentExceptions(int $code, string $message): void
    {
        $e = new Swift_TransportException($message, $code);
        $this->assertFalse(
            $this->classifier->isRetryable($e),
            "Expected permanent for code={$code} message='{$message}'",
        );
    }

    public static function permanentExceptionsProvider(): array
    {
        return [
            'SMTP 535 auth failed'        => [535, 'Authentication failed'],
            'SMTP 550 mailbox not found'  => [550, 'Mailbox not found'],
            'SMTP 553 bad address'        => [553, 'Invalid address'],
            'SMTP 554 transaction failed' => [554, 'Transaction failed'],
            'HTTP 401 unauthorized'       => [401, 'Unauthorized'],
            'HTTP 403 forbidden'          => [403, 'Forbidden'],
            'auth failed message'         => [0, 'Authentication failed for user@example.com'],
            'invalid api key message'     => [0, 'Invalid API key provided'],
            'relay denied message'        => [0, 'Relay access denied'],
            'SMTP 530 auth required'      => [530, 'Authentication required'],
            'SMTP 500 syntax error'       => [500, 'Syntax error, command unrecognized'],
        ];
    }

    public function testPermanentPatternOverridesRetryableCode(): void
    {
        // Code 0 is normally retryable, but "authentication failed" message makes it permanent
        $e = new Swift_TransportException('Authentication failed', 0);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testCaseInsensitiveMessageMatching(): void
    {
        $e = new Swift_TransportException('CONNECTION TIMED OUT', 0);
        $this->assertTrue($this->classifier->isRetryable($e));

        $e2 = new Swift_TransportException('AUTHENTICATION FAILED', 0);
        $this->assertFalse($this->classifier->isRetryable($e2));
    }
}
