<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class PostMarkTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_PostMarkTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_PostMarkTransport(
            'test-server-token',
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
                'https://api.postmarkapp.com/email',
                $this->callback(function (array $options): bool {
                    // Verify auth header
                    $this->assertArrayHasKey('X-Postmark-Server-Token', $options['headers']);
                    $this->assertEquals('test-server-token', $options['headers']['X-Postmark-Server-Token']);

                    // Verify content type headers
                    $this->assertEquals('application/json', $options['headers']['Content-Type']);
                    $this->assertEquals('application/json', $options['headers']['Accept']);

                    // Verify payload structure
                    $payload = $options['json'];
                    $this->assertEquals('Sender Name <sender@example.com>', $payload['From']);
                    $this->assertEquals('Recipient Name <recipient@example.com>', $payload['To']);
                    $this->assertEquals('Test Subject', $payload['Subject']);
                    $this->assertEquals('Plain text body', $payload['TextBody']);
                    $this->assertArrayNotHasKey('HtmlBody', $payload);
                    $this->assertArrayNotHasKey('Cc', $payload);
                    $this->assertArrayNotHasKey('Bcc', $payload);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                'ErrorCode' => 0,
                'Message'   => 'OK',
                'MessageID' => 'test-message-id',
            ])));

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
                'https://api.postmarkapp.com/email',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertEquals('To One <to1@example.com>, To Two <to2@example.com>', $payload['To']);
                    $this->assertEquals('CC User <cc@example.com>', $payload['Cc']);
                    $this->assertEquals('BCC User <bcc@example.com>', $payload['Bcc']);
                    $this->assertEquals('Reply User <reply@example.com>', $payload['ReplyTo']);
                    $this->assertEquals('<p>HTML body</p>', $payload['HtmlBody']);
                    $this->assertArrayNotHasKey('TextBody', $payload);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                'ErrorCode' => 0,
                'Message'   => 'OK',
                'MessageID' => 'uuid-123',
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(4, $sent);
    }

    public function testAuthHeaderIsPostmarkServerToken(): void
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
                    $this->assertArrayHasKey('X-Postmark-Server-Token', $options['headers']);
                    $this->assertEquals('test-server-token', $options['headers']['X-Postmark-Server-Token']);

                    // Verify it is NOT using Bearer auth
                    $this->assertArrayNotHasKey('Authorization', $options['headers']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                'ErrorCode' => 0,
                'Message'   => 'OK',
                'MessageID' => 'uuid',
            ])));

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.postmarkapp.com/server',
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('X-Postmark-Server-Token', $options['headers']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode(['Name' => 'My Server'])));

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->assertFalse($this->transport->ping());
    }

    public function testSendThrowsOnNonZeroErrorCode(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Error test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(422, [], \json_encode([
                'ErrorCode' => 300,
                'Message'   => 'Invalid email request',
            ])));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Postmark API error 300: Invalid email request');

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
                'https://api.postmarkapp.com/email',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('Attachments', $payload);
                    $this->assertCount(1, $payload['Attachments']);

                    $attachment = $payload['Attachments'][0];
                    $this->assertEquals('document.pdf', $attachment['Name']);
                    $this->assertEquals(\base64_encode('file content'), $attachment['Content']);
                    $this->assertEquals('application/pdf', $attachment['ContentType']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                'ErrorCode' => 0,
                'Message'   => 'OK',
                'MessageID' => 'uuid-attach',
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithTagsAndMetadata(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Tag test')
            ->setBody('Hello');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'onboarding');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '55');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // PostMark only supports single tag
                    $this->assertEquals('welcome', $payload['Tag']);

                    // Metadata object
                    $this->assertEquals(['user_id' => '55'], $payload['Metadata']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                'ErrorCode' => 0,
                'Message'   => 'OK',
                'MessageID' => 'uuid-tag',
            ])));

        $this->transport->send($message);
    }

    public function testSendWithInlineAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Inline test');
        $message->setBody('<p>Hello <img src="'.$message->embed(new \Swift_Image('image data', 'logo.png', 'image/png')).'" /></p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options): bool {
                $payload = $options['json'];

                $this->assertArrayHasKey('Attachments', $payload);
                $inlineFound = false;
                foreach ($payload['Attachments'] as $att) {
                    if (isset($att['ContentID'])) {
                        $this->assertStringStartsWith('cid:', $att['ContentID']);
                        $inlineFound = true;
                    }
                }
                $this->assertTrue($inlineFound, 'Expected an inline attachment with ContentID');

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'ErrorCode' => 0,
                'Message'   => 'OK',
                'MessageID' => 'uuid-inline',
            ])));

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
}
