<?php

class Swift_Events_SendEventTest extends PHPUnit\Framework\TestCase
{
    public function testMessageCanBeFetchedViaGetter()
    {
        $message   = $this->createMessage();
        $transport = $this->createTransport();

        $evt = $this->createEvent($transport, $message);

        $ref = $evt->getMessage();
        $this->assertEquals(
            $message,
            $ref,
            '%s: Message should be returned from getMessage()',
        );
    }

    public function testTransportCanBeFetchViaGetter()
    {
        $message   = $this->createMessage();
        $transport = $this->createTransport();

        $evt = $this->createEvent($transport, $message);

        $ref = $evt->getTransport();
        $this->assertEquals(
            $transport,
            $ref,
            '%s: Transport should be returned from getTransport()',
        );
    }

    public function testTransportCanBeFetchViaGetSource()
    {
        $message   = $this->createMessage();
        $transport = $this->createTransport();

        $evt = $this->createEvent($transport, $message);

        $ref = $evt->getSource();
        $this->assertEquals(
            $transport,
            $ref,
            '%s: Transport should be returned from getSource()',
        );
    }

    public function testResultCanBeSetAndGet()
    {
        $message   = $this->createMessage();
        $transport = $this->createTransport();

        $evt = $this->createEvent($transport, $message);

        $evt->setResult(
            Swift_Events_SendEvent::RESULT_SUCCESS | Swift_Events_SendEvent::RESULT_TENTATIVE,
        );

        $this->assertTrue((bool) ($evt->getResult() & Swift_Events_SendEvent::RESULT_SUCCESS));
        $this->assertTrue((bool) ($evt->getResult() & Swift_Events_SendEvent::RESULT_TENTATIVE));
    }

    public function testFailedRecipientsCanBeSetAndGet()
    {
        $message   = $this->createMessage();
        $transport = $this->createTransport();

        $evt = $this->createEvent($transport, $message);

        $evt->setFailedRecipients(['foo@bar', 'zip@button']);

        $this->assertEquals(
            ['foo@bar', 'zip@button'],
            $evt->getFailedRecipients(),
            '%s: FailedRecipients should be returned from getter',
        );
    }

    public function testFailedRecipientsGetsPickedUpCorrectly()
    {
        $message   = $this->createMessage();
        $transport = $this->createTransport();

        $evt = $this->createEvent($transport, $message);
        $this->assertEquals([], $evt->getFailedRecipients());
    }

    public function testNotRejectedByDefault()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());

        $this->assertFalse($evt->isRejected());
        $this->assertNull($evt->getRejectionReason());
    }

    public function testRejectWithReason()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->reject('Recipient is on suppression list');

        $this->assertTrue($evt->isRejected());
        $this->assertSame('Recipient is on suppression list', $evt->getRejectionReason());
    }

    public function testRejectWithoutReason()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->reject();

        $this->assertTrue($evt->isRejected());
        $this->assertNull($evt->getRejectionReason());
    }

    public function testRejectAlsoCancelsBubble()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->reject('Blocked');

        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testEnvelopeIsNullByDefault()
    {
        $transport = $this->createTransport();
        $message   = $this->createMessage();
        $evt       = $this->createEvent($transport, $message);

        $this->assertNull($evt->getEnvelope());
    }

    public function testEnvelopeCanBeSetAndRetrieved()
    {
        $transport = $this->createTransport();
        $message   = $this->createMessage();
        $envelope  = new Swift_Envelope('sender@example.com', ['to@example.com']);
        $evt       = $this->createEvent($transport, $message);

        $evt->setEnvelope($envelope);

        $retrieved = $evt->getEnvelope();
        $this->assertNotNull($retrieved);
        $this->assertEquals($envelope->getSender(), $retrieved->getSender());
        $this->assertEquals($envelope->getRecipients(), $retrieved->getRecipients());
    }

    public function testDefaultResultIsPending()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $this->assertTrue((bool) ($evt->getResult() & Swift_Events_SendEvent::RESULT_PENDING));
    }

    public function testResultSuccess()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
        $this->assertTrue((bool) ($evt->getResult() & Swift_Events_SendEvent::RESULT_SUCCESS));
    }

    public function testResultFailed()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $evt->getResult());
    }

    public function testResultSpooled()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->setResult(Swift_Events_SendEvent::RESULT_SPOOLED);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SPOOLED, $evt->getResult());
    }

    public function testInheritsEventObject()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $this->assertInstanceOf(Swift_Events_EventObject::class, $evt);
    }

    public function testBubbleCancellation()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $this->assertFalse($evt->bubbleCancelled());
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testGetTransportReturnsSameAsGetSource()
    {
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport, $this->createMessage());
        $this->assertSame($evt->getTransport(), $evt->getSource());
    }

    public function testSetFailedRecipientsOverwritesPrevious()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->setFailedRecipients(['a@b.com']);
        $evt->setFailedRecipients(['c@d.com', 'e@f.com']);
        $this->assertEquals(['c@d.com', 'e@f.com'], $evt->getFailedRecipients());
    }

    public function testRejectCanBeCalledMultipleTimes()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createMessage());
        $evt->reject('First reason');
        $evt->reject('Second reason');
        $this->assertTrue($evt->isRejected());
        $this->assertSame('Second reason', $evt->getRejectionReason());
    }

    public function testEnvelopeCanBeSetToNull()
    {
        $evt      = $this->createEvent($this->createTransport(), $this->createMessage());
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com']);
        $evt->setEnvelope($envelope);
        $this->assertNotNull($evt->getEnvelope());
        $evt->setEnvelope(null);
        $this->assertNull($evt->getEnvelope());
    }

    public function testEnvelopeSender()
    {
        $evt      = $this->createEvent($this->createTransport(), $this->createMessage());
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com']);
        $evt->setEnvelope($envelope);
        $this->assertSame('sender@example.com', $evt->getEnvelope()->getSender());
    }

    public function testEnvelopeRecipients()
    {
        $evt      = $this->createEvent($this->createTransport(), $this->createMessage());
        $envelope = new Swift_Envelope('sender@example.com', ['a@b.com', 'c@d.com']);
        $evt->setEnvelope($envelope);
        $this->assertCount(2, $evt->getEnvelope()->getRecipients());
    }

    public function testSendEventEnvelopeIsCloned()
    {
        $evt      = $this->createEvent($this->createTransport(), $this->createMessage());
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com']);
        $evt->setEnvelope($envelope);

        $retrieved = $evt->getEnvelope();
        $this->assertNotSame($envelope, $retrieved);
        $this->assertEquals($envelope->getSender(), $retrieved->getSender());
        $this->assertEquals($envelope->getRecipients(), $retrieved->getRecipients());

        $retrieved2 = $evt->getEnvelope();
        $this->assertNotSame($retrieved, $retrieved2);
    }

    public function testResultConstants()
    {
        $this->assertSame(0x0001, Swift_Events_SendEvent::RESULT_PENDING);
        $this->assertSame(0x0011, Swift_Events_SendEvent::RESULT_SPOOLED);
        $this->assertSame(0x0010, Swift_Events_SendEvent::RESULT_SUCCESS);
        $this->assertSame(0x0100, Swift_Events_SendEvent::RESULT_TENTATIVE);
        $this->assertSame(0x1000, Swift_Events_SendEvent::RESULT_FAILED);
    }

    private function createEvent(Swift_Transport $source, Swift_Mime_SimpleMessage $message)
    {
        return new Swift_Events_SendEvent($source, $message);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }

    private function createMessage()
    {
        return $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
    }
}
