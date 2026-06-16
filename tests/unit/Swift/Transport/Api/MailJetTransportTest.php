<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_MailJetTransportTest extends TestCase
{
    private $httpClientMock;

    private $eventDispatcherMock;

    private $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailJetTransport(
            'test-public-key',
            'test-private-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test Subject');
        $message->setBody('Hello World', 'text/plain');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailjet.com/v3.1/send',
                $this->callback(function ($options) {
                    $payload = $options['json'];

                    return isset($payload['Messages'][0])
                        && 'from@example.com' === $payload['Messages'][0]['From']['Email']
                        && 'Sender'           === $payload['Messages'][0]['From']['Name']
                        && 'to@example.com'   === $payload['Messages'][0]['To'][0]['Email']
                        && 'Recipient'        === $payload['Messages'][0]['To'][0]['Name']
                        && 'Test Subject'     === $payload['Messages'][0]['Subject']
                        && 'Hello World'      === $payload['Messages'][0]['TextPart'];
                }),
            )
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success', 'To' => [['Email' => 'to@example.com']]]],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message);
        $this->assertEquals(1, $result);
    }

    public function testPayloadStructure(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setCc(['cc@example.com' => 'CC User']);
        $message->setBcc(['bcc@example.com' => 'BCC User']);
        $message->setSubject('Test');
        $message->setBody('<p>Hello</p>', 'text/html');
        $message->attach(new \Swift_MimePart('Hello', 'text/plain'));

        $capturedPayload = null;

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use (&$capturedPayload) {
                $capturedPayload = $options['json'];

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message);
        $this->assertEquals(3, $result);

        $msg = $capturedPayload['Messages'][0];
        $this->assertArrayHasKey('Messages', $capturedPayload);
        $this->assertCount(1, $capturedPayload['Messages']);
        $this->assertEquals('from@example.com', $msg['From']['Email']);
        $this->assertEquals([['Email' => 'to@example.com', 'Name' => 'Recipient']], $msg['To']);
        $this->assertEquals([['Email' => 'cc@example.com', 'Name' => 'CC User']], $msg['Cc']);
        $this->assertEquals([['Email' => 'bcc@example.com', 'Name' => 'BCC User']], $msg['Bcc']);
        $this->assertEquals('<p>Hello</p>', $msg['HTMLPart']);
        $this->assertEquals('Hello', $msg['TextPart']);
    }

    public function testBasicAuthHeader(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $expectedAuth = 'Basic '.\base64_encode('test-public-key:test-private-key');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use ($expectedAuth) {
                return isset($options['headers']['Authorization'])
                    && $options['headers']['Authorization'] === $expectedAuth;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testPingSuccess(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.mailjet.com/v3/REST/apikey', $this->callback(function ($options) {
                return isset($options['headers']['Authorization']);
            }))
            ->willReturn(new Response(200, [], '{"Count":1,"Data":[{}],"Total":1}'));

        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->assertTrue($this->transport->ping());
    }

    public function testPingFailure(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Connection failed'));

        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->assertFalse($this->transport->ping());
    }

    public function testSendApiError(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(400, [], \json_encode([
                'Messages' => [[
                    'Status' => 'error',
                    'Errors' => [['ErrorMessage' => 'Invalid sender']],
                ]],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Invalid sender');

        $this->transport->send($message);
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
                $msg = $options['json']['Messages'][0];

                return isset($msg['ReplyTo'])
                    && 'reply@example.com' === $msg['ReplyTo']['Email']
                    && 'Reply User'        === $msg['ReplyTo']['Name'];
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

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
        $message->setSubject('Tag test');
        $message->setBody('Hello');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'summer-sale');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-campaign', 'summer');

        $capturedPayload = null;

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use (&$capturedPayload) {
                $capturedPayload = $options['json'];

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);

        $msg = $capturedPayload['Messages'][0];
        $this->assertEquals('summer-sale', $msg['CustomCampaign']);
        // Mailjet v3.1 has no Properties field; metadata is sent via EventPayload as JSON.
        $this->assertEquals(\json_encode(['campaign' => 'summer']), $msg['EventPayload']);
    }

    public function testSendWithAttachments(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Attachment Test');
        $message->setBody('Body');
        $message->attach(new \Swift_Attachment('file content', 'doc.pdf', 'application/pdf'));

        $capturedPayload = null;

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use (&$capturedPayload) {
                $capturedPayload = $options['json'];

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);

        $msg = $capturedPayload['Messages'][0];
        $this->assertArrayHasKey('Attachments', $msg);
        $this->assertCount(1, $msg['Attachments']);
        $this->assertSame('doc.pdf', $msg['Attachments'][0]['Filename']);
        $this->assertSame('application/pdf', $msg['Attachments'][0]['ContentType']);
        $this->assertSame(\base64_encode('file content'), $msg['Attachments'][0]['Base64Content']);
    }

    public function testSendWithMultipleRecipients(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo([
            'first@example.com'  => 'First Recipient',
            'second@example.com' => null,
        ]);
        $message->setSubject('Multi Test');
        $message->setBody('Hello');

        $capturedPayload = null;

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use (&$capturedPayload) {
                $capturedPayload = $options['json'];

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);

        $msg = $capturedPayload['Messages'][0];
        $this->assertCount(2, $msg['To']);
        $this->assertEquals(
            [
                ['Email' => 'first@example.com', 'Name' => 'First Recipient'],
                ['Email' => 'second@example.com'],
            ],
            $msg['To'],
        );
    }

    public function testSendWithoutSenderName(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('No Name Test');
        $message->setBody('Hello');

        $capturedPayload = null;

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use (&$capturedPayload) {
                $capturedPayload = $options['json'];

                return true;
            }))
            ->willReturn(new Response(200, [], \json_encode([
                'Messages' => [['Status' => 'success']],
            ])));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);

        $msg = $capturedPayload['Messages'][0];
        $this->assertSame(['Email' => 'from@example.com'], $msg['From']);
        $this->assertArrayNotHasKey('Name', $msg['From']);
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
