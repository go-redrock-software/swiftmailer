<?php

class Swift_Plugins_AntiFloodPluginTest extends PHPUnit\Framework\TestCase
{
    public function testThresholdCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_AntiFloodPlugin(10);
        $this->assertEquals(10, $plugin->getThreshold());
        $plugin->setThreshold(100);
        $this->assertEquals(100, $plugin->getThreshold());
    }

    public function testSleepTimeCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_AntiFloodPlugin(10, 5);
        $this->assertEquals(5, $plugin->getSleepTime());
        $plugin->setSleepTime(1);
        $this->assertEquals(1, $plugin->getSleepTime());
    }

    public function testPluginStopsConnectionAfterThreshold()
    {
        $transport = $this->createTransport();
        $transport->expects($this->once())
            ->method('start');
        $transport->expects($this->once())
            ->method('stop');

        $evt = $this->createSendEvent($transport);

        $plugin = new Swift_Plugins_AntiFloodPlugin(10);
        for ($i = 0; $i < 12; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testPluginCanStopAndStartMultipleTimes()
    {
        $transport = $this->createTransport();
        $transport->expects($this->exactly(5))
            ->method('start');
        $transport->expects($this->exactly(5))
            ->method('stop');

        $evt = $this->createSendEvent($transport);

        $plugin = new Swift_Plugins_AntiFloodPlugin(2);
        for ($i = 0; $i < 11; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testPluginCanSleepDuringRestart()
    {
        $sleeper = $this->getMockBuilder('Swift_Plugins_Sleeper')->getMock();
        $sleeper->expects($this->once())
            ->method('sleep')
            ->with(10);

        $transport = $this->createTransport();
        $transport->expects($this->once())
            ->method('start');
        $transport->expects($this->once())
            ->method('stop');

        $evt = $this->createSendEvent($transport);

        $plugin = new Swift_Plugins_AntiFloodPlugin(99, 10, $sleeper);
        for ($i = 0; $i < 101; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testDefaultThreshold()
    {
        $plugin = new Swift_Plugins_AntiFloodPlugin();
        $this->assertEquals(99, $plugin->getThreshold());
    }

    public function testDefaultSleepTime()
    {
        $plugin = new Swift_Plugins_AntiFloodPlugin();
        $this->assertEquals(0, $plugin->getSleepTime());
    }

    public function testBeforeSendPerformedIsNoop()
    {
        $transport = $this->createTransport();
        $transport->expects($this->never())->method('start');
        $transport->expects($this->never())->method('stop');

        $evt = $this->createSendEvent($transport);

        $plugin = new Swift_Plugins_AntiFloodPlugin(10);
        $plugin->beforeSendPerformed($evt);
    }

    public function testPluginImplementsSendListener()
    {
        $plugin = new Swift_Plugins_AntiFloodPlugin();
        $this->assertInstanceOf(Swift_Events_SendListener::class, $plugin);
    }

    public function testPluginImplementsSleeper()
    {
        $plugin = new Swift_Plugins_AntiFloodPlugin();
        $this->assertInstanceOf(Swift_Plugins_Sleeper::class, $plugin);
    }

    public function testExactThresholdTriggersRestart()
    {
        $transport = $this->createTransport();
        $transport->expects($this->once())->method('start');
        $transport->expects($this->once())->method('stop');

        $evt    = $this->createSendEvent($transport);
        $plugin = new Swift_Plugins_AntiFloodPlugin(5);

        for ($i = 0; $i < 5; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testBelowThresholdDoesNotRestart()
    {
        $transport = $this->createTransport();
        $transport->expects($this->never())->method('start');
        $transport->expects($this->never())->method('stop');

        $evt    = $this->createSendEvent($transport);
        $plugin = new Swift_Plugins_AntiFloodPlugin(10);

        for ($i = 0; $i < 9; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testSleeperNotCalledWhenSleepTimeIsZero()
    {
        $sleeper = $this->getMockBuilder('Swift_Plugins_Sleeper')->getMock();
        $sleeper->expects($this->never())->method('sleep');

        $transport = $this->createTransport();
        $evt       = $this->createSendEvent($transport);

        $plugin = new Swift_Plugins_AntiFloodPlugin(2, 0, $sleeper);
        for ($i = 0; $i < 3; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testThresholdOfOneRestartsEveryTime()
    {
        $transport = $this->createTransport();
        $transport->expects($this->exactly(5))->method('start');
        $transport->expects($this->exactly(5))->method('stop');

        $evt    = $this->createSendEvent($transport);
        $plugin = new Swift_Plugins_AntiFloodPlugin(1);

        for ($i = 0; $i < 5; ++$i) {
            $plugin->sendPerformed($evt);
        }
    }

    public function testSleepUsesNativeWhenNoSleeperSet()
    {
        // Verify native sleep path doesn't throw (sleep 0 for speed)
        $plugin = new Swift_Plugins_AntiFloodPlugin();
        $plugin->sleep(0);
        $this->addToAssertionCount(1);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }

    private function createSendEvent($transport)
    {
        $evt = $this->getMockBuilder('Swift_Events_SendEvent')
            ->disableOriginalConstructor()
            ->getMock();
        $evt->expects($this->any())
            ->method('getSource')
            ->willReturn($transport);
        $evt->expects($this->any())
            ->method('getTransport')
            ->willReturn($transport);

        return $evt;
    }
}
