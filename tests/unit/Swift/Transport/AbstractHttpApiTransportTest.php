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
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new class(
            'test-api-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        ) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test-123', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.test.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return ['Authorization' => 'Bearer ' . $this->apiKey];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return json_decode($response->getBody()->getContents(), true);
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
        $reflection->setAccessible(true);
        $this->assertSame($this->httpClientMock, $reflection->invoke($this->transport));
    }
}
