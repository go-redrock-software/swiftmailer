<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ResendTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_ResendTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_ResendTransport(
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

        $response = $this->createMockResponse(200, ['id' => 'msg-uuid-123']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.resend.com/emails',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('Sender Name <sender@example.com>', $payload['from']);
                    $this->assertSame(['Recipient Name <recipient@example.com>'], $payload['to']);
                    $this->assertSame('Test Subject', $payload['subject']);
                    $this->assertSame('Plain text body', $payload['text']);
                    $this->assertArrayNotHasKey('html', $payload);
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $count = $this->transport->send($message);

        $this->assertSame(1, $count);
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

        $response = $this->createMockResponse(200, ['id' => 'msg-uuid-456']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.resend.com/emails',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame(['To User <to@example.com>'], $payload['to']);
                    $this->assertSame(['CC User <cc@example.com>'], $payload['cc']);
                    $this->assertSame(['BCC User <bcc@example.com>'], $payload['bcc']);

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

        $response = $this->createMockResponse(200, ['id' => 'msg-uuid-789']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.resend.com/emails',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('Reply User <replyto@example.com>', $payload['reply_to']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('HTML Test')
            ->setBody('<h1>Hello</h1>', 'text/html');

        $response = $this->createMockResponse(200, ['id' => 'msg-uuid-html']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.resend.com/emails',
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

    public function testAuthHeaderContainsBearerToken(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Auth Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, ['id' => 'msg-uuid-auth']);

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
                'https://api.resend.com/api-keys',
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
            'statusCode' => 422,
            'name' => 'validation_error',
            'message' => 'Invalid email address',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email via Swift_Transport_Api_ResendTransport');

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

        $response = $this->createMockResponse(200, ['id' => 'msg-uuid-attach']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.resend.com/emails',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);
                    $this->assertSame('document.txt', $payload['attachments'][0]['filename']);
                    $this->assertSame(base64_encode('file contents'), $payload['attachments'][0]['content']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendWithTagsAndMetadata(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Tag test')
            ->setBody('Body');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'invite');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-ref', 'abc');

        $response = $this->createMockResponse(200, ['id' => 'msg-tag']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Tags → array of {name, value}
                    $this->assertEquals([['name' => 'invite', 'value' => 'invite']], $payload['tags']);

                    // Metadata → headers object
                    $this->assertEquals(['ref' => 'abc'], $payload['headers']);

                    return true;
                }),
            )
            ->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

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
        $stream->method('__toString')->willReturn(json_encode($body));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
