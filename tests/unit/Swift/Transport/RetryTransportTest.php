<?php

class Swift_Transport_RetryTransportTest extends PHPUnit\Framework\TestCase
{
    public function testSendDelegatesToInnerTransport()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->with($message)
            ->willReturn(1);

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnTransientExceptionThenSucceeds()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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
        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testThrowsAfterMaxRetriesExhausted()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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
        $inner   = $this->createMock(Swift_Transport::class);
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
        $inner   = $this->createMock(Swift_Transport::class);
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
        $inner   = $this->createMock(Swift_Transport::class);
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

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnConnectionTimeoutMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp429TooManyRequests()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp5xxServerError()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
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
        $inner  = $this->createMock(Swift_Transport::class);
        $plugin = $this->createMock(Swift_Events_EventListener::class);

        $inner->expects($this->once())
            ->method('registerPlugin')
            ->with($plugin);

        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->registerPlugin($plugin);
    }

    public function testFailedRecipientsPassedThrough()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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
        $retry            = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result           = $retry->send($message, $failedRecipients);

        $this->assertSame(0, $result);
        $this->assertSame(['to@example.com'], $failedRecipients);
    }

    public function testDefaultMaxRetriesIsThree()
    {
        $inner   = $this->createMock(Swift_Transport::class);
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
        $inner   = $this->createMock(Swift_Transport::class);
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

    public function testCustomClassifierThatAlwaysRetries()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $classifier = new class implements Swift_Transport_RetryClassifier {
            public function isRetryable(Swift_TransportException $e): bool
            {
                return true;
            }
        };

        // Even auth failures get retried with this classifier
        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Auth failed', 535)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0, classifier: $classifier);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testMaxRetriesZeroMeansNoRetries()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 0, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testMaxRetriesOneGivesTwoTotalAttempts()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 1, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testSuccessOnFirstAttemptReturnsImmediately()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willReturn(5);

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(5, $result);
    }

    public function testDoesNotRetrySmtp553PermanentFailure()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Requested action not taken', 553));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp554PermanentFailure()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Transaction failed', 554));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetriesOnSmtp450TemporaryFailure()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Mailbox busy', 450)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnSmtp451TemporaryFailure()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Error in processing', 451)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnSmtp452InsufficientStorage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Insufficient storage', 452)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testIsStartedReturnsFalseWhenInnerNotStarted()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('isStarted')->willReturn(false);

        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertFalse($retry->isStarted());
    }

    public function testPingReturnsFalseWhenInnerReturnsFalse()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('ping')->willReturn(false);

        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertFalse($retry->ping());
    }

    public function testRetriesOnBrokenPipeMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Broken pipe', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testDoesNotRetryHttp401Unauthorized()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Unauthorized', 401));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetryHttp403Forbidden()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Forbidden', 403));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetriesOnServiceUnavailableMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Service unavailable', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetryTransportRestartsStoppedInnerTransport()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $callCount = 0;
        $inner->method('send')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new Swift_TransportException('Connection reset', 0);
                }
                return 1;
            });

        $inner->method('isStarted')
            ->willReturnOnConsecutiveCalls(false, true);

        $inner->expects($this->once())->method('start');

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testFailedRecipientsNotSetOnSuccess()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willReturn(1);

        $failedRecipients = [];
        $retry            = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result           = $retry->send($message, $failedRecipients);

        $this->assertSame(1, $result);
        $this->assertSame([], $failedRecipients);
    }

    public function testDoesNotRetryInvalidApiKeyMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Invalid API key provided', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetryRelayAccessDenied()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Relay access denied', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetryUserUnknown()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('User unknown', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetryAuthenticationRequired()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Authentication required', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetriesOnTemporarilyUnavailable()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Temporarily unavailable', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnStreamSocketClientError()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('stream_socket_client(): failed', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnTooManyRequestsMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Too many requests', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnTryAgainMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Please try again later', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp502BadGateway()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Bad Gateway', 502)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp503ServiceUnavailable()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Service Unavailable', 503)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnHttp504GatewayTimeout()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Gateway Timeout', 504)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testDoesNotRetrySmtp500SyntaxError()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Syntax error', 500));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp501SyntaxError()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Syntax error in parameters', 501));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp530RequiresAuth()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Authentication required', 530));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp551UserNotLocal()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('User not local', 551));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp552ExceededStorage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Exceeded storage allocation', 552));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetriesOnConnectionRefusedMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Connection refused by host', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testRetriesOnRateLimitMessage()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Rate limit exceeded', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testLastExceptionIsThrownAfterRetries()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Error 1', 0)),
                $this->throwException(new Swift_TransportException('Error 2', 0)),
                $this->throwException(new Swift_TransportException('Error 3', 0)),
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 2, baseDelayMs: 0);

        try {
            $retry->send($message);
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertEquals('Error 3', $e->getMessage());
        }
    }

    public function testSuccessOnLastRetryAttempt()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        // Fails 3 times, succeeds on 4th (3 retries)
        $inner->expects($this->exactly(4))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Timeout', 0)),
                $this->throwException(new Swift_TransportException('Timeout', 0)),
                $this->throwException(new Swift_TransportException('Timeout', 0)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testSendReturnsMultipleRecipientCount()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['a@example.com', 'b@example.com', 'c@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willReturn(3);

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(3, $result);
    }

    public function testStartDelegatesToInnerTransport()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())->method('start');

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $retry->start();
    }

    public function testStopDelegatesToInnerTransport()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())->method('stop');

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $retry->stop();
    }

    public function testIsStartedDelegatesToInnerTransport()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())
            ->method('isStarted')
            ->willReturn(true);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertTrue($retry->isStarted());
    }

    public function testIsStartedReturnsFalseWhenInnerStopped()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertFalse($retry->isStarted());
    }

    public function testPingDelegatesToInnerTransport()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())
            ->method('ping')
            ->willReturn(true);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertTrue($retry->ping());
    }

    public function testPingReturnsFalseWhenInnerFails()
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())
            ->method('ping')
            ->willReturn(false);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertFalse($retry->ping());
    }

    public function testRegisterPluginDelegatesToInnerTransport()
    {
        $inner  = $this->createMock(Swift_Transport::class);
        $plugin = $this->createMock(Swift_Events_EventListener::class);
        $inner->expects($this->once())
            ->method('registerPlugin')
            ->with($plugin);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $retry->registerPlugin($plugin);
    }

    public function testRetriesOnSmtp450MailboxBusy()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Mailbox busy', 450)),
                1,
            );

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);
        $this->assertSame(1, $result);
    }

    public function testDoesNotRetrySmtp553MailboxNameNotAllowed()
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Mailbox name not allowed', 553));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }
}
