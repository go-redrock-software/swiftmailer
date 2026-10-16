<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_DsnSmtpOptionsTest extends TestCase
{
    public function testOmissionPreservesNativeDefaults(): void
    {
        $transport = $this->factory('smtp://default');
        $this->assertSame(30, $transport->getTimeout());
        $this->assertNull($transport->getAuthMode());
        $this->assertSame('[127.0.0.1]', $transport->getLocalDomain());
        $this->assertFalse($transport->isStarted());
    }

    /** @dataProvider smtpSchemes */
    public function testOptionsApplyToEverySmtpSchemeWithoutConnecting(string $scheme): void
    {
        $transport = $this->factory($scheme.'://fixture:secret@default?timeout=7&auth_mode=login&local_domain=client.safe.test');
        $this->assertSame(7, $transport->getTimeout());
        $this->assertSame('LOGIN', $transport->getAuthMode());
        $this->assertSame('client.safe.test', $transport->getLocalDomain());
        $this->assertSame('fixture', $transport->getUsername());
        $this->assertSame('secret', $transport->getPassword());
        $this->assertFalse($transport->isStarted());
    }

    public static function smtpSchemes(): iterable
    {
        foreach (['smtp', 'smtp+tls', 'smtp+ssl', 'gmail+smtp', 'amazon+smtp', 'brevo+smtp', 'infobip+smtp', 'mandrill+smtp', 'mailersend+smtp', 'mailgun+smtp', 'mailjet+smtp', 'postmark+smtp', 'resend+smtp', 'scaleway+smtp', 'sendgrid+smtp', 'ahasend+smtp', 'mailomat+smtp', 'mailtrap+smtp', 'sweego+smtp'] as $scheme) {
            yield [$scheme];
        }
    }

    /** @dataProvider timeouts */
    public function testPositiveWholeSecondTimeouts(string $value, int $expected): void
    {
        $this->assertSame($expected, $this->factory('smtp://default?timeout='.$value)->getTimeout());
    }

    public static function timeouts(): iterable
    {
        yield ['1', 1];
        yield ['5', 5];
        yield ['0007', 7];
        yield ['%31%35', 15];
        yield [(string) PHP_INT_MAX, PHP_INT_MAX];
    }

    /** @dataProvider authModes */
    public function testConfiguredAuthenticatorsCanBeSelected(string $mode): void
    {
        $this->assertSame(\strtoupper($mode), $this->factory('smtp://fixture:secret@default?auth_mode='.$mode)->getAuthMode());
    }

    public static function authModes(): iterable
    {
        foreach (['plain', 'login', 'cram-md5', 'ntlm', 'xoauth2', 'PLAIN', 'LOGIN', 'CRAM-MD5', 'NTLM', 'XOAUTH2'] as $mode) {
            yield [$mode];
        }
    }

    /** @dataProvider domains */
    public function testLocalDomainUsesNativeHostAndAddressFormatting(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->factory('smtp://default?local_domain='.\rawurlencode($input))->getLocalDomain());
    }

    public static function domains(): iterable
    {
        yield ['client.safe.test', 'client.safe.test'];
        yield ['localhost', 'localhost'];
        yield ['client.safe.test.', 'client.safe.test.'];
        yield ['192.0.2.1', '[192.0.2.1]'];
        yield ['2001:db8::1', '[IPv6:2001:db8::1]'];
        yield ['[192.0.2.1]', '[192.0.2.1]'];
        yield ['[IPv6:2001:db8::1]', '[IPv6:2001:db8::1]'];
    }

    public function testTlsAndExistingSmtpOptionsRemainIntact(): void
    {
        $transport = $this->factory('smtp+tls://fixture:secret@smtp.safe.test:2525?timeout=5&auth_mode=login&local_domain=client.safe.test&verify_peer=1&peer_fingerprint=fixture-fingerprint&source_ip=127.0.0.2&smtputf8=0');
        $this->assertSame('tls', $transport->getEncryption());
        $this->assertSame(2525, $transport->getPort());
        $this->assertSame(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_fingerprint' => 'fixture-fingerprint']], $transport->getStreamOptions());
        $this->assertSame('127.0.0.2', $transport->getSourceIp());
        $this->assertInstanceOf(Swift_AddressEncoder_IdnAddressEncoder::class, $transport->getAddressEncoder());
        $this->assertSame(5, $transport->getTimeout());
    }

    /** @dataProvider wrappers */
    public function testOptionsSurvivePublicHelperAndDecorators(string $kind): void
    {
        $leaf = 'smtp://fixture:secret@smtp.safe.test?timeout=8&auth_mode=plain&local_domain=client.safe.test';
        $dsn  = match ($kind) {
            'helper' => $leaf,
            'retry'  => $leaf.'&retries=2&retry_delay=0',
            default  => 'retry('.$kind.'('.$leaf.' null://default)?retry_period=4)',
        };
        $transport = Swift::createTransportFromDsn($dsn)->getTransport();
        if ($transport instanceof Swift_Transport_RetryTransport) {
            $transport = $transport->getInnerTransport();
        }
        if ($transport instanceof Swift_Transport_LoadBalancedTransport) {
            $this->assertSame(4, $transport->getRetryPeriod());
            $transport = $transport->getTransports()[0];
        }
        $this->assertSame(8, $transport->getTimeout());
        $this->assertSame('PLAIN', $transport->getAuthMode());
        $this->assertSame('client.safe.test', $transport->getLocalDomain());
        $this->assertFalse($transport->isStarted());
    }

    public static function wrappers(): iterable
    {
        foreach (['helper', 'retry', 'failover', 'roundrobin'] as $kind) {
            yield [$kind];
        }
    }

    /** @dataProvider invalidQueries */
    public function testInvalidOptionsRejectWithCredentialSafeOwnFactoryFrames(string $query): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                $this->factory('smtp://PRIVATE-SMTP-USER:PRIVATE-SMTP-KEY@default?'.$query);
                $this->fail('Invalid SMTP options must throw.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('SMTP', $error->getMessage());
                $frames = \array_filter($error->getTrace(), static fn (array $frame): bool => Swift_Transport_DsnTransportFactory::class === ($frame['class'] ?? null));
                $debug  = $error->getMessage().\print_r($frames, true);
                $this->assertStringNotContainsString('PRIVATE-SMTP-USER', $debug);
                $this->assertStringNotContainsString('PRIVATE-SMTP-KEY', $debug);
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function invalidQueries(): iterable
    {
        foreach (['timeout=0', 'timeout=', 'timeout=-1', 'timeout=1.5', 'timeout=1e3', 'timeout=+1', 'timeout=999999999999999999999999999', 'timeout[]=5', 'timeout=1&timeout=2', 'timeout%00=5', 'auth_mode=', 'auth_mode=bogus', 'auth_mode=login%0d%0a', 'auth_mode[]=login', 'auth.mode=login', 'auth_mode=plain&auth%5fmode=login', 'local_domain=', 'local_domain=bad_domain', 'local_domain=-bad.test', 'local_domain=bad.test-', 'local_domain=bad%20domain', 'local_domain=client.safe.test%0d%0aAUTH%20PLAIN%20injected', 'local_domain=%00', 'local_domain=[not-an-address]', 'local_domain=[IPv6:not-an-address]', 'local_domain[]=safe.test', 'local_domain=one.test&local_domain=two.test', 'local.domain=client.safe.test', 'timeout=1;timeout=2', 'auth_mode=plain;auth_mode=login', 'local_domain=one.test;local_domain=two.test', 'timeout=1&timeout=2&peer_fingerprint=PRIVATE-SMTP-KEY', 'auth_mode=PRIVATE-SMTP-KEY', 'local_domain=PRIVATE-SMTP-KEY%20invalid'] as $query) {
            yield [$query];
        }
    }

    /** @dataProvider credentialSpellings */
    public function testLiteralAndEncodedZeroCredentialsArePreserved(string $dsn, ?string $username, ?string $password): void
    {
        $transport = $this->factory($dsn);
        $this->assertSame($username, $transport->getUsername());
        $this->assertSame($password, $transport->getPassword());
    }

    public static function credentialSpellings(): iterable
    {
        yield ['smtp://0:0@default', '0', '0'];
        yield ['smtp://%30:%30@default', '0', '0'];
        yield ['smtp:0:0@default', '0', '0'];
        yield ['smtp://0@default', '0', null];
        yield ['smtp://fixture:0@default', 'fixture', '0'];
        yield ['smtp://0:fixture@default', '0', 'fixture'];
    }

    /** @dataProvider querySeparators */
    public function testConfiguredSeparatorsAndAuthenticatorRegistry(string $separators): void
    {
        $implementation = (new ReflectionClass(Swift_Transport_DsnTransportFactory::class))->getFileName();
        $this->assertIsString($implementation);
        $script = <<<'PHP'
require $argv[1];
require $argv[2];
$factory = new Swift_Transport_DsnTransportFactory();
try {
    $transport = $factory->fromDsnString('smtp://0:0@default?timeout=5;auth_mode=login;local_domain=client.safe.test');
    $options = [$transport->getTimeout(), $transport->getAuthMode(), $transport->getLocalDomain(), $transport->getUsername(), $transport->getPassword()];
} catch (InvalidArgumentException $error) {
    $options = null;
}
$rejected = 0;
foreach (['timeout=1;timeout=2', 'auth_mode=plain;auth_mode=login', 'local_domain=one.test;local_domain=two.test'] as $query) {
    try {
        $factory->fromDsnString('smtp://fixture:secret@default?'.$query);
    } catch (InvalidArgumentException $error) {
        ++$rejected;
    }
}
$custom = new class implements Swift_Transport_Esmtp_Authenticator {
    public function getAuthKeyword() { return 'FIXTURE'; }
    public function authenticate(Swift_Transport_SmtpAgent $agent, $username, $password) { return true; }
};
Swift_DependencyContainer::getInstance()->register('transport.authhandlers')->asValue([$custom]);
try {
    $customMode = $factory->fromDsnString('smtp://fixture:secret@default?auth_mode=fixture')->getAuthMode();
} catch (InvalidArgumentException $error) {
    $customMode = null;
}
echo json_encode(['options' => $options, 'rejected' => $rejected, 'custom' => $customMode], JSON_THROW_ON_ERROR);
PHP;
        $process = \proc_open([\PHP_BINARY, '-d', 'arg_separator.input='.$separators, '-r', $script, $implementation, \dirname(__DIR__, 4).'/vendor/autoload.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = \stream_get_contents($pipes[1]);
        $errors = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($process), $errors);
        $this->assertSame(['options' => [5, 'LOGIN', 'client.safe.test', '0', '0'], 'rejected' => 3, 'custom' => 'FIXTURE'], \json_decode($output, true, 512, \JSON_THROW_ON_ERROR));
    }

    public static function querySeparators(): iterable
    {
        yield ['&;'];
        yield [';'];
    }

    /** @dataProvider unsupportedSchemes */
    public function testSmtpOptionsRejectOtherSchemes(string $scheme): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP');
        $this->factory($scheme.'://fixture-key@default?timeout=5');
    }

    public static function unsupportedSchemes(): iterable
    {
        foreach (['null', 'sendgrid', 'resend', 'native', 'sendmail'] as $scheme) {
            yield [$scheme];
        }
    }

    /** @dataProvider wireCredentials */
    public function testDsnSettingsReachRealEhloAndAuthBytes(string $mode, string $username, string $password): void
    {
        $wire = $this->withServer(function (int $port) use ($mode, $username, $password): void {
            $transport = $this->factory('smtp://'.\rawurlencode($username).':'.\rawurlencode($password).'@127.0.0.1:'.$port.'?timeout=2&auth_mode='.$mode.'&local_domain=client.safe.test');
            try {
                $transport->start();
                $this->assertTrue($transport->isStarted());
            } finally {
                $transport->stop();
                $transport->getBuffer()->terminate();
            }
        });
        $this->assertSame('EHLO client.safe.test', $wire[0]);
        $expected = match ($mode) {
            'plain'   => ['AUTH PLAIN '.\base64_encode($username."\0".$username."\0".$password)],
            'login'   => ['AUTH LOGIN', \base64_encode($username), \base64_encode($password)],
            'xoauth2' => ['AUTH XOAUTH2 '.\base64_encode('user='.$username."\x01auth=Bearer ".$password."\x01\x01")],
        };
        $this->assertSame(\array_merge(['EHLO client.safe.test'], $expected, ['QUIT']), $wire);
    }

    public static function wireCredentials(): iterable
    {
        foreach (['plain', 'login', 'xoauth2'] as $mode) {
            foreach ([['fixture-user', 'fixture-secret'], ['0', 'fixture-secret'], ['fixture-user', '0'], ['0', '0']] as [$username, $password]) {
                yield [$mode, $username, $password];
            }
        }
    }

    /** @dataProvider nativeUsernames */
    public function testNativeAuthPreservesZeroAndSkipsAbsentUsernames($username, bool $authenticate): void
    {
        $wire = $this->withServer(function (int $port) use ($username): void {
            $transport = (new Swift_SmtpTransport('127.0.0.1', $port))->setTimeout(2)->setUsername($username)->setPassword('0')->setAuthMode('LOGIN')->setLocalDomain('client.safe.test');
            try {
                $transport->start();
            } finally {
                $transport->stop();
                $transport->getBuffer()->terminate();
            }
        });
        $this->assertSame($authenticate ? ['EHLO client.safe.test', 'AUTH LOGIN', \base64_encode($username), \base64_encode('0'), 'QUIT'] : ['EHLO client.safe.test', 'QUIT'], $wire);
    }

    public static function nativeUsernames(): iterable
    {
        yield ['0', true];
        yield ['', false];
        yield [null, false];
        yield [false, false];
    }

    public function testExplicitZeroAuthenticatorDoesNotFallBackToAutomaticSelection(): void
    {
        $agent = $this->createMock(Swift_Transport_SmtpAgent::class);
        $plain = $this->createMock(Swift_Transport_Esmtp_Authenticator::class);
        $plain->method('getAuthKeyword')->willReturn('PLAIN');
        $plain->expects($this->never())->method('authenticate');
        $selected = $this->createMock(Swift_Transport_Esmtp_Authenticator::class);
        $selected->method('getAuthKeyword')->willReturn('0');
        $selected->expects($this->once())->method('authenticate')->with($agent, 'fixture', 'secret')->willReturn(true);
        $handler = new Swift_Transport_Esmtp_AuthHandler([$plain, $selected]);
        $handler->setUsername('fixture');
        $handler->setPassword('secret');
        $handler->setAuthMode('0');
        $handler->setKeywordParams(['PLAIN', '0']);
        $handler->afterEhlo($agent);
    }

    public function testConfiguredTimeoutActuallyBoundsSmtpRead(): void
    {
        $wire = $this->withServer(function (int $port): void {
            $transport = $this->factory('smtp://127.0.0.1:'.$port.'?timeout=1&local_domain=client.safe.test');
            try {
                $transport->start();
                $this->fail('The fixture does not answer EHLO.');
            } catch (Swift_TransportException $error) {
                $this->assertStringContainsString('Timed Out', $error->getMessage());
            } finally {
                $transport->getBuffer()->terminate();
            }
        }, 'hold');
        $this->assertSame(['EHLO client.safe.test'], $wire);
    }

    private function factory(string $dsn): Swift_Transport
    {
        return (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
    }

    private function withServer(callable $call, string $scenario = 'auth'): array
    {
        $process = \proc_open([\PHP_BINARY, \dirname(__DIR__, 4).'/tests/fixtures/smtp-dsn-server.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            \stream_set_timeout($pipes[1], 8);
            $address = \trim((string) \fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^127\\.0\\.0\\.1:[0-9]+$/', $address);
            $port = (int) \substr(\strrchr($address, ':'), 1);
            $call($port);
            $wire   = \stream_get_contents($pipes[1]);
            $errors = \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $exit    = \proc_close($process);
            $process = null;
            $this->assertSame(0, $exit, $errors);

            return \json_decode($wire, true, 512, \JSON_THROW_ON_ERROR);
        } finally {
            if (\is_resource($process)) {
                \proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        \fclose($pipe);
                    }
                }
                \proc_close($process);
            }
        }
    }
}
