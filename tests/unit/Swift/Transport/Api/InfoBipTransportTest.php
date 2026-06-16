<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Swift_Transport_Api_InfoBipTransportTest extends TestCase
{
    private $httpClientMock;

    private $eventDispatcherMock;

    private \Swift_Transport_Api_InfoBipTransport $transport;

    protected function setUp(): void
    {
        $this->httpClientMock      = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_InfoBipTransport(
            'test-infobip-api-key',
            'xxxxx.api.infobip.com',
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
        $message->setBody('Hello plain text');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'abc-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://xxxxx.api.infobip.com/email/3/send',
                $this->callback(function (array $options) {
                    $this->assertSame('App test-infobip-api-key', $options['headers']['Authorization']);

                    $multipart = $options['multipart'];
                    $fields    = $this->indexMultipart($multipart);

                    $this->assertSame('Sender Name <sender@example.com>', $fields['from'][0]);
                    $this->assertSame('Recipient Name <recipient@example.com>', $fields['to'][0]);
                    $this->assertSame('Test Subject', $fields['subject'][0]);
                    $this->assertSame('Hello plain text', $fields['text'][0]);
                    $this->assertArrayNotHasKey('html', $fields);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(1, $count);
    }

    public function testSendHtmlMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('HTML Test');
        $message->setBody('<p>Hello HTML</p>', 'text/html');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'def-456'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $fields = $this->indexMultipart($options['multipart']);

                    $this->assertSame('<p>Hello HTML</p>', $fields['html'][0]);
                    $this->assertArrayNotHasKey('text', $fields);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendWithCcAndBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'To User']);
        $message->setCc(['cc@example.com' => 'CC User']);
        $message->setBcc(['bcc@example.com' => 'BCC User']);
        $message->setSubject('CC/BCC Test');
        $message->setBody('Body text');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'ghi-789'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $fields = $this->indexMultipart($options['multipart']);

                    $this->assertSame('CC User <cc@example.com>', $fields['cc'][0]);
                    $this->assertSame('BCC User <bcc@example.com>', $fields['bcc'][0]);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(3, $count);
    }

    public function testSendWithMultipleToRecipients(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo([
            'first@example.com'  => 'First',
            'second@example.com' => 'Second',
        ]);
        $message->setSubject('Multiple To Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'multi-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $fields = $this->indexMultipart($options['multipart']);

                    // Each To recipient should produce a separate 'to' field
                    $this->assertCount(2, $fields['to']);
                    $this->assertSame('First <first@example.com>', $fields['to'][0]);
                    $this->assertSame('Second <second@example.com>', $fields['to'][1]);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $count = $this->transport->send($message);
        $this->assertSame(2, $count);
    }

    public function testSendWithReplyTo(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setReplyTo(['replyto@example.com' => 'Reply Name']);
        $message->setSubject('Reply-To Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'reply-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $fields = $this->indexMultipart($options['multipart']);

                    $this->assertSame('replyto@example.com', $fields['replyTo'][0]);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testAuthHeaderIsSet(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Auth Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'auth-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $this->assertArrayHasKey('Authorization', $options['headers']);
                    $this->assertSame('App test-infobip-api-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://xxxxx.api.infobip.com/email/1/domains',
                $this->callback(function (array $options) {
                    $this->assertSame('App test-infobip-api-key', $options['headers']['Authorization']);

                    return true;
                }),
            )
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

    public function testSendApiErrorThrowsException(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Error Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(400, [
            'requestError' => [
                'serviceException' => [
                    'text' => 'Invalid email address',
                ],
            ],
        ]);

        $this->httpClientMock->method('request')->willReturn($response);

        $this->setupEventMocks();

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Infobip API error: Invalid email address');

        $this->transport->send($message);
    }

    public function testSendNonPendingStatusThrowsException(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Status Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'messages' => [
                [
                    'status' => [
                        'groupName'   => 'REJECTED',
                        'description' => 'Message rejected by server',
                    ],
                ],
            ],
        ]);

        $this->httpClientMock->method('request')->willReturn($response);

        $this->setupEventMocks();

        $exceptionEvt = $this->createMock(\Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exceptionEvt);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Infobip API error: Message rejected by server');

        $this->transport->send($message);
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        $transport = new \Swift_Transport_Api_InfoBipTransport(
            'key',
            'xxxxx.api.infobip.com/',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );

        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Trim Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'trim-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://xxxxx.api.infobip.com/email/3/send',
                $this->anything(),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $transport->send($message);
    }

    public function testSendWithAttachments(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Attachment Test');
        $message->setBody('Body text');
        $message->attach(new \Swift_Attachment('file content', 'doc.pdf', 'application/pdf'));

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'att-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) {
                $fields = $this->indexMultipart($options['multipart']);

                $this->assertArrayHasKey('attachment', $fields);

                return true;
            }))
            ->willReturn($response);

        $this->setupEventMocks();
        $this->transport->send($message);
    }

    public function testSendWithInlineImage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Inline Image Test');
        $message->setBody('<p>Hello <img src="'.$message->embed(new \Swift_Image('image data', 'logo.png', 'image/png')).'" /></p>', 'text/html');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'inline-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) {
                $fields = $this->indexMultipart($options['multipart']);

                // Inline images should use 'inlineImage' field name
                $this->assertArrayHasKey('inlineImage', $fields);

                return true;
            }))
            ->willReturn($response);

        $this->setupEventMocks();
        $this->transport->send($message);
    }

    public function testSendWithTextAndHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['sender@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Multipart Alternative Test');
        $message->setBody('<p>Hello HTML</p>', 'text/html');
        $message->attach(new \Swift_MimePart('Hello plain text', 'text/plain'));

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'alt-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $fields = $this->indexMultipart($options['multipart']);

                    // When both parts exist, Infobip receives both text and html fields
                    $this->assertSame('Hello plain text', $fields['text'][0]);
                    $this->assertSame('<p>Hello HTML</p>', $fields['html'][0]);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

        $this->transport->send($message);
    }

    public function testSendFromWithoutDisplayName(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom('sender@example.com');
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('From Without Name Test');
        $message->setBody('Body');

        $response = $this->createMockResponse(200, [
            'messages' => [
                ['status' => ['groupName' => 'PENDING'], 'messageId' => 'noname-123'],
            ],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $fields = $this->indexMultipart($options['multipart']);

                    // No display name -> bare email, no angle brackets
                    $this->assertSame('sender@example.com', $fields['from'][0]);

                    return true;
                }),
            )
            ->willReturn($response);

        $this->setupEventMocks();

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
        $json   = \json_encode($body);
        $stream = $this->createMock(StreamInterface::class);
        // getResponseBody() consumes the stream via eof()/read(); a real PSR-7 stream
        // yields its contents once and then reports EOF. Stub that so the read loop
        // terminates instead of spinning forever on an unstubbed eof() (default false).
        $stream->method('getSize')->willReturn(\strlen($json));
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn($json);
        $stream->method('getContents')->willReturn($json);
        $stream->method('__toString')->willReturn($json);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function setupEventMocks(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($sendEvt);
    }

    /**
     * Index multipart form data by field name, collecting values into arrays
     * to handle repeated fields (like multiple 'to' entries).
     *
     * @return array<string, array<string>>
     */
    private function indexMultipart(array $multipart): array
    {
        $fields = [];
        foreach ($multipart as $part) {
            $fields[$part['name']][] = $part['contents'];
        }

        return $fields;
    }
}
