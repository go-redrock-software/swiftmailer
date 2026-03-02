<?php

class Swift_Plugins_ThrottlerPluginTest extends SwiftMailerTestCase
{
    public function testBytesPerMinuteThrottling()
    {
        $sleeper = $this->createSleeper();
        $timer   = $this->createTimer();

        // 10MB/min
        $plugin = new Swift_Plugins_ThrottlerPlugin(
            10000000,
            Swift_Plugins_ThrottlerPlugin::BYTES_PER_MINUTE,
            $sleeper,
            $timer,
        );

        $timer->shouldReceive('getTimestamp')->once()->andReturn(0);
        $timer->shouldReceive('getTimestamp')->once()->andReturn(1); // expected 0.6
        $timer->shouldReceive('getTimestamp')->once()->andReturn(1); // expected 1.2 (sleep 1)
        $timer->shouldReceive('getTimestamp')->once()->andReturn(2); // expected 1.8
        $timer->shouldReceive('getTimestamp')->once()->andReturn(2); // expected 2.4 (sleep 1)
        $sleeper->shouldReceive('sleep')->twice()->with(1);

        // 10,000,000 bytes per minute
        // 100,000 bytes per email

        // .: (10,000,000/100,000)/60 emails per second = 1.667 emais/sec

        $message = $this->createMessageWithByteCount(100000); // 100KB

        $evt = $this->createSendEvent($message);

        for ($i = 0; $i < 5; ++$i) {
            $plugin->beforeSendPerformed($evt);
            $plugin->sendPerformed($evt);
        }
    }

    public function testMessagesPerMinuteThrottling()
    {
        $sleeper = $this->createSleeper();
        $timer   = $this->createTimer();

        // 60/min
        $plugin = new Swift_Plugins_ThrottlerPlugin(
            60,
            Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE,
            $sleeper,
            $timer,
        );

        $timer->shouldReceive('getTimestamp')->once()->andReturn(0);
        $timer->shouldReceive('getTimestamp')->once()->andReturn(0); // expected 1 (sleep 1)
        $timer->shouldReceive('getTimestamp')->once()->andReturn(2); // expected 2
        $timer->shouldReceive('getTimestamp')->once()->andReturn(2); // expected 3 (sleep 1)
        $timer->shouldReceive('getTimestamp')->once()->andReturn(4); // expected 4
        $sleeper->shouldReceive('sleep')->twice()->with(1);

        // 60 messages per minute
        // 1 message per second

        $message = $this->createMessageWithByteCount(10);

        $evt = $this->createSendEvent($message);

        for ($i = 0; $i < 5; ++$i) {
            $plugin->beforeSendPerformed($evt);
            $plugin->sendPerformed($evt);
        }
    }

    private function createSleeper()
    {
        return $this->getMockery('Swift_Plugins_Sleeper');
    }

    private function createTimer()
    {
        return $this->getMockery('Swift_Plugins_Timer');
    }

    private function createMessageWithByteCount($bytes)
    {
        $msg = $this->getMockery('Swift_Mime_SimpleMessage');
        $msg->shouldReceive('toByteStream')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($is) use ($bytes) {
                for ($i = 0; $i < $bytes; ++$i) {
                    $is->write('x');
                }
            });

        return $msg;
    }

    public function testMessagesPerSecondThrottling()
    {
        $sleeper = $this->createSleeper();
        $timer   = $this->createTimer();

        // 2/sec
        $plugin = new Swift_Plugins_ThrottlerPlugin(
            2,
            Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_SECOND,
            $sleeper,
            $timer,
        );

        $timer->shouldReceive('getTimestamp')->once()->andReturn(0);
        $timer->shouldReceive('getTimestamp')->once()->andReturn(0); // expected 0.5 (sleep 1)
        $timer->shouldReceive('getTimestamp')->once()->andReturn(1); // expected 1
        $timer->shouldReceive('getTimestamp')->once()->andReturn(1); // expected 1.5 (sleep 1)
        $sleeper->shouldReceive('sleep')->twice()->with(1);

        $message = $this->createMessageWithByteCount(10);
        $evt = $this->createSendEvent($message);

        for ($i = 0; $i < 4; ++$i) {
            $plugin->beforeSendPerformed($evt);
            $plugin->sendPerformed($evt);
        }
    }

    public function testModeConstants()
    {
        $this->assertSame(0x01, Swift_Plugins_ThrottlerPlugin::BYTES_PER_MINUTE);
        $this->assertSame(0x11, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_SECOND);
        $this->assertSame(0x10, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE);
    }

    public function testPluginImplementsSleeper()
    {
        $plugin = new Swift_Plugins_ThrottlerPlugin(100);
        $this->assertInstanceOf(Swift_Plugins_Sleeper::class, $plugin);
    }

    public function testPluginImplementsTimer()
    {
        $plugin = new Swift_Plugins_ThrottlerPlugin(100);
        $this->assertInstanceOf(Swift_Plugins_Timer::class, $plugin);
    }

    public function testPluginExtendsBandwidthMonitor()
    {
        $plugin = new Swift_Plugins_ThrottlerPlugin(100);
        $this->assertInstanceOf(Swift_Plugins_BandwidthMonitorPlugin::class, $plugin);
    }

    public function testGetTimestampUsesTimerWhenSet()
    {
        $timer = $this->createTimer();
        $timer->shouldReceive('getTimestamp')->once()->andReturn(42);

        $plugin = new Swift_Plugins_ThrottlerPlugin(
            100,
            Swift_Plugins_ThrottlerPlugin::BYTES_PER_MINUTE,
            null,
            $timer,
        );

        $this->assertSame(42, $plugin->getTimestamp());
    }

    public function testGetTimestampUsesSystemTimeWhenNoTimer()
    {
        $plugin = new Swift_Plugins_ThrottlerPlugin(100);
        $timestamp = $plugin->getTimestamp();
        // Should be approximately current time
        $this->assertEqualsWithDelta(\time(), $timestamp, 2);
    }

    private function createSendEvent($message)
    {
        $evt = $this->getMockery('Swift_Events_SendEvent');
        $evt->shouldReceive('getMessage')
            ->zeroOrMoreTimes()
            ->andReturn($message);

        return $evt;
    }
}
