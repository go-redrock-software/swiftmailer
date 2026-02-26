<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class MailomatTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_MailomatTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailomatTransport(
            'test-api-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender Name'])
            ->setTo(['recipient@example.com' => 'Recipient Name'])
            ->setSubject('Test Subject')
            ->setBody('Plain text body');

        $response = $this->createMockResponse(202, ['messageUuid' => 'uuid-123']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailomat.swiss/message',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertSame([['email' => 'recipient@example.com', 'name' => 'Recipient Name']], $payload['to']);
                    $this->assertSame('Test Subject', $payload['subject']);
                    $this->assertSame('Plain text body', $payload['text']);
                    $this->assertArrayNotHasKey('html', $payload);
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);
                    $this->assertArrayNotHasKey('replyTo', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $count = $this->transport->send($message);

        $this->assertSame(1, $count);
    }

    public function testSendMessageWithHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('HTML Test')
            ->setBody('<h1>Hello</h1>', 'text/html');

        $response = $this->createMockResponse(202, ['messageUuid' => 'uuid-html']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailomat.swiss/message',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('<h1>Hello</h1>', $payload['html']);
                    $this->assertArrayNotHasKey('text', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setBcc(['bcc@example.com' => 'BCC User'])
            ->setSubject('CC/BCC Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(202, ['messageUuid' => 'uuid-456']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailomat.swiss/message',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame([['email' => 'to@example.com', 'name' => 'To User']], $payload['to']);
                    $this->assertSame([['email' => 'cc@example.com', 'name' => 'CC User']], $payload['cc']);
                    $this->assertSame([['email' => 'bcc@example.com', 'name' => 'BCC User']], $payload['bcc']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $count = $this->transport->send($message);

        $this->assertSame(3, $count);
    }

    public function testSendMessageWithReplyTo(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setReplyTo(['replyto@example.com' => 'Reply User'])
            ->setSubject('Reply-To Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(202, ['messageUuid' => 'uuid-789']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailomat.swiss/message',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame([['email' => 'replyto@example.com', 'name' => 'Reply User']], $payload['replyTo']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Attachment Test')
            ->setBody('Body with attachment')
            ->attach(new \Swift_Attachment('file contents', 'document.txt', 'text/plain'));

        $response = $this->createMockResponse(202, ['messageUuid' => 'uuid-attach']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailomat.swiss/message',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);
                    $this->assertSame('document.txt', $payload['attachments'][0]['filename']);
                    $this->assertSame(\base64_encode('file contents'), $payload['attachments'][0]['contentBase64']);
                    $this->assertSame('text/plain', $payload['attachments'][0]['contentType']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testAuthHeaderContainsBearerToken(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Auth Test')
            ->setBody('Body');

        $response = $this->createMockResponse(202, ['messageUuid' => 'uuid-auth']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame('Bearer test-api-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testPingReturnsTrue(): void
    {
        $response = $this->createMockResponse(200, []);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.mailomat.swiss/events',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Bearer test-api-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->assertFalse($this->transport->ping());
    }

    public function testSendThrowsOnApiError(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Error Test')
            ->setBody('Body');

        $response = $this->createMockResponse(422, [
            'message' => 'Invalid email address',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email via Swift_Transport_Api_MailomatTransport');

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendThrowsOnBadRequest(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Bad Request Test')
            ->setBody('Body');

        $response = $this->createMockResponse(400, [
            'error' => 'Missing required field: to',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email via Swift_Transport_Api_MailomatTransport');

        $this->transport->start();
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
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(\json_encode($body));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
