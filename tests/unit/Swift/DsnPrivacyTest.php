<?php

use PHPUnit\Framework\TestCase;

class Swift_DsnPrivacyTest extends TestCase
{
    /** @dataProvider invalidDsns */
    public function testParserFailuresExposeOnlySafeDiagnostics(string $entry, string $dsn, string $ignoreArgs): void
    {
        [$exit, $stdout, $stderr] = $this->process([
            PHP_BINARY, '-d', 'zend.exception_ignore_args='.$ignoreArgs,
            '-d', 'zend.exception_string_param_max_len=100000',
            __DIR__.'/../../fixtures/dsn-error-report.php',
            (new ReflectionClass(Swift_Transport_DsnTransportFactory::class))->getFileName(),
            (new ReflectionClass(Swift_Dsn_Configuration::class))->getFileName(),
            __DIR__.'/../../../vendor/autoload.php',
        ], \json_encode(['entry' => $entry, 'dsn' => $dsn], JSON_THROW_ON_ERROR));
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stderr);
        $result = \json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($result['accepted']);
        $this->assertSame(InvalidArgumentException::class, $result['class']);
        $this->assertTrue($result['diagnostic']);
        $this->assertSame(0, $result['code']);
        $this->assertFalse($result['previous']);
        $this->assertFalse($result['raw_dsn_accessor']);
        $this->assertSame(['message' => false, 'string' => false, 'trace' => false, 'object' => false], $result['leaks']);
    }

    public static function invalidDsns(): iterable
    {
        $leaves = [
            'smtp://PRIVATE-USER:PRIVATE-PASSWORD@host.test:99999',
            'smtp://PRIVATE-USER:PRIVATE-PASSWORD@host.test:-1',
            'smtp://PRIVATE-USER:PRIVATE-PASSWORD@@host.test',
            'smtp://PRIVATE-USER:PRIVATE-PASSWORD@host.test/path with spaces',
            'smtp://%50%52%49%56%41%54%45-USER:%50%52%49%56%41%54%45-PASS@host.test:99999',
            'PRIVATE-WHOLE-INPUT',
            'retry(smtp://PRIVATE-USER:PRIVATE-PASSWORD@host.test:99999)',
            'failover(null://PRIVATE-FIRST@default smtp://PRIVATE-USER:PRIVATE-PASSWORD@host.test:99999)',
            'roundrobin(null://PRIVATE-FIRST@default smtp://PRIVATE-USER:PRIVATE-PASSWORD@@host.test)',
        ];
        foreach (['factory', 'factory-alias', 'mailer', 'metadata-static', 'metadata-new', 'metadata-alias'] as $entry) {
            $cases = $leaves;
            if (\in_array($entry, ['factory', 'factory-alias', 'mailer'], true)) {
                $cases[] = 'smtp://PRIVATE-USER:PRIVATE-PASSWORD@/path';
                $cases[] = 'unsupported(smtp://PRIVATE-USER:PRIVATE-PASSWORD@host.test)';
                $cases[] = 'smtp://PRIVATE-USER:PRIVATE-PASSWORD@?token=PRIVATE-TOKEN';
            }
            foreach ($cases as $dsn) {
                foreach (['0', '1'] as $ignoreArgs) {
                    yield [$entry, $dsn, $ignoreArgs];
                }
            }
        }
    }

    /** @dataProvider cliDsns */
    public function testCliDoesNotPrintDsnFields(string $dsn, int $expectedExit, bool $environment): void
    {
        $env                      = \getenv();
        $env['SWIFTMAILER_DSN']   = $environment ? $dsn : '';
        $env['NO_COLOR']          = '1';
        [$exit, $stdout, $stderr] = $this->process([
            PHP_BINARY, __DIR__.'/../../../bin/swiftmailer-test', $environment ? 'env' : $dsn,
            '--to=recipient@example.com', '--from=sender@example.com',
        ], '', $env);
        $this->assertSame($expectedExit, $exit);
        $this->assertStringContainsString('DSN:       [redacted]', $stdout);
        $this->assertStringNotContainsString('PRIVATE-', $stdout.$stderr);
        $this->assertStringNotContainsString('%50%52%49%56%41%54%45-', $stdout.$stderr);
        $this->assertStringContainsString('sender@example.com', $stdout);
        $this->assertStringContainsString('recipient@example.com', $stdout);
        if ($environment) {
            $this->assertStringNotContainsString('process list', $stderr);
        } else {
            $this->assertStringContainsString('process list', $stderr);
        }
        $this->assertStringContainsString(0 === $exit ? 'Email sent successfully to 1 recipient(s).' : 'Failed to create transport:', $stdout.$stderr);
    }

    public static function cliDsns(): iterable
    {
        $cases = [
            ['null://PRIVATE-KEY@default', 0],
            ['null://PRIVATE-USER:PRIVATE-PASS@default', 0],
            ['null://%50%52%49%56%41%54%45-USER:%50%52%49%56%41%54%45-PASS@default', 0],
            ['null://PRIVATE-USER:PRIVATE-PASS@PRIVATE-HOST/PRIVATE-PATH?token=PRIVATE-TOKEN', 0],
            ['retry(null://PRIVATE-KEY@default)', 0],
            ['failover(null://PRIVATE-KEY@default null://PRIVATE-SECOND@default)', 0],
            ['roundrobin(null://PRIVATE-KEY@default null://PRIVATE-SECOND@default)', 0],
            ['smtp://PRIVATE-USER:PRIVATE-PASS@host.test:99999', 1],
            ['gmail+api://PRIVATE-KEY:PRIVATE-SECRET@default', 1],
            ['mailgun://PRIVATE-KEY@default', 1],
            ['unsupported://PRIVATE-KEY@default', 1],
            ['PRIVATE-WHOLE-INPUT', 1],
        ];
        foreach ($cases as [$dsn, $exit]) {
            foreach ([false, true] as $environment) {
                yield [$dsn, $exit, $environment];
            }
        }
    }

    public function testValidMetadataStillRetainsFunctionArgumentsAndDecodedValues(): void
    {
        $config = Swift_Dsn_Configuration::parse('custom(smtp://user:p%40ss@host.test:2525/prefix null://default)?tag=one');
        $this->assertSame('custom', $config->getFunction());
        $this->assertSame(2, $config->countDsn());
        $this->assertSame('p@ss', $config->getFirst()->getPassword());
        $this->assertSame('host.test', $config->getFirst()->getHost());
        $this->assertSame(2525, $config->getFirst()->getPort());
        $this->assertSame('/prefix', $config->getFirst()->getPath());
        $this->assertSame('one', $config->getParameter('tag'));
    }

    public function testNonParserValidationRetainsItsSpecificDiagnostic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP timeout');
        (new Swift_Transport_DsnTransportFactory())->fromDsnString('smtp://user:pass@host.test?timeout=0');
    }

    private function process(array $command, string $stdin, ?array $environment = null): array
    {
        $process = \proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($process);
        \fwrite($pipes[0], $stdin);
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($process), $stdout, $stderr];
    }
}
