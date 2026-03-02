<?php

class Swift_Transport_Api_AmazonSesHttpTransportTest extends \PHPUnit\Framework\TestCase
{
    private $eventDispatcherMock;

    protected function setUp(): void
    {
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);
    }

    public function testImplementsSwiftTransport(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());
        $this->assertInstanceOf(Swift_Transport::class, $transport);
    }

    public function testExtendsAbstractApiTransport(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());
        $this->assertInstanceOf(Swift_Transport_AbstractApiTransport::class, $transport);
    }

    public function testIsNotStartedByDefault(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());
        $this->assertFalse($transport->isStarted());
    }

    public function testStartSetsStartedState(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $client = new class {
            public function listIdentities(): array
            {
                return ['test@example.com'];
            }

            public function sendEmail($request = null): object
            {
                return new class {
                    public function getMessageId(): string
                    {
                        return 'id';
                    }
                };
            }
        };

        $transport = $this->createTransportWithClient($client);
        $this->assertTrue($transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $client = new class {
            public function listIdentities(): never
            {
                throw new \RuntimeException('AWS error');
            }

            public function sendEmail($request = null): object
            {
                return new class {
                    public function getMessageId(): string
                    {
                        return 'id';
                    }
                };
            }
        };

        $transport = $this->createTransportWithClient($client);
        $this->assertFalse($transport->ping());
    }

    public function testSendBasicMessage(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $result = $transport->send($message);
        $this->assertSame(1, $result);
    }

    public function testSendCountsMultipleRecipients(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to1@example.com' => 'R1', 'to2@example.com' => 'R2']);
        $message->setCc(['cc@example.com' => 'CC']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $result = $transport->send($message);
        $this->assertSame(3, $result);
    }

    public function testSendWithBccRecipients(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setBcc(['bcc@example.com' => 'BCC']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $result = $transport->send($message);
        $this->assertSame(2, $result);
    }

    public function testSendAddsMessageIdHeader(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient('ses-msg-id-123'));

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $transport->send($message);

        $header = $message->getHeaders()->get('X-SES-Message-ID');
        $this->assertNotNull($header);
        $this->assertSame('ses-msg-id-123', $header->getFieldBody());
    }

    public function testSendThrowsTransportExceptionOnFailure(): void
    {
        $client = new class {
            public function sendEmail($request = null): never
            {
                throw new \RuntimeException('AWS SES error');
            }

            public function listIdentities(): array
            {
                return [];
            }
        };

        $transport = $this->createTransportWithClient($client);

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email');

        $transport->send($message);
    }

    public function testSendPopulatesFailedRecipientsOnError(): void
    {
        $client = new class {
            public function sendEmail($request = null): never
            {
                throw new \RuntimeException('Error');
            }

            public function listIdentities(): array
            {
                return [];
            }
        };

        $transport = $this->createTransportWithClient($client);

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setCc(['cc@example.com' => 'CC']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $failedRecipients = [];
        try {
            $transport->send($message, $failedRecipients);
        } catch (Swift_TransportException $e) {
            // expected
        }

        $this->assertContains('to@example.com', $failedRecipients);
        $this->assertContains('cc@example.com', $failedRecipients);
    }

    public function testSendWithOnlyToRecipients(): void
    {
        $transport = $this->createTransportWithClient($this->createSuccessClient());

        $message = new Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->assertSame(1, $transport->send($message));
    }

    public function testGetApiConnectionReturnsSesClient(): void
    {
        $client = $this->createSuccessClient();
        $transport = $this->createTransportWithClient($client);

        $reflection = new \ReflectionMethod($transport, 'getApiConnection');
        $reflection->setAccessible(true);
        $this->assertSame($client, $reflection->invoke($transport));
    }

    private function createTransportWithClient(object $client): Swift_Transport_Api_AmazonSesHttpTransport
    {
        return new Swift_Transport_Api_AmazonSesHttpTransport($client, $this->eventDispatcherMock);
    }

    private function createSuccessClient(string $messageId = 'test-msg-id'): object
    {
        return new class($messageId) {
            public function __construct(private readonly string $messageId) {}

            public function sendEmail($request = null): object
            {
                $id = $this->messageId;
                return new class($id) {
                    public function __construct(private readonly string $id) {}

                    public function getMessageId(): string
                    {
                        return $this->id;
                    }
                };
            }

            public function listIdentities(): array
            {
                return [];
            }
        };
    }
}
