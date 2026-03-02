<?php

class Swift_Transport_RetryTransportExtraTest extends PHPUnit\Framework\TestCase
{
    public function testImplementsSwiftTransport(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertInstanceOf(Swift_Transport::class, $retry);
    }

    public function testGetInnerTransport(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertSame($inner, $retry->getInnerTransport());
    }

    public function testIsStartedDelegatesToInner(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('isStarted')->willReturn(true);
        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertTrue($retry->isStarted());
    }

    public function testIsNotStartedDelegatesToInner(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('isStarted')->willReturn(false);
        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertFalse($retry->isStarted());
    }

    public function testStartDelegatesToInner(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())->method('start');
        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->start();
    }

    public function testStopDelegatesToInner(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->expects($this->once())->method('stop');
        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->stop();
    }

    public function testPingDelegatesToInner(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('ping')->willReturn(true);
        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertTrue($retry->ping());
    }

    public function testPingReturnsFalse(): void
    {
        $inner = $this->createMock(Swift_Transport::class);
        $inner->method('ping')->willReturn(false);
        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertFalse($retry->ping());
    }

    public function testRegisterPluginDelegatesToInner(): void
    {
        $inner  = $this->createMock(Swift_Transport::class);
        $plugin = $this->createMock(Swift_Events_EventListener::class);
        $inner->expects($this->once())->method('registerPlugin')->with($plugin);
        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->registerPlugin($plugin);
    }

    public function testSendDelegatesToInner(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();
        $inner->expects($this->once())->method('send')->willReturn(1);

        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertSame(1, $retry->send($message));
    }

    public function testSendReturnsInnerResult(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();
        $inner->method('send')->willReturn(5);

        $retry = new Swift_Transport_RetryTransport($inner);
        $this->assertSame(5, $retry->send($message));
    }

    public function testDefaultMaxRetriesIsThree(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        // Default = 3 retries = 4 total attempts
        $inner->expects($this->exactly(4))
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testCustomMaxRetries(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        // maxRetries=1 = 2 total attempts
        $inner->expects($this->exactly(2))
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 1, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testCustomMaxRetries5(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        // maxRetries=5 = 6 total attempts
        $inner->expects($this->exactly(6))
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 5, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetryThenSucceed(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Timeout', 0)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertSame(1, $retry->send($message));
    }

    public function testRetryTwiceThenSucceed(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('err1', 0)),
                $this->throwException(new Swift_TransportException('err2', 0)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertSame(1, $retry->send($message));
    }

    public function testDoesNotRetryOnPermanentFailure(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Auth failed', 535));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Auth failed');
        $retry->send($message);
    }

    public function testDoesNotRetrySmtp550(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Mailbox not found', 550));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRetriesSmtp421(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Try later', 421)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertSame(1, $retry->send($message));
    }

    public function testRetriesHttp429(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Rate limit', 429)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertSame(1, $retry->send($message));
    }

    public function testRetriesHttp500(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('Internal server error', 500)),
                1,
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $this->assertSame(1, $retry->send($message));
    }

    public function testFailedRecipientsPassedThrough(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->method('send')
            ->willReturnCallback(function ($msg, &$failed = null) {
                $failed = ['fail@example.com'];

                return 0;
            });

        $failedRecipients = [];
        $retry            = new Swift_Transport_RetryTransport($inner, baseDelayMs: 0);
        $result           = $retry->send($message, $failedRecipients);

        $this->assertSame(0, $result);
        $this->assertSame(['fail@example.com'], $failedRecipients);
    }

    public function testCustomClassifierNeverRetries(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $classifier = new class implements Swift_Transport_RetryClassifier {
            public function isRetryable(Swift_TransportException $e): bool
            {
                return false;
            }
        };

        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0, classifier: $classifier);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testCustomClassifierAlwaysRetries(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $classifier = new class implements Swift_Transport_RetryClassifier {
            public function isRetryable(Swift_TransportException $e): bool
            {
                return true;
            }
        };

        // Even permanent codes get retried with this classifier
        $inner->expects($this->exactly(4)) // 1 + 3 retries
            ->method('send')
            ->willThrowException(new Swift_TransportException('Auth failed', 535));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0, classifier: $classifier);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testRestartsTransportAfterFailure(): void
    {
        $startCount = 0;
        $sendCount  = 0;

        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->method('isStarted')->willReturn(false);
        $inner->method('start')->willReturnCallback(function () use (&$startCount) {
            ++$startCount;
        });
        $inner->method('send')->willReturnCallback(function () use (&$sendCount) {
            ++$sendCount;
            if (1 === $sendCount) {
                throw new Swift_TransportException('Connection lost', 0);
            }

            return 1;
        });

        $retry  = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 0);
        $result = $retry->send($message);

        $this->assertSame(1, $result);
        $this->assertGreaterThanOrEqual(1, $startCount);
    }

    public function testEnvelopeIsPassedThrough(): void
    {
        $inner    = $this->createMock(Swift_Transport::class);
        $message  = $this->createMessage();
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com']);

        $inner->expects($this->once())
            ->method('send')
            ->with($message, $this->anything(), $envelope)
            ->willReturn(1);

        $retry = new Swift_Transport_RetryTransport($inner);
        $retry->send($message, $failures, $envelope);
    }

    public function testSendWithZeroMaxRetries(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        // maxRetries=0 means no retries at all, just one attempt
        $inner->expects($this->once())
            ->method('send')
            ->willThrowException(new Swift_TransportException('Timeout', 0));

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 0, baseDelayMs: 0);

        $this->expectException(Swift_TransportException::class);
        $retry->send($message);
    }

    public function testSendSucceedsOnFirstAttempt(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->once())->method('send')->willReturn(3);

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 5, baseDelayMs: 0);
        $this->assertSame(3, $retry->send($message));
    }

    public function testLastExceptionIsThrown(): void
    {
        $inner   = $this->createMock(Swift_Transport::class);
        $message = $this->createMessage();

        $inner->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Swift_TransportException('First error', 0)),
                $this->throwException(new Swift_TransportException('Second error', 0)),
            );

        $retry = new Swift_Transport_RetryTransport($inner, maxRetries: 1, baseDelayMs: 0);

        try {
            $retry->send($message);
            $this->fail('Expected exception');
        } catch (Swift_TransportException $e) {
            $this->assertSame('Second error', $e->getMessage());
        }
    }

    private function createMessage(): Swift_Message
    {
        return (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');
    }
}
