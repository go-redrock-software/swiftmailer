# Transport Retry with Exponential Backoff — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a `Swift_Transport_RetryTransport` decorator that wraps any `Swift_Transport` and automatically retries on transient failures (connection timeouts, 4xx SMTP responses, HTTP 429/5xx) with configurable exponential backoff.

**Architecture:** `Swift_Transport_RetryTransport` implements `Swift_Transport` as a decorator (like `FailoverTransport` wraps multiple transports, this wraps a single transport). It delegates all interface methods to the inner transport. On `send()`, it catches `Swift_TransportException`, classifies the exception as retryable or permanent via `Swift_Transport_RetryTransport_RetryClassifier`, and retries with exponential backoff (base delay * 2^attempt + jitter). A separate `Swift_Transport_RetryClassifier` strategy class examines the exception code and message to decide retryability. DSN support via `retry(inner_dsn)` wrapper syntax in `DsnTransportFactory` and `?retries=N&retry_delay=M` query parameters on any DSN.

**Tech Stack:** PHP 8.1+, existing SwiftMailer transport layer. No new dependencies.

---

## Task 1: Swift_Transport_RetryTransport — Core Decorator with Retry Logic

**Files:**
- Create: `lib/classes/Swift/Transport/RetryTransport.php`
- Create: `tests/unit/Swift/Transport/RetryTransportTest.php`

**Step 1: Write the failing tests**

```php
<?php

class Swift_Transport_RetryTransportTest extends \PHPUnit\Framework\TestCase
{
    public function testSendDelegatesToInnerTransport()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->with($message)
            ->willReturn(1);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnTransientExceptionThenSucceeds()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Connection timed out', 0)),
                $this->throwException(new Swift_TransportException('Connection reset', 0)),
                1,
            );

        // Use 0 base delay for fast tests
        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testThrowsAfterMaxRetriesExhausted()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(4)) // 1 initial + 3 retries
            ->method('send')
            ->willThrowException(new Swift_TransportException('Connection refused', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Connection refused');
        $retry->send($message);
    }

    public function testDoesNotRetryOnPermanentException()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        // SMTP 535 = authentication failure, should not retry
        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Authentication failed', 535));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Authentication failed');
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp550PermanentFailure()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Mailbox not found', 550));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetriesOnSmtp4xxTemporaryFailure()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Try again later', 421)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnConnectionTimeoutMessage()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Connection could not be established with host smtp.example.com', 0)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp429TooManyRequests()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        // HTTP API transports wrap exceptions with code 0 but include status info in message
        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('API error: rate limit exceeded', 429)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp5xxServerError()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Internal server error', 500)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testIsStartedDelegatesToInner()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('isStarted')->willReturn(true);

        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertTrue($retry->isStarted());
    }

    public function testStartDelegatesToInner()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())->method('start');

        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->start();
    }

    public function testStopDelegatesToInner()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())->method('stop');

        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->stop();
    }

    public function testPingDelegatesToInner()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('ping')->willReturn(true);

        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertTrue($retry->ping());
    }

    public function testRegisterPluginDelegatesToInner()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $inner->expects($this->once())
            ->method('registerPlugin')
            ->with($plugin);

        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->registerPlugin($plugin);
    }

    public function testFailedRecipientsPassedThrough()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($msg, &$failed = null) {
                $failed = ['to@example.com'];

                return 0;
            });

        $failedRecipients = [];
        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message, $failedRecipients);

        $this->assertSame(0, $result);
        $this->assertSame(['to@example.com'], $failedRecipients);
    }

    public function testDefaultMaxRetriesIsThree()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        // Default is 3 retries = 4 total attempts
        $inner->expects($this->exactly(4))
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testGetInnerTransport()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $retry = new Swift_Transport_RetryTransport($inner);

        $this->assertSame($inner, $retry->getInnerTransport());
    }

    public function testCustomClassifier()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        // Custom classifier that says nothing is retryable
        $classifier = new class implements Swift_Transport_RetryClassifier {
            public function isRetryable(Swift_TransportException $e): bool
            {
                return false;
            }
        };

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport(
            $inner,
            maxRetries: 3,
            baseDelayMs: 0,
            classifier: $classifier,
        );

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/RetryTransportTest.php --verbose`
Expected: FAIL — class `Swift_Transport_RetryTransport` not found.

