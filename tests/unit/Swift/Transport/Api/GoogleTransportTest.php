<?php

namespace Swift\Transport\Api;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use PHPUnit\Framework\TestCase;
use Swift_Attachment;
use Swift_Events_EventDispatcher;
use Swift_KeyCache_ArrayKeyCache;
use Swift_KeyCache_SimpleKeyCacheInputStream;
use Swift_Mime_ContentEncoder_Base64ContentEncoder;
use Swift_Mime_HeaderEncoder_Base64HeaderEncoder;
use Swift_Mime_IdGenerator;
use Swift_Mime_SimpleHeaderFactory;
use Swift_Mime_SimpleHeaderSet;
use Swift_Mime_SimpleMessage;
use Swift_Transport_Api_GoogleTransport;

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
            $rawMessage = getRawMessage($message);
            $decodedMessage = base64_decode($rawMessage);

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
                (new Swift_Mime_SimpleMessage(
                    new Swift_Mime_SimpleHeaderSet(
                        new Swift_Mime_SimpleHeaderFactory(
                            new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                            new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                            new \Egulias\EmailValidator\EmailValidator()
                        )
                    ),
                    new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream()),
                    new Swift_Mime_IdGenerator('example.com')
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('This is the body'),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Subject: Test Subject',
                    base64_encode('This is the body')
                ]
            ],
            [
                'Message with CC and BCC',
                (new Swift_Mime_SimpleMessage(
                    new Swift_Mime_SimpleHeaderSet(
                        new Swift_Mime_SimpleHeaderFactory(
                            new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                            new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                            new \Egulias\EmailValidator\EmailValidator()
                        )
                    ),
                    new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream()),
                    new Swift_Mime_IdGenerator('example.com')
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
                    base64_encode('This is the body')
                ]
            ],
            [
                'Message with Reply-To header',
                (new Swift_Mime_SimpleMessage(
                    new Swift_Mime_SimpleHeaderSet(
                        new Swift_Mime_SimpleHeaderFactory(
                            new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                            new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                            new \Egulias\EmailValidator\EmailValidator()
                        )
                    ),
                    new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream()),
                    new Swift_Mime_IdGenerator('example.com')
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
                    base64_encode('This is the body')
                ]
            ],
            [
                'Message with HTML body',
                (new Swift_Mime_SimpleMessage(
                    new Swift_Mime_SimpleHeaderSet(
                        new Swift_Mime_SimpleHeaderFactory(
                            new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                            new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                            new \Egulias\EmailValidator\EmailValidator()
                        )
                    ),
                    new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream()),
                    new Swift_Mime_IdGenerator('example.com')
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('<p>This is the HTML body</p>', 'text/html'),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Subject: Test Subject',
                    base64_encode('<p>This is the HTML body</p>')
                ]
            ],
            [
                'Message with attachment',
                (new Swift_Mime_SimpleMessage(
                    new Swift_Mime_SimpleHeaderSet(
                        new Swift_Mime_SimpleHeaderFactory(
                            new Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                            new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                            new \Egulias\EmailValidator\EmailValidator()
                        )
                    ),
                    new Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream()),
                    new Swift_Mime_IdGenerator('example.com')
                ))
                    ->setFrom(['from@example.com' => 'From Name'])
                    ->setTo(['to@example.com' => 'To Name'])
                    ->setSubject('Test Subject')
                    ->setBody('This is the body')
                    ->attach(new Swift_Attachment('Attachment content', 'file.txt', 'text/plain')),
                [
                    'From: From Name <from@example.com>',
                    'To: To Name <to@example.com>',
                    'Subject: Test Subject',
                    base64_encode('This is the body'),
                    'Content-Disposition: attachment; filename=file.txt',
                    base64_encode('Attachment content')
                ]
            ]
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

    protected function setUp(): void
    {
        $this->googleClientMock = $this->createMock(Client::class);
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);

        $this->gmailServiceMock = $this->createMock(Gmail::class);
        $this->messageMock = $this->createMock(Message::class);
        $this->swiftMessageMock = $this->createMock(Swift_Mime_SimpleMessage::class);


        $this->googleTransport = new Swift_Transport_Api_GoogleTransport(
            $this->googleClientMock,
            $this->eventDispatcherMock
        );
    }
}
