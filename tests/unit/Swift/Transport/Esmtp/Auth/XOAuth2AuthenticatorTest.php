<?php

class Swift_Transport_Esmtp_Auth_XOAuth2AuthenticatorTest extends SwiftMailerTestCase
{
    private $agent;

    protected function setUp(): void
    {
        $this->agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
    }

    public function testKeywordIsXOAuth2()
    {
        $auth = new Swift_Transport_Esmtp_Auth_XOAuth2Authenticator();
        $this->assertEquals('XOAUTH2', $auth->getAuthKeyword());
    }

    public function testSuccessfulAuthentication()
    {
        $auth = new Swift_Transport_Esmtp_Auth_XOAuth2Authenticator();

        $email         = 'user@gmail.com';
        $token         = 'ya29.access-token';
        $expectedParam = \base64_encode("user=$email\1auth=Bearer $token\1\1");

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with('AUTH XOAUTH2 '.$expectedParam."\r\n", [235]);

        $this->assertTrue($auth->authenticate($this->agent, $email, $token));
    }

    public function testAuthenticationFailureSendsRsetAndRethrows()
    {
        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('535 5.7.3 Authentication unsuccessful');

        $auth = new Swift_Transport_Esmtp_Auth_XOAuth2Authenticator();

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::on(function ($cmd) {
                return \str_starts_with($cmd, 'AUTH XOAUTH2 ');
            }), [235])
            ->andThrow(new Swift_TransportException('535 5.7.3 Authentication unsuccessful', 535));

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("RSET\r\n", [250]);

        $auth->authenticate($this->agent, 'user@gmail.com', 'bad-token');
    }

    public function testErrorChallengeIsAnsweredWithEmptyResponseNotRset()
    {
        // Gmail answers a rejected token with a 334 error challenge, and SASL requires an
        // empty reply. Sending RSET there is read as the SASL reply (535-5.7.8) and leaves
        // the next authenticator on a broken exchange, so the fallback to PLAIN/LOGIN fails.
        $auth  = new Swift_Transport_Esmtp_Auth_XOAuth2Authenticator();
        $error = new Swift_TransportException(
            'Expected response code 235 but got code "334", with message "334 eyJzdGF0dXMiOiI0MDAifQ=="',
            334,
        );

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::on(function ($cmd) {
                return \str_starts_with($cmd, 'AUTH XOAUTH2 ');
            }), [235])
            ->andThrow($error);
        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("\r\n", []);
        $this->agent->shouldReceive('executeCommand')
            ->with("RSET\r\n", Mockery::any())
            ->never();

        try {
            $auth->authenticate($this->agent, 'user@gmail.com', 'app-password');
            $this->fail('A rejected XOAUTH2 token must throw');
        } catch (Swift_TransportException $e) {
            $this->assertSame($error, $e);
        }
    }

    public function testCleanupFailureDoesNotReplaceTheAuthError()
    {
        // Servers may drop the connection after a failed AUTH. The RSET write then fails
        // (a broken-pipe warning, promoted to ErrorException by apps), and that must not hide
        // the real 535 the caller needs to see.
        $auth  = new Swift_Transport_Esmtp_Auth_XOAuth2Authenticator();
        $error = new Swift_TransportException('535 5.7.3 Authentication unsuccessful', 535);

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::on(function ($cmd) {
                return \str_starts_with($cmd, 'AUTH XOAUTH2 ');
            }), [235])
            ->andThrow($error);
        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("RSET\r\n", [250])
            ->andThrow(new ErrorException('fwrite(): SSL: Broken pipe'));

        try {
            $auth->authenticate($this->agent, 'user@outlook.com', 'expired-token');
            $this->fail('A rejected XOAUTH2 token must throw');
        } catch (Swift_TransportException $e) {
            $this->assertSame($error, $e);
        }
    }
}