**Step 3: Write implementation**

Create `lib/classes/Swift/Transport/RetryClassifier.php`:

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Strategy interface for classifying whether a transport exception is retryable.
 */
interface Swift_Transport_RetryClassifier
{
    /**
     * Determine whether the given exception represents a transient failure
     * that should be retried.
     */
    public function isRetryable(Swift_TransportException $e): bool;
}
```

Create `lib/classes/Swift/Transport/DefaultRetryClassifier.php`:

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Default retry classifier for transport exceptions.
 *
 * Classifies exceptions as retryable based on:
 * - Exception code: SMTP 4xx codes, HTTP 429/5xx codes, code 0 (connection-level)
 * - Exception message: connection timeouts, resets, refused, rate limits
 *
 * Permanent failures (SMTP 5xx, auth errors) are NOT retried.
 */
class Swift_Transport_DefaultRetryClassifier implements Swift_Transport_RetryClassifier
{
    /**
     * SMTP/HTTP codes that are permanently non-retryable.
     * 5xx SMTP = permanent failure, 401/403 = auth errors.
     */
    private const PERMANENT_CODES = [
        501, 502, 503, 504, 530, 535, 550, 551, 552, 553, 554, // SMTP permanent
        401, 403, // HTTP auth errors
    ];

    /**
     * SMTP/HTTP codes that are explicitly retryable.
     */
    private const RETRYABLE_CODES = [
        421, 450, 451, 452, // SMTP temporary failures
        429, // HTTP too many requests
        500, 502, 503, 504, // HTTP server errors (note: SMTP 500 is permanent, but HTTP 500 is transient)
    ];

    /**
     * Message substrings that indicate a transient/connection-level failure.
     */
    private const RETRYABLE_PATTERNS = [
        'connection could not be established',
        'connection timed out',
        'connection reset',
        'connection refused',
        'broken pipe',
        'stream_socket_client',
        'rate limit',
        'too many requests',
        'try again',
        'temporarily unavailable',
        'service unavailable',
        'internal server error',
    ];

    /**
     * Message substrings that indicate a permanent failure (never retry).
     */
    private const PERMANENT_PATTERNS = [
        'authentication failed',
        'authentication required',
        'invalid api key',
        'unauthorized',
        'forbidden',
        'mailbox not found',
        'user unknown',
        'relay access denied',
    ];

    public function isRetryable(Swift_TransportException $e): bool
    {
        $code = $e->getCode();
        $message = \strtolower($e->getMessage());

        // Check permanent message patterns first (highest priority)
        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (\str_contains($message, $pattern)) {
                return false;
            }
        }

        // Check permanent codes
        if (\in_array($code, self::PERMANENT_CODES, true)) {
            return false;
        }

        // Check explicit retryable codes
        if (\in_array($code, self::RETRYABLE_CODES, true)) {
            return true;
        }

        // Check retryable message patterns
        foreach (self::RETRYABLE_PATTERNS as $pattern) {
            if (\str_contains($message, $pattern)) {
                return true;
            }
        }

        // Code 0 typically means connection-level failure (no SMTP/HTTP code received)
        if (0 === $code) {
            return true;
        }

        // Unknown code in 4xx range = retryable
        if ($code >= 400 && $code < 500) {
            return true;
        }

        // Everything else is considered permanent
        return false;
    }
}
```

Create `lib/classes/Swift/Transport/RetryTransport.php`:

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Decorator transport that retries sending on transient failures with exponential backoff.
 *
 * Wraps any Swift_Transport and catches Swift_TransportException on send().
 * If the exception is classified as retryable (connection timeout, SMTP 4xx, HTTP 429/5xx),
 * the send is retried up to maxRetries times with exponential backoff + jitter.
 *
 * Usage:
 *     $inner = new Swift_SmtpTransport('smtp.example.com', 587, 'tls');
 *     $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 1000);
 *     $mailer = new Swift_Mailer($retry);
 *
 * Backoff formula: delay = baseDelayMs * 2^attempt + random(0, baseDelayMs/2)
 */
