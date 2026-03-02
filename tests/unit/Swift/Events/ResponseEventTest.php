<?php

class Swift_Events_ResponseEventTest extends PHPUnit\Framework\TestCase
{
    public function testResponseCanBeFetchViaGetter()
    {
        $evt = $this->createEvent($this->createTransport(), "250 Ok\r\n", true);
        $this->assertEquals(
            "250 Ok\r\n",
            $evt->getResponse(),
            '%s: Response should be available via getResponse()',
        );
    }

    public function testResultCanBeFetchedViaGetter()
    {
        $evt = $this->createEvent($this->createTransport(), "250 Ok\r\n", false);
        $this->assertFalse(
            $evt->isValid(),
            '%s: Result should be checkable via isValid()',
        );
    }

    public function testSourceIsBuffer()
    {
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport, "250 Ok\r\n", true);
        $ref       = $evt->getSource();
        $this->assertEquals($transport, $ref);
    }

    public function testValidResponseReturnsTrue()
    {
        $evt = $this->createEvent($this->createTransport(), "250 Ok\r\n", true);
        $this->assertTrue($evt->isValid());
    }

    public function testDefaultValidIsFalse()
    {
        $transport = $this->createTransport();
        $evt = new Swift_Events_ResponseEvent($transport, "550 Failed\r\n");
        $this->assertFalse($evt->isValid());
    }

    public function testEmptyResponse()
    {
        $evt = $this->createEvent($this->createTransport(), '', false);
        $this->assertEquals('', $evt->getResponse());
    }

    public function testInheritsEventObject()
    {
        $evt = $this->createEvent($this->createTransport(), "220 Ready\r\n", true);
        $this->assertInstanceOf(Swift_Events_EventObject::class, $evt);
    }

    public function testBubbleCancellation()
    {
        $evt = $this->createEvent($this->createTransport(), "250 Ok\r\n", true);
        $this->assertFalse($evt->bubbleCancelled());
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testMultiLineResponse()
    {
        $response = "250-SIZE 35882577\r\n250-8BITMIME\r\n250 DSN\r\n";
        $evt = $this->createEvent($this->createTransport(), $response, true);
        $this->assertEquals($response, $evt->getResponse());
    }

    public function testResponseWithErrorCode()
    {
        $evt = $this->createEvent($this->createTransport(), "550 5.1.1 User unknown\r\n", false);
        $this->assertFalse($evt->isValid());
        $this->assertStringContainsString('550', $evt->getResponse());
    }

    private function createEvent(Swift_Transport $source, $response, $result)
    {
        return new Swift_Events_ResponseEvent($source, $response, $result);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }
}
