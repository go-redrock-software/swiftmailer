<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_SendgridTransportTest extends TestCase
{
    private $httpClientMock;

    private $eventDispatcherMock;

    private $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_SendgridTransport(
            'test-sendgrid-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello', 'text/plain');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.sendgrid.com/v3/mail/send',
                $this->callback(function ($options) {
                    $payload = \json_decode($options['body'], true);

                    return 'from@example.com' === $payload['from']['email']
                        && 'to@example.com'   === $payload['personalizations'][0]['to'][0]['email']
                        && 'Test'             === $payload['subject']
                        && 'Hello'            === $payload['content'][0]['value'];
                }),
            )
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message);
        $this->assertEquals(1, $result);
    }

    public function testSendWithCcBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setCc(['cc@example.com' => 'CC']);
        $message->setBcc(['bcc@example.com' => 'BCC']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = \json_decode($options['body'], true);

                return isset($payload['personalizations'][0]['cc'])
                    && isset($payload['personalizations'][0]['bcc']);
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message);
        $this->assertEquals(3, $result);
    }

    public function testSendWithHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('<p>Hello</p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = \json_decode($options['body'], true);
                $types   = \array_column($payload['content'], 'type');

                return \in_array('text/html', $types);
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testPingSuccess(): void
    {
        $this->httpClientMock->method('request')->willReturn(new Response(200, [], '{"scopes":[]}'));
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->assertTrue($this->transport->ping());
    }

    public function testAuthHeader(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                return isset($options['headers']['Authorization'])
                    && 'Bearer test-sendgrid-key' === $options['headers']['Authorization'];
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testSendWithTagsAndMetadata(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello', 'text/plain');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'campaign-1');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'campaign-2');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '123');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-env', 'prod');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    $payload = \json_decode($options['body'], true);

                    // Tags → categories
                    $this->assertEquals(['campaign-1', 'campaign-2'], $payload['categories']);

                    // Metadata → custom_args in personalizations
                    $this->assertEquals(
                        ['user_id' => '123', 'env' => 'prod'],
                        $payload['personalizations'][0]['custom_args'],
                    );

                    return true;
                }),
            )
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testSendApiErrorThrowsException(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(400, [], \json_encode([
                'errors' => [['message' => 'Invalid API key']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $failedEvt = $this->createMock(\Swift_Events_FailedMessageEvent::class);
        $this->eventDispatcherMock->method('createFailedMessageEvent')->willReturn($failedEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('SendGrid API error: Invalid API key');

        $this->transport->send($message);
    }

    public function testSendApiErrorWithUnknownErrorMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(500, [], \json_encode([])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $failedEvt = $this->createMock(\Swift_Events_FailedMessageEvent::class);
        $this->eventDispatcherMock->method('createFailedMessageEvent')->willReturn($failedEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Unknown SendGrid error');

        $this->transport->send($message);
    }

    public function testParseResponseReturnsDecodedJson(): void
    {
        $reflection = new \ReflectionMethod($this->transport, 'parseResponse');

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getContents')->willReturn(\json_encode(['key' => 'value']));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $result = $reflection->invoke($this->transport, $response);
        $this->assertSame(['key' => 'value'], $result);
    }

    public function testSendWithReplyTo(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setReplyTo(['reply@example.com' => 'Reply User']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = \json_decode($options['body'], true);

                return isset($payload['reply_to'])
                    && 'reply@example.com' === $payload['reply_to']['email']
                    && 'Reply User' === $payload['reply_to']['name'];
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testSendWithAttachments(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');
        $message->attach(new \Swift_Attachment('file content', 'doc.pdf', 'application/pdf'));

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = \json_decode($options['body'], true);

                $this->assertArrayHasKey('attachments', $payload);
                $this->assertCount(1, $payload['attachments']);
                $this->assertSame('doc.pdf', $payload['attachments'][0]['filename']);
                $this->assertSame('application/pdf', $payload['attachments'][0]['type']);
                $this->assertSame(\base64_encode('file content'), $payload['attachments'][0]['content']);
                $this->assertSame('attachment', $payload['attachments'][0]['disposition']);

                return true;
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testSendWithInlineAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('<p>Hello <img src="' . $message->embed(new \Swift_Image('image data', 'logo.png', 'image/png')) . '" /></p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = \json_decode($options['body'], true);

                $this->assertArrayHasKey('attachments', $payload);
                $this->assertGreaterThanOrEqual(1, \count($payload['attachments']));

                $inlineAtt = $payload['attachments'][0];
                $this->assertSame('inline', $inlineAtt['disposition']);
                $this->assertArrayHasKey('content_id', $inlineAtt);

                return true;
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testSendWithEnvelopeUsesEnvelopeRecipientsAndSender(): void
    {
        // Covers AbstractHttpApiTransport lines 199 and 232:
        // countRecipients and getEnvelopeSender using activeEnvelope
        $message = $this->createSwiftMessage();
        $message->setFrom(['original@example.com' => 'Original']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Envelope Test');
        $message->setBody('Body', 'text/plain');

        $envelope = new \Swift_Envelope('envelope-sender@example.com', ['env-rcpt@example.com']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(202, [], ''));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(false);
        $evt->method('getEnvelope')->willReturn($envelope);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message, $failedRecipients, $envelope);
        $this->assertSame(1, $result);
    }

    public function testSendThrowsTransportExceptionOnApiFailure(): void
    {
        // Covers AbstractHttpApiTransport line 149: throwException path
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Fail Test');
        $message->setBody('Body', 'text/plain');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('API down'));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $evt->method('bubbleCancelled')->willReturn(false);
        $evt->method('getEnvelope')->willReturn(null);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('API down');
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
