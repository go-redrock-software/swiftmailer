<?php

class Swift_Events_CommandEventTest extends PHPUnit\Framework\TestCase
{
    public function testCommandCanBeFetchedByGetter()
    {
        $evt = $this->createEvent($this->createTransport(), "FOO\r\n");
        $this->assertEquals("FOO\r\n", $evt->getCommand());
    }

    public function testSuccessCodesCanBeFetchedViaGetter()
    {
        $evt = $this->createEvent($this->createTransport(), "FOO\r\n", [250]);
        $this->assertEquals([250], $evt->getSuccessCodes());
    }

    public function testSourceIsBuffer()
    {
        $transport = $this->createTransport();
        $evt       = $this->createEvent($transport, "FOO\r\n");
        $ref       = $evt->getSource();
        $this->assertEquals($transport, $ref);
    }

    public function testEmptySuccessCodesDefault()
    {
        $evt = $this->createEvent($this->createTransport(), "EHLO\r\n");
        $this->assertEquals([], $evt->getSuccessCodes());
    }

    public function testMultipleSuccessCodes()
    {
        $evt = $this->createEvent($this->createTransport(), "RCPT TO:<test@example.com>\r\n", [250, 251]);
        $this->assertEquals([250, 251], $evt->getSuccessCodes());
    }

    public function testEmptyCommand()
    {
        $evt = $this->createEvent($this->createTransport(), '');
        $this->assertEquals('', $evt->getCommand());
    }

    public function testInheritsEventObject()
    {
        $transport = $this->createTransport();
        $evt = $this->createEvent($transport, "QUIT\r\n");
        $this->assertInstanceOf(Swift_Events_EventObject::class, $evt);
    }

    public function testBubbleCancellationWorks()
    {
        $evt = $this->createEvent($this->createTransport(), "HELO\r\n");
        $this->assertFalse($evt->bubbleCancelled());
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testCommandWithSpecialCharacters()
    {
        $command = "AUTH LOGIN dXNlcm5hbWU=\r\n";
        $evt = $this->createEvent($this->createTransport(), $command);
        $this->assertEquals($command, $evt->getCommand());
    }

    public function testLongCommand()
    {
        $command = 'DATA '.str_repeat('x', 500)."\r\n";
        $evt = $this->createEvent($this->createTransport(), $command);
        $this->assertEquals($command, $evt->getCommand());
    }

    private function createEvent(Swift_Transport $source, $command, $successCodes = [])
    {
        return new Swift_Events_CommandEvent($source, $command, $successCodes);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }
}
