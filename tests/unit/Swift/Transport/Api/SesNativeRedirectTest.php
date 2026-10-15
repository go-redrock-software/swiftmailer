<?php

use AsyncAws\Ses\SesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

class Swift_Transport_Api_SesNativeRedirectTest extends TestCase
{
    private array $servers = [];

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $process) {
            \proc_terminate($process);
            \proc_close($process);
        }
        foreach ($this->files as $file) {
            \unlink($file);
        }
    }

    /** @dataProvider cases */
    public function testNativeRedirectStopsAtFirstServerAndOriginalClientStillFollows(string $kind, string $mode, int $status): void
    {
        [$target, $targetLog] = $this->server(200);
        [$source, $sourceLog] = $this->server($status, $target.'/collect');
        $http                 = match ($mode) {
            'native' => new NativeHttpClient(),
            'retry'  => new RetryableHttpClient(new NativeHttpClient(), new GenericRetryStrategy([301, 302, 303, 307, 308], 0), 2),
            default  => null,
        };
        $client = new SesClient(['region' => 'us-east-1', 'accessKeyId' => 'fixture-key', 'accessKeySecret' => 'fixture-secret', 'endpoint' => $source], null, $http);
        $class  = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $failed = [];
        try {
            (new Swift_Transport_RetryTransport(new $class($client), maxRetries: 2, baseDelayMs: 0))->send((new Swift_Message('fixture', 'fixture-private-body'))->setFrom('from@example.test')->setTo('to@example.test')->setBcc('blind@example.test'), $failed);
            $this->fail('Native HTTP must not follow a redirected SES request.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $wire = $this->readLog($sourceLog);
        $this->assertCount(1, $wire);
        $this->assertSame([], $this->readLog($targetLog));
        $this->assertSame('POST', $wire[0]['method']);
        $this->assertStringContainsString('fixture-private-body', $wire[0]['body']);
        $this->assertStringContainsString('blind@example.test', $wire[0]['body']);
        $this->assertStringContainsString('AWS4-HMAC-SHA256', $wire[0]['authorization']);
        $this->assertCount(2, $failed);

        // The same caller SDK retains its original redirect policy after Swift's scoped send.
        $this->assertSame('fixture-native-id', $client->sendEmail(\json_decode($wire[0]['body'], true, 512, \JSON_THROW_ON_ERROR))->getMessageId());
        $this->assertCount(2, $this->readLog($sourceLog));
        $this->assertCount(1, $this->readLog($targetLog));
    }

    public function testNativeProgressCallbackAndCallerOptionsRemainActive(): void
    {
        [$source, $log] = $this->server(200);
        $progress       = [];
        $http           = new NativeHttpClient(['headers' => ['X-Fixture' => 'caller'], 'max_redirects' => 7, 'on_progress' => static function (int $now, int $size, array $info) use (&$progress): void {
            $progress[] = [$now, $size, $info];
        }]);
        $client    = new SesClient(['region' => 'us-east-1', 'accessKeyId' => 'fixture-key', 'accessKeySecret' => 'fixture-secret', 'endpoint' => $source], null, $http);
        $transport = new Swift_Transport_Api_AmazonSesApiTransport($client);
        $this->assertSame(1, $transport->send((new Swift_Message('fixture', 'body'))->setFrom('from@example.test')->setTo('to@example.test')));
        $this->assertNotEmpty($progress);
        $this->assertSame('caller', $this->readLog($log)[0]['fixture']);
        $this->assertSame($http, (new ReflectionProperty(AsyncAws\Core\AbstractApi::class, 'httpClient'))->getValue($client));
    }

    public static function cases(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach (['default', 'native', 'retry'] as $mode) {
                foreach ([301, 302, 303, 307, 308] as $status) {
                    yield "$kind $mode $status" => [$kind, $mode, $status];
                }
            }
        }
    }

    private function server(int $status, string $target = ''): array
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket, $error);
        $address = \stream_socket_get_name($socket, false);
        \fclose($socket);
        $log    = $this->file('');
        $router = $this->file('<?php '.
            '$body = file_get_contents("php://input"); file_put_contents('.\var_export($log, true).', json_encode(["method" => $_SERVER["REQUEST_METHOD"], "body" => $body, "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? "", "fixture" => $_SERVER["HTTP_X_FIXTURE"] ?? ""])."\\n", FILE_APPEND);'.
            'http_response_code('.$status.'); header("Content-Type: application/json");'.
            ($target ? 'header('.\var_export('Location: '.$target, true).');' : '').
            'echo \'{"MessageId":"fixture-native-id"}\';');
        $process = \proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->servers[] = $process;
        $ready           = false;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @\stream_socket_client('tcp://'.$address, $errno, $error, 0.01);
            if (false !== $connection) {
                \fclose($connection);
                $ready = true;
                break;
            }
            \usleep(10000);
        }
        $this->assertTrue($ready, 'The local HTTP fixture must start.');

        return ['http://'.$address, $log];
    }

    private function file(string $contents): string
    {
        $file          = \tempnam(\sys_get_temp_dir(), 'swiftmailer-59-http-');
        $this->files[] = $file;
        \file_put_contents($file, $contents);

        return $file;
    }

    private function readLog(string $log): array
    {
        return \array_map(static fn (string $line) => \json_decode($line, true, 512, \JSON_THROW_ON_ERROR), \file($log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
    }
}
