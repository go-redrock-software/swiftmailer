<?php

class Swift_MessageLimitsTest extends PHPUnit\Framework\TestCase
{
    private Swift_MessageLimits $limits;

    protected function setUp(): void
    {
        $this->limits = new Swift_MessageLimits();
    }

    public function testAcceptsValidMessage()
    {
        $message = $this->createMessage(
            body: 'Hello',
            to: ['a@b.com' => 'A'],
            cc: [],
            bcc: [],
            children: []
        );

        $this->limits->validate($message);
        $this->addToAssertionCount(1);
    }

    public function testRejectsTooManyRecipients()
    {
        $this->limits->maxRecipientCount = 3;

        $to = ['a@b.com' => 'A', 'b@b.com' => 'B'];
        $cc = ['c@b.com' => 'C', 'd@b.com' => 'D'];

        $message = $this->createMessage(
            body: 'Hello',
            to: $to,
            cc: $cc,
            bcc: [],
            children: []
        );

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Message has 4 recipients, maximum is 3');
        $this->limits->validate($message);
    }

    public function testRejectsOversizedBody()
    {
        $this->limits->maxBodySize = 10;

        $message = $this->createMessage(
            body: str_repeat('x', 11),
            to: ['a@b.com' => 'A'],
            cc: [],
            bcc: [],
            children: []
        );

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Message body is 11 bytes, maximum is 10');
        $this->limits->validate($message);
    }

    public function testRejectsTooManyAttachments()
    {
        $this->limits->maxAttachmentCount = 1;

        $att1 = $this->createAttachment('file1.txt', 'data1');
        $att2 = $this->createAttachment('file2.txt', 'data2');

        $message = $this->createMessage(
            body: 'Hello',
            to: ['a@b.com' => 'A'],
            cc: [],
            bcc: [],
            children: [$att1, $att2]
        );

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Message has 2 attachments, maximum is 1');
        $this->limits->validate($message);
    }

    public function testRejectsOversizedAttachment()
    {
        $this->limits->maxAttachmentSize = 5;

        $att = $this->createAttachment('big.txt', str_repeat('x', 6));

        $message = $this->createMessage(
            body: 'Hello',
            to: ['a@b.com' => 'A'],
            cc: [],
            bcc: [],
            children: [$att]
        );

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Attachment "big.txt" is 6 bytes, maximum is 5');
        $this->limits->validate($message);
    }

    public function testRejectsOversizedTotal()
    {
        $this->limits->maxTotalSize = 10;

        $att = $this->createAttachment('a.txt', str_repeat('x', 6));

        $message = $this->createMessage(
            body: str_repeat('y', 6),
            to: ['a@b.com' => 'A'],
            cc: [],
            bcc: [],
            children: [$att]
        );

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Total message size is 12 bytes, maximum is 10');
        $this->limits->validate($message);
    }

    public function testDefaultLimitsAcceptReasonableMessage()
    {
        $message = $this->createMessage(
            body: str_repeat('a', 1000),
            to: ['a@b.com' => 'A'],
            cc: [],
            bcc: [],
            children: []
        );

        $this->limits->validate($message);
        $this->addToAssertionCount(1);
    }

    private function createMessage(string $body, array $to, array $cc, array $bcc, array $children): Swift_Mime_SimpleMessage
    {
        $message = $this->createMock(Swift_Mime_SimpleMessage::class);
        $message->method('getBody')->willReturn($body);
        $message->method('getTo')->willReturn($to);
        $message->method('getCc')->willReturn($cc);
        $message->method('getBcc')->willReturn($bcc);
        $message->method('getChildren')->willReturn($children);

        return $message;
    }

    private function createAttachment(string $filename, string $body): Swift_Attachment
    {
        $att = $this->createMock(Swift_Attachment::class);
        $att->method('getFilename')->willReturn($filename);
        $att->method('getBody')->willReturn($body);

        return $att;
    }
}
