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

        $email = 'user@gmail.com';
        $token = 'ya29.access-token';
        $expectedParam = \base64_encode("user=$email\1auth=Bearer $token\1\1");

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with('AUTH XOAUTH2 ' . $expectedParam . "\r\n", [235]);

        $this->assertTrue($auth->authenticate($this->agent, $email, $token));
    }

    public function testAuthenticationFailureSendsRsetAndRethrows()
    {
        $this->expectException(Swift_TransportException::class);

        $auth = new Swift_Transport_Esmtp_Auth_XOAuth2Authenticator();

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with(Mockery::on(function ($cmd) {
                return str_starts_with($cmd, 'AUTH XOAUTH2 ');
            }), [235])
            ->andThrow(new Swift_TransportException('Auth failed'));

        $this->agent->shouldReceive('executeCommand')
            ->once()
            ->with("RSET\r\n", [250]);

        $auth->authenticate($this->agent, 'user@gmail.com', 'bad-token');
    }
}
