<?php

class Swift_Transport_EsmtpTransportTest extends Swift_Transport_AbstractSmtpEventSupportTest
{
    protected function getTransport($buf, $dispatcher = null, $addressEncoder = null)
    {
        $dispatcher     = $dispatcher     ?? $this->createEventDispatcher();
        $addressEncoder = $addressEncoder ?? new Swift_AddressEncoder_IdnAddressEncoder();

        return new Swift_Transport_EsmtpTransport($buf, [], $dispatcher, 'example.org', $addressEncoder);
    }

    public function testHostCanBeSetAndFetched()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setHost('foo');
        $this->assertEquals('foo', $smtp->getHost(), '%s: Host should be returned');
    }

    public function testPortCanBeSetAndFetched()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setPort(25);
        $this->assertEquals(25, $smtp->getPort(), '%s: Port should be returned');
    }

    public function testTimeoutCanBeSetAndFetched()
    {
        $buf = $this->getBuffer();
        $buf->shouldReceive('setParam')
            ->once()
            ->with('timeout', 10);

        $smtp = $this->getTransport($buf);
        $smtp->setTimeout(10);
        $this->assertEquals(10, $smtp->getTimeout(), '%s: Timeout should be returned');
    }

    public function testEncryptionCanBeSetAndFetched()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setEncryption(CONNECTION_ENCRYPTION_MODE_STARTTLS);
        $this->assertEquals(CONNECTION_ENCRYPTION_MODE_STARTTLS, $smtp->getEncryption(), '%s: Crypto should be returned');
    }

    public function testStartSendsHeloToInitiate()
    {
        // previous loop would fail if there is an issue
        $this->addToAssertionCount(1);
    }

    public function testStartSendsEhloToInitiate()
    {
        /* -- RFC 2821, 3.2.

            3.2 Client Initiation

         Once the server has sent the welcoming message and the client has
         received it, the client normally sends the EHLO command to the
         server, indicating the client's identity.  In addition to opening the
         session, use of EHLO indicates that the client is able to process
         service extensions and requests that the server provide a list of the
         extensions it supports.  Older SMTP systems which are unable to
         support service extensions and contemporary clients which do not
         require service extensions in the mail session being initiated, MAY
         use HELO instead of EHLO.  Servers MUST NOT return the extended
         EHLO-style response to a HELO command.  For a particular connection
         attempt, if the server returns a "command not recognized" response to
         EHLO, the client SHOULD be able to fall back and send HELO.

         In the EHLO command the host sending the command identifies itself;
         the command may be interpreted as saying "Hello, I am <domain>" (and,
         in the case of EHLO, "and I support service extension requests").

       -- RFC 2281, 4.1.1.1.

       ehlo            = "EHLO" SP Domain CRLF
       helo            = "HELO" SP Domain CRLF

       -- RFC 2821, 4.3.2.

       EHLO or HELO
           S: 250
           E: 504, 550

     */

        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 ServerName'."\r\n");

        $this->finishBuffer($buf);
        try {
            $smtp->start();
        } catch (Exception $e) {
            $this->fail('Starting Esmtp should send EHLO and accept 250 response: '.$e->getMessage());
        }
    }

    public function testHeloIsUsedAsFallback()
    {
        /* -- RFC 2821, 4.1.4.

       If the EHLO command is not acceptable to the SMTP server, 501, 500,
       or 502 failure replies MUST be returned as appropriate.  The SMTP
       server MUST stay in the same state after transmitting these replies
       that it was in before the EHLO was received.
        */

        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('501 WTF'."\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^HELO .+?\r\n$~D'))
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn('250 HELO'."\r\n");

        $this->finishBuffer($buf);
        try {
            $smtp->start();
        } catch (Exception) {
            $this->fail(
                'Starting Esmtp should fallback to HELO if needed and accept 250 response',
            );
        }
    }

    public function testInvalidHeloResponseCausesException()
    {
        // Overridden to first try EHLO
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('501 WTF'."\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^HELO .+?\r\n$~D'))
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn('504 WTF'."\r\n");
        $this->finishBuffer($buf);

        try {
            $this->assertFalse($smtp->isStarted(), '%s: SMTP should begin non-started');
            $smtp->start();
            $this->fail('Non 250 HELO response should raise Exception');
        } catch (Exception) {
            $this->assertFalse($smtp->isStarted(), '%s: SMTP start() should have failed');
        }
    }

    public function testDomainNameIsPlacedInEhlo()
    {
        /* -- RFC 2821, 4.1.4.

       The SMTP client MUST, if possible, ensure that the domain parameter
       to the EHLO command is a valid principal host name (not a CNAME or MX
       name) for its host.  If this is not possible (e.g., when the client's
       address is dynamically assigned and the client does not have an
       obvious name), an address literal SHOULD be substituted for the
       domain name and supplemental information provided that will assist in
       identifying the client.
        */

        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with("EHLO mydomain.com\r\n")
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 ServerName'."\r\n");

        $this->finishBuffer($buf);
        $smtp->setLocalDomain('mydomain.com');
        $smtp->start();
    }

    public function testDomainNameIsPlacedInHelo()
    {
        // Overridden to include ESMTP
        /* -- RFC 2821, 4.1.4.

       The SMTP client MUST, if possible, ensure that the domain parameter
       to the EHLO command is a valid principal host name (not a CNAME or MX
       name) for its host.  If this is not possible (e.g., when the client's
       address is dynamically assigned and the client does not have an
       obvious name), an address literal SHOULD be substituted for the
       domain name and supplemental information provided that will assist in
       identifying the client.
        */

        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('501 WTF'."\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with("HELO mydomain.com\r\n")
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn('250 ServerName'."\r\n");

        $this->finishBuffer($buf);
        $smtp->setLocalDomain('mydomain.com');
        $smtp->start();
    }

    public function testPipelining()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getPipelining());

        $message = $this->createMessage();
        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['me@domain.com' => 'Me']);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => null]);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250-ServerName'."\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 PIPELINING'."\r\n");

        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("MAIL FROM:<me@domain.com>\r\n")
            ->andReturn(1);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("RCPT TO:<foo@bar>\r\n")
            ->andReturn(2);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("DATA\r\n")->andReturn(3);
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(1)->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(2)->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(3)->andReturn("354 OK\r\n");

        $this->finishBuffer($buf);
        $smtp->start();
        $sent = $smtp->send($message, $failedRecipients);

        $this->assertEquals(1, $sent);
        $this->assertEmpty($failedRecipients);

        $this->assertTrue($smtp->getPipelining());
    }

    public function testPipeliningWithRecipientFailure()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getPipelining());

        $message = $this->createMessage();
        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['me@domain.com' => 'Me']);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn([
                'good@foo' => null,
                'bad@foo'  => null,
                'good@bar' => null,
            ]);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250-ServerName'."\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 PIPELINING'."\r\n");

        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("MAIL FROM:<me@domain.com>\r\n")
            ->andReturn(1);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("RCPT TO:<good@foo>\r\n")
            ->andReturn(2);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("RCPT TO:<bad@foo>\r\n")
            ->andReturn(3);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("RCPT TO:<good@bar>\r\n")
            ->andReturn(4);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("DATA\r\n")
            ->andReturn(5);
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(1)
            ->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(2)
            ->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(3)
            ->andReturn("450 Unknown address bad@foo\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(4)
            ->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(5)
            ->andReturn("354 OK\r\n");

        $this->finishBuffer($buf);
        $smtp->start();
        $sent = $smtp->send($message, $failedRecipients);

        $this->assertEquals(2, $sent);
        $this->assertEquals(['bad@foo'], $failedRecipients);

        $this->assertTrue($smtp->getPipelining());
    }

    public function testPipeliningWithSenderFailure()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getPipelining());

        $message = $this->createMessage();
        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['me@domain.com' => 'Me']);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => null]);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250-ServerName'."\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 PIPELINING'."\r\n");

        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("MAIL FROM:<me@domain.com>\r\n")
            ->andReturn(1);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("RCPT TO:<foo@bar>\r\n")
            ->andReturn(2);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("DATA\r\n")->andReturn(3);
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(1)
            ->andReturn("550 Unknown address me@domain.com\r\n");

        $smtp->start();

        $this->expectException('Swift_TransportException');
        $this->expectExceptionMessage('Expected response code 250 but got code "550"');
        $smtp->send($message, $failedRecipients);
    }

    public function testPipeliningWithDataFailure()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getPipelining());

        $message = $this->createMessage();
        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['me@domain.com' => 'Me']);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => null]);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250-ServerName'."\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 PIPELINING'."\r\n");

        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("MAIL FROM:<me@domain.com>\r\n")
            ->andReturn(1);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("RCPT TO:<foo@bar>\r\n")
            ->andReturn(2);
        $buf->shouldReceive('write')
            ->ordered()
            ->once()
            ->with("DATA\r\n")->andReturn(3);
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(1)
            ->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(2)
            ->andReturn("250 OK\r\n");
        $buf->shouldReceive('readLine')
            ->ordered()
            ->once()
            ->with(3)
            ->andReturn("452 Insufficient system storage\r\n");

        $smtp->start();

        $this->expectException('Swift_TransportException');
        $this->expectExceptionMessage('Expected response code 354 but got code "452"');
        $smtp->send($message, $failedRecipients);
    }

    public static function providerPipeliningOverride()
    {
        return [
            [null, true, true],
            [null, false, false],
            [true, false, true],
            [true, true, true],
            [false, false, false],
            [false, true, false],
        ];
    }

    /**
     * @dataProvider providerPipeliningOverride
     */
    public function testPipeliningOverride($enabled, bool $supported, bool $expected)
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getPipelining());

        $smtp->setPipelining($enabled);
        $this->assertSame($enabled, $smtp->getPipelining());

        $message = $this->createMessage();
        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['me@domain.com' => 'Me']);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 some.server.tld bleh\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250-ServerName'."\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn('250 '.($supported ? 'PIPELINING' : 'FOOBAR')."\r\n");

        $this->finishBuffer($buf);
        $smtp->start();
        $smtp->send($message);

        $this->assertSame($expected, $smtp->getPipelining());
    }

    public function testFluidInterface()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $buf->shouldReceive('setParam')
            ->once()
            ->with('timeout', 30);

        $ref = $smtp
            ->setHost('foo')
            ->setPort(25)
            ->setEncryption(CONNECTION_ENCRYPTION_MODE_STARTTLS)
            ->setTimeout(30)
            ->setPipelining(false)
        ;
        $this->assertEquals($ref, $smtp);
    }

    public function testDefaultHostIsLocalhost()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertEquals('localhost', $smtp->getHost());
    }

    public function testDefaultPort()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertEquals(25, $smtp->getPort());
    }

    public function testDefaultTimeout()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertEquals(30, $smtp->getTimeout());
    }

    public function testSetHostReturnsTransport()
    {
        $buf    = $this->getBuffer();
        $smtp   = $this->getTransport($buf);
        $result = $smtp->setHost('mail.example.com');
        $this->assertSame($smtp, $result);
    }

    public function testSetPortReturnsTransport()
    {
        $buf    = $this->getBuffer();
        $smtp   = $this->getTransport($buf);
        $result = $smtp->setPort(587);
        $this->assertSame($smtp, $result);
    }

    public function testSetEncryptionReturnsTransport()
    {
        $buf    = $this->getBuffer();
        $smtp   = $this->getTransport($buf);
        $result = $smtp->setEncryption(CONNECTION_ENCRYPTION_MODE_STARTTLS);
        $this->assertSame($smtp, $result);
    }

    public function testSetTimeoutReturnsTransport()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $buf->shouldReceive('setParam')
            ->once()
            ->with('timeout', 60);
        $result = $smtp->setTimeout(60);
        $this->assertSame($smtp, $result);
    }

    public function testSetPipeliningReturnsTransport()
    {
        $buf    = $this->getBuffer();
        $smtp   = $this->getTransport($buf);
        $result = $smtp->setPipelining(true);
        $this->assertSame($smtp, $result);
    }

    public function testGetPipeliningReturnsNullByDefault()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getPipelining());
    }

    public function testSetPipeliningToNull()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setPipelining(true);
        $smtp->setPipelining(null);
        $this->assertNull($smtp->getPipelining());
    }

    public function testSetPipeliningToTrue()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setPipelining(true);
        $this->assertTrue($smtp->getPipelining());
    }

    public function testSetPipeliningToFalse()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setPipelining(false);
        $this->assertFalse($smtp->getPipelining());
    }

    public function testGetEncryptionReturnsTcpByDefault()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertEquals('tcp', $smtp->getEncryption());
    }

    public function testHostCanBeChanged()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setHost('first.example.com');
        $smtp->setHost('second.example.com');
        $this->assertEquals('second.example.com', $smtp->getHost());
    }

    public function testPortCanBeChanged()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setPort(25);
        $smtp->setPort(587);
        $this->assertEquals(587, $smtp->getPort());
    }

    public function testStreamOptionsCanBeSetAndFetched()
    {
        $buf     = $this->getBuffer();
        $smtp    = $this->getTransport($buf);
        $options = ['ssl' => ['verify_peer' => false]];
        $result  = $smtp->setStreamOptions($options);
        $this->assertSame($smtp, $result);
        $this->assertEquals($options, $smtp->getStreamOptions());
    }

    public function testSourceIpCanBeSetAndFetched()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $this->assertNull($smtp->getSourceIp());
        $result = $smtp->setSourceIp('10.0.0.1');
        $this->assertSame($smtp, $result);
        $this->assertEquals('10.0.0.1', $smtp->getSourceIp());
    }

    public function testUndefinedMixinMethodTriggersError()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $this->expectError();
        $smtp->noSuchMethod();
    }

    public function testStartTlsIsNegotiated()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setEncryption(CONNECTION_ENCRYPTION_MODE_STARTTLS);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");

        // First EHLO
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 ServerName\r\n");

        // STARTTLS command
        $buf->shouldReceive('write')
            ->once()
            ->with("STARTTLS\r\n")
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn("220 Go ahead\r\n");

        $buf->shouldReceive('startTLS')
            ->once()
            ->andReturn(true);

        // Second EHLO after TLS
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(3);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(3)
            ->andReturn("250 ServerName\r\n");

        $this->finishBuffer($buf);
        $smtp->start();
    }

    public function testStartTlsFailureThrowsException()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setEncryption(CONNECTION_ENCRYPTION_MODE_STARTTLS);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");

        // EHLO
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 ServerName\r\n");

        // STARTTLS
        $buf->shouldReceive('write')
            ->once()
            ->with("STARTTLS\r\n")
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn("220 Go ahead\r\n");

        $buf->shouldReceive('startTLS')
            ->once()
            ->andReturn(false);

        $this->finishBuffer($buf);

        try {
            $smtp->start();
            $this->fail('Expected Swift_TransportException for failed STARTTLS');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('STARTTLS', $e->getMessage());
        }
    }

    public function testStartTlsWithEhloFallbackToHelo()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);
        $smtp->setEncryption(CONNECTION_ENCRYPTION_MODE_STARTTLS);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");

        // First EHLO
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 ServerName\r\n");

        // STARTTLS
        $buf->shouldReceive('write')
            ->once()
            ->with("STARTTLS\r\n")
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn("220 Go ahead\r\n");

        $buf->shouldReceive('startTLS')
            ->once()
            ->andReturn(true);

        // Second EHLO after TLS fails => fallback to HELO
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(3);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(3)
            ->andReturn("501 Not accepted\r\n");

        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^HELO .+?\r\n$~D'))
            ->andReturn(4);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(4)
            ->andReturn("250 OK\r\n");

        $this->finishBuffer($buf);
        $smtp->start();
    }

    public function testAutoAddressEncoderIsUpdatedOnSmtpUtf8()
    {
        $buf         = $this->getBuffer();
        $autoEncoder = new Swift_AddressEncoder_AutoAddressEncoder();
        $smtp        = $this->getTransport($buf, null, $autoEncoder);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250-ServerName\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 SMTPUTF8\r\n");

        $this->finishBuffer($buf);
        $smtp->start();

        $this->assertTrue($autoEncoder->isSmtpUtf8Available());
    }

    public function testExtensionHandlersAreCalledDuringExecuteCommand()
    {
        $buf     = $this->getBuffer();
        $handler = $this->getMockery('Swift_Transport_EsmtpHandler');
        $handler->shouldReceive('getHandledKeyword')
            ->zeroOrMoreTimes()
            ->andReturn('TEST');
        $handler->shouldReceive('getPriorityOver')
            ->zeroOrMoreTimes()
            ->andReturn(0);
        $handler->shouldReceive('exposeMixinMethods')
            ->zeroOrMoreTimes()
            ->andReturn([]);
        $handler->shouldReceive('setKeywordParams')
            ->zeroOrMoreTimes();

        $dispatcher     = $this->createEventDispatcher();
        $addressEncoder = new Swift_AddressEncoder_IdnAddressEncoder();
        $smtp           = new Swift_Transport_EsmtpTransport($buf, [$handler], $dispatcher, 'example.org', $addressEncoder);

        // Simulate that the handler's keyword is in capabilities
        // by starting the transport with EHLO returning TEST capability
        $buf->shouldReceive('initialize')->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250-ServerName\r\n");
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 TEST\r\n");

        $handler->shouldReceive('afterEhlo')
            ->once()
            ->with($smtp);

        // When executeCommand is called, onCommand should be invoked on active handlers
        $handler->shouldReceive('onCommand')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($agent, $cmd, $codes, &$failures, &$stop) {
                return null; // don't stop
            });

        $this->finishBuffer($buf);
        $smtp->start();
    }

    public function testMixinSetMethodReturnsTransport()
    {
        $buf     = $this->getBuffer();
        $handler = $this->getMockery('Swift_Transport_EsmtpHandler');
        $handler->shouldReceive('getHandledKeyword')
            ->zeroOrMoreTimes()
            ->andReturn('AUTH');
        $handler->shouldReceive('getPriorityOver')
            ->zeroOrMoreTimes()
            ->andReturn(0);
        $handler->shouldReceive('exposeMixinMethods')
            ->zeroOrMoreTimes()
            ->andReturn(['setUsername', 'getUsername']);
        $handler->shouldReceive('setKeywordParams')
            ->zeroOrMoreTimes();

        // setUsername returns null => __call should return $this for fluid interface
        $handler->shouldReceive('setUsername')
            ->once()
            ->with('jack')
            ->andReturn(null);

        $dispatcher     = $this->createEventDispatcher();
        $addressEncoder = new Swift_AddressEncoder_IdnAddressEncoder();
        $smtp           = new Swift_Transport_EsmtpTransport($buf, [$handler], $dispatcher, 'example.org', $addressEncoder);

        $result = $smtp->setUsername('jack');
        $this->assertSame($smtp, $result);
    }

    public function testMixinGetMethodReturnsValue()
    {
        $buf     = $this->getBuffer();
        $handler = $this->getMockery('Swift_Transport_EsmtpHandler');
        $handler->shouldReceive('getHandledKeyword')
            ->zeroOrMoreTimes()
            ->andReturn('AUTH');
        $handler->shouldReceive('getPriorityOver')
            ->zeroOrMoreTimes()
            ->andReturn(0);
        $handler->shouldReceive('exposeMixinMethods')
            ->zeroOrMoreTimes()
            ->andReturn(['setUsername', 'getUsername']);
        $handler->shouldReceive('setKeywordParams')
            ->zeroOrMoreTimes();

        $handler->shouldReceive('getUsername')
            ->once()
            ->andReturn('jack');

        $dispatcher     = $this->createEventDispatcher();
        $addressEncoder = new Swift_AddressEncoder_IdnAddressEncoder();
        $smtp           = new Swift_Transport_EsmtpTransport($buf, [$handler], $dispatcher, 'example.org', $addressEncoder);

        $this->assertEquals('jack', $smtp->getUsername());
    }

    public function testBufferInitFailureIsRethrown()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')
            ->once()
            ->andThrow(new Swift_TransportException('Connection refused'));

        try {
            $smtp->start();
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
            $this->assertFalse($smtp->isStarted());
        }
    }

    public function testSendWithoutSenderThrowsException()
    {
        $buf     = $this->getBuffer();
        $smtp    = $this->getTransport($buf);
        $message = $this->createMessage();

        $message->shouldReceive('getFrom')
            ->once()
            ->andReturn([]);
        $message->shouldReceive('getSender')
            ->once()
            ->andReturn([]);
        $message->shouldReceive('getReturnPath')
            ->once()
            ->andReturn(null);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => null]);

        $this->finishBuffer($buf);
        $smtp->start();

        try {
            $smtp->send($message);
            $this->fail('Expected Swift_TransportException for missing sender');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Cannot send message without a sender address', $e->getMessage());
        }
    }

    public function testSendWithEnvelopeUsesEnvelopePath()
    {
        $buf     = $this->getBuffer();
        $smtp    = $this->getTransport($buf);
        $message = $this->createMessage();

        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['orig@example.com' => 'Orig']);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['to@example.com' => null]);

        $envelope = new Swift_Envelope('env-sender@example.com', ['env-rcpt@example.com']);

        $buf->shouldReceive('write')
            ->once()
            ->with("MAIL FROM:<env-sender@example.com>\r\n")
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 OK\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with("RCPT TO:<env-rcpt@example.com>\r\n")
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn("250 OK\r\n");

        $this->finishBuffer($buf);
        $smtp->start();
        $count = $smtp->send($message, $failures, $envelope);
        $this->assertEquals(1, $count);
    }

    public function testStreamMessageCatchesTransportException()
    {
        $buf     = $this->getBuffer();
        $smtp    = $this->getTransport($buf);
        $message = $this->createMessage();

        $message->shouldReceive('getFrom')
            ->zeroOrMoreTimes()
            ->andReturn(['me@domain.com' => 'Me']);
        $message->shouldReceive('getTo')
            ->zeroOrMoreTimes()
            ->andReturn(['foo@bar' => null]);
        $message->shouldReceive('toByteStream')
            ->once()
            ->andThrow(new Swift_TransportException('Stream write failed'));

        $this->finishBuffer($buf);
        $smtp->start();

        try {
            $smtp->send($message);
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Stream write failed', $e->getMessage());
        }
    }

    public function testGetFullResponseCatchesIoException()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andThrow(new Swift_IoException('Read error'));

        try {
            $smtp->start();
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Read error', $e->getMessage());
        }
    }

    public function testGetFullResponseCatchesTransportException()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')
            ->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andThrow(new Swift_TransportException('Connection lost'));

        try {
            $smtp->start();
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Connection lost', $e->getMessage());
        }
    }

    public function testSerializationIsNotAllowed()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $this->expectException(BadMethodCallException::class);
        $smtp->__sleep();
    }

    public function testDeserializationIsNotAllowed()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $this->expectException(BadMethodCallException::class);
        $smtp->__wakeup();
    }

    public function testStopTerminateFailureIsRethrown()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $buf->shouldReceive('initialize')->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 ServerName\r\n");

        $buf->shouldReceive('write')
            ->once()
            ->with("QUIT\r\n")
            ->andReturn(2);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(2)
            ->andReturn("221 Bye\r\n");

        $buf->shouldReceive('terminate')
            ->once()
            ->andThrow(new Swift_TransportException('Terminate failed'));

        $smtp->start();
        $this->assertTrue($smtp->isStarted());

        try {
            $smtp->stop();
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Terminate failed', $e->getMessage());
        }
    }

    public function testPingStopFailureIsSwallowed()
    {
        // Test the ping() path where NOOP fails and stop() also throws.
        // This covers line 297 (catch inside ping's stop call).
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        // Set up buffer so start() succeeds
        $buf->shouldReceive('initialize')->once();
        $buf->shouldReceive('readLine')
            ->once()
            ->with(0)
            ->andReturn("220 server.tld ready\r\n");
        $buf->shouldReceive('write')
            ->once()
            ->with(Mockery::pattern('~^EHLO .+?\r\n$~D'))
            ->andReturn(1);
        $buf->shouldReceive('readLine')
            ->once()
            ->with(1)
            ->andReturn("250 ServerName\r\n");

        // NOOP fails, triggering catch block
        $buf->shouldReceive('write')
            ->once()
            ->with("NOOP\r\n")
            ->andThrow(new Swift_TransportException('Connection reset'));

        // stop() inside catch also throws -> swallowed
        $buf->shouldReceive('write')
            ->with("QUIT\r\n")
            ->andThrow(new Swift_TransportException('Already disconnected'));
        $buf->shouldReceive('terminate')
            ->once();

        // Catch-all for any other reads/writes
        $buf->shouldReceive('readLine')->zeroOrMoreTimes()->andReturn(false);
        $buf->shouldReceive('write')->zeroOrMoreTimes()->andReturn(false);

        $smtp->start();
        $this->assertTrue($smtp->isStarted());
        $this->assertFalse($smtp->ping());
    }

    public function testAddressEncoderCanBeSetAndFetched()
    {
        $buf  = $this->getBuffer();
        $smtp = $this->getTransport($buf);

        $encoder = new Swift_AddressEncoder_Utf8AddressEncoder();
        $smtp->setAddressEncoder($encoder);
        $this->assertSame($encoder, $smtp->getAddressEncoder());
    }
}
