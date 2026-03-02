<?php

class Swift_Events_TransportExceptionEventTest extends PHPUnit\Framework\TestCase
{
    public function testExceptionCanBeFetchViaGetter()
    {
        $ex        = $this->createException();
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport, $ex);
        $ref       = $evt->getException();
        $this->assertEquals(
            $ex,
            $ref,
            '%s: Exception should be available via getException()',
        );
    }

    public function testSourceIsTransport()
    {
        $ex        = $this->createException();
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport, $ex);
        $ref       = $evt->getSource();
        $this->assertEquals(
            $transport,
            $ref,
            '%s: Transport should be available via getSource()',
        );
    }

    public function testInheritsEventObject()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createException());
        $this->assertInstanceOf(Swift_Events_EventObject::class, $evt);
    }

    public function testExceptionMessageIsPreserved()
    {
        $ex = new Swift_TransportException('Connection timed out');
        $evt = $this->createEvent($this->createTransport(), $ex);
        $this->assertSame('Connection timed out', $evt->getException()->getMessage());
    }

    public function testBubbleCancellation()
    {
        $evt = $this->createEvent($this->createTransport(), $this->createException());
        $this->assertFalse($evt->bubbleCancelled());
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testExceptionCodeIsPreserved()
    {
        $ex = new Swift_TransportException('Failure', 500);
        $evt = $this->createEvent($this->createTransport(), $ex);
        $this->assertSame(500, $evt->getException()->getCode());
    }

    public function testExceptionWithPreviousException()
    {
        $previous = new RuntimeException('Network error');
        $ex = new Swift_TransportException('Transport failed', 0, $previous);
        $evt = $this->createEvent($this->createTransport(), $ex);
        $this->assertSame($previous, $evt->getException()->getPrevious());
    }

    private function createEvent(Swift_Transport $transport, Swift_TransportException $ex)
    {
        return new Swift_Events_TransportExceptionEvent($transport, $ex);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }

    private function createException()
    {
        return new Swift_TransportException('');
    }
}
