<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class MailtrapTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_MailtrapTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailtrapTransport(
            'test-mailtrap-api-key',
            false,
            null,
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessageLive(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender Name'])
            ->setTo(['recipient@example.com' => 'Recipient Name'])
            ->setSubject('Test Subject')
            ->setBody('Hello plain text');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-1'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://send.api.mailtrap.io/api/send',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertSame([['email' => 'recipient@example.com', 'name' => 'Recipient Name']], $payload['to']);
                    $this->assertSame('Test Subject', $payload['subject']);
                    $this->assertSame('Hello plain text', $payload['text']);
                    $this->assertArrayNotHasKey('html', $payload);
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);
                    $this->assertArrayNotHasKey('attachments', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(1, $count);
    }

    public function testSendBasicMessageSandbox(): void
    {
        $sandboxTransport = new \Swift_Transport_Api_MailtrapTransport(
            'test-mailtrap-api-key',
            true,
            'inbox-12345',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender Name'])
            ->setTo(['recipient@example.com' => 'Recipient Name'])
            ->setSubject('Sandbox Test')
            ->setBody('Sandbox body');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-sandbox'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://sandbox.api.mailtrap.io/api/send/inbox-12345',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertSame([['email' => 'recipient@example.com', 'name' => 'Recipient Name']], $payload['to']);
                    $this->assertSame('Sandbox Test', $payload['subject']);
                    $this->assertSame('Sandbox body', $payload['text']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $sandboxTransport->send($message);
        $this->assertSame(1, $count);
    }

    public function testSendHtmlMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('HTML Test')
            ->setBody('<h1>Hello HTML</h1>', 'text/html');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-html'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('<h1>Hello HTML</h1>', $payload['html']);
                    $this->assertArrayNotHasKey('text', $payload);

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
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setBcc(['bcc@example.com' => 'BCC User'])
            ->setSubject('CC/BCC Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-ccbcc'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame([['email' => 'cc@example.com', 'name' => 'CC User']], $payload['cc']);
                    $this->assertSame([['email' => 'bcc@example.com', 'name' => 'BCC User']], $payload['bcc']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(3, $count);
    }

    public function testSendWithAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Attachment Test')
            ->setBody('Body with attachment')
            ->attach(new \Swift_Attachment('file contents', 'document.txt', 'text/plain'));

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-attach'],
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
                    $this->assertSame('document.txt', $payload['attachments'][0]['filename']);
                    $this->assertSame('text/plain', $payload['attachments'][0]['type']);
                    $this->assertSame(\base64_encode('file contents'), $payload['attachments'][0]['content']);
                    $this->assertSame('attachment', $payload['attachments'][0]['disposition']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithTags(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Tags Test')
            ->setBody('Body');

        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome-email');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'onboarding');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-tags'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Mailtrap only supports a single category — use the first tag
                    $this->assertSame('welcome-email', $payload['category']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithMetadata(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Metadata Test')
            ->setBody('Body');

        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-campaign', 'spring-sale');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-meta'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('custom_variables', $payload);
                    $this->assertSame('12345', $payload['custom_variables']['user_id']);
                    $this->assertSame('spring-sale', $payload['custom_variables']['campaign']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testAuthHeaderIsSet(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Auth Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-auth'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('Authorization', $options['headers']);
                    $this->assertSame('Bearer test-mailtrap-api-key', $options['headers']['Authorization']);

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
                'https://send.api.mailtrap.io/api/send',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Bearer test-mailtrap-api-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->assertTrue($this->transport->ping());
    }

    public function testPingSandboxEndpoint(): void
    {
        $sandboxTransport = new \Swift_Transport_Api_MailtrapTransport(
            'test-mailtrap-api-key',
            true,
            'inbox-99',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://sandbox.api.mailtrap.io/api/send/inbox-99',
                $this->anything(),
            )
            ->willReturn($response);

        $this->assertTrue($sandboxTransport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->assertFalse($this->transport->ping());
    }

    public function testSendApiErrorThrowsException(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Error Test')
            ->setBody('Body');

        $response = $this->createMockResponse(422, [
            'success' => false,
            'errors'  => ['Invalid email address', 'Missing required field'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->setupEventMocks();

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Mailtrap API error (422): Invalid email address; Missing required field');

        $this->transport->send($message);
    }

    public function testSendApiErrorWithSuccessFalseAndOk200(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Error Test 200')
            ->setBody('Body');

        $response = $this->createMockResponse(200, [
            'success' => false,
            'errors'  => ['Rate limit exceeded'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->setupEventMocks();

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Mailtrap API error (200): Rate limit exceeded');

        $this->transport->send($message);
    }

    public function testSendWithInlineAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Inline test');
        $message->setBody('<p>Hello <img src="'.$message->embed(new \Swift_Image('image data', 'logo.png', 'image/png')).'" /></p>', 'text/html');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-inline'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options): bool {
                $payload = $options['json'];

                $this->assertArrayHasKey('attachments', $payload);
                $inlineFound = false;
                foreach ($payload['attachments'] as $att) {
                    if (isset($att['content_id'])) {
                        $inlineFound = true;
                    }
                }
                $this->assertTrue($inlineFound, 'Expected an inline attachment with content_id');

                return true;
            }))
            ->willReturn($response);

        $this->setupEventMocks();
        $this->transport->send($message);
    }

    public function testFromWithoutName(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['noreply@example.com' => null])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('No Name Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-noname'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // With null name, array_filter removes the name key
                    $this->assertSame(['email' => 'noreply@example.com'], $payload['from']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithMultipleRecipients(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo([
                'first@example.com'  => 'First Recipient',
                'second@example.com' => 'Second Recipient',
            ])
            ->setSubject('Multiple Recipients Test')
            ->setBody('Body for many');

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-multi'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame([
                        ['email' => 'first@example.com', 'name' => 'First Recipient'],
                        ['email' => 'second@example.com', 'name' => 'Second Recipient'],
                    ], $payload['to']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(2, $count);
    }

    public function testSendWithTextAndHtmlParts(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Multipart Test')
            ->setBody('Plain text alternative');
        $message->attach(new \Swift_MimePart('<h1>HTML alternative</h1>', 'text/html'));

        $response = $this->createMockResponse(200, [
            'success'     => true,
            'message_ids' => ['msg-uuid-multipart'],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('Plain text alternative', $payload['text']);
                    $this->assertSame('<h1>HTML alternative</h1>', $payload['html']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
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
