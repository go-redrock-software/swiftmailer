<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_MailGunTransportTest extends TestCase
{
    private $httpClientMock;

    private $eventDispatcherMock;

    private $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_MailGunTransport(
            'test-mailgun-key',
            'example.com',
            'https://api.mailgun.net',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessageUsesMultipart(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test Subject');
        $message->setBody('Hello world', 'text/plain');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    // Must use multipart key (not body/json)
                    if (!isset($options['multipart']) || !\is_array($options['multipart'])) {
                        return false;
                    }

                    $fields = $this->indexMultipart($options['multipart']);

                    return 'Sender <from@example.com>'  === $fields['from']
                        && 'Recipient <to@example.com>' === $fields['to']
                        && 'Test Subject'               === $fields['subject']
                        && 'Hello world'                === $fields['text'];
                }),
            )
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $result = $this->transport->send($message);
        $this->assertEquals(1, $result);
    }

    public function testSendIncludesDomainInUrl(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailgun.net/v3/example.com/messages',
                $this->anything(),
            )
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $this->transport->send($message);
    }

    public function testSendUsesBasicAuthHeader(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $expectedAuth = 'Basic '.\base64_encode('api:test-mailgun-key');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) use ($expectedAuth) {
                return isset($options['headers']['Authorization'])
                    && $expectedAuth === $options['headers']['Authorization'];
            }))
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $this->transport->send($message);
    }

    public function testSendWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setCc(['cc@example.com' => 'CC User']);
        $message->setBcc(['bcc@example.com' => 'BCC User']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $fields = $this->indexMultipart($options['multipart']);

                return isset($fields['cc']) && \str_contains($fields['cc'], 'cc@example.com')
                                            && isset($fields['bcc']) && \str_contains($fields['bcc'], 'bcc@example.com');
            }))
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $result = $this->transport->send($message);
        $this->assertEquals(3, $result);
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
                $fields = $this->indexMultipart($options['multipart']);

                return isset($fields['h:Reply-To']) && \str_contains($fields['h:Reply-To'], 'reply@example.com');
            }))
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $this->transport->send($message);
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
                $fields = $this->indexMultipart($options['multipart']);

                return isset($fields['html']) && '<p>Hello</p>' === $fields['html'];
            }))
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $this->transport->send($message);
    }

    public function testSendWithAttachment(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');
        $message->attach(new \Swift_Attachment('file contents', 'report.pdf', 'application/pdf'));

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                foreach ($options['multipart'] as $part) {
                    if ('attachment' === $part['name']) {
                        return 'file contents'   === $part['contents']
                            && 'report.pdf'      === $part['filename']
                            && 'application/pdf' === $part['headers']['Content-Type'];
                    }
                }

                return false;
            }))
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $this->transport->send($message);
    }

    public function testPingSuccess(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.mailgun.net/v3/domains/example.com',
                $this->anything(),
            )
            ->willReturn(new Response(200, [], '{"domain":{"name":"example.com"}}'));

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

    public function testEuRegionHost(): void
    {
        $transport = new \Swift_Transport_Api_MailGunTransport(
            'eu-key',
            'eu-domain.com',
            'https://api.eu.mailgun.net',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message->setFrom(['from@eu-domain.com' => 'Sender']);
        $message->setTo(['to@eu-domain.com' => 'Recipient']);
        $message->setSubject('EU Test');
        $message->setBody('Hello from EU');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.eu.mailgun.net/v3/eu-domain.com/messages',
                $this->anything(),
            )
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $transport->send($message);
    }

    public function testSendWithTagsAndMetadata(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'promo');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'newsletter');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '42');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $multipart = $options['multipart'];

                // Collect all o:tag values
                $tags     = [];
                $metaKeys = [];
                foreach ($multipart as $part) {
                    if ('o:tag' === $part['name']) {
                        $tags[] = $part['contents'];
                    }
                    if (\str_starts_with($part['name'], 'v:')) {
                        $metaKeys[$part['name']] = $part['contents'];
                    }
                }

                return $tags === ['promo', 'newsletter']
                    && isset($metaKeys['v:user_id'])
                    && '42' === $metaKeys['v:user_id'];
            }))
            ->willReturn(new Response(200, [], '{"id":"<abc@mailgun.org>","message":"Queued."}'));

        $this->stubEventDispatcher();

        $this->transport->send($message);
    }

    /**
     * Index multipart form fields by name for easy assertion.
     */
    private function indexMultipart(array $multipart): array
    {
        $fields = [];
        foreach ($multipart as $part) {
            $fields[$part['name']] = $part['contents'];
        }

        return $fields;
    }

    private function stubEventDispatcher(): void
    {
        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));
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
