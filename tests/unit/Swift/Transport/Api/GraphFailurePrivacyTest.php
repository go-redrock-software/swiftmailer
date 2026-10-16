<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_GraphFailurePrivacyTest extends TestCase
{
    /** @dataProvider failures */
    public function testReturnedFailuresHaveSafeDiagnosticsAndAuthoritativeStatus(array $input, string $ignoreArgs, int $code, bool $terminal, bool $retryable): void
    {
        $files = [];
        foreach ([Swift_Transport::class, Swift_Transport_AbstractApiTransport::class, Swift_Transport_Api_MicrosoftGraphTransport::class, Swift_Transport_Api_GraphRequestPolicy::class] as $class) {
            $files[$class] = (new ReflectionClass($class))->getFileName();
        }
        $process = \proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args='.$ignoreArgs, '-d', 'zend.exception_string_param_max_len=100000', __DIR__.'/../../../../fixtures/graph-error-report.php', \json_encode($files, JSON_THROW_ON_ERROR), __DIR__.'/../../../../../vendor/autoload.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        \fwrite($pipes[0], \json_encode($input, JSON_THROW_ON_ERROR));
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($process), $stderr);
        $this->assertSame('', $stderr);
        $result = \json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['guard']);
        $this->assertTrue($result['exception']);
        $this->assertTrue($result['swift']);
        $this->assertSame(1, $result['tokens']);
        $this->assertSame($result['expected_resources'], $result['resources']);
        $this->assertSame($code, $result['code']);
        $this->assertSame($terminal, $result['terminal']);
        $this->assertSame($retryable, $result['retryable']);
        $this->assertFalse($result['previous']);
        $this->assertSame(['message' => false, 'string' => false, 'trace' => false, 'object' => false], $result['leaks']);
        $this->assertSame(['message' => false, 'string' => false, 'trace' => false, 'object' => false], $result['credentials']);
        $this->assertEqualsCanonicalizing(['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $result['failed']);
        $this->assertSame([1, 1, Swift_Events_SendEvent::RESULT_FAILED], $result['events']);
        $this->assertTrue($result['restored']);
        $this->assertTrue($result['ownership']);
    }

    public static function failures(): iterable
    {
        $scenarios = [];
        foreach ([401, 403, 429, 500, 501, 502, 503, 504, 301, 302, 303, 307, 308] as $status) {
            $scenarios['resource '.$status] = ['resource', 'http', $status, $status >= 300 && $status < 400, \in_array($status, [429, 500, 502, 503, 504], true)];
        }
        foreach (['network' => [false, true], 'type-error' => [true, false], 'wrapped-error' => [true, false]] as $kind => [$terminal,$retryable]) {
            $scenarios[$kind] = ['resource', $kind, 0, $terminal, $retryable];
        }
        foreach ([400, 401, 403, 503, 307] as $status) {
            $scenarios['token '.$status] = ['token', 'http', $status, 307 === $status, 503 === $status];
        }
        $scenarios['token type-error']                 = ['token', 'type-error', 0, true, false];
        $scenarios['token body-error']                 = ['token', 'body-error', 403, true, false];
        $scenarios['async rejected resource redirect'] = ['resource', 'async-rejected', 307, true, false];
        $scenarios['rejected resource redirect']       = ['resource', 'rejected', 307, true, false];
        $scenarios['rejected resource auth']           = ['resource', 'rejected', 403, false, false];
        $scenarios['rejected token auth']              = ['token', 'rejected', 403, false, false];
        foreach (['client', 'code', 'obo'] as $auth) {
            foreach ([false, true] as $httpErrors) {
                foreach (['0', '1'] as $ignore) {
                    foreach ($scenarios as $label => [$phase,$kind,$code,$terminal,$retryable]) {
                        yield $auth.' / errors '.(int) $httpErrors.' / args '.$ignore.' / '.$label => [['auth' => $auth, 'phase' => $phase, 'kind' => $kind, 'code' => $code, 'stage' => 'sendMail', 'http_errors' => $httpErrors, 'retry' => false], $ignore, $code, $terminal, $retryable];
                    }
                }
            }
        }
        foreach (['sendMail', 'draft', 'small attachment', 'upload session', 'upload chunk', 'draft send', 'event lookup', 'event create', 'event update', 'event cancel'] as $stage) {
            foreach ([false, true] as $httpErrors) {
                foreach (['0', '1'] as $ignore) {
                    yield $stage.' advanced / errors '.(int) $httpErrors.' / args '.$ignore => [['auth' => 'client', 'phase' => 'resource', 'kind' => 'http', 'code' => 403, 'stage' => $stage, 'http_errors' => $httpErrors, 'retry' => false], $ignore, 403, false, false];
                }
            }
        }
        foreach (['type-error' => 0, 'wrapped-error' => 0, 'rejected' => 307] as $kind => $code) {
            foreach (['retry', 'failover', 'roundrobin'] as $wrapper) {
                yield 'hard wrapper '.$wrapper.' / '.$kind => [['auth' => 'client', 'phase' => 'resource', 'kind' => $kind, 'code' => $code, 'stage' => 'sendMail', 'http_errors' => false, 'retry' => false, 'wrapper' => $wrapper], '0', $code, true, false];
            }
        }
        foreach (['client', 'code', 'obo'] as $auth) {
            foreach ([false, true] as $httpErrors) {
                yield 'default OAuth wrapper / '.$auth.' / errors '.(int) $httpErrors => [['auth' => $auth, 'phase' => 'token', 'kind' => 'http', 'code' => 400, 'stage' => 'sendMail', 'http_errors' => $httpErrors, 'retry' => true], '0', 400, false, false];
            }
        }
        foreach ([401, 403] as $status) {
            foreach ([false, true] as $httpErrors) {
                yield 'default wrapper / errors '.(int) $httpErrors.' / '.$status => [['auth' => 'client', 'phase' => 'resource', 'kind' => 'http', 'code' => $status, 'stage' => 'sendMail', 'http_errors' => $httpErrors, 'retry' => true], '0', $status, false, false];
            }
        }
    }
}