class Swift_Transport_RetryTransport implements Swift_Transport
{
    private Swift_Transport $innerTransport;

    private int $maxRetries;

    private int $baseDelayMs;

    private Swift_Transport_RetryClassifier $classifier;

    /**
     * @param Swift_Transport               $innerTransport The transport to wrap
     * @param int                           $maxRetries     Maximum number of retry attempts (default: 3)
     * @param int                           $baseDelayMs    Base delay in milliseconds for backoff (default: 1000)
     * @param Swift_Transport_RetryClassifier|null $classifier     Strategy for classifying retryable exceptions
     */
    public function __construct(
        Swift_Transport $innerTransport,
        int $maxRetries = 3,
        int $baseDelayMs = 1000,
        ?Swift_Transport_RetryClassifier $classifier = null,
    ) {
        $this->innerTransport = $innerTransport;
        $this->maxRetries = $maxRetries;
        $this->baseDelayMs = $baseDelayMs;
        $this->classifier = $classifier ?? new Swift_Transport_DefaultRetryClassifier();
    }

    /**
     * Get the wrapped inner transport.
     */
    public function getInnerTransport(): Swift_Transport
    {
        return $this->innerTransport;
    }

    public function isStarted(): bool
    {
        return $this->innerTransport->isStarted();
    }

    public function start(): void
    {
        $this->innerTransport->start();
    }

    public function stop(): void
    {
        $this->innerTransport->stop();
    }

    public function ping(): bool
    {
        return $this->innerTransport->ping();
    }

