<?php

use PHPUnit\Framework\TestCase;

class Swift_Integration_SdkCompatibilityTest extends TestCase
{
    /** @dataProvider nativeContracts */
    public function testNativeProductionDependencyContractInAFreshProcess(string $name): void
    {
        $root        = \dirname(__DIR__, 4);
        $diagnostics = \tmpfile();
        $this->assertIsResource($diagnostics);
        $process = \proc_open([PHP_BINARY, $root.'/tests/compatibility/sdk.php', $root, $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $diagnostics], $pipes, $root);
        $this->assertIsResource($process);
        \fclose($pipes[0]);
        $output = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $status = \proc_close($process);
        // A file-backed diagnostic stream cannot block a verbose child process.
        // Lowest-dependency CI emits dependency deprecations directly as diagnostics.
        \fclose($diagnostics);
        $results = [];
        foreach (\explode("\n", $output) as $line) {
            if (\str_starts_with($line, 'SWIFT_COMPAT_RESULT=')) {
                $results[] = \json_decode(\substr($line, \strlen('SWIFT_COMPAT_RESULT=')), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $this->assertSame(0, $status, $name.' production SDK contract failed.');
        $this->assertSame([['case' => $name, 'passed' => true]], $results);
    }

    public static function nativeContracts(): iterable
    {
        foreach (['pool metadata', 'SMTP zero credentials', 'Sendgrid tracking zero', 'Gmail MIME send', 'SES model MIME send', 'SES array MIME send', 'HTTP derived repeated envelope', 'Gmail derived repeated envelope', 'Graph derived repeated envelope', 'Graph native send', 'Graph bearer DSN send', 'Graph application DSN send'] as $name) {
            yield $name => [$name];
        }
    }
}
