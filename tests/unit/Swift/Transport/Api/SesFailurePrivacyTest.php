<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_SesFailurePrivacyTest extends TestCase
{
    /** @dataProvider failures */
    public function testReturnedFailuresDoNotRetainSdkCredentials(string $scheme, bool $direct, string $scenario, string $ignoreArgs, int $code, bool $terminal, bool $retryable): void
    {
        $classes         = [Swift_Transport_DsnTransportFactory::class, Swift_Transport::class, Swift_Transport_AbstractApiTransport::class, Swift_Transport_Api_SesRequestPolicy::class, Swift_Transport_Api_AmazonSesApiTransport::class, Swift_Transport_Api_AmazonSesHttpTransport::class];
        $implementations = \array_map(static fn (string $class): string => (new ReflectionClass($class))->getFileName(), $classes);
        $process         = \proc_open([
            PHP_BINARY, '-d', 'zend.exception_ignore_args='.$ignoreArgs, '-d', 'zend.exception_string_param_max_len=100000',
            __DIR__.'/../../../../fixtures/ses-error-report.php', ...$implementations,
            __DIR__.'/../../../../../vendor/autoload.php',
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        \fwrite($pipes[0], \json_encode(['scheme' => $scheme, 'direct' => $direct, 'scenario' => $scenario], JSON_THROW_ON_ERROR));
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($process), $stderr);
        $this->assertSame('', $stderr);
        $result = \json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['guard']);
        $this->assertFalse($result['accepted']);
        $this->assertTrue($result['transport_exception']);
        $this->assertSame(['message' => false, 'string' => false, 'trace' => false, 'object' => false], $result['leaks']);
        $this->assertFalse($result['previous']);
        $this->assertSame($code, $result['code']);
        $this->assertSame($terminal, $result['terminal']);
        $this->assertSame($retryable, $result['retryable']);
        $this->assertSame(1, $result['requests']);
        $this->assertSame(['to@example.test'], $result['failed']);
    }

    public static function failures(): iterable
    {
        foreach (['amazon+api', 'amazon+http'] as $scheme) {
            foreach ([false, true] as $direct) {
                foreach (['denied' => [403, false, false], 'throttled' => [429, false, true], 'server500' => [500, false, true], 'server502' => [502, false, true], 'server503' => [503, false, true], 'server504' => [504, false, true], 'unsupported' => [501, false, false], 'redirect' => [307, true, false], 'malformed' => [200, true, false], 'network' => [0, false, true], 'wrapped-error' => [0, true, false], 'type-error' => [0, true, false]] as $scenario => [$code, $terminal, $retryable]) {
                    foreach (['0', '1'] as $ignoreArgs) {
                        yield [$scheme, $direct, $scenario, $ignoreArgs, $code, $terminal, $retryable];
                    }
                }
            }
        }
    }
}