    /**
     * Send with retry logic.
     *
     * Attempts to send via the inner transport. On transient failures, retries
     * up to maxRetries times with exponential backoff. Permanent failures are
     * thrown immediately without retry.
     *
     * @param string[] $failedRecipients An array of failures by-reference
     *
     * @return int Number of accepted recipients
     *
     * @throws Swift_TransportException on permanent failure or after all retries exhausted
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->innerTransport->send($message, $failedRecipients);
            } catch (Swift_TransportException $e) {
                if (!$this->classifier->isRetryable($e) || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                $this->backoff($attempt);
                ++$attempt;

                // Restart transport in case connection was dropped
                if (!$this->innerTransport->isStarted()) {
                    try {
                        $this->innerTransport->start();
                    } catch (Swift_TransportException $startException) {
                        // Will be retried on next iteration
                    }
                }
            }
        }
    }

    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
        $this->innerTransport->registerPlugin($plugin);
    }

    /**
     * Sleep for exponential backoff duration.
     *
     * Formula: baseDelayMs * 2^attempt + random jitter (0 to baseDelayMs/2)
     */
    protected function backoff(int $attempt): void
    {
        if ($this->baseDelayMs <= 0) {
            return;
        }

        $delay = $this->baseDelayMs * (2 ** $attempt);
        $jitter = \random_int(0, (int) ($this->baseDelayMs / 2));
        $totalMs = $delay + $jitter;

        \usleep($totalMs * 1000);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/RetryTransportTest.php --verbose`
Expected: PASS (18 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/RetryTransport.php \
        lib/classes/Swift/Transport/RetryClassifier.php \
        lib/classes/Swift/Transport/DefaultRetryClassifier.php \
        tests/unit/Swift/Transport/RetryTransportTest.php
git commit -m "feat: add RetryTransport decorator with exponential backoff for transient failures"
```

---

## Task 2: DefaultRetryClassifier Unit Tests

**Files:**
- Create: `tests/unit/Swift/Transport/DefaultRetryClassifierTest.php`

**Step 1: Write the tests**

```php
<?php

class Swift_Transport_DefaultRetryClassifierTest extends \PHPUnit\Framework\TestCase
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
            'SMTP 450 mailbox busy' => [450, 'Mailbox unavailable'],
            'SMTP 451 local error' => [451, 'Local error in processing'],
            'SMTP 452 insufficient storage' => [452, 'Insufficient system storage'],
            'HTTP 429 rate limit' => [429, 'Too many requests'],
            'HTTP 500 server error' => [500, 'Internal server error'],
            'HTTP 502 bad gateway' => [502, 'Bad gateway'],
            'HTTP 503 service unavailable' => [503, 'Service temporarily unavailable'],
            'HTTP 504 gateway timeout' => [504, 'Gateway timeout'],
            'code 0 connection timeout' => [0, 'Connection timed out'],
            'code 0 connection refused' => [0, 'Connection refused'],
            'code 0 connection reset' => [0, 'Connection reset by peer'],
            'code 0 stream error' => [0, 'stream_socket_client(): unable to connect'],
            'code 0 broken pipe' => [0, 'Broken pipe'],
            'code 0 generic' => [0, 'Some unknown error'],
            'rate limit in message' => [0, 'API error: rate limit exceeded'],
            'try again in message' => [0, 'Please try again later'],
            'connection established msg' => [0, 'Connection could not be established with host smtp.example.com'],
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
            'SMTP 535 auth failed' => [535, 'Authentication failed'],
            'SMTP 550 mailbox not found' => [550, 'Mailbox not found'],
            'SMTP 553 bad address' => [553, 'Invalid address'],
            'SMTP 554 transaction failed' => [554, 'Transaction failed'],
            'HTTP 401 unauthorized' => [401, 'Unauthorized'],
            'HTTP 403 forbidden' => [403, 'Forbidden'],
            'auth failed message' => [0, 'Authentication failed for user@example.com'],
            'invalid api key message' => [0, 'Invalid API key provided'],
            'relay denied message' => [0, 'Relay access denied'],
            'SMTP 530 auth required' => [530, 'Authentication required'],
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
```

**Step 2: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DefaultRetryClassifierTest.php --verbose`
Expected: PASS (all tests, including data provider tests).

**Step 3: Commit**

```bash
git add tests/unit/Swift/Transport/DefaultRetryClassifierTest.php
git commit -m "test: add comprehensive unit tests for DefaultRetryClassifier"
```

---

## Task 3: DSN Support — `retry()` Wrapper and Query Parameters

**Files:**
- Modify: `lib/classes/Swift/Transport/DsnTransportFactory.php`
- Create: `tests/unit/Swift/Transport/DsnTransportFactoryRetryTest.php`

**Step 1: Write the failing tests**

```php
<?php

class Swift_Transport_DsnTransportFactoryRetryTest extends \PHPUnit\Framework\TestCase
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
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryRetryTest.php --verbose`
Expected: FAIL — `retry()` not handled, query params not handled.

**Step 3: Modify DsnTransportFactory**

In `lib/classes/Swift/Transport/DsnTransportFactory.php`, update the `fromDsnString()` method to handle `retry()` wrapper, and update `createTransport()` to handle `retries`/`retry_delay` query parameters:

```php
public function fromDsnString(string $dsnString): Swift_Transport
{
    // Check for meta-transport wrappers
    if (\preg_match('/^(failover|roundrobin|retry)\((.+)\)$/', $dsnString, $matches)) {
        $wrapper   = $matches[1];
        $innerPart = \trim($matches[2]);

        if ('retry' === $wrapper) {
            $innerTransport = $this->fromDsnString($innerPart);

            return new Swift_Transport_RetryTransport($innerTransport);
        }

        $innerDsns = \preg_split('/\s+/', $innerPart);

        $transports = [];
        foreach ($innerDsns as $innerDsn) {
            $transports[] = $this->fromDsnString($innerDsn);
        }

        if ('failover' === $wrapper) {
            $transport = new Swift_Transport_FailoverTransport();
        } else {
            $transport = new Swift_Transport_LoadBalancedTransport();
        }
        $transport->setTransports($transports);

        return $transport;
    }

    return $this->createTransport($dsnString);
}
```

In `createTransport()`, after creating the transport, check for retry query parameters and wrap if present:

```php
private function createTransport(string $dsnString): Swift_Transport
{
    $nyholmDsn = DsnParser::parseUrl($dsnString);
    $dsn       = new Swift_Dsn($nyholmDsn);
    $params    = $dsn->getParameters();

    // Extract retry parameters before creating transport
    $retries    = isset($params['retries']) ? (int) $params['retries'] : null;
    $retryDelay = isset($params['retry_delay']) ? (int) $params['retry_delay'] : 1000;

    $class = $dsn->getTransportClass();

    // NullTransport needs an event dispatcher
    if (Swift_Transport_NullTransport::class === $class) {
        $transport = new Swift_Transport_NullTransport(
            new Swift_Events_SimpleEventDispatcher(),
        );
    } elseif (Swift_Transport_EsmtpTransport::class === $class) {
        // SMTP transports
        $transport = $this->createSmtpTransport($dsn);
    } else {
        // HTTP API transports
        $apiKey     = $dsn->getUser() ?: $dsn->getPassword() ?: '';
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $transport  = new $class($apiKey, null, $dispatcher);
    }

    // Wrap with retry if query params specify it
    if (null !== $retries && $retries > 0) {
        $transport = new Swift_Transport_RetryTransport($transport, $retries, $retryDelay);
    }

    return $transport;
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryRetryTest.php --verbose`
Expected: PASS (5 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/DsnTransportFactory.php \
        tests/unit/Swift/Transport/DsnTransportFactoryRetryTest.php
git commit -m "feat: add retry() DSN wrapper and ?retries=N&retry_delay=M query parameters"
```

---

## Task 4: Integration Test — Mock Transport That Fails N Times Then Succeeds

**Files:**
- Create: `tests/unit/Swift/Transport/RetryTransportIntegrationTest.php`

**Step 1: Write the integration test**

```php
<?php

class Swift_Transport_RetryTransportIntegrationTest extends \PHPUnit\Framework\TestCase
{
    public function testRetryWithFlakyTransportEventuallySucceeds()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $failCount = 0;
        $maxFailures = 2;

        // Anonymous transport that fails N times then succeeds
        $flakyTransport = new class ($dispatcher, $failCount, $maxFailures) extends Swift_Transport_AbstractHttpApiTransport {
            private int $callCount = 0;
            private int $failRef;
            private int $maxFail;

            public function __construct(Swift_Events_EventDispatcher $d, int &$failCount, int $maxFailures)
            {
                parent::__construct('test-key', new \GuzzleHttp\Client(), $d);
                $this->failRef = &$failCount;
                $this->maxFail = $maxFailures;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                ++$this->callCount;
                if ($this->callCount <= $this->maxFail) {
                    ++$this->failRef;
                    throw new \Exception('Service temporarily unavailable');
                }

                return ['message_id' => 'retry-test-'.uniqid(), 'recipients' => \count($message->getTo())];
            }

            protected function getEndpoint(): string { return 'https://api.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $r): array { return []; }
            protected function getPingEndpoint(): string { return 'https://api.example.com/ping'; }
        };

        $retry = new Swift_Transport_RetryTransport($flakyTransport, maxRetries: 3, baseDelayMs: 0);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Retry integration test')
            ->setBody('Hello');

        $result = $retry->send($message);

        $this->assertSame(1, $result);
        $this->assertSame(2, $failCount);
    }

    public function testRetryWithPermanentFailureDoesNotRetry()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $callCount = 0;

        $permanentFailTransport = new class ($dispatcher, $callCount) extends Swift_Transport_AbstractHttpApiTransport {
            private int $countRef;

            public function __construct(Swift_Events_EventDispatcher $d, int &$callCount)
            {
                parent::__construct('bad-key', new \GuzzleHttp\Client(), $d);
                $this->countRef = &$callCount;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                ++$this->countRef;
                throw new \Exception('Invalid API key provided');
            }

            protected function getEndpoint(): string { return 'https://api.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $r): array { return []; }
            protected function getPingEndpoint(): string { return 'https://api.example.com/ping'; }
        };

        $retry = new Swift_Transport_RetryTransport($permanentFailTransport, maxRetries: 3, baseDelayMs: 0);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Auth fail test')
            ->setBody('Hello');

        try {
            $retry->send($message);
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
        }

        // AbstractHttpApiTransport wraps the exception, so the retry transport sees code 0
        // but the "Invalid API key" message triggers permanent classification
        $this->assertSame(1, $callCount);
    }

    public function testRetryTransportWithPlugins()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $sendCount = 0;

        $flakyTransport = new class ($dispatcher, $sendCount) extends Swift_Transport_AbstractHttpApiTransport {
            private int $calls = 0;
            private int $countRef;

            public function __construct(Swift_Events_EventDispatcher $d, int &$sendCount)
            {
                parent::__construct('test-key', new \GuzzleHttp\Client(), $d);
                $this->countRef = &$sendCount;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                ++$this->calls;
                ++$this->countRef;
                if (1 === $this->calls) {
                    throw new \Exception('Connection timed out');
                }

                return ['message_id' => 'test-id', 'recipients' => \count($message->getTo())];
            }

            protected function getEndpoint(): string { return 'https://api.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $r): array { return []; }
            protected function getPingEndpoint(): string { return 'https://api.example.com/ping'; }
        };

        // Register a logger plugin to verify events still flow through
        $logger = new Swift_Plugins_Loggers_ArrayLogger();
        $loggerPlugin = new Swift_Plugins_LoggerPlugin($logger);

        $retry = new Swift_Transport_RetryTransport($flakyTransport, maxRetries: 3, baseDelayMs: 0);
        $retry->registerPlugin($loggerPlugin);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Plugin test')
            ->setBody('Hello');

        $result = $retry->send($message);

        $this->assertSame(1, $result);
        $this->assertSame(2, $sendCount);

        // Logger should have captured events
        $log = $logger->dump();
        $this->assertNotEmpty($log);
    }

    public function testRetryTransportWithDsnFactory()
    {
        $factory = new Swift_Transport_DsnTransportFactory();

        // Test retry() wrapper syntax
        $transport = $factory->fromDsnString('retry(null://default)');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('DSN test')
            ->setBody('Hello');

        // NullTransport always returns recipient count
        $result = $transport->send($message);
        $this->assertSame(1, $result);
    }
}
```

**Step 2: Run test**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/RetryTransportIntegrationTest.php --verbose`
Expected: PASS (4 tests).

**Step 3: Commit**

```bash
git add tests/unit/Swift/Transport/RetryTransportIntegrationTest.php
git commit -m "test: add RetryTransport integration tests with flaky transport, plugins, and DSN factory"
```

---

## Task 5: Run Full Test Suite and Code Style

**Step 1: Run all unit tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All tests pass, including existing tests (no regressions).

**Step 2: Run code style fixer**

Run: `composer php-cs-fixer`

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "style: fix code style for RetryTransport files"
```

---

## Summary of Changes

| File | Change |
|-|-|
| `lib/classes/Swift/Transport/RetryClassifier.php` | New interface for retry classification strategy |
| `lib/classes/Swift/Transport/DefaultRetryClassifier.php` | Default classifier: SMTP 4xx, HTTP 429/5xx, connection errors = retry; auth/5xx SMTP = permanent |
| `lib/classes/Swift/Transport/RetryTransport.php` | New decorator transport: wraps any transport with retry + exponential backoff |
| `lib/classes/Swift/Transport/DsnTransportFactory.php` | Add `retry()` wrapper support and `?retries=N&retry_delay=M` query params |
| `tests/unit/Swift/Transport/RetryTransportTest.php` | 18 unit tests for RetryTransport |
| `tests/unit/Swift/Transport/DefaultRetryClassifierTest.php` | Data-provider tests for classifier (retryable + permanent cases) |
| `tests/unit/Swift/Transport/DsnTransportFactoryRetryTest.php` | 5 tests for DSN retry integration |
| `tests/unit/Swift/Transport/RetryTransportIntegrationTest.php` | 4 integration tests with flaky transports, plugins, DSN factory |
