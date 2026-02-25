<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class MailPaceTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_MailPaceTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailPaceTransport(
            'test-mailpace-token',
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
                'https://app.mailpace.com/api/v1/send',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Verify MailPace-specific field names
                    $this->assertEquals('Sender Name <sender@example.com>', $payload['from']);
                    $this->assertEquals('Recipient Name <recipient@example.com>', $payload['to']);
                    $this->assertEquals('Test Subject', $payload['subject']);
                    $this->assertEquals('Plain text body', $payload['textbody']);

                    // Must be 'textbody', not 'text' or 'text_body'
                    $this->assertArrayHasKey('textbody', $payload);
                    $this->assertArrayNotHasKey('text', $payload);
                    $this->assertArrayNotHasKey('text_body', $payload);

                    // No HTML body for plain text message
                    $this->assertArrayNotHasKey('htmlbody', $payload);
                    $this->assertArrayNotHasKey('cc', $payload);
                    $this->assertArrayNotHasKey('bcc', $payload);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'id' => 123,
                'status' => 'pending',
            ])));

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
            ->setBody('<p>Hello</p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // Must be 'htmlbody', not 'html' or 'html_body'
                    $this->assertArrayHasKey('htmlbody', $payload);
                    $this->assertArrayNotHasKey('html', $payload);
                    $this->assertArrayNotHasKey('html_body', $payload);
                    $this->assertEquals('<p>Hello</p>', $payload['htmlbody']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'id' => 456,
                'status' => 'pending',
            ])));

        $this->transport->send($message);
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
                'https://app.mailpace.com/api/v1/send',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertEquals('To One <to1@example.com>, To Two <to2@example.com>', $payload['to']);
                    $this->assertEquals('CC User <cc@example.com>', $payload['cc']);
                    $this->assertEquals('BCC User <bcc@example.com>', $payload['bcc']);

                    // Must be 'replyto' (no hyphen), not 'reply_to' or 'reply-to'
                    $this->assertArrayHasKey('replyto', $payload);
                    $this->assertArrayNotHasKey('reply_to', $payload);
                    $this->assertArrayNotHasKey('reply-to', $payload);
                    $this->assertEquals('Reply User <reply@example.com>', $payload['replyto']);

                    $this->assertEquals('<p>HTML body</p>', $payload['htmlbody']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'id' => 789,
                'status' => 'pending',
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(4, $sent);
    }

    public function testAuthHeaderIsMailPaceServerToken(): void
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
                    $this->assertArrayHasKey('MailPace-Server-Token', $options['headers']);
                    $this->assertEquals('test-mailpace-token', $options['headers']['MailPace-Server-Token']);

                    // Verify it is NOT using Bearer auth
                    $this->assertArrayNotHasKey('Authorization', $options['headers']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'id' => 100,
                'status' => 'pending',
            ])));

        $this->transport->send($message);
    }

    public function testPingReturnsTrue(): void
    {
        // MailPace has no health endpoint — ping should always return true
        // without making any HTTP request
        $this->httpClientMock->expects($this->never())
            ->method('request');

        $this->assertTrue($this->transport->ping());
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
            ->willReturn(new Response(403, [], json_encode([
                'error' => 'Invalid API token',
            ])));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('MailPace API error (403): Invalid API token');

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
                'https://app.mailpace.com/api/v1/send',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);

                    $attachment = $payload['attachments'][0];
                    $this->assertEquals('document.pdf', $attachment['name']);
                    $this->assertEquals(base64_encode('file content'), $attachment['content']);
                    $this->assertEquals('application/pdf', $attachment['content_type']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'id' => 200,
                'status' => 'pending',
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
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
}
