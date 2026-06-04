<?php

class Swift_Transport_UrlValidatorTest extends SwiftMailerTestCase
{
    public function testAcceptsValidHttpsUrl()
    {
        Swift_Transport_UrlValidator::validate('https://api.example.com/v1/send');
        $this->addToAssertionCount(1);
    }

    public function testRejectsHttpUrl()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API endpoint must use HTTPS');

        Swift_Transport_UrlValidator::validate('http://api.example.com/v1/send');
    }

    /**
     * @dataProvider localhostProvider
     */
    public function testRejectsLocalhostAndLoopback(string $host)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not point to localhost or loopback');

        Swift_Transport_UrlValidator::validate('https://'.$host.'/api');
    }

    public static function localhostProvider(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1'],
            'all-zeros'     => ['0.0.0.0'],
            'localhost'     => ['localhost'],
            'ipv6 bracket'  => ['[::1]'],
        ];
    }

    public function testRejectsPrivateIpRange()
    {
        // 10.0.0.1 is private — gethostbyname returns the IP itself for numeric hosts
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private/reserved IP');

        Swift_Transport_UrlValidator::validate('https://10.0.0.1/api');
    }
}
