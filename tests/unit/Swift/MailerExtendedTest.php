<?php

class Swift_MailerExtendedTest extends PHPUnit\Framework\TestCase
{
    public function testGetTransportReturnsSameInstance()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $mailer    = new Swift_Mailer($transport);
        $this->assertSame($transport, $mailer->getTransport());
        $this->assertSame($transport, $mailer->getTransport());
    }

    public function testSendWithAlreadyStartedTransport()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->never())->method('start');
        $transport->method('send')->willReturn(3);

        $mailer = new Swift_Mailer($transport);
        $this->assertEquals(3, $mailer->send($message));
    }

    public function testSendStartsStoppedTransport()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(false);
        $transport->expects($this->once())->method('start');
        $transport->method('send')->willReturn(1);

        $mailer = new Swift_Mailer($transport);
        $mailer->send($message);
    }

    public function testSendReturnsSentCount()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(42);

        $mailer = new Swift_Mailer($transport);
        $this->assertEquals(42, $mailer->send($message));
    }

    public function testSendReturnsZeroOnSendFailure()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(0);

        $mailer = new Swift_Mailer($transport);
        $this->assertEquals(0, $mailer->send($message));
    }

    public function testSendCatchesRfcComplianceException()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willThrowException(new Swift_RfcComplianceException('invalid'));
        $message->method('getTo')->willReturn(['bad@example.com' => 'Bad']);

        $mailer = new Swift_Mailer($transport);
        $failed = [];
        $result = $mailer->send($message, $failed);

        $this->assertEquals(0, $result);
        $this->assertEquals(['bad@example.com'], $failed);
    }

    public function testSendCollectsAllFailedRecipientsOnRfcException()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willThrowException(new Swift_RfcComplianceException('invalid'));
        $message->method('getTo')->willReturn([
            'a@test.com' => 'A',
            'b@test.com' => 'B',
            'c@test.com' => 'C',
        ]);

        $mailer = new Swift_Mailer($transport);
        $failed = [];
        $mailer->send($message, $failed);

        $this->assertCount(3, $failed);
    }

    public function testRegisterPluginDelegates()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $plugin    = $this->createMock(Swift_Events_EventListener::class);

        $transport->expects($this->once())
            ->method('registerPlugin')
            ->with($plugin);

        $mailer = new Swift_Mailer($transport);
        $mailer->registerPlugin($plugin);
    }

    public function testSendPassesEnvelope()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);
        $envelope  = new Swift_Envelope('sender@test.com', ['rcpt@test.com']);

        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with($message, $this->anything(), $envelope)
            ->willReturn(1);

        $mailer = new Swift_Mailer($transport);
        $mailer->send($message, $f, $envelope);
    }

    public function testSendWithoutEnvelopePassesNull()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with($message, $this->anything(), null)
            ->willReturn(1);

        $mailer = new Swift_Mailer($transport);
        $mailer->send($message);
    }

    public function testFailedRecipientsIsInitializedAsArray()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message   = $this->createMock(Swift_Mime_SimpleMessage::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(1);

        $mailer = new Swift_Mailer($transport);
        $failed = null;
        $mailer->send($message, $failed);
        $this->assertIsArray($failed);
    }

    public function testSendMultipleMessages()
    {
        $transport = $this->createMock(Swift_Transport::class);

        $transport->method('isStarted')->willReturn(true);
        $transport->method('send')->willReturn(1);

        $mailer = new Swift_Mailer($transport);
        for ($i = 0; $i < 5; ++$i) {
            $message = $this->createMock(Swift_Mime_SimpleMessage::class);
            $this->assertEquals(1, $mailer->send($message));
        }
    }
}
