<?php

class Swift_Plugins_PopBeforeSmtpPluginTest extends PHPUnit\Framework\TestCase
{
    public function testPluginConnectsToPop3HostBeforeTransportStarts()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())
            ->method('connect');

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);

        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        $plugin->beforeTransportStarted($evt);
    }

    public function testPluginDisconnectsFromPop3HostBeforeTransportStarts()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())
            ->method('disconnect');

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);

        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        $plugin->beforeTransportStarted($evt);
    }

    public function testPluginDoesNotConnectToSmtpIfBoundToDifferentTransport()
    {
        $connection = $this->createConnection();
        $connection->expects($this->never())
            ->method('disconnect');
        $connection->expects($this->never())
            ->method('connect');

        $smtp = $this->createTransport();

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->bindSmtp($smtp);

        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        $plugin->beforeTransportStarted($evt);
    }

    public function testPluginCanBindToSpecificTransport()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())
            ->method('connect');

        $smtp = $this->createTransport();

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->bindSmtp($smtp);

        $evt = $this->createTransportChangeEvent($smtp);

        $plugin->beforeTransportStarted($evt);
    }

    private function createTransport()
    {
        return $this->getMockBuilder('Swift_Transport')->getMock();
    }

    private function createTransportChangeEvent($transport)
    {
        $evt = $this->getMockBuilder('Swift_Events_TransportChangeEvent')
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

    public function createConnection()
    {
        return $this->getMockBuilder('Swift_Plugins_Pop_Pop3Connection')->getMock();
    }

    public function createPlugin($host, $port, $crypto = null)
    {
        return new Swift_Plugins_PopBeforeSmtpPlugin($host, $port, $crypto);
    }

    public function testPluginImplementsTransportChangeListener()
    {
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $this->assertInstanceOf(Swift_Events_TransportChangeListener::class, $plugin);
    }

    public function testPluginImplementsPop3Connection()
    {
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $this->assertInstanceOf(Swift_Plugins_Pop_Pop3Connection::class, $plugin);
    }

    public function testSetConnectionReturnsSelf()
    {
        $connection = $this->createConnection();
        $plugin     = $this->createPlugin('pop.host.tld', 110);

        $result = $plugin->setConnection($connection);
        $this->assertSame($plugin, $result);
    }

    public function testSetTimeoutReturnsSelf()
    {
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $result = $plugin->setTimeout(30);
        $this->assertSame($plugin, $result);
    }

    public function testSetUsernameReturnsSelf()
    {
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $result = $plugin->setUsername('user');
        $this->assertSame($plugin, $result);
    }

    public function testSetPasswordReturnsSelf()
    {
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $result = $plugin->setPassword('pass');
        $this->assertSame($plugin, $result);
    }

    public function testTransportStartedIsNoop()
    {
        $plugin    = $this->createPlugin('pop.host.tld', 110);
        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        // Should not throw
        $plugin->transportStarted($evt);
        $this->assertTrue(true);
    }

    public function testBeforeTransportStoppedIsNoop()
    {
        $plugin    = $this->createPlugin('pop.host.tld', 110);
        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        // Should not throw
        $plugin->beforeTransportStopped($evt);
        $this->assertTrue(true);
    }

    public function testTransportStoppedIsNoop()
    {
        $plugin    = $this->createPlugin('pop.host.tld', 110);
        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        // Should not throw
        $plugin->transportStopped($evt);
        $this->assertTrue(true);
    }

    public function testConstructorAcceptsCryptoParameter()
    {
        // Should not throw
        $plugin = $this->createPlugin('pop.host.tld', 995, 'ssl');
        $this->assertInstanceOf(Swift_Plugins_PopBeforeSmtpPlugin::class, $plugin);
    }

    public function testDefaultPortIs110()
    {
        // Constructor signature has default 110
        $plugin = new Swift_Plugins_PopBeforeSmtpPlugin('pop.host.tld');
        $this->assertInstanceOf(Swift_Plugins_PopBeforeSmtpPlugin::class, $plugin);
    }
}
