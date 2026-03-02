<?php

class Swift_Plugins_BandwidthMonitorPluginTest extends PHPUnit\Framework\TestCase
{
    private $monitor;

    private $bytes = 0;

    protected function setUp(): void
    {
        $this->monitor = new Swift_Plugins_BandwidthMonitorPlugin();
    }

    public function testBytesOutIncreasesWhenCommandsSent()
    {
        $evt = $this->createCommandEvent("RCPT TO:<foo@bar.com>\r\n");

        $this->assertEquals(0, $this->monitor->getBytesOut());
        $this->monitor->commandSent($evt);
        $this->assertEquals(23, $this->monitor->getBytesOut());
        $this->monitor->commandSent($evt);
        $this->assertEquals(46, $this->monitor->getBytesOut());
    }

    public function testBytesInIncreasesWhenResponsesReceived()
    {
        $evt = $this->createResponseEvent("250 Ok\r\n");

        $this->assertEquals(0, $this->monitor->getBytesIn());
        $this->monitor->responseReceived($evt);
        $this->assertEquals(8, $this->monitor->getBytesIn());
        $this->monitor->responseReceived($evt);
        $this->assertEquals(16, $this->monitor->getBytesIn());
    }

    public function testCountersCanBeReset()
    {
        $evt = $this->createResponseEvent("250 Ok\r\n");

        $this->assertEquals(0, $this->monitor->getBytesIn());
        $this->monitor->responseReceived($evt);
        $this->assertEquals(8, $this->monitor->getBytesIn());
        $this->monitor->responseReceived($evt);
        $this->assertEquals(16, $this->monitor->getBytesIn());

        $evt = $this->createCommandEvent("RCPT TO:<foo@bar.com>\r\n");

        $this->assertEquals(0, $this->monitor->getBytesOut());
        $this->monitor->commandSent($evt);
        $this->assertEquals(23, $this->monitor->getBytesOut());
        $this->monitor->commandSent($evt);
        $this->assertEquals(46, $this->monitor->getBytesOut());

        $this->monitor->reset();

        $this->assertEquals(0, $this->monitor->getBytesOut());
        $this->assertEquals(0, $this->monitor->getBytesIn());
    }

    public function testBytesOutIncreasesAccordingToMessageLength()
    {
        $message = $this->createMessageWithByteCount(6);
        $evt     = $this->createSendEvent($message);

        $this->assertEquals(0, $this->monitor->getBytesOut());
        $this->monitor->sendPerformed($evt);
        $this->assertEquals(6, $this->monitor->getBytesOut());
        $this->monitor->sendPerformed($evt);
        $this->assertEquals(12, $this->monitor->getBytesOut());
    }

    private function createSendEvent($message)
    {
        $evt = $this->getMockBuilder('Swift_Events_SendEvent')
            ->disableOriginalConstructor()
            ->getMock();
        $evt->expects($this->any())
            ->method('getMessage')
            ->willReturn($message);

        return $evt;
    }

    private function createCommandEvent($command)
    {
        $evt = $this->getMockBuilder('Swift_Events_CommandEvent')
            ->disableOriginalConstructor()
            ->getMock();
        $evt->expects($this->any())
            ->method('getCommand')
            ->willReturn($command);

        return $evt;
    }

    private function createResponseEvent($response)
    {
        $evt = $this->getMockBuilder('Swift_Events_ResponseEvent')
            ->disableOriginalConstructor()
            ->getMock();
        $evt->expects($this->any())
            ->method('getResponse')
            ->willReturn($response);

        return $evt;
    }

    private function createMessageWithByteCount($bytes)
    {
        $this->bytes = $bytes;
        $msg         = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $msg->expects($this->any())
            ->method('toByteStream')
            ->willReturnCallback([$this, 'write']);
        /*  $this->checking(Expectations::create()
              -> ignoring($msg)->toByteStream(any()) -> calls(array($this, 'write'))
          ); */

        return $msg;
    }

    public function testWriteMethodUpdatesOutCounter()
    {
        $this->assertEquals(0, $this->monitor->getBytesOut());
        $this->monitor->write('hello');
        $this->assertEquals(5, $this->monitor->getBytesOut());
    }

    public function testWriteWithArray()
    {
        $this->monitor->write(['abc', 'def']);
        $this->assertEquals(6, $this->monitor->getBytesOut());
    }

    public function testCommitIsNoop()
    {
        // Should not throw
        $this->monitor->commit();
        $this->assertEquals(0, $this->monitor->getBytesOut());
    }

    public function testFlushBuffersMirrorsToBindings()
    {
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->once())->method('flushBuffers');
        $this->monitor->bind($mirror);
        $this->monitor->flushBuffers();
    }

    public function testBindMirrorsWrites()
    {
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->once())->method('write')->with('test');
        $this->monitor->bind($mirror);
        $this->monitor->write('test');
    }

    public function testUnbindStopsMirroring()
    {
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->never())->method('write');
        $this->monitor->bind($mirror);
        $this->monitor->unbind($mirror);
        $this->monitor->write('test');
    }

    public function testMultipleMirrorsGetWrites()
    {
        $mirror1 = $this->createMock(Swift_InputByteStream::class);
        $mirror2 = $this->createMock(Swift_InputByteStream::class);
        $mirror1->expects($this->once())->method('write')->with('data');
        $mirror2->expects($this->once())->method('write')->with('data');

        $this->monitor->bind($mirror1);
        $this->monitor->bind($mirror2);
        $this->monitor->write('data');
    }

    public function testBeforeSendPerformedIsNoop()
    {
        $evt = $this->getMockBuilder('Swift_Events_SendEvent')
            ->disableOriginalConstructor()
            ->getMock();
        // Should not throw
        $this->monitor->beforeSendPerformed($evt);
        $this->assertEquals(0, $this->monitor->getBytesOut());
    }

    public function testImplementsRequiredInterfaces()
    {
        $this->assertInstanceOf(Swift_Events_SendListener::class, $this->monitor);
        $this->assertInstanceOf(Swift_Events_CommandListener::class, $this->monitor);
        $this->assertInstanceOf(Swift_Events_ResponseListener::class, $this->monitor);
        $this->assertInstanceOf(Swift_InputByteStream::class, $this->monitor);
    }

    public function testResetDoesNotAffectMirrors()
    {
        $mirror = $this->createMock(Swift_InputByteStream::class);
        $mirror->expects($this->once())->method('write')->with('after');
        $this->monitor->bind($mirror);
        $this->monitor->reset();
        $this->monitor->write('after');
    }

    public function testCombinedInOutTracking()
    {
        $cmdEvt = $this->createCommandEvent("EHLO\r\n");
        $resEvt = $this->createResponseEvent("250 Ok\r\n");

        $this->monitor->commandSent($cmdEvt);
        $this->monitor->responseReceived($resEvt);

        $this->assertEquals(6, $this->monitor->getBytesOut());
        $this->assertEquals(8, $this->monitor->getBytesIn());
    }

    public function write($is)
    {
        for ($i = 0; $i < $this->bytes; ++$i) {
            $is->write('x');
        }
    }
}
