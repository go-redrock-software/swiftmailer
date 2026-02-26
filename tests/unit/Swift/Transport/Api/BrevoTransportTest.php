<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Swift_Transport_Api_BrevoTransportTest extends TestCase
{
    private $httpClientMock;
    private $eventDispatcherMock;
    private \Swift_Transport_Api_BrevoTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_BrevoTransport(
            'test-brevo-api-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender Name']);
        $message->setTo(['recipient@example.com' => 'Recipient Name']);
        $message->setSubject('Test Subject');
        $message->setBody('<p>Hello</p>', 'text/html');

        $response = $this->createMockResponse(201, ['messageId' => '<abc123@brevo.com>']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.brevo.com/v3/smtp/email',
                $this->callback(function (array $options) {
                    $this->assertSame('application/json', $options['headers']['Content-Type']);
                    $this->assertSame('test-brevo-api-key', $options['headers']['api-key']);

                    $json = $options['json'];
                    $this->assertSame('sender@example.com', $json['sender']['email']);
                    $this->assertSame('Sender Name', $json['sender']['name']);
                    $this->assertSame('recipient@example.com', $json['to'][0]['email']);
                    $this->assertSame('Recipient Name', $json['to'][0]['name']);
                    $this->assertSame('Test Subject', $json['subject']);
                    $this->assertSame('<p>Hello</p>', $json['htmlContent']);
                    $this->assertArrayNotHasKey('cc', $json);
                    $this->assertArrayNotHasKey('bcc', $json);

                    return true;
                }),
            )
            ->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $count = $this->transport->send($message);
        $this->assertSame(1, $count);
    }

    public function testSendMessageWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'To User']);
        $message->setCc(['cc@example.com' => 'CC User']);
        $message->setBcc(['bcc@example.com' => 'BCC User']);
        $message->setSubject('CC/BCC Test');
        $message->setBody('Plain text body');

        $response = $this->createMockResponse(201, ['messageId' => '<def456@brevo.com>']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.brevo.com/v3/smtp/email',
                $this->callback(function (array $options) {
                    $json = $options['json'];

                    $this->assertCount(1, $json['cc']);
                    $this->assertSame('cc@example.com', $json['cc'][0]['email']);
                    $this->assertSame('CC User', $json['cc'][0]['name']);

                    $this->assertCount(1, $json['bcc']);
                    $this->assertSame('bcc@example.com', $json['bcc'][0]['email']);
                    $this->assertSame('BCC User', $json['bcc'][0]['name']);

                    $this->assertSame('Plain text body', $json['textContent']);

                    return true;
                }),
            )
            ->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $count = $this->transport->send($message);
        $this->assertSame(3, $count);
    }

    public function testAuthHeaderIsSet(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'To User']);
        $message->setSubject('Auth Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(201, ['messageId' => '<ghi789@brevo.com>']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $this->assertArrayHasKey('api-key', $options['headers']);
                    $this->assertSame('test-brevo-api-key', $options['headers']['api-key']);

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

    public function testPingReturnsTrueOnSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.brevo.com/v3/account', $this->callback(function (array $options) {
                $this->assertSame('test-brevo-api-key', $options['headers']['api-key']);

                return true;
            }))
            ->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', '/'),
            ));

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertFalse($this->transport->ping());
    }

    public function testSendWithReplyTo(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'To User']);
        $message->setReplyTo(['replyto@example.com' => 'Reply Name']);
        $message->setSubject('Reply-To Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(201, ['messageId' => '<jkl012@brevo.com>']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $json = $options['json'];
                    $this->assertSame('replyto@example.com', $json['replyTo']['email']);
                    $this->assertSame('Reply Name', $json['replyTo']['name']);

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

    public function testSendApiErrorThrowsException(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'To User']);
        $message->setSubject('Error Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(400, [
            'code' => 'invalid_parameter',
            'message' => 'Invalid email address',
        ]);

        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Brevo API error (invalid_parameter): Invalid email address');

        $this->transport->send($message);
    }

    public function testSendWithTagsAndMetadata(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Tag test');
        $message->setBody('Hello');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'transactional');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-order_id', '999');

        $capturedPayload = null;
        $response = $this->createMockResponse(201, ['messageId' => '<tag@brevo.com>']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) use (&$capturedPayload) {
                    $capturedPayload = $options['json'];

                    return true;
                }),
            )
            ->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);

        $this->transport->send($message);

        $this->assertEquals(['transactional'], $capturedPayload['tags']);
        $this->assertEquals(['X-Metadata-order_id' => '999'], $capturedPayload['headers']);
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
        $stream->method('getContents')->willReturn(json_encode($body));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
