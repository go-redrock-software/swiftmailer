<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_GmailFailurePrivacyTest extends TestCase
{
    /** @dataProvider failures */
    public function testTerminalFailuresDoNotRetainOAuthGraphs(string $scenario, string $ignoreArgs, ?int $code): void
    {
        $files = [];
        foreach ([Swift_Transport::class, Swift_Transport_AbstractApiTransport::class, Swift_Transport_Api_GoogleTransport::class, Swift_Transport_Api_NoRedirectClient::class, 'Swift_Transport_Api_GmailRequestPolicy'] as $class) {
            if (\class_exists($class) || \interface_exists($class)) {
                $files[$class] = (new ReflectionClass($class))->getFileName();
            }
        }
        $process = \proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args='.$ignoreArgs, '-d', 'zend.exception_string_param_max_len=100000', __DIR__.'/../../../../fixtures/gmail-error-report.php', \json_encode($files, JSON_THROW_ON_ERROR), __DIR__.'/../../../../../vendor/autoload.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        \fwrite($pipes[0], \json_encode(['scenario' => $scenario], JSON_THROW_ON_ERROR));
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($process), $stderr);
        $this->assertSame('', $stderr);
        $result = \json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['guard']);
        $this->assertSame(1, $result['requests']);
        $this->assertSame(['to@safe.test'], $result['failed']);
        $this->assertSame(null !== $code, $result['exception']);
        if (null === $code) {
            $this->assertSame(0, $result['count']);

            return;
        }
        $this->assertTrue($result['swift']);
        $this->assertTrue($result['terminal']);
        $this->assertSame($code, $result['code']);
        $this->assertFalse($result['previous']);
        $this->assertSame(['message' => false, 'string' => false, 'trace' => false, 'object' => false], $result['leaks']);
    }

    public static function failures(): iterable
    {
        foreach (['redirect301' => 301, 'redirect302' => 302, 'redirect303' => 303, 'redirect307' => 307, 'redirect308' => 308, 'malformed' => 200, 'type-error' => 0, 'wrapped-error' => 0, 'token-redirect' => 307, 'token-type-error' => 0, 'denied' => null, 'server' => null] as $scenario => $code) {
            foreach (['0', '1'] as $ignoreArgs) {
                yield [$scenario, $ignoreArgs, $code];
            }
        }
    }
}
