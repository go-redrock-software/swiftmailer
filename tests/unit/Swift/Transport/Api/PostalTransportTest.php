<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class PostalTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_PostalTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_PostalTransport(
            'test-postal-api-key',
            'postal.example.com',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender Name']);
        $message->setTo(['recipient@example.com' => 'Recipient Name']);
        $message->setSubject('Test Subject');
        $message->setBody('Plain text body');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => [
                'message_id' => 'uuid@rp.postal.example.com',
                'messages'   => ['recipient@example.com' => ['id' => 123]],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://postal.example.com/api/v1/send/message',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('Sender Name <sender@example.com>', $payload['from']);
                    $this->assertSame('sender@example.com', $payload['sender']);
                    $this->assertSame(['recipient@example.com'], $payload['to']);
                    $this->assertSame('Test Subject', $payload['subject']);
                    $this->assertSame('Plain text body', $payload['plain_body']);
                    $this->assertArrayNotHasKey('html_body', $payload);
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);
                    $this->assertArrayNotHasKey('reply_to', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(1, $count);
    }

    public function testSendHtmlMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('HTML Test');
        $message->setBody('<p>Hello HTML</p>', 'text/html');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'html-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('<p>Hello HTML</p>', $payload['html_body']);
                    $this->assertArrayNotHasKey('plain_body', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithTextAndHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Multipart Test');
        $message->setBody('Plain text version');
        // Swift_Mime_SimpleMessage has no addPart(); attach the HTML part directly
        // (this is what Swift_Message::addPart() does internally).
        $message->attach(new \Swift_MimePart('<p>HTML version</p>', 'text/html'));

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'multipart-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Both parts present when a message carries text and HTML
                    $this->assertSame('Plain text version', $payload['plain_body']);
                    $this->assertSame('<p>HTML version</p>', $payload['html_body']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'To User']);
        $message->setCc(['cc@example.com' => 'CC User']);
        $message->setBcc(['bcc@example.com' => 'BCC User']);
        $message->setSubject('CC/BCC Test');
        $message->setBody('Body text');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'cc-bcc-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Postal uses plain email address arrays
                    $this->assertSame(['to@example.com'], $payload['to']);
                    $this->assertSame(['cc@example.com'], $payload['cc']);
                    $this->assertSame(['bcc@example.com'], $payload['bcc']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(3, $count);
    }

    public function testSendWithMultipleRecipients(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo([
            'first@example.com'  => 'First Recipient',
            'second@example.com' => 'Second Recipient',
            'third@example.com'  => 'Third Recipient',
        ]);
        $message->setSubject('Multiple Recipients Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'multi-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Postal serialises recipients as a plain email-address array
                    $this->assertSame(
                        ['first@example.com', 'second@example.com', 'third@example.com'],
                        $payload['to'],
                    );

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(3, $count);
    }

    public function testSendWithReplyTo(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setReplyTo(['reply@example.com' => 'Reply User']);
        $message->setSubject('Reply-To Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'reply-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('reply@example.com', $payload['reply_to']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithAttachments(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Attachment Test');
        $message->setBody('Body text');
        $message->attach(new \Swift_Attachment('file content', 'document.pdf', 'application/pdf'));

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'attach-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);

                    $attachment = $payload['attachments'][0];
                    $this->assertSame('document.pdf', $attachment['name']);
                    $this->assertSame('application/pdf', $attachment['content_type']);
                    $this->assertSame(\base64_encode('file content'), $attachment['data']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(1, $count);
    }

    public function testSendWithTags(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Tag Test');
        $message->setBody('Body');

        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome-email');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'second-tag');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'tag-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Postal only supports a single tag — first one should be used
                    $this->assertSame('welcome-email', $payload['tag']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testAuthHeaderIsServerApiKey(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Auth Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'auth-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('X-Server-API-Key', $options['headers']);
                    $this->assertSame('test-postal-api-key', $options['headers']['X-Server-API-Key']);

                    // Verify it's NOT using Bearer auth
                    $this->assertArrayNotHasKey('Authorization', $options['headers']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://postal.example.com/api/v1/messages/deliveries',
                $this->callback(function (array $options): bool {
                    $this->assertSame('test-postal-api-key', $options['headers']['X-Server-API-Key']);

                    return true;
                }),
            )
            ->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', '/'),
            ));

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertFalse($this->transport->ping());
    }

    public function testSendApiErrorThrowsException(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Error Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'status' => 'error',
            'data'   => [
                'code'    => 'ValidationError',
                'message' => 'The from address is not valid',
            ],
        ]);

        $this->httpClientMock->method('request')->willReturn($response);

        $this->setupEventMocks();

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Postal API error (ValidationError): The from address is not valid');

        $this->transport->send($message);
    }

    public function testCustomHostIsUsedInEndpoint(): void
    {
        $transport = new \Swift_Transport_Api_PostalTransport(
            'key',
            'mail.custom-server.org',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Custom Host Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'custom-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://mail.custom-server.org/api/v1/send/message',
                $this->anything(),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $transport->send($message);
    }

    public function testHostTrailingSlashIsTrimmed(): void
    {
        $transport = new \Swift_Transport_Api_PostalTransport(
            'key',
            'postal.example.com/',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Trim Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'status' => 'success',
            'data'   => ['message_id' => 'trim-uuid'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://postal.example.com/api/v1/send/message',
                $this->anything(),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $transport->send($message);
    }

    private function createSwiftMessage(): \Swift_Mime_SimpleMessage
    {
        return new \Swift_Mime_SimpleMessage(
            new \Swift_Mime_SimpleHeaderSet(
                new \Swift_Mime_SimpleHeaderFactory(
                    new \Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                    new \Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new \Egulias\EmailValidator\EmailValidator(),
                ),
            ),
            new \Swift_Mime_ContentEncoder_Base64ContentEncoder(),
            new \Swift_KeyCache_ArrayKeyCache(new \Swift_KeyCache_SimpleKeyCacheInputStream()),
            new \Swift_Mime_IdGenerator('example.com'),
        );
    }

    private function createMockResponse(int $statusCode, array $body): ResponseInterface
    {
        $json   = \json_encode($body);
        $stream = $this->createMock(StreamInterface::class);
        // getResponseBody() consumes the stream via eof()/read(); a real PSR-7 stream
        // yields its contents once and then reports EOF. Stub that so the read loop
        // terminates instead of spinning forever on an unstubbed eof() (default false).
        $stream->method('getSize')->willReturn(\strlen($json));
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn($json);
        $stream->method('getContents')->willReturn($json);
        $stream->method('__toString')->willReturn($json);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function setupEventMocks(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);
    }
}
