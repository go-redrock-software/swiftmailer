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
