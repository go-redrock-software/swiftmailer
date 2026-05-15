<?php

namespace Swift\Transport\Api;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use PHPUnit\Framework\TestCase;

use function Swift\getRawMessage;

class Swift_Transport_Api_GoogleTransportTest extends TestCase
{
    private $googleClientMock;

    private $eventDispatcherMock;

    private $gmailServiceMock;

    private $messageMock;

    private $swiftMessageMock;

    private $googleTransport;

    public function testPingReturnsTrue(): void
    {
        $this->assertTrue($this->googleTransport->ping());
    }

    public function testMessageConversion(): void
    {
        $testCases = $this->messageProvider();

        foreach ($testCases as $testCase) {
            [$description, $message, $expected] = $testCase;
            $rawMessage                         = getRawMessage($message);
            $decodedMessage                     = \base64_decode($rawMessage);

            foreach ($expected as $expectedString) {
                $this->assertStringContainsString($expectedString, $decodedMessage, $description);
            }
        }
    }

    public function messageProvider()
    {
        return [
            [
                'Basic message with From, To, Subject, and Body',
                (new \Swift_Mime_SimpleMessage(
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
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('This is the body'),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Subject: Test Subject',
                    \base64_encode('This is the body'),
                ],
            ],
            [
                'Message with CC and BCC',
                (new \Swift_Mime_SimpleMessage(
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
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setCc(['cc@example.com' => 'CC Name'])
                    ->setBcc(['bcc@example.com' => 'BCC Name'])
                    ->setSubject('Test Subject')
                    ->setBody('This is the body'),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Cc: CC Name <cc@example.com>',
                    'Bcc: BCC Name <bcc@example.com>',
                    'Subject: Test Subject',
                    \base64_encode('This is the body'),
                ],
            ],
            [
                'Message with Reply-To header',
                (new \Swift_Mime_SimpleMessage(
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
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setReplyTo(['replyto@example.com' => 'Reply-To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('This is the body'),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Reply-To: Reply-To Name <replyto@example.com>',
                    'Subject: Test Subject',
                    \base64_encode('This is the body'),
                ],
            ],
            [
                'Message with HTML body',
                (new \Swift_Mime_SimpleMessage(
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
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('<p>This is the HTML body</p>', 'text/html'),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Subject: Test Subject',
                    \base64_encode('<p>This is the HTML body</p>'),
                ],
            ],
            [
                'Message with attachment',
                (new \Swift_Mime_SimpleMessage(
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
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('This is the body')
                    ->attach(new \Swift_Attachment('Attachment content', 'file.txt', 'text/plain')),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Subject: Test Subject',
                    \base64_encode('This is the body'),
                    'Content-Disposition: attachment; filename=file.txt',
                    \base64_encode('Attachment content'),
                ],
            ],
        ];
    }

    public function testStart(): void
    {
        $this->eventDispatcherMock->expects($this->once())
            ->method('createTransportChangeEvent')
            ->with($this->googleTransport)
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->eventDispatcherMock->expects($this->exactly(2))
            ->method('dispatchEvent');

        $this->googleTransport->start();
        $this->assertTrue($this->googleTransport->isStarted());
    }

    public function testGetRawMessage(): void
    {
        $this->swiftMessageMock->expects($this->once())
            ->method('toString')
            ->willReturn('test message');

        $result = getRawMessage($this->swiftMessageMock);
        $this->assertEquals(\Swift\base64url_encode('test message'), $result);
    }

    // -- send: success path -----------------------------------------------

    public function testSendSuccessReturnsRecipientCount(): void
    {
        $client = $this->createGoogleClientWithMockHttp(200, '{"id": "msg-123"}');
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport($client, $dispatcher);

        $m = $this->createSwiftMessage();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'To']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        $this->assertSame(1, $transport->send($m));
    }

    public function testSendWithCcAndBccCountsAllRecipients(): void
    {
        $client = $this->createGoogleClientWithMockHttp(200, '{"id": "msg-456"}');
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport($client, $dispatcher);

        $m = $this->createSwiftMessage();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'To']);
        $m->setCc(['cc@example.com' => 'CC']);
        $m->setBcc(['bcc@example.com' => 'BCC']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        $this->assertSame(3, $transport->send($m));
    }

    // -- send: bubble cancelled -------------------------------------------

    public function testSendReturnsZeroWhenBubbleCancelled(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $sendEvt->method('bubbleCancelled')->willReturn(true);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport(
            $this->createMock(Client::class),
            $dispatcher,
        );

        $m = $this->createSwiftMessage();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(0, $transport->send($m));
    }

    // -- send: error path -------------------------------------------------

    public function testSendReturnsZeroOnException(): void
    {
        // Use a mock handler that throws an exception
        $client = $this->createGoogleClientWithMockHttp(500, '{"error": {"message": "Server Error"}}');
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);

        $sendEvt = $this->createMock(\Swift_Events_SendEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport($client, $dispatcher);

        $m = $this->createSwiftMessage();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(0, $transport->send($m));
    }

    // -- send: without event dispatcher send event -----------------------

    public function testSendWithoutSendEvent(): void
    {
        $client = $this->createGoogleClientWithMockHttp(200, '{"id": "msg-789"}');
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn(null);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport($client, $dispatcher);

        $m = $this->createSwiftMessage();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(1, $transport->send($m));
    }

    // -- start: bubble cancelled ------------------------------------------

    public function testStartDoesNotSetStartedWhenBubbleCancelled(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $changeEvt->method('bubbleCancelled')->willReturn(true);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport(
            $this->createMock(Client::class),
            $dispatcher,
        );

        $transport->start();
        $this->assertFalse($transport->isStarted());
    }

    // -- start: already started does nothing ------------------------------

    public function testStartWhenAlreadyStartedDoesNothing(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_GoogleTransport(
            $this->createMock(Client::class),
            $dispatcher,
        );

        $transport->start();
        $this->assertTrue($transport->isStarted());

        // Second call should be a no-op
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    // -- getApiConnection -------------------------------------------------

    public function testGetApiConnectionReturnsGoogleClient(): void
    {
        $client    = $this->createMock(Client::class);
        $transport = new \Swift_Transport_Api_GoogleTransport($client, $this->eventDispatcherMock);

        $ref = new \ReflectionMethod($transport, 'getApiConnection');
        $this->assertSame($client, $ref->invoke($transport));
    }

    // -- Helpers -----------------------------------------------------------

    protected function setUp(): void
    {
        $this->googleClientMock    = $this->createMock(Client::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->gmailServiceMock = $this->createMock(Gmail::class);
        $this->messageMock      = $this->createMock(Message::class);
        $this->swiftMessageMock = $this->createMock(\Swift_Mime_SimpleMessage::class);

        $this->googleTransport = new \Swift_Transport_Api_GoogleTransport(
            $this->googleClientMock,
            $this->eventDispatcherMock,
        );
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

    private function createGoogleClientWithMockHttp(int $statusCode, string $body): Client
    {
        $mockHandler = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response($statusCode, ['Content-Type' => 'application/json'], $body),
        ]);
        $handlerStack = \GuzzleHttp\HandlerStack::create($mockHandler);
        $httpClient   = new \GuzzleHttp\Client(['handler' => $handlerStack]);

        $googleClient = new Client();
        $googleClient->setHttpClient($httpClient);

        return $googleClient;
    }
}
