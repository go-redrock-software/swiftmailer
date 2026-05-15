<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class AhaSendTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_AhaSendTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_AhaSendTransport(
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

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.ahasend.com/v1/email/send',
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('X-Api-Key', $options['headers']);
                    $this->assertEquals('test-api-key', $options['headers']['X-Api-Key']);

                    $this->assertEquals('application/json', $options['headers']['Content-Type']);
                    $this->assertEquals('application/json', $options['headers']['Accept']);

                    $payload = $options['json'];
                    $this->assertEquals(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertEquals([['email' => 'recipient@example.com', 'name' => 'Recipient Name']], $payload['recipients']);
                    $this->assertEquals('Test Subject', $payload['subject']);
                    $this->assertEquals('Plain text body', $payload['content']['text_body']);
                    $this->assertArrayNotHasKey('html_body', $payload['content']);
                    $this->assertArrayNotHasKey('attachments', $payload);

                    return true;
                }),
            )
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid-123', 'status' => 'queued'],
                ],
            ]));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('HTML test')
            ->setBody('<p>Hello world</p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];
                    $this->assertEquals('<p>Hello world</p>', $payload['content']['html_body']);
                    $this->assertArrayNotHasKey('text_body', $payload['content']);

                    return true;
                }),
            )
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid-html', 'status' => 'queued'],
                ],
            ]));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to1@example.com' => 'To One', 'to2@example.com' => 'To Two'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setBcc(['bcc@example.com' => 'BCC User'])
            ->setSubject('Multi-recipient test')
            ->setBody('Body text');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload    = $options['json'];
                    $recipients = $payload['recipients'];

                    $this->assertCount(4, $recipients);
                    $this->assertEquals(['email' => 'to1@example.com', 'name' => 'To One'], $recipients[0]);
                    $this->assertEquals(['email' => 'to2@example.com', 'name' => 'To Two'], $recipients[1]);
                    $this->assertEquals(['email' => 'cc@example.com', 'name' => 'CC User'], $recipients[2]);
                    $this->assertEquals(['email' => 'bcc@example.com', 'name' => 'BCC User'], $recipients[3]);

                    // AhaSend merges all into recipients — no separate CC/BCC fields
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);

                    return true;
                }),
            )
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid-multi', 'status' => 'queued'],
                ],
            ]));

        $sent = $this->transport->send($message);
        $this->assertEquals(4, $sent);
    }

    public function testSendWithAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Attachment test')
            ->setBody('Body text')
            ->attach(new \Swift_Attachment('file content', 'document.pdf', 'application/pdf'));

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
                    $this->assertEquals('document.pdf', $attachment['file_name']);
                    $this->assertEquals('application/pdf', $attachment['content_type']);
                    $this->assertEquals(\base64_encode('file content'), $attachment['data']);
                    $this->assertArrayNotHasKey('content_id', $attachment);

                    return true;
                }),
            )
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid-attach', 'status' => 'queued'],
                ],
            ]));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testAuthHeaderIsXApiKey(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Auth test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('X-Api-Key', $options['headers']);
                    $this->assertEquals('test-api-key', $options['headers']['X-Api-Key']);

                    // Should NOT use Bearer auth or other schemes
                    $this->assertArrayNotHasKey('Authorization', $options['headers']);

                    return true;
                }),
            )
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid', 'status' => 'queued'],
                ],
            ]));

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.ahasend.com/v1/email/send',
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('X-Api-Key', $options['headers']);
                    $this->assertEquals('test-api-key', $options['headers']['X-Api-Key']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], '{}'));

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
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Error test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse(422, [
                'error' => [
                    'type'    => 'validation_error',
                    'message' => 'Invalid email address',
                ],
            ]));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('AhaSend API error [validation_error]: Invalid email address');

        $this->transport->send($message);
    }

    public function testSendWithInlineAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Inline test');
        $message->setBody('<p>Hello <img src="' . $message->embed(new \Swift_Image('image data', 'logo.png', 'image/png')) . '" /></p>', 'text/html');

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
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid-inline', 'status' => 'queued'],
                ],
            ]));

        $this->transport->send($message);
    }

    public function testFromWithoutNameOmitsNameField(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('No name test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];
                    $this->assertEquals(['email' => 'from@example.com'], $payload['from']);
                    $this->assertArrayNotHasKey('name', $payload['from']);

                    // Recipient without name should also omit name
                    $this->assertEquals(['email' => 'to@example.com'], $payload['recipients'][0]);
                    $this->assertArrayNotHasKey('name', $payload['recipients'][0]);

                    return true;
                }),
            )
            ->willReturn($this->createMockResponse(200, [
                'object' => 'list',
                'data'   => [
                    ['object' => 'message', 'id' => 'uuid-noname', 'status' => 'queued'],
                ],
            ]));

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
