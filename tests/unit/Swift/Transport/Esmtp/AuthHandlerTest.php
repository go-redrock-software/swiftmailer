<?php

class Swift_Transport_Esmtp_AuthHandlerTest extends SwiftMailerTestCase
{
    private $agent;

    protected function setUp(): void
    {
        $this->agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
    }

    public function testKeywordIsAuth()
    {
        $auth = $this->createHandler([]);
        $this->assertEquals('AUTH', $auth->getHandledKeyword());
    }

    public function testUsernameCanBeSetAndFetched()
    {
        $auth = $this->createHandler([]);
        $auth->setUsername('jack');
        $this->assertEquals('jack', $auth->getUsername());
    }

    public function testPasswordCanBeSetAndFetched()
    {
        $auth = $this->createHandler([]);
        $auth->setPassword('pass');
        $this->assertEquals('pass', $auth->getPassword());
    }

    public function testAuthModeCanBeSetAndFetched()
    {
        $auth = $this->createHandler([]);
        $auth->setAuthMode('PLAIN');
        $this->assertEquals('PLAIN', $auth->getAuthMode());
    }

    public function testMixinMethods()
    {
        $auth   = $this->createHandler([]);
        $mixins = $auth->exposeMixinMethods();
        $this->assertTrue(
            \in_array('getUsername', $mixins),
            '%s: getUsername() should be accessible via mixin',
        );
        $this->assertTrue(
            \in_array('setUsername', $mixins),
            '%s: setUsername() should be accessible via mixin',
        );
        $this->assertTrue(
            \in_array('getPassword', $mixins),
            '%s: getPassword() should be accessible via mixin',
        );
        $this->assertTrue(
            \in_array('setPassword', $mixins),
            '%s: setPassword() should be accessible via mixin',
        );
        $this->assertTrue(
            \in_array('setAuthMode', $mixins),
            '%s: setAuthMode() should be accessible via mixin',
        );
        $this->assertTrue(
            \in_array('getAuthMode', $mixins),
            '%s: getAuthMode() should be accessible via mixin',
        );
    }

    public function testAuthenticatorsAreCalledAccordingToParamsAfterEhlo()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a2 = $this->createMockAuthenticator('LOGIN');

        $a1->shouldReceive('authenticate')
            ->never()
            ->with($this->agent, 'jack', 'pass');
        $a2->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(true);

        $auth = $this->createHandler([$a1, $a2]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');

        $auth->setKeywordParams(['CRAM-MD5', 'LOGIN']);
        $auth->afterEhlo($this->agent);
    }

    public function testAuthenticatorsAreNotUsedIfNoUsernameSet()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a2 = $this->createMockAuthenticator('LOGIN');

        $a1->shouldReceive('authenticate')
            ->never()
            ->with($this->agent, 'jack', 'pass');
        $a2->shouldReceive('authenticate')
            ->never()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(true);

        $auth = $this->createHandler([$a1, $a2]);

        $auth->setKeywordParams(['CRAM-MD5', 'LOGIN']);
        $auth->afterEhlo($this->agent);
    }

    public function testSeveralAuthenticatorsAreTriedIfNeeded()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a2 = $this->createMockAuthenticator('LOGIN');

        $a1->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(false);
        $a2->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(true);

        $auth = $this->createHandler([$a1, $a2]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');

        $auth->setKeywordParams(['PLAIN', 'LOGIN']);
        $auth->afterEhlo($this->agent);
    }

    public function testFirstAuthenticatorToPassBreaksChain()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a2 = $this->createMockAuthenticator('LOGIN');
        $a3 = $this->createMockAuthenticator('CRAM-MD5');

        $a1->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(false);
        $a2->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(true);
        $a3->shouldReceive('authenticate')
            ->never()
            ->with($this->agent, 'jack', 'pass');

        $auth = $this->createHandler([$a1, $a2]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');

        $auth->setKeywordParams(['PLAIN', 'LOGIN', 'CRAM-MD5']);
        $auth->afterEhlo($this->agent);
    }

    public function testGetAuthenticatorsReturnsAuthenticators()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a2 = $this->createMockAuthenticator('LOGIN');

        $auth = $this->createHandler([$a1, $a2]);
        $this->assertSame([$a1, $a2], $auth->getAuthenticators());
    }

    public function testAfterEhloThrowsWhenNoAuthenticatorsMatch()
    {
        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to authenticate on SMTP server');

        $a1 = $this->createMockAuthenticator('PLAIN');

        $auth = $this->createHandler([$a1]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');

        // Set keyword params that don't match any authenticator
        $auth->setKeywordParams(['CRAM-MD5']);
        $auth->afterEhlo($this->agent);
    }

    public function testAfterEhloCollectsErrorsFromFailedAuthenticators()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a1->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andThrow(new Swift_TransportException('Connection refused'));

        $auth = $this->createHandler([$a1]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');
        $auth->setKeywordParams(['PLAIN']);

        try {
            $auth->afterEhlo($this->agent);
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Authenticator PLAIN returned Connection refused', $e->getMessage());
        }
    }

    public function testGetMailParamsReturnsEmptyArray()
    {
        $auth = $this->createHandler([]);
        $this->assertEquals([], $auth->getMailParams());
    }

    public function testGetRcptParamsReturnsEmptyArray()
    {
        $auth = $this->createHandler([]);
        $this->assertEquals([], $auth->getRcptParams());
    }

    public function testOnCommandIsNoOp()
    {
        $auth = $this->createHandler([]);
        $failedRecipients = null;
        $stop = false;
        $auth->onCommand($this->agent, "MAIL FROM:<foo@bar>\r\n", [250], $failedRecipients, $stop);
        $this->assertFalse($stop);
    }

    public function testGetPriorityOverReturnsZero()
    {
        $auth = $this->createHandler([]);
        $this->assertEquals(0, $auth->getPriorityOver('8BITMIME'));
    }

    public function testResetStateIsNoOp()
    {
        $auth = $this->createHandler([]);
        $auth->resetState();
        $this->addToAssertionCount(1);
    }

    public function testInvalidAuthModeThrowsException()
    {
        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Auth mode bogus is invalid');

        $a1 = $this->createMockAuthenticator('PLAIN');

        $auth = $this->createHandler([$a1]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');
        $auth->setAuthMode('bogus');
        $auth->setKeywordParams(['PLAIN']);
        $auth->afterEhlo($this->agent);
    }

    public function testValidAuthModeFiltersAuthenticators()
    {
        $a1 = $this->createMockAuthenticator('PLAIN');
        $a2 = $this->createMockAuthenticator('LOGIN');

        // Only LOGIN should be used when auth mode is set to LOGIN
        $a1->shouldReceive('authenticate')
            ->never();
        $a2->shouldReceive('authenticate')
            ->once()
            ->with($this->agent, 'jack', 'pass')
            ->andReturn(true);

        $auth = $this->createHandler([$a1, $a2]);
        $auth->setUsername('jack');
        $auth->setPassword('pass');
        $auth->setAuthMode('LOGIN');
        $auth->setKeywordParams(['PLAIN', 'LOGIN']);
        $auth->afterEhlo($this->agent);
    }

    private function createHandler($authenticators)
    {
        return new Swift_Transport_Esmtp_AuthHandler($authenticators);
    }

    private function createMockAuthenticator($type)
    {
        $authenticator = $this->getMockery('Swift_Transport_Esmtp_Authenticator')->shouldIgnoreMissing();
        $authenticator->shouldReceive('getAuthKeyword')
            ->zeroOrMoreTimes()
            ->andReturn($type);

        return $authenticator;
    }
}
