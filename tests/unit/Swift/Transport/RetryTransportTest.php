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
}
