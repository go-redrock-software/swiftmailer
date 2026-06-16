<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class MailChimpTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_MailChimpTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailChimpTransport(
            'test-mandrill-key',
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
                'https://mandrillapp.com/api/1.0/messages/send',
                $this->callback(function (array $options): bool {
                    // Auth must NOT be in headers
                    $this->assertArrayNotHasKey('Authorization', $options['headers'] ?? []);

                    // Verify content type headers
                    $this->assertEquals('application/json', $options['headers']['Content-Type']);
                    $this->assertEquals('application/json', $options['headers']['Accept']);

                    // Verify API key is in body
                    $payload = $options['json'];
                    $this->assertEquals('test-mandrill-key', $payload['key']);

                    // Verify message structure
                    $msg = $payload['message'];
                    $this->assertEquals('sender@example.com', $msg['from_email']);
                    $this->assertEquals('Sender Name', $msg['from_name']);
                    $this->assertEquals('Test Subject', $msg['subject']);
                    $this->assertEquals('Plain text body', $msg['text']);
                    $this->assertArrayNotHasKey('html', $msg);

                    // Verify to array
                    $this->assertCount(1, $msg['to']);
                    $this->assertEquals('recipient@example.com', $msg['to'][0]['email']);
                    $this->assertEquals('Recipient Name', $msg['to'][0]['name']);
                    $this->assertEquals('to', $msg['to'][0]['type']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'recipient@example.com', 'status' => 'sent', '_id' => 'abc123'],
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
            ->setBcc(['bcc@example.com' => null])
            ->setReplyTo(['reply@example.com' => 'Reply User'])
            ->setSubject('Multi-recipient test')
            ->setBody('<p>HTML body</p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://mandrillapp.com/api/1.0/messages/send',
                $this->callback(function (array $options): bool {
                    $msg = $options['json']['message'];

                    // Should have 4 recipients in to array with correct types
                    $this->assertCount(4, $msg['to']);

                    $this->assertEquals('to1@example.com', $msg['to'][0]['email']);
                    $this->assertEquals('To One', $msg['to'][0]['name']);
                    $this->assertEquals('to', $msg['to'][0]['type']);

                    $this->assertEquals('to2@example.com', $msg['to'][1]['email']);
                    $this->assertEquals('To Two', $msg['to'][1]['name']);
                    $this->assertEquals('to', $msg['to'][1]['type']);

                    $this->assertEquals('cc@example.com', $msg['to'][2]['email']);
                    $this->assertEquals('CC User', $msg['to'][2]['name']);
                    $this->assertEquals('cc', $msg['to'][2]['type']);

                    $this->assertEquals('bcc@example.com', $msg['to'][3]['email']);
                    $this->assertArrayNotHasKey('name', $msg['to'][3]);
                    $this->assertEquals('bcc', $msg['to'][3]['type']);

                    // Reply-To in headers
                    $this->assertArrayHasKey('headers', $msg);
                    $this->assertEquals('Reply User <reply@example.com>', $msg['headers']['Reply-To']);

                    // HTML body
                    $this->assertEquals('<p>HTML body</p>', $msg['html']);
                    $this->assertArrayNotHasKey('text', $msg);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to1@example.com', 'status' => 'sent', '_id' => 'a1'],
                ['email' => 'to2@example.com', 'status' => 'sent', '_id' => 'a2'],
                ['email' => 'cc@example.com', 'status' => 'sent', '_id' => 'a3'],
                ['email' => 'bcc@example.com', 'status' => 'sent', '_id' => 'a4'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(4, $sent);
    }

    public function testSendWithTextAndHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['recipient@example.com' => 'Recipient'])
            ->setSubject('Multipart test')
            ->setBody('Plain text body');
        $message->attach(new \Swift_MimePart('<p>HTML alternative</p>', 'text/html'));

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://mandrillapp.com/api/1.0/messages/send',
                $this->callback(function (array $options): bool {
                    $msg = $options['json']['message'];

                    // Both alternative parts must be present in the payload
                    $this->assertEquals('Plain text body', $msg['text']);
                    $this->assertEquals('<p>HTML alternative</p>', $msg['html']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'recipient@example.com', 'status' => 'sent', '_id' => 'mp1'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testAuthKeyIsInBodyNotHeaders(): void
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
                    // No auth headers
                    $this->assertArrayNotHasKey('Authorization', $options['headers'] ?? []);
                    $this->assertArrayNotHasKey('X-Api-Key', $options['headers'] ?? []);

                    // API key must be in JSON body
                    $this->assertEquals('test-mandrill-key', $options['json']['key']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to@example.com', 'status' => 'sent', '_id' => 'x'],
            ])));

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOnPong(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://mandrillapp.com/api/1.0/users/ping',
                $this->callback(function (array $options): bool {
                    $this->assertEquals(['key' => 'test-mandrill-key'], $options['json']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], '"PONG!"'));

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->assertFalse($this->transport->ping());
    }

    public function testPingReturnsFalseOnNonPongResponse(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], '"INVALID"'));

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
            ->willReturn(new Response(500, [], \json_encode([
                'status'  => 'error',
                'message' => 'Invalid API key',
            ])));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Mandrill API error: Invalid API key');

        $this->transport->send($message);
    }

    public function testSendCountsQueuedAsSuccess(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com'])
            ->setTo(['a@example.com' => 'A', 'b@example.com' => 'B'])
            ->setSubject('Queued test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'a@example.com', 'status' => 'sent', '_id' => 'x1'],
                ['email' => 'b@example.com', 'status' => 'queued', '_id' => 'x2'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(2, $sent);
    }

    public function testSendCountsRejectedAsFailure(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com'])
            ->setTo(['a@example.com' => 'A', 'b@example.com' => 'B'])
            ->setSubject('Rejected test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'a@example.com', 'status' => 'sent', '_id' => 'x1'],
                ['email' => 'b@example.com', 'status' => 'rejected', '_id' => 'x2'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
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
                'https://mandrillapp.com/api/1.0/messages/send',
                $this->callback(function (array $options): bool {
                    $msg = $options['json']['message'];

                    $this->assertArrayHasKey('attachments', $msg);
                    $this->assertCount(1, $msg['attachments']);

                    $attachment = $msg['attachments'][0];
                    $this->assertEquals('application/pdf', $attachment['type']);
                    $this->assertEquals('document.pdf', $attachment['name']);
                    $this->assertEquals(\base64_encode('file content'), $attachment['content']);

                    // No inline images
                    $this->assertArrayNotHasKey('images', $msg);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to@example.com', 'status' => 'sent', '_id' => 'att1'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithInlineImage(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Inline image test')
            ->setBody('<p>Image: <img src="cid:img001"></p>', 'text/html');

        $image = new \Swift_Image('image-data', 'photo.png', 'image/png');
        $message->embed($image);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://mandrillapp.com/api/1.0/messages/send',
                $this->callback(function (array $options): bool {
                    $msg = $options['json']['message'];

                    // Inline images should be in 'images', not 'attachments'
                    $this->assertArrayHasKey('images', $msg);
                    $this->assertCount(1, $msg['images']);
                    $this->assertArrayNotHasKey('attachments', $msg);

                    $img = $msg['images'][0];
                    $this->assertEquals('image/png', $img['type']);
                    $this->assertEquals(\base64_encode('image-data'), $img['content']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to@example.com', 'status' => 'sent', '_id' => 'img1'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithFromNameOmittedWhenNull(): void
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
                    $msg = $options['json']['message'];
                    $this->assertEquals('from@example.com', $msg['from_email']);
                    $this->assertArrayNotHasKey('from_name', $msg);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to@example.com', 'status' => 'sent', '_id' => 'nn1'],
            ])));

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
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'drip');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'week1');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '77');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $msg = $options['json']['message'];

                    $this->assertEquals(['drip', 'week1'], $msg['tags']);
                    $this->assertEquals(['user_id' => '77'], $msg['metadata']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to@example.com', 'status' => 'sent', '_id' => 'tag1'],
            ])));

        $this->transport->send($message);
    }

    public function testSendWithBccHasName(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setBcc(['bcc@example.com' => 'BCC Named User'])
            ->setSubject('BCC with name test')
            ->setBody('Body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options): bool {
                $msg = $options['json']['message'];

                // BCC entry should have a name
                $bccEntry = null;
                foreach ($msg['to'] as $entry) {
                    if ('bcc' === $entry['type']) {
                        $bccEntry = $entry;
                        break;
                    }
                }

                $this->assertNotNull($bccEntry);
                $this->assertSame('bcc@example.com', $bccEntry['email']);
                $this->assertSame('BCC Named User', $bccEntry['name']);

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                ['email' => 'to@example.com', 'status' => 'sent', '_id' => 'b1'],
                ['email' => 'bcc@example.com', 'status' => 'sent', '_id' => 'b2'],
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(2, $sent);
    }

    public function testGetAuthHeadersReturnsEmptyArray(): void
    {
        $reflection = new \ReflectionMethod($this->transport, 'getAuthHeaders');
        $result     = $reflection->invoke($this->transport);
        $this->assertSame([], $result);
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
