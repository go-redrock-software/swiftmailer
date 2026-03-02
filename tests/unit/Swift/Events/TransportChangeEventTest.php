<?php

class Swift_Events_TransportChangeEventTest extends PHPUnit\Framework\TestCase
{
    public function testGetTransportReturnsTransport()
    {
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport);
        $ref       = $evt->getTransport();
        $this->assertEquals($transport, $ref);
    }

    public function testSourceIsTransport()
    {
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport);
        $ref       = $evt->getSource();
        $this->assertEquals($transport, $ref);
    }

    public function testInheritsEventObject()
    {
        $evt = $this->createEvent($this->createTransport());
        $this->assertInstanceOf(Swift_Events_EventObject::class, $evt);
    }

    public function testBubbleCancellation()
    {
        $evt = $this->createEvent($this->createTransport());
        $this->assertFalse($evt->bubbleCancelled());
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testGetTransportAndGetSourceReturnSameObject()
    {
        $transport = $this->createTransport();
        $evt = $this->createEvent($transport);
        $this->assertSame($evt->getTransport(), $evt->getSource());
    }

    private function createEvent(Swift_Transport $source)
    {
        return new Swift_Events_TransportChangeEvent($source);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }
}
