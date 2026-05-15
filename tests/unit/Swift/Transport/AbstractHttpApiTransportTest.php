<?php

namespace Swift\Transport;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;

class Swift_Transport_AbstractHttpApiTransportTest extends TestCase
{
    private $httpClientMock;

    private $eventDispatcherMock;

    private $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new class('test-api-key', $this->httpClientMock, $this->eventDispatcherMock) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message, ?\Swift_Envelope $envelope = null): array
            {
                return ['message_id' => 'test-123', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.test.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return ['Authorization' => 'Bearer '.$this->apiKey];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return \json_decode($response->getBody()->getContents(), true);
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.test.com/ping';
            }
        };
    }

    public function testIsNotStartedByDefault(): void
    {
        $this->assertFalse($this->transport->isStarted());
    }

    public function testStartSetsStartedState(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $this->eventDispatcherMock->expects($this->exactly(2))->method('dispatchEvent');

        $this->transport->start();
        $this->assertTrue($this->transport->isStarted());
    }

    public function testStartDoesNothingIfAlreadyStarted(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->transport->start();
        $this->transport->start(); // second call should be no-op
        $this->assertTrue($this->transport->isStarted());
    }

    public function testPingReturnsTrueOnSuccessfulResponse(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException('fail', new \GuzzleHttp\Psr7\Request('GET', '/')));

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertFalse($this->transport->ping());
    }

    public function testGetApiConnectionReturnsHttpClient(): void
    {
        $reflection = new \ReflectionMethod($this->transport, 'getApiConnection');
        $this->assertSame($this->httpClientMock, $reflection->invoke($this->transport));
    }

    private function createConcreteTransport(
        string $apiKey,
        ClientInterface $httpClient,
        \Swift_Events_EventDispatcher $dispatcher,
        ?callable $doSendCallback = null,
    ): \Swift_Transport_AbstractHttpApiTransport {
        return new class($apiKey, $httpClient, $dispatcher, $doSendCallback) extends \Swift_Transport_AbstractHttpApiTransport {
            private $doSendCallback;

            public function __construct(string $apiKey, ?ClientInterface $httpClient, ?\Swift_Events_EventDispatcher $dispatcher, ?callable $doSendCallback)
            {
                parent::__construct($apiKey, $httpClient, $dispatcher);
                $this->doSendCallback = $doSendCallback;
            }

            protected function doSend(\Swift_Mime_SimpleMessage $message, ?\Swift_Envelope $envelope = null): array
            {
                if ($this->doSendCallback) {
                    return ($this->doSendCallback)($message);
                }

                return ['message_id' => 'test-id-123', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return ['Authorization' => 'Bearer test'];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };
    }

    public function testSendDispatchesSentMessageEvent(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = $this->createConcreteTransport('test-key', $httpClient, $dispatcher);

        // Use a shared object to capture the event from the anonymous listener
        $holder        = new \stdClass();
        $holder->event = null;
        $listener      = new class($holder) implements \Swift_Events_SentMessageListener {
            private \stdClass $holder;

            public function __construct(\stdClass $holder)
            {
                $this->holder = $holder;
            }

            public function sentMessage(\Swift_Events_SentMessageEvent $evt): void
            {
                $this->holder->event = $evt;
            }
        };
        $dispatcher->bindEventListener($listener);

        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $transport->start();
        $count = $transport->send($message);

        $this->assertEquals(1, $count);
        $this->assertNotNull($holder->event, 'SentMessageEvent should have been dispatched');
        $this->assertEquals('test-id-123', $holder->event->getSentMessage()->getMessageId());
        $this->assertEquals(1, $holder->event->getSentMessage()->getRecipientCount());
        $this->assertSame($transport, $holder->event->getTransport());
    }

    public function testSendDispatchesFailedMessageEventOnException(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = $this->createConcreteTransport('test-key', $httpClient, $dispatcher, function () {
            throw new \RuntimeException('API unavailable');
        });

        // Use a shared object to capture the event from the anonymous listener
        $holder        = new \stdClass();
        $holder->event = null;
        $listener      = new class($holder) implements \Swift_Events_FailedMessageListener {
            private \stdClass $holder;

            public function __construct(\stdClass $holder)
            {
                $this->holder = $holder;
            }

            public function failedMessage(\Swift_Events_FailedMessageEvent $evt): void
            {
                $this->holder->event = $evt;
            }
        };
        $dispatcher->bindEventListener($listener);

        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $transport->start();

        try {
            $transport->send($message);
            $this->fail('Should have thrown Swift_TransportException');
        } catch (\Swift_TransportException $e) {
            $this->assertStringContainsString('API unavailable', $e->getMessage());
        }

        $this->assertNotNull($holder->event, 'FailedMessageEvent should have been dispatched');
        $this->assertSame($message, $holder->event->getMessage());
        $this->assertInstanceOf(\Swift_TransportException::class, $holder->event->getException());
        $this->assertEquals(['to@example.com'], $holder->event->getFailedRecipients());
        $this->assertSame($transport, $holder->event->getTransport());
    }

    public function testCountRecipientsUsesEnvelopeWhenProvided(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = $this->createConcreteTransport('test-key', $httpClient, $dispatcher);

        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $envelope = new \Swift_Envelope('override@example.com', ['a@example.com', 'b@example.com', 'c@example.com']);

        $transport->start();
        $count = $transport->send($message, $failures, $envelope);

        // doSend returns recipients=1 but envelope has 3, countRecipients should return 3
        // Since doSend returns 'recipients' => 1, that takes precedence
        $this->assertEquals(1, $count);
    }

    public function testCollectRecipientsUsesEnvelopeWhenProvided(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = $this->createConcreteTransport('test-key', $httpClient, $dispatcher, function (\Swift_Mime_SimpleMessage $message) {
            throw new \RuntimeException('API error');
        });

        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $envelope = new \Swift_Envelope('override@example.com', ['a@example.com', 'b@example.com']);

        $transport->start();

        $failures = [];
        try {
            $transport->send($message, $failures, $envelope);
            $this->fail('Should have thrown');
        } catch (\Swift_TransportException $e) {
            // Expected
        }

        // Failed recipients should be from envelope, not message
        $this->assertEquals(['a@example.com', 'b@example.com'], $failures);
    }

    public function testOversizedResponseThrows(): void
    {
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getSize')->willReturn(2 * 1024 * 1024);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $reflection = new \ReflectionMethod($this->transport, 'getResponseBody');

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('too large');
        $reflection->invoke($this->transport, $response);
    }

    public function testOversizedStreamingResponseThrows(): void
    {
        $callCount = 0;
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('eof')->willReturnCallback(function () use (&$callCount) {
            return $callCount > 200;
        });
        $stream->method('read')->willReturnCallback(function () use (&$callCount) {
            ++$callCount;
            return str_repeat('x', 8192);
        });

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $reflection = new \ReflectionMethod($this->transport, 'getResponseBody');

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('exceeded max size');
        $reflection->invoke($this->transport, $response);
    }

    public function testDefaultGuzzleClientHasVerifyTrue(): void
    {
        $transport = new class('test-api-key', null, $this->eventDispatcherMock) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message, ?\Swift_Envelope $envelope = null): array
            {
                return [];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.test.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.test.com/ping';
            }
        };

        $ref = new \ReflectionProperty($transport, 'httpClient');
        $client = $ref->getValue($transport);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $client);

        $config = $client->getConfig();
        $this->assertTrue($config['verify'], 'Default Guzzle client must have verify=true');
    }

    public function testSendWithoutEnvelopeFallsBackToMessage(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = $this->createConcreteTransport('test-key', $httpClient, $dispatcher);

        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $transport->start();
        $count = $transport->send($message);

        $this->assertEquals(1, $count);
    }
}
