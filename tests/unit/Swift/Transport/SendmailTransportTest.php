<?php

class Swift_Transport_SendmailTransportTest extends Swift_Transport_AbstractSmtpEventSupportTest
{
    protected function getTransport($buf, $dispatcher = null, $addressEncoder = null, $command = '/usr/sbin/sendmail -bs')
    {
        if (!$dispatcher) {
            $dispatcher = $this->createEventDispatcher();
        }
        $transport = new Swift_Transport_SendmailTransport($buf, $dispatcher, 'example.org', $addressEncoder);
        $transport->setCommand($command);

        return $transport;
    }

    protected function getSendmail($buf, $dispatcher = null)
    {
        if (!$dispatcher) {
            $dispatcher = $this->createEventDispatcher();
        }

        return new Swift_Transport_SendmailTransport($buf, $dispatcher);
    }

    public function testCommandCanBeSetAndFetched()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);

        $sendmail->setCommand('/usr/sbin/sendmail -bs');
        $this->assertEquals('/usr/sbin/sendmail -bs', $sendmail->getCommand());
        $sendmail->setCommand('/usr/sbin/sendmail -oi -t');
        $this->assertEquals('/usr/sbin/sendmail -oi -t', $sendmail->getCommand());
    }

    public function testSendingMessageInTModeUsesSimplePipe()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar', 'zip@button' => 'Zippy']);
        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with(["\r\n" => "\n", "\n." => "\n.."]);
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with([]);

        $sendmail->setCommand('/usr/sbin/sendmail -t');
        $this->assertEquals(2, $sendmail->send($message));
    }

    public function testSendingInTModeWithIFlagDoesntEscapeDot()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar', 'zip@button' => 'Zippy']);
        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with(["\r\n" => "\n"]);
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with([]);

        $sendmail->setCommand('/usr/sbin/sendmail -i -t');
        $this->assertEquals(2, $sendmail->send($message));
    }

    public function testSendingInTModeWithOiFlagDoesntEscapeDot()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar', 'zip@button' => 'Zippy']);
        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with(["\r\n" => "\n"]);
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with([]);

        $sendmail->setCommand('/usr/sbin/sendmail -oi -t');
        $this->assertEquals(2, $sendmail->send($message));
    }

    public function testSendingMessageRegeneratesId()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar', 'zip@button' => 'Zippy']);
        $message->shouldReceive('generateId');
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with(["\r\n" => "\n", "\n." => "\n.."]);
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with([]);

        $sendmail->setCommand('/usr/sbin/sendmail -t');
        $this->assertEquals(2, $sendmail->send($message));
    }

    public function testFluidInterface()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getTransport($buf);

        $ref = $sendmail->setCommand('/foo');
        $this->assertEquals($ref, $sendmail);
    }

    public function testSendingInTModeWithCancelledBubbleReturnsZero()
    {
        $buf        = $this->getBuffer();
        $dispatcher = $this->createEventDispatcher(false);
        $evt        = $this->getMockery('Swift_Events_SendEvent')->shouldIgnoreMissing();
        $sendmail   = $this->getSendmail($buf, $dispatcher);
        $message    = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar']);

        $dispatcher->shouldReceive('createSendEvent')
            ->once()
            ->andReturn($evt);
        $dispatcher->shouldReceive('dispatchEvent')
            ->once()
            ->with($evt, 'beforeSendPerformed');
        $evt->shouldReceive('bubbleCancelled')
            ->once()
            ->andReturn(true);
        $evt->shouldReceive('setResult')
            ->once()
            ->with(Swift_Events_SendEvent::RESULT_FAILED);
        $evt->shouldReceive('cancelBubble')
            ->once()
            ->with(false);
        $dispatcher->shouldReceive('dispatchEvent')
            ->once()
            ->with($evt, 'sendPerformed');

        $sendmail->setCommand('/usr/sbin/sendmail -t');
        $this->assertEquals(0, $sendmail->send($message));
    }

    public function testSendingInTModeWithEnvelopeUsesEnvelopeSenderAndCount()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $envelope = new Swift_Envelope('override@example.com', ['r1@example.com', 'r2@example.com', 'r3@example.com']);

        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with(["\r\n" => "\n", "\n." => "\n.."]);
        $buf->shouldReceive('setWriteTranslations')
            ->once()
            ->with([]);

        $sendmail->setCommand('/usr/sbin/sendmail -t');
        $this->assertEquals(3, $sendmail->send($message, $failedRecipients, $envelope));
    }

    public function testSendingInTModeDispatchesSendPerformedEvent()
    {
        $buf        = $this->getBuffer();
        $dispatcher = $this->createEventDispatcher(false);
        $evt        = $this->getMockery('Swift_Events_SendEvent')->shouldIgnoreMissing();
        $sendmail   = $this->getSendmail($buf, $dispatcher);
        $message    = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar']);
        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);

        $dispatcher->shouldReceive('createSendEvent')
            ->once()
            ->andReturn($evt);
        $evt->shouldReceive('bubbleCancelled')
            ->once()
            ->andReturn(false);
        $evt->shouldReceive('setResult')
            ->once()
            ->with(Swift_Events_SendEvent::RESULT_SUCCESS);
        $evt->shouldReceive('setFailedRecipients')
            ->once()
            ->with([]);
        $dispatcher->shouldReceive('dispatchEvent')
            ->once()
            ->with($evt, 'sendPerformed');
        $dispatcher->shouldReceive('dispatchEvent')
            ->zeroOrMoreTimes();

        $buf->shouldReceive('initialize')->once();
        $buf->shouldReceive('terminate')->once();
        $buf->shouldReceive('setWriteTranslations')
            ->zeroOrMoreTimes();

        $sendmail->setCommand('/usr/sbin/sendmail -t');
        $this->assertEquals(1, $sendmail->send($message));
    }

    public function testUnsupportedCommandFlagsThrowsException()
    {
        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Unsupported sendmail command flags');

        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $sendmail->setCommand('/usr/sbin/sendmail -q');
        $sendmail->send($message);
    }

    public function testSendingInTModeWithEnvelopeReversePath()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $envelope = new Swift_Envelope('envelope-sender@example.com', ['r1@example.com']);

        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);

        // Check the command includes -f with envelope sender
        $buf->shouldReceive('initialize')
            ->once()
            ->with(Mockery::on(function ($params) {
                return str_contains($params['command'] ?? '', 'envelope-sender@example.com');
            }));
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->zeroOrMoreTimes();

        $sendmail->setCommand('/usr/sbin/sendmail -t');
        $this->assertEquals(1, $sendmail->send($message, $failedRecipients, $envelope));
    }

    public function testSendingInTModeWithExistingFFlag()
    {
        $buf      = $this->getBuffer();
        $sendmail = $this->getSendmail($buf);
        $message  = $this->createMessage();

        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => 'Foobar']);
        $message->shouldReceive('toByteStream')
            ->once()
            ->with($buf);

        // When -f is already in the command, it should not be appended again
        $buf->shouldReceive('initialize')
            ->once()
            ->with(Mockery::on(function ($params) {
                // Command should remain exactly as set (no extra -f appended)
                return $params['command'] === '/usr/sbin/sendmail -f sender@test.com -t';
            }));
        $buf->shouldReceive('terminate')
            ->once();
        $buf->shouldReceive('setWriteTranslations')
            ->zeroOrMoreTimes();

        $sendmail->setCommand('/usr/sbin/sendmail -f sender@test.com -t');
        $this->assertEquals(1, $sendmail->send($message));
    }

    protected function createEventDispatcher($stub = true)
    {
        return $this->getMockery('Swift_Events_EventDispatcher')->shouldIgnoreMissing();
    }
}
