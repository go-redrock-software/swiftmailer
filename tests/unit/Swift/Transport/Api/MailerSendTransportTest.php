<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class MailerSendTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_MailerSendTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailerSendTransport(
            'test-mailersend-key',
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
                'https://api.mailersend.com/v1/email',
                $this->callback(function (array $options): bool {
                    $this->assertEquals('application/json', $options['headers']['Content-Type']);
                    $this->assertEquals('application/json', $options['headers']['Accept']);

                    $payload = $options['json'];
                    $this->assertEquals(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertCount(1, $payload['to']);
                    $this->assertEquals(['email' => 'recipient@example.com', 'name' => 'Recipient Name'], $payload['to'][0]);
                    $this->assertEquals('Test Subject', $payload['subject']);
                    $this->assertEquals('Plain text body', $payload['text']);
                    $this->assertArrayNotHasKey('html', $payload);
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);
                    $this->assertArrayNotHasKey('reply_to', $payload);

                    return true;
                }),
            )
            ->willReturn(new Response(202, ['x-message-id' => 'msg-abc-123']));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithCcBccAndReplyTo(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to1@example.com' => 'To One', 'to2@example.com' => 'To Two'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setBcc(['bcc@example.com' => 'BCC User'])
            ->setReplyTo(['reply@example.com' => 'Reply User'])
            ->setSubject('Multi-recipient test')
            ->setBody('<p>HTML body</p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailersend.com/v1/email',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // To addresses
                    $this->assertCount(2, $payload['to']);
                    $this->assertEquals(['email' => 'to1@example.com', 'name' => 'To One'], $payload['to'][0]);
                    $this->assertEquals(['email' => 'to2@example.com', 'name' => 'To Two'], $payload['to'][1]);

                    // CC
                    $this->assertCount(1, $payload['cc']);
                    $this->assertEquals(['email' => 'cc@example.com', 'name' => 'CC User'], $payload['cc'][0]);

                    // BCC
                    $this->assertCount(1, $payload['bcc']);
                    $this->assertEquals(['email' => 'bcc@example.com', 'name' => 'BCC User'], $payload['bcc'][0]);

                    // Reply-To
                    $this->assertEquals(['email' => 'reply@example.com', 'name' => 'Reply User'], $payload['reply_to']);

                    // HTML body
                    $this->assertEquals('<p>HTML body</p>', $payload['html']);
                    $this->assertArrayNotHasKey('text', $payload);

                    return true;
                }),
            )
            ->willReturn(new Response(202, ['x-message-id' => 'msg-def-456']));

        $sent = $this->transport->send($message);
        $this->assertEquals(4, $sent);
    }

    public function testPayloadStructureUsesObjectFormat(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'From Name'])
            ->setTo(['to@example.com' => 'To Name'])
            ->setSubject('Payload test')
            ->setBody('text body')
            ->attach(new \Swift_MimePart('<p>html body</p>', 'text/html'));

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // from is an object, not a string
                    $this->assertIsArray($payload['from']);
                    $this->assertArrayHasKey('email', $payload['from']);
                    $this->assertArrayHasKey('name', $payload['from']);

                    // to is an array of objects
                    $this->assertIsArray($payload['to']);
                    $this->assertIsArray($payload['to'][0]);
                    $this->assertArrayHasKey('email', $payload['to'][0]);

                    // Both text and html are present
                    $this->assertEquals('text body', $payload['text']);
                    $this->assertEquals('<p>html body</p>', $payload['html']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, ['x-message-id' => 'msg-ghi-789']));

        $this->transport->send($message);
    }

    public function testAuthHeaderUsesBearerToken(): void
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
                    $this->assertArrayHasKey('Authorization', $options['headers']);
                    $this->assertEquals('Bearer test-mailersend-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, ['x-message-id' => 'msg-auth']));

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.mailersend.com/v1/api-quota',
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('Authorization', $options['headers']);
                    $this->assertEquals('Bearer test-mailersend-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode(['remaining' => 100])));

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
            ->willReturn(new Response(422, [], json_encode([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'from.email' => ['The from.email must be a verified domain.'],
                ],
            ])));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('MailerSend API error');

        $this->transport->send($message);
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
                'https://api.mailersend.com/v1/email',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);

                    $attachment = $payload['attachments'][0];
                    $this->assertEquals('document.pdf', $attachment['filename']);
                    $this->assertEquals(base64_encode('file content'), $attachment['content']);
                    $this->assertEquals('attachment', $attachment['disposition']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, ['x-message-id' => 'msg-attach']));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    private function createSwiftMessage(): \Swift_Mime_SimpleMessage
    {
        return new \Swift_Mime_SimpleMessage(
            new \Swift_Mime_SimpleHeaderSet(new \Swift_Mime_SimpleHeaderFactory(
                new \Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                new \Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                new \Egulias\EmailValidator\EmailValidator(),
            )),
            new \Swift_Mime_ContentEncoder_Base64ContentEncoder(),
            new \Swift_KeyCache_ArrayKeyCache(new \Swift_KeyCache_SimpleKeyCacheInputStream()),
            new \Swift_Mime_IdGenerator('example.com'),
        );
    }
}
