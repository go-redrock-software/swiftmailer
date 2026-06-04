<?php

class Swift_Transport_Esmtp_Auth_CramMd5AuthenticatorTest extends SwiftMailerTestCase
{
    private $agent;

    protected function setUp(): void
    {
        $this->agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
    }

    public function testKeywordIsCramMd5()
    {
        /* -- RFC 2195, 2.
        The authentication type associated with CRAM is "CRAM-MD5".
        */

        $cram = $this->getAuthenticator();
        $this->assertEquals('CRAM-MD5', $cram->getAuthKeyword());
    }

    public function testSuccessfulAuthentication()
    {
        $cram = $this->getAuthenticator();

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("AUTH CRAM-MD5\r\n", [334])
            ->andReturn('334 '.\base64_encode('<foo@bar>')."\r\n");
        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::any(), [235]);

        $this->assertTrue(
            @$cram->authenticate($this->agent, 'jack', 'pass'),
            '%s: The buffer accepted all commands authentication should succeed',
        );
    }

    public function testAuthenticationFailureSendRset()
    {
        $this->expectException(Swift_TransportException::class);

        $cram = $this->getAuthenticator();

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("AUTH CRAM-MD5\r\n", [334])
            ->andReturn('334 '.\base64_encode('<foo@bar>')."\r\n");
        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::any(), [235])
            ->andThrow(new Swift_TransportException(''));
        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("RSET\r\n", [250]);

        @$cram->authenticate($this->agent, 'jack', 'pass');
    }

    public function testAuthenticationWithLongPassword()
    {
        // Password > 64 chars triggers the md5 packing branch in getResponse()
        $cram         = $this->getAuthenticator();
        $longPassword = \str_repeat('x', 65);

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("AUTH CRAM-MD5\r\n", [334])
            ->andReturn('334 '.\base64_encode('<challenge@server>')."\r\n");
        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::any(), [235]);

        $this->assertTrue(
            @$cram->authenticate($this->agent, 'jack', $longPassword),
        );
    }

    /**
     * @group legacy
     */
    public function testCramMd5TriggersDeprecation()
    {
        $cram = $this->getAuthenticator();

        $this->agent->shouldReceive('executeCommand')
            ->with("AUTH CRAM-MD5\r\n", [334])
            ->andReturn('334 '.\base64_encode('<foo@bar>')."\r\n");
        $this->agent->shouldReceive('executeCommand')
            ->with(Mockery::any(), [235]);

        $triggered = false;
        \set_error_handler(function (int $errno, string $errstr) use (&$triggered) {
            if (\E_USER_DEPRECATED === $errno && \str_contains($errstr, 'CRAM-MD5')) {
                $triggered = true;
            }

            return false;
        });

        try {
            $cram->authenticate($this->agent, 'jack', 'pass');
        } finally {
            \restore_error_handler();
        }

        $this->assertTrue($triggered, 'Expected E_USER_DEPRECATED mentioning CRAM-MD5');
    }

    private function getAuthenticator()
    {
        return new Swift_Transport_Esmtp_Auth_CramMd5Authenticator();
    }
}
