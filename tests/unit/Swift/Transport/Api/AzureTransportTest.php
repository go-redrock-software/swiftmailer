<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AzureTransportTest extends TestCase
{
    private const CONNECTION_STRING = 'endpoint=https://my-resource.communication.azure.com/;accesskey=dGVzdGFjY2Vzc2tleQ==';

    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_AzureTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_AzureTransport(
            self::CONNECTION_STRING,
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testConnectionStringParsing(): void
    {
        // If construction succeeded, parsing worked. Verify the endpoint is used in requests.
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test')
            ->setBody('Body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('my-resource.communication.azure.com'),
                $this->anything(),
            )
            ->willReturn(new Response(202, [], \json_encode([
                'id'     => 'test-uuid',
                'status' => 'Running',
            ])));

        $this->transport->send($message);
    }

    public function testConnectionStringMissingEndpointThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('endpoint');

        new \Swift_Transport_Api_AzureTransport(
            'accesskey=abc123',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testConnectionStringMissingAccessKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('accesskey');

        new \Swift_Transport_Api_AzureTransport(
            'endpoint=https://example.communication.azure.com/',
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
                $this->stringContains('/emails:send?api-version=2024-07-01-preview'),
                $this->callback(function (array $options): bool {
                    // Verify HMAC auth header
                    $this->assertArrayHasKey('Authorization', $options['headers']);
                    $this->assertStringStartsWith('HMAC-SHA256 ', $options['headers']['Authorization']);
                    $this->assertStringContainsString('SignedHeaders=x-ms-date;host;x-ms-content-sha256', $options['headers']['Authorization']);
                    $this->assertStringContainsString('Signature=', $options['headers']['Authorization']);

                    // Verify other signed headers
                    $this->assertArrayHasKey('x-ms-date', $options['headers']);
                    $this->assertArrayHasKey('x-ms-content-sha256', $options['headers']);
                    $this->assertArrayHasKey('host', $options['headers']);
                    $this->assertEquals('application/json', $options['headers']['Content-Type']);

                    // Verify payload structure
                    $payload = \json_decode($options['body'], true);
                    $this->assertEquals('sender@example.com', $payload['senderAddress']);
                    $this->assertEquals('Test Subject', $payload['content']['subject']);
                    $this->assertEquals('Plain text body', $payload['content']['plainText']);
                    $this->assertArrayNotHasKey('html', $payload['content']);

                    // Verify recipients
                    $this->assertCount(1, $payload['recipients']['to']);
                    $this->assertEquals('recipient@example.com', $payload['recipients']['to'][0]['address']);
                    $this->assertEquals('Recipient Name', $payload['recipients']['to'][0]['displayName']);

                    $this->assertArrayNotHasKey('cc', $payload['recipients']);
                    $this->assertArrayNotHasKey('bcc', $payload['recipients']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, [], \json_encode([
                'id'     => 'test-message-id',
                'status' => 'Running',
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
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = \json_decode($options['body'], true);

                    // To recipients
                    $this->assertCount(2, $payload['recipients']['to']);
                    $this->assertEquals('to1@example.com', $payload['recipients']['to'][0]['address']);
                    $this->assertEquals('To One', $payload['recipients']['to'][0]['displayName']);
                    $this->assertEquals('to2@example.com', $payload['recipients']['to'][1]['address']);

                    // CC
                    $this->assertCount(1, $payload['recipients']['cc']);
                    $this->assertEquals('cc@example.com', $payload['recipients']['cc'][0]['address']);
                    $this->assertEquals('CC User', $payload['recipients']['cc'][0]['displayName']);

                    // BCC
                    $this->assertCount(1, $payload['recipients']['bcc']);
                    $this->assertEquals('bcc@example.com', $payload['recipients']['bcc'][0]['address']);

                    // Reply-To
                    $this->assertCount(1, $payload['replyTo']);
                    $this->assertEquals('reply@example.com', $payload['replyTo'][0]['address']);
                    $this->assertEquals('Reply User', $payload['replyTo'][0]['displayName']);

                    // HTML body
                    $this->assertEquals('<p>HTML body</p>', $payload['content']['html']);
                    $this->assertArrayNotHasKey('plainText', $payload['content']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, [], \json_encode([
                'id'     => 'uuid-123',
                'status' => 'Running',
            ])));

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
                    $payload = \json_decode($options['body'], true);

                    $this->assertArrayHasKey('attachments', $payload);
                    $this->assertCount(1, $payload['attachments']);

                    $attachment = $payload['attachments'][0];
                    $this->assertEquals('document.pdf', $attachment['name']);
                    $this->assertEquals('application/pdf', $attachment['contentType']);
                    $this->assertEquals(\base64_encode('file content'), $attachment['contentInBase64']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, [], \json_encode([
                'id'     => 'uuid-attach',
                'status' => 'Running',
            ])));

        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendAddressWithoutDisplayName(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('No display name')
            ->setBody('Body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = \json_decode($options['body'], true);

                    $this->assertEquals('to@example.com', $payload['recipients']['to'][0]['address']);
                    $this->assertArrayNotHasKey('displayName', $payload['recipients']['to'][0]);

                    return true;
                }),
            )
            ->willReturn(new Response(202, [], \json_encode([
                'id'     => 'uuid',
                'status' => 'Running',
            ])));

        $this->transport->send($message);
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
            ->willReturn(new Response(400, [], \json_encode([
                'error' => [
                    'code'    => 'InvalidPayload',
                    'message' => 'The request payload is invalid.',
                ],
            ])));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Azure Communication Services API error InvalidPayload: The request payload is invalid.');

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOn404(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('/emails/operations/00000000-0000-0000-0000-000000000000'),
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('Authorization', $options['headers']);
                    $this->assertStringStartsWith('HMAC-SHA256 ', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn(new Response(404, [], \json_encode([
                'error' => [
                    'code'    => 'NotFound',
                    'message' => 'Operation not found.',
                ],
            ])));

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOn401(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(401, [], \json_encode([
                'error' => [
                    'code'    => 'Unauthorized',
                    'message' => 'Invalid credentials.',
                ],
            ])));

        $this->assertFalse($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->assertFalse($this->transport->ping());
    }

    public function testHmacAuthorizationHeaderFormat(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('HMAC test')
            ->setBody('body');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $auth = $options['headers']['Authorization'];

                    // Must start with HMAC-SHA256
                    $this->assertStringStartsWith('HMAC-SHA256 ', $auth);

                    // Must contain SignedHeaders and Signature parts
                    $this->assertMatchesRegularExpression(
                        '/^HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=.+$/',
                        $auth,
                    );

                    // Signature should be valid base64
                    $signaturePart = \substr($auth, \strpos($auth, 'Signature=') + 10);
                    $this->assertNotFalse(\base64_decode($signaturePart, true));

                    // Content hash should be valid base64
                    $this->assertNotFalse(\base64_decode($options['headers']['x-ms-content-sha256'], true));

                    // Host should match the endpoint
                    $this->assertEquals('my-resource.communication.azure.com', $options['headers']['host']);

                    return true;
                }),
            )
            ->willReturn(new Response(202, [], \json_encode([
                'id'     => 'uuid',
                'status' => 'Running',
            ])));

        $this->transport->send($message);
    }

    public function testGetAuthHeadersReturnsEmpty(): void
    {
        // Use reflection to call the protected method
        $reflection = new \ReflectionMethod($this->transport, 'getAuthHeaders');

        $this->assertEmpty($reflection->invoke($this->transport));
    }

    public function testGetPingEndpoint(): void
    {
        $reflection = new \ReflectionMethod($this->transport, 'getPingEndpoint');
        $result     = $reflection->invoke($this->transport);
        $this->assertStringContainsString('/emails/operations/00000000-0000-0000-0000-000000000000', $result);
        $this->assertStringContainsString('api-version=', $result);
    }

    public function testParseConnectionStringIgnoresEmptyParts(): void
    {
        // Connection string with trailing semicolons and empty segments
        $transport = new \Swift_Transport_Api_AzureTransport(
            'endpoint=https://my-resource.communication.azure.com/;;accesskey=dGVzdGFjY2Vzc2tleQ==;',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        // If construction succeeds, the empty parts were properly skipped
        $this->assertInstanceOf(\Swift_Transport_Api_AzureTransport::class, $transport);
    }

    public function testParseConnectionStringIgnoresPartsWithoutEquals(): void
    {
        // Connection string with a segment that has no equals sign
        $transport = new \Swift_Transport_Api_AzureTransport(
            'endpoint=https://my-resource.communication.azure.com/;badpart;accesskey=dGVzdGFjY2Vzc2tleQ==',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $this->assertInstanceOf(\Swift_Transport_Api_AzureTransport::class, $transport);
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
