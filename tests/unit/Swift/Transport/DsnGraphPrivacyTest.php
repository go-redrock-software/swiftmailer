<?php

use PHPUnit\Framework\TestCase;

class Swift_Transport_DsnGraphPrivacyTest extends TestCase
{
    /** @dataProvider errors */
    public function testFactoryAndSendFailuresDetachCredentialAndClaimsGraphs(array $input, string $ignore, int $code, bool $terminal, bool $retryable): void
    {
        $files = [];
        foreach ([Swift_Transport::class, Swift_Transport_DsnTransportFactory::class, Swift_Transport_Api_GraphBearerAuthenticationProvider::class, Swift_Transport_Api_GraphClientCredentialContext::class, Swift_Transport_Api_GraphRequestPolicy::class, Swift_Transport_Api_GraphAcceptancePolicy::class, Swift_Transport_Api_MicrosoftGraphTransport::class, Swift_Transport_AbstractApiTransport::class] as $class) {
            $files[$class] = (new ReflectionClass($class))->getFileName();
        }
        $process = \proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args='.$ignore, '-d', 'zend.exception_string_param_max_len=100000', __DIR__.'/../../../fixtures/dsn-graph-report.php', \json_encode($files, JSON_THROW_ON_ERROR), __DIR__.'/../../../../vendor/autoload.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
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
        $this->assertSame('construct' === $input['phase'], $result['invalid']);
        $this->assertSame('construct' !== $input['phase'], $result['swift']);
        $this->assertSame($code, $result['code']);
        $this->assertSame($terminal, $result['terminal']);
        $this->assertSame($retryable, $result['retryable']);
        $this->assertFalse($result['previous']);
        $this->assertSame(['message' => false, 'string' => false, 'trace' => false, 'object' => false], $result['leaks']);
        $this->assertSame('construct' === $input['phase'] || 'token' === $input['phase'] ? 0 : 1, $result['resources']);
        $this->assertSame('construct' === $input['phase'] || 'bearer' === $input['auth'] ? 0 : 1, $result['tokens']);
        $this->assertTrue($result['ownership']);
        $this->assertTrue($result['restored']);
        if ('construct' !== $input['phase']) {
            $this->assertEqualsCanonicalizing(['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $result['failed']);
            $this->assertSame([1, 1, Swift_Events_SendEvent::RESULT_FAILED], $result['events']);
        }
    }

    public static function errors(): iterable
    {
        foreach (['bearer', 'application'] as $auth) {
            foreach ([false, true] as $httpErrors) {
                foreach (['0', '1'] as $ignore) {
                    foreach (['http' => [403, 403, false, false], 'ambiguous' => [202, 202, true, false], 'redirect' => [307, 307, true, false], 'error' => [0, 0, true, false], 'network' => [0, 0, false, true]] as $kind => [$status, $code, $terminal, $retryable]) {
                        yield $auth.' / '.$kind.' / errors '.(int) $httpErrors.' / args '.$ignore => [['auth' => $auth, 'phase' => 'resource', 'kind' => $kind, 'status' => $status, 'http_errors' => $httpErrors, 'retry' => 'network' !== $kind], $ignore, $code, $terminal, $retryable];
                    }
                    if ('application' === $auth) {
                        yield 'CAE / errors '.(int) $httpErrors.' / args '.$ignore => [['auth' => $auth, 'phase' => 'resource', 'kind' => 'cae', 'status' => 401, 'http_errors' => $httpErrors, 'retry' => true], $ignore, 0, false, false];
                        foreach ([400, 403, 503, 307] as $status) {
                            yield 'token '.$status.' / errors '.(int) $httpErrors.' / args '.$ignore => [['auth' => $auth, 'phase' => 'token', 'kind' => 'http', 'status' => $status, 'http_errors' => $httpErrors, 'retry' => false], $ignore, $status, 307 === $status, 503 === $status];
                        }
                    }
                }
            }
        }
        foreach (['microsoft-graph://TRACE-ID:TRACE-SECRET@default', 'microsoft-graph://TRACE-TOKEN@other.safe.test', 'microsoft-graph://TRACE-TOKEN@default?scope=TRACE-SCOPE', 'microsoft-graph://TRACE-ID:TRACE-SECRET@default?tenant_id=TRACE-TENANT%2F..&user_id=TRACE-USER', 'microsoft-graph://TRACE-ID:TRACE-SECRET@default?tenant_id=fixture&tenant.id=TRACE-TENANT&user_id=TRACE-USER'] as $dsn) {
            foreach (['0', '1'] as $ignore) {
                yield 'invalid / '.$dsn.' / args '.$ignore => [['auth' => 'application', 'phase' => 'construct', 'kind' => 'http', 'status' => 0, 'http_errors' => true, 'retry' => false, 'dsn' => $dsn], $ignore, 0, false, false];
            }
        }
    }
}
