<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class SweegoTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_SweegoTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_SweegoTransport(
            'test-sweego-api-key',
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

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-1234']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.sweego.io/send',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertSame([['email' => 'recipient@example.com']], $payload['recipients']);
                    $this->assertSame('Test Subject', $payload['subject']);
                    $this->assertSame('Plain text body', $payload['message-txt']);
                    $this->assertArrayNotHasKey('message-html', $payload);
                    $this->assertArrayNotHasKey('headers', $payload);
                    $this->assertArrayNotHasKey('attachments', $payload);

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

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-5678']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('<h1>Hello</h1>', $payload['message-html']);
                    $this->assertArrayNotHasKey('message-txt', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithCc(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setSubject('CC Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-cc']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // CC recipients should be merged into the recipients array
                    $this->assertCount(2, $payload['recipients']);
                    $this->assertSame(['email' => 'to@example.com'], $payload['recipients'][0]);
                    $this->assertSame(['email' => 'cc@example.com'], $payload['recipients'][1]);

                    // CC should appear in headers object
                    $this->assertArrayHasKey('headers', $payload);
                    $this->assertSame('CC User <cc@example.com>', $payload['headers']['Cc']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $count = $this->transport->send($message);

        $this->assertSame(2, $count);
    }

    public function testSendMessageWithBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setBcc(['bcc@example.com' => 'BCC User'])
            ->setSubject('BCC Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-bcc']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // BCC recipients should be merged into the recipients array
                    $this->assertCount(2, $payload['recipients']);
                    $this->assertSame(['email' => 'to@example.com'], $payload['recipients'][0]);
                    $this->assertSame(['email' => 'bcc@example.com'], $payload['recipients'][1]);

                    // BCC should NOT appear in headers
                    $this->assertArrayNotHasKey('headers', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $count = $this->transport->send($message);

        $this->assertSame(2, $count);
    }

    public function testSendMessageWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setBcc(['bcc@example.com' => 'BCC User'])
            ->setSubject('CC+BCC Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-ccbcc']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // All recipients merged into recipients
                    $this->assertCount(3, $payload['recipients']);
                    $this->assertSame(['email' => 'to@example.com'], $payload['recipients'][0]);
                    $this->assertSame(['email' => 'cc@example.com'], $payload['recipients'][1]);
                    $this->assertSame(['email' => 'bcc@example.com'], $payload['recipients'][2]);

                    // Only CC header, no BCC header
                    $this->assertArrayHasKey('Cc', $payload['headers']);
                    $this->assertArrayNotHasKey('Bcc', $payload['headers']);

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

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-reply']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('headers', $payload);
                    $this->assertSame('Reply User <replyto@example.com>', $payload['headers']['Reply-To']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithAttachments(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Attachment Test')
            ->setBody('Body with attachment')
            ->attach(new \Swift_Attachment('file contents', 'document.txt', 'text/plain'));

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-attach']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);
                    $this->assertSame('file contents', $payload['attachments'][0]['content']);
                    $this->assertSame('document.txt', $payload['attachments'][0]['filename']);
                    $this->assertSame('attachment', $payload['attachments'][0]['disposition']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testRequiredFieldsAlwaysPresent(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Required Fields Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-required']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame('email', $payload['channel']);
                    $this->assertSame('sweego', $payload['provider']);
                    $this->assertSame('transactional', $payload['campaign-type']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testAuthHeaderIsSet(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Auth Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-auth']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame('test-sweego-api-key', $options['headers']['Api-Key']);

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
                'https://api.sweego.io/send',
                $this->callback(function (array $options): bool {
                    $this->assertSame('test-sweego-api-key', $options['headers']['Api-Key']);

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

        $response = $this->createMockResponse(400, [
            'message' => 'Invalid request payload',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email via Swift_Transport_Api_SweegoTransport');

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithInlineImage(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Inline Image Test');
        $message->setBody('<p>Hello <img src="'.$message->embed(new \Swift_Image('image data', 'logo.png', 'image/png')).'" /></p>', 'text/html');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-inline']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Embedded images are emitted as inline attachments carrying a content_id
                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);
                    $this->assertSame('image data', $payload['attachments'][0]['content']);
                    $this->assertSame('logo.png', $payload['attachments'][0]['filename']);
                    $this->assertSame('inline', $payload['attachments'][0]['disposition']);
                    $this->assertArrayHasKey('content_id', $payload['attachments'][0]);
                    $this->assertNotEmpty($payload['attachments'][0]['content_id']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithMultipartBody(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Multipart Test')
            ->setBody('Plain text body');
        // Swift_Mime_SimpleMessage has no addPart(); attaching a MimePart is exactly
        // what Swift_Message::addPart() does internally to add the HTML alternative.
        $message->attach(new \Swift_MimePart('<h1>Hello</h1>', 'text/html'));

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-multipart']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Both plain-text and HTML bodies are sent together
                    $this->assertSame('Plain text body', $payload['message-txt']);
                    $this->assertSame('<h1>Hello</h1>', $payload['message-html']);
                    // A MimePart alternative is not treated as an attachment
                    $this->assertArrayNotHasKey('attachments', $payload);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendMessageWithMultipleRecipients(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo([
                'first@example.com'  => 'First User',
                'second@example.com' => 'Second User',
            ])
            ->setSubject('Multiple Recipients Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-multi']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Each To recipient becomes its own entry; the display name is omitted
                    $this->assertCount(2, $payload['recipients']);
                    $this->assertSame(['email' => 'first@example.com'], $payload['recipients'][0]);
                    $this->assertSame(['email' => 'second@example.com'], $payload['recipients'][1]);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $count = $this->transport->send($message);

        $this->assertSame(2, $count);
    }

    public function testSendMessageFromWithoutName(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom('sender@example.com')
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('From Without Name Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, ['transaction_id' => 'uuid-noname']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // With no display name the 'name' key is filtered out of the from object
                    $this->assertSame(['email' => 'sender@example.com'], $payload['from']);

                    return true;
                }),
            )
            ->willReturn($response);

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
}
