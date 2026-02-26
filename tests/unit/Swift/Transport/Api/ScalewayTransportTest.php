<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ScalewayTransportTest extends TestCase
{
    private ClientInterface $httpClientMock;

    private \Swift_Events_EventDispatcher $eventDispatcherMock;

    private \Swift_Transport_Api_ScalewayTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_ScalewayTransport(
            'scw-secret-key',
            'project-id-123',
            'fr-par',
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

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-1', 'message_id' => '<msg-id-1@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails',
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertSame(['email' => 'sender@example.com', 'name' => 'Sender Name'], $payload['from']);
                    $this->assertSame([['email' => 'recipient@example.com', 'name' => 'Recipient Name']], $payload['to']);
                    $this->assertSame('Test Subject', $payload['subject']);
                    $this->assertSame('Plain text body', $payload['text']);
                    $this->assertSame('project-id-123', $payload['project_id']);
                    $this->assertArrayNotHasKey('html', $payload);
                    $this->assertArrayNotHasKey('additional_headers', $payload);
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

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-2', 'message_id' => '<msg-id-2@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
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

    public function testSendMessageWithCc(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setCc(['cc@example.com' => 'CC User'])
            ->setSubject('CC Test')
            ->setBody('Body text');

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-3', 'message_id' => '<msg-id-3@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // CC recipients should be merged into the to array
                    $this->assertCount(2, $payload['to']);
                    $this->assertSame(['email' => 'to@example.com', 'name' => 'To User'], $payload['to'][0]);
                    $this->assertSame(['email' => 'cc@example.com', 'name' => 'CC User'], $payload['to'][1]);

                    // CC should appear as an additional_header
                    $this->assertNotEmpty($payload['additional_headers']);
                    $ccHeader = $payload['additional_headers'][0];
                    $this->assertSame('Cc', $ccHeader['key']);
                    $this->assertSame('CC User <cc@example.com>', $ccHeader['value']);

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

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-4', 'message_id' => '<msg-id-4@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // BCC recipients should be merged into the to array
                    $this->assertCount(2, $payload['to']);
                    $this->assertSame(['email' => 'to@example.com', 'name' => 'To User'], $payload['to'][0]);
                    $this->assertSame(['email' => 'bcc@example.com', 'name' => 'BCC User'], $payload['to'][1]);

                    // BCC should NOT appear in additional_headers
                    $this->assertArrayNotHasKey('additional_headers', $payload);

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

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-5', 'message_id' => '<msg-id-5@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    // All recipients merged into to
                    $this->assertCount(3, $payload['to']);
                    $this->assertSame(['email' => 'to@example.com', 'name' => 'To User'], $payload['to'][0]);
                    $this->assertSame(['email' => 'cc@example.com', 'name' => 'CC User'], $payload['to'][1]);
                    $this->assertSame(['email' => 'bcc@example.com', 'name' => 'BCC User'], $payload['to'][2]);

                    // Only CC header, no BCC header
                    $this->assertCount(1, $payload['additional_headers']);
                    $this->assertSame('Cc', $payload['additional_headers'][0]['key']);

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

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-6', 'message_id' => '<msg-id-6@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $payload = $options['json'];

                    $this->assertNotEmpty($payload['additional_headers']);
                    $replyToHeader = $payload['additional_headers'][0];
                    $this->assertSame('Reply-To', $replyToHeader['key']);
                    $this->assertSame('Reply User <replyto@example.com>', $replyToHeader['value']);

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

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-7', 'message_id' => '<msg-id-7@scaleway>']],
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
                    $this->assertSame('document.txt', $payload['attachments'][0]['name']);
                    $this->assertSame('text/plain', $payload['attachments'][0]['type']);
                    $this->assertSame(\base64_encode('file contents'), $payload['attachments'][0]['content']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testAuthHeaderContainsToken(): void
    {
        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Auth Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-8', 'message_id' => '<msg-id-8@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame('scw-secret-key', $options['headers']['X-Auth-Token']);

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
                'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/domains',
                $this->callback(function (array $options): bool {
                    $this->assertSame('scw-secret-key', $options['headers']['X-Auth-Token']);

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

        $response = $this->createMockResponse(403, [
            'message' => 'Insufficient permissions',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email via Swift_Transport_Api_ScalewayTransport');

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testCustomRegion(): void
    {
        $transport = new \Swift_Transport_Api_ScalewayTransport(
            'scw-secret-key',
            'project-id-456',
            'nl-ams',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Region Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-9', 'message_id' => '<msg-id-9@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.scaleway.com/transactional-email/v1alpha1/regions/nl-ams/emails',
                $this->anything(),
            )
            ->willReturn($response);

        $transport->start();
        $transport->send($message);
    }

    public function testDefaultRegionIsFrPar(): void
    {
        $transport = new \Swift_Transport_Api_ScalewayTransport(
            'scw-secret-key',
            'project-id-789',
            httpClient: $this->httpClientMock,
            eventDispatcher: $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'To User'])
            ->setSubject('Default Region Test')
            ->setBody('Body');

        $response = $this->createMockResponse(200, [
            'emails' => [['id' => 'email-uuid-10', 'message_id' => '<msg-id-10@scaleway>']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails',
                $this->anything(),
            )
            ->willReturn($response);

        $transport->start();
        $transport->send($message);
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
            'emails' => [['id' => 'email-uuid-11', 'message_id' => '<msg-id-11@scaleway>']],
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
