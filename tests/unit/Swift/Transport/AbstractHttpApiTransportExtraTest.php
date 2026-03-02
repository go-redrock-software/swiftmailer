<?php

namespace Swift\Transport;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_AbstractHttpApiTransportExtraTest extends TestCase
{
    private $httpClientMock;

    private $eventDispatcherMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);
    }

    private function createConcreteTransport(?callable $doSendCallback = null): \Swift_Transport_AbstractHttpApiTransport
    {
        return new class('test-key', $this->httpClientMock, $this->eventDispatcherMock, $doSendCallback) extends \Swift_Transport_AbstractHttpApiTransport {
            private $callback;

            public function __construct(string $apiKey, ?ClientInterface $httpClient, ?\Swift_Events_EventDispatcher $dispatcher, ?callable $callback)
            {
                parent::__construct($apiKey, $httpClient, $dispatcher);
                $this->callback = $callback;
            }

            protected function doSend(\Swift_Mime_SimpleMessage $message, ?\Swift_Envelope $envelope = null): array
            {
                if ($this->callback) {
                    return ($this->callback)($message, $envelope);
                }

                return ['message_id' => 'test-id', 'recipients' => 1];
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
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.test.com/ping';
            }

            // Expose protected methods for testing
            public function testCountRecipients(\Swift_Mime_SimpleMessage $message): int
            {
                return $this->countRecipients($message);
            }

            public function testCollectRecipients(\Swift_Mime_SimpleMessage $message): array
            {
                return $this->collectRecipients($message);
            }

            public function testGetEnvelopeSender(\Swift_Mime_SimpleMessage $message): ?string
            {
                return $this->getEnvelopeSender($message);
            }

            public function testFormatAddress(string $email, ?string $name = null): string
            {
                return $this->formatAddress($email, $name);
            }

            public function testFormatAddresses(array $addresses): array
            {
                return $this->formatAddresses($addresses);
            }

            public function testGetMessageBody(\Swift_Mime_SimpleMessage $message): array
            {
                return $this->getMessageBody($message);
            }

            public function testExtractTags(\Swift_Mime_SimpleMessage $message): array
            {
                return $this->extractTags($message);
            }

            public function testExtractMetadata(\Swift_Mime_SimpleMessage $message): array
            {
                return $this->extractMetadata($message);
            }

            public function testGetMessageAttachments(\Swift_Mime_SimpleMessage $message): array
            {
                return $this->getMessageAttachments($message);
            }
        };
    }

    // --- countRecipients ---

    public function testCountRecipientsWithToOnly(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $this->assertSame(1, $transport->testCountRecipients($message));
    }

    public function testCountRecipientsWithToCcBcc(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setTo(['to@example.com' => 'To'])
            ->setCc(['cc@example.com' => 'CC'])
            ->setBcc(['bcc@example.com' => 'BCC']);
        $this->assertSame(3, $transport->testCountRecipients($message));
    }

    public function testCountRecipientsWithMultipleTo(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setTo(['a@example.com' => 'A', 'b@example.com' => 'B', 'c@example.com' => 'C']);
        $this->assertSame(3, $transport->testCountRecipients($message));
    }

    public function testCountRecipientsWithNoRecipients(): void
    {
        $transport = $this->createConcreteTransport();
        $message = new \Swift_Message();
        $this->assertSame(0, $transport->testCountRecipients($message));
    }

    // --- collectRecipients ---

    public function testCollectRecipientsWithToOnly(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $this->assertSame(['to@example.com'], $transport->testCollectRecipients($message));
    }

    public function testCollectRecipientsWithToCcBcc(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setTo(['to@example.com' => 'To'])
            ->setCc(['cc@example.com' => 'CC'])
            ->setBcc(['bcc@example.com' => 'BCC']);
        $result = $transport->testCollectRecipients($message);
        $this->assertCount(3, $result);
        $this->assertContains('to@example.com', $result);
        $this->assertContains('cc@example.com', $result);
        $this->assertContains('bcc@example.com', $result);
    }

    public function testCollectRecipientsWithNoRecipients(): void
    {
        $transport = $this->createConcreteTransport();
        $message = new \Swift_Message();
        $this->assertSame([], $transport->testCollectRecipients($message));
    }

    // --- getEnvelopeSender ---

    public function testGetEnvelopeSenderFromMessage(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setFrom(['from@example.com' => 'From']);
        $this->assertSame('from@example.com', $transport->testGetEnvelopeSender($message));
    }

    public function testGetEnvelopeSenderReturnsNullWhenNoFrom(): void
    {
        $transport = $this->createConcreteTransport();
        $message = new \Swift_Message();
        $this->assertNull($transport->testGetEnvelopeSender($message));
    }

    // --- formatAddress ---

    public function testFormatAddressWithEmailOnly(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame('test@example.com', $transport->testFormatAddress('test@example.com'));
    }

    public function testFormatAddressWithName(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame('John Doe <john@example.com>', $transport->testFormatAddress('john@example.com', 'John Doe'));
    }

    public function testFormatAddressWithNullName(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame('test@example.com', $transport->testFormatAddress('test@example.com', null));
    }

    public function testFormatAddressWithEmptyName(): void
    {
        $transport = $this->createConcreteTransport();
        // Empty string is falsy, so treated same as no name
        $this->assertSame('test@example.com', $transport->testFormatAddress('test@example.com', ''));
    }

    // --- formatAddresses ---

    public function testFormatAddressesSingle(): void
    {
        $transport = $this->createConcreteTransport();
        $result = $transport->testFormatAddresses(['test@example.com' => 'Test']);
        $this->assertSame(['Test <test@example.com>'], $result);
    }

    public function testFormatAddressesMultiple(): void
    {
        $transport = $this->createConcreteTransport();
        $result = $transport->testFormatAddresses([
            'a@example.com' => 'Alice',
            'b@example.com' => 'Bob',
        ]);
        $this->assertSame(['Alice <a@example.com>', 'Bob <b@example.com>'], $result);
    }

    public function testFormatAddressesWithNullNames(): void
    {
        $transport = $this->createConcreteTransport();
        $result = $transport->testFormatAddresses([
            'a@example.com' => null,
            'b@example.com' => null,
        ]);
        $this->assertSame(['a@example.com', 'b@example.com'], $result);
    }

    public function testFormatAddressesEmpty(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame([], $transport->testFormatAddresses([]));
    }

    // --- getMessageBody ---

    public function testGetMessageBodyPlainText(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setTo(['to@example.com' => 'To'])
            ->setBody('Hello plain', 'text/plain');
        $result = $transport->testGetMessageBody($message);
        $this->assertSame('Hello plain', $result['text']);
        $this->assertNull($result['html']);
    }

    public function testGetMessageBodyHtml(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setTo(['to@example.com' => 'To'])
            ->setBody('<p>Hello</p>', 'text/html');
        $result = $transport->testGetMessageBody($message);
        $this->assertSame('<p>Hello</p>', $result['html']);
        $this->assertNull($result['text']);
    }

    public function testGetMessageBodyWithAlternativeParts(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setTo(['to@example.com' => 'To'])
            ->setBody('Plain text', 'text/plain')
            ->addPart('<p>HTML</p>', 'text/html');
        $result = $transport->testGetMessageBody($message);
        $this->assertSame('Plain text', $result['text']);
        $this->assertSame('<p>HTML</p>', $result['html']);
    }

    // --- extractTags ---

    public function testExtractTagsNoTags(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $tags = $transport->testExtractTags($message);
        $this->assertSame([], $tags);
    }

    public function testExtractTagsSingleTag(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'campaign-1');
        $tags = $transport->testExtractTags($message);
        $this->assertSame(['campaign-1'], $tags);
    }

    public function testExtractTagsMultipleTags(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'tag-1');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'tag-2');
        $tags = $transport->testExtractTags($message);
        $this->assertSame(['tag-1', 'tag-2'], $tags);
    }

    public function testExtractTagsRemovesHeaders(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'tag-1');
        $transport->testExtractTags($message);
        $this->assertFalse($message->getHeaders()->has('X-Mailer-Tag'));
    }

    // --- extractMetadata ---

    public function testExtractMetadataNoMetadata(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $metadata = $transport->testExtractMetadata($message);
        $this->assertSame([], $metadata);
    }

    public function testExtractMetadataSingle(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '123');
        $metadata = $transport->testExtractMetadata($message);
        $this->assertSame(['user_id' => '123'], $metadata);
    }

    public function testExtractMetadataMultiple(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '123');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-env', 'prod');
        $metadata = $transport->testExtractMetadata($message);
        $this->assertSame('123', $metadata['user_id']);
        $this->assertSame('prod', $metadata['env']);
    }

    public function testExtractMetadataRemovesHeaders(): void
    {
        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())->setTo(['to@example.com' => 'To']);
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '123');
        $transport->testExtractMetadata($message);
        $this->assertFalse($message->getHeaders()->has('X-Mailer-Metadata-user_id'));
    }

    // --- Ping ---

    public function testPingReturnsTrue200(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $this->assertTrue($transport->ping());
    }

    public function testPingReturnsTrue299(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(299);
        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $this->assertTrue($transport->ping());
    }

    public function testPingReturnsFalse300(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(300);
        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $this->assertFalse($transport->ping());
    }

    public function testPingReturnsFalse500(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $this->assertFalse($transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $this->assertFalse($transport->ping());
    }

    // --- Start/Stop ---

    public function testStartSetsStarted(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $this->assertFalse($transport->isStarted());
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testStartIsIdempotent(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $transport->start();
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testStartCancelledByBubble(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $evt->method('bubbleCancelled')->willReturn(true);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $transport = $this->createConcreteTransport();
        $transport->start();
        $this->assertFalse($transport->isStarted());
    }

    // --- Send ---

    public function testSendAutoStarts(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $result = $transport->send($message);
        $this->assertSame(1, $result);
        $this->assertTrue($transport->isStarted());
    }

    public function testSendReturnsZeroWhenBubbleCancelled(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $sendEvt->method('bubbleCancelled')->willReturn(true);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $result = $transport->send($message);
        $this->assertSame(0, $result);
    }

    public function testSendThrowsOnDoSendException(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $transport = $this->createConcreteTransport(function () {
            throw new \RuntimeException('API error');
        });

        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('API error');
        $transport->send($message);
    }

    // --- API key ---

    public function testApiKeyIsSet(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame('test-key', $transport->apiKey);
    }

    // --- HTTP Client ---

    public function testHttpClientIsSet(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertSame($this->httpClientMock, $transport->httpClient);
    }

    // --- activeEnvelope ---

    public function testActiveEnvelopeIsNullByDefault(): void
    {
        $transport = $this->createConcreteTransport();
        $this->assertNull($transport->activeEnvelope);
    }

    public function testActiveEnvelopeIsNullAfterSend(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $transport = $this->createConcreteTransport();
        $message = (new \Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $envelope = new \Swift_Envelope('sender@example.com', ['to@example.com']);
        $transport->send($message, $failures, $envelope);

        $this->assertNull($transport->activeEnvelope);
    }
}
