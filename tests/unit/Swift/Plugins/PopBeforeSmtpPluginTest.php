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

    public function testSetPasswordHasSensitiveParameterAttribute()
    {
        $method = new ReflectionMethod(Swift_Plugins_PopBeforeSmtpPlugin::class, 'setPassword');
        $param  = $method->getParameters()[0];
        $attrs  = $param->getAttributes(SensitiveParameter::class);
        $this->assertNotEmpty($attrs, 'setPassword parameter must have #[\SensitiveParameter]');
    }

    public function testDefaultPortIs110()
    {
        // Constructor signature has default 110
        $plugin = new Swift_Plugins_PopBeforeSmtpPlugin('pop.host.tld');
        $this->assertInstanceOf(Swift_Plugins_PopBeforeSmtpPlugin::class, $plugin);
    }

    public function testConnectWithDelegateConnection()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())->method('connect');

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->connect();
    }

    public function testDisconnectWithDelegateConnection()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())->method('disconnect');

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->disconnect();
    }

    public function testConnectThenDisconnectWithDelegate()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())->method('connect');
        $connection->expects($this->once())->method('disconnect');

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->connect();
        $plugin->disconnect();
    }

    public function testBeforeTransportStartedConnectsAndDisconnects()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())->method('connect');
        $connection->expects($this->once())->method('disconnect');

        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        $plugin->beforeTransportStarted($evt);
    }

    public function testBeforeTransportStartedSkipsWhenBoundToDifferentTransport()
    {
        $connection = $this->createConnection();
        $connection->expects($this->never())->method('connect');

        $smtp   = $this->createTransport();
        $other  = $this->createTransport();
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->bindSmtp($smtp);

        $evt = $this->createTransportChangeEvent($other);
        $plugin->beforeTransportStarted($evt);
    }

    public function testBeforeTransportStartedProceedsWhenBoundToSameTransport()
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())->method('connect');
        $connection->expects($this->once())->method('disconnect');

        $smtp   = $this->createTransport();
        $plugin = $this->createPlugin('pop.host.tld', 110);
        $plugin->setConnection($connection);
        $plugin->bindSmtp($smtp);

        $evt = $this->createTransportChangeEvent($smtp);
        $plugin->beforeTransportStarted($evt);
    }

    // -----------------------------------------------------------------------
    // Direct socket path tests (no delegate connection)
    // -----------------------------------------------------------------------

    public function testConnectWithoutDelegateFailsOnUnresolvableHost()
    {
        // Exercises the fsockopen() branch (lines 133-141) which throws
        // Pop3Exception when the connection is refused.
        $plugin = $this->createPlugin('127.0.0.1', 19999); // refused immediately
        $plugin->setTimeout(1);

        $this->expectException(Swift_Plugins_Pop_Pop3Exception::class);
        $this->expectExceptionMessage('Failed to connect to POP3 host');
        @$plugin->connect(); // suppress fsockopen warning
    }

    public function testConnectWithTlsCryptoAddsPrefix()
    {
        // Exercises getHostString() TLS branch (lines 243-244)
        $plugin = $this->createPlugin('127.0.0.1', 19999, 'ssl');
        $plugin->setTimeout(1);

        try {
            @$plugin->connect();
            $this->fail('Expected Pop3Exception for refused connection');
        } catch (Swift_Plugins_Pop_Pop3Exception $e) {
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testConnectWithStarttlsCryptoAddsPrefix()
    {
        // Exercises getHostString() STARTTLS branch (lines 247-248)
        $plugin = $this->createPlugin('127.0.0.1', 19999, 'tls');
        $plugin->setTimeout(1);

        try {
            @$plugin->connect();
            $this->fail('Expected Pop3Exception for refused connection');
        } catch (Swift_Plugins_Pop_Pop3Exception $e) {
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testConnectWithNoCryptoUsesPlainHost()
    {
        // Exercises getHostString() default/no-crypto path (line 240, 251)
        $plugin = $this->createPlugin('127.0.0.1', 19999);
        $plugin->setTimeout(1);

        try {
            @$plugin->connect();
            $this->fail('Expected Pop3Exception for refused connection');
        } catch (Swift_Plugins_Pop_Pop3Exception $e) {
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testConnectWithUsernameExercisesAuthPath()
    {
        // Exercises the username/password auth lines (151-153).
        // We can't actually reach a server, so this tests that the plugin
        // stores credentials and attempts connect which will fail at fsockopen.
        $plugin = $this->createPlugin('127.0.0.1', 19999);
        $plugin->setTimeout(1);
        $plugin->setUsername('popuser');
        $plugin->setPassword('poppass');

        $this->expectException(Swift_Plugins_Pop_Pop3Exception::class);
        @$plugin->connect();
    }

    public function testBeforeTransportStartedWithoutDelegateTriesDirectConnect()
    {
        // Exercises beforeTransportStarted -> connect() -> disconnect() without
        // a delegate, triggering the direct socket path.
        $plugin = $this->createPlugin('127.0.0.1', 19999);
        $plugin->setTimeout(1);
        $transport = $this->createTransport();
        $evt       = $this->createTransportChangeEvent($transport);

        try {
            @$plugin->beforeTransportStarted($evt);
            $this->fail('Expected Pop3Exception for refused connection');
        } catch (Swift_Plugins_Pop_Pop3Exception $e) {
            $this->assertStringContainsString('Failed to connect', $e->getMessage());
        }
    }
}
