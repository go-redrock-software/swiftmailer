<?php

use PHPUnit\Framework\TestCase;

/** Client-observed output while the real CLI/FPM request is held after notify(). */
class Swift_Plugins_Reporters_HtmlReporterStreamingTest extends TestCase
{
    /** @dataProvider runtimes */
    public function testNotificationDeliveryBeforeRequestCompletion(string $sapi, bool $buffered, int $result): void
    {
        $fpm = 'fpm-fcgi' === $sapi ? $this->fpmBinary() : null;
        if (!\in_array('unix', \stream_get_transports(), true)) {
            if ('1' === \getenv('SWIFTMAILER_REQUIRE_FPM')) {
                $this->fail('Private Unix sockets are required for CI streaming coverage.');
            }
            $this->markTestSkipped('Unix sockets are needed for the isolated streaming test.');
        }
        $expected = '<div style="color: #fff; background: '.(Swift_Plugins_Reporter::RESULT_PASS === $result ? '#006600' : '#880000').'; padding: 2px; margin: 2px;">'.\PHP_EOL
            .(Swift_Plugins_Reporter::RESULT_PASS === $result ? 'PASS' : 'FAIL').' stream&lt;&amp;&quot;@example.test'.\PHP_EOL
            .'</div>'.\PHP_EOL;
        \ob_start();
        try {
            (new Swift_Plugins_Reporters_HtmlReporter())->notify(new Swift_Message('Streaming fixture'), 'stream<&"@example.test', $result);
            $captured = \ob_get_contents();
        } finally {
            \ob_end_clean();
        }
        $this->assertSame($expected, $captured, 'Buffer-captured and client-streamed reports must preserve the same safe HTML.');
        $directory = \sys_get_temp_dir().'/sf41-'.\bin2hex(\random_bytes(6));
        // Keep sockaddr_un paths short even when the system temp directory is long.
        if (\strlen($directory) > 70 && \is_dir('/tmp')) {
            $directory = '/tmp/sf41-'.\bin2hex(\random_bytes(6));
        }
        $this->assertTrue(\mkdir($directory, 0700));
        $control = $request = $gate = $process = null;
        $pipes   = [];
        try {
            $control = \stream_socket_server('unix://'.$directory.'/control', $code, $error);
            $this->assertIsResource($control, $error);
            $root           = \dirname(__DIR__, 5);
            $implementation = $this->implementationFile();
            $config         = ['implementation' => $implementation, 'autoload' => $root.'/vendor/autoload.php', 'control' => $directory.'/control', 'buffered' => $buffered, 'result' => $result];
            $fixture        = $root.'/tests/fixtures/html-reporter-stream.php';
            if (null !== $fpm) {
                $configuration = $directory.'/fpm.conf';
                \file_put_contents($configuration, "[global]\npid=$directory/fpm.pid\nerror_log=$directory/error.log\ndaemonize=no\n[stream]\nlisten=$directory/fpm\nlisten.mode=0600\nuser=root\ngroup=root\npm=static\npm.max_children=1\nrequest_terminate_timeout=10s\nphp_admin_value[output_buffering]=0\nphp_admin_value[implicit_flush]=0\nphp_admin_value[zlib.output_compression]=0\nphp_admin_value[opcache.enable]=0\nphp_admin_value[cgi.fix_pathinfo]=0\n");
                $process = \proc_open([$fpm, '-F', '-R', '-y', $configuration, '-d', 'xdebug.mode=off'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XDEBUG_MODE' => 'off']);
                $this->assertIsResource($process);
                \fclose($pipes[0]);
                $request = $this->awaitFpm($process, $directory);
                $this->assertIsResource($request, $error);
                $parameters = ['SCRIPT_FILENAME' => $fixture, 'SCRIPT_NAME' => '/html-reporter-stream.php', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/html-reporter-stream.php', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => '0', 'SWIFT_STREAM_CONFIG' => \json_encode($config, \JSON_THROW_ON_ERROR)];
                $payload    = '';
                foreach ($parameters as $key => $value) {
                    $payload .= self::length(\strlen($key)).self::length(\strlen($value)).$key.$value;
                }
                $this->write($request, self::record(1, \pack('nCxxxxx', 1, 0)).self::record(4, $payload).self::record(4, '').self::record(5, ''));
            } else {
                $process = \proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'implicit_flush=0', $fixture, \json_encode($config, \JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XDEBUG_MODE' => 'off']);
                $this->assertIsResource($process);
                \fclose($pipes[0]);
                $request = $pipes[1];
            }
            $gate = \stream_socket_accept($control, 5);
            $this->assertIsResource($gate, 'The worker must reach the post-notify control barrier.');
            \stream_set_timeout($gate, 5);
            $ready = \fgets($gate);
            $this->assertIsString($ready);
            $state = \json_decode($ready, true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame($sapi, $state['sapi']);
            $this->assertSame(\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION, $state['version'], 'CLI and FPM must use the same supported PHP release.');
            $this->assertSame($implementation, $state['implementation'], 'The worker must execute the selected original or actual Infection replacement.');
            $this->assertSame($buffered ? 1 : 0, $state['level']);
            \stream_set_blocking($request, false);
            $early = \stream_get_contents($request);
            $this->assertIsString($early);
            $output = null === $fpm ? $early : $this->fastCgiOutput($early, false);
            // The barrier is still closed. Completion cannot make this assertion pass.
            $this->assertSame($buffered ? '' : $expected, $output, 'Only the backend buffer is flushed; PHP user buffers remain under caller control.');
            $this->write($gate, 'r');
            \fclose($gate);
            $gate = null;
            \stream_set_blocking($request, true);
            \stream_set_timeout($request, 5);
            $remaining = \stream_get_contents($request);
            $this->assertIsString($remaining);
            $this->assertFalse(\stream_get_meta_data($request)['timed_out']);
            $complete = $early.$remaining;
            $this->assertSame($expected, null === $fpm ? $complete : $this->fastCgiOutput($complete, true));
            if (null === $fpm) {
                $deadline = \microtime(true) + 3;
                do {
                    $status = \proc_get_status($process);
                    if ($status['running']) {
                        \usleep(1000);
                    }
                } while ($status['running'] && \microtime(true) < $deadline);
                $this->assertFalse($status['running']);
                $this->assertSame(0, $status['exitcode']);
                $this->assertSame('', \stream_get_contents($pipes[2]));
            }
        } finally {
            if (\is_resource($gate)) {
                @\fwrite($gate, 'r');
                \fclose($gate);
            }
            if (\is_resource($request)) {
                \fclose($request);
            }
            if (\is_resource($control)) {
                \fclose($control);
            }
            if (\is_resource($process)) {
                $this->stop($process);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            foreach (\glob($directory.'/*') ?: [] as $path) {
                \unlink($path);
            }
            \rmdir($directory);
        }
    }

    public static function runtimes(): iterable
    {
        foreach (['cli', 'fpm-fcgi'] as $sapi) {
            foreach ([false, true] as $buffered) {
                foreach ([Swift_Plugins_Reporter::RESULT_PASS, Swift_Plugins_Reporter::RESULT_FAIL] as $result) {
                    yield $sapi.' / buffered '.(int) $buffered.' / result '.$result => [$sapi, $buffered, $result];
                }
            }
        }
    }

    private function implementationFile(): string
    {
        $file = (new ReflectionClass(Swift_Plugins_Reporters_HtmlReporter::class))->getFileName();
        // IncludeInterceptor keeps the original Reflection filename even when its
        // bytecode came from a mutant. Pass that actual replacement to the worker.
        foreach (\get_declared_classes() as $class) {
            if ('Infection\\StreamWrapper\\IncludeInterceptor' !== $class && !\str_ends_with($class, '\\Infection\\StreamWrapper\\IncludeInterceptor')) {
                continue;
            }
            $interceptor = new ReflectionClass($class);
            $this->assertTrue($interceptor->hasProperty('intercept') && $interceptor->hasProperty('replacement'), 'The active Infection source mapping must remain inspectable.');
            $original = $interceptor->getProperty('intercept')->getValue();
            if (!\is_string($original) || \realpath($original) !== \realpath($file)) {
                continue;
            }
            $replacement = $interceptor->getProperty('replacement')->getValue();
            $this->assertIsString($replacement);
            $this->assertFileIsReadable($replacement);

            return $replacement;
        }

        return $file;
    }

    private function fpmBinary(): string
    {
        $override   = \getenv('SWIFTMAILER_PHP_FPM');
        $prefix     = \dirname(\PHP_BINARY, 2);
        $candidates = false !== $override ? [$override] : [$prefix.'/sbin/php-fpm', $prefix.'/sbin/php-fpm'.\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION, \dirname(\PHP_BINARY).'/php-fpm'];
        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }
        if (false !== $override || '1' === \getenv('SWIFTMAILER_REQUIRE_FPM')) {
            $this->fail('A matching PHP-FPM binary is required; set SWIFTMAILER_PHP_FPM to its executable path.');
        }
        $this->markTestSkipped('PHP-FPM is not installed; CLI-only coverage cannot prove FastCGI flushing.');
    }

    private function awaitFpm($process, string $directory)
    {
        $deadline = \microtime(true) + 5;
        do {
            if (!\proc_get_status($process)['running']) {
                $this->fail('PHP-FPM exited before opening its private socket.');
            }
            $request = @\stream_socket_client('unix://'.$directory.'/fpm', $code, $error, 0.1);
            if (\is_resource($request)) {
                return $request;
            }
            \usleep(10000);
        } while (\microtime(true) < $deadline);
        $this->fail('PHP-FPM did not open its private socket within the bounded startup window.');
    }

    private function stop($process): void
    {
        if (\proc_get_status($process)['running']) {
            \proc_terminate($process);
        }
        $deadline = \microtime(true) + 3;
        while (\proc_get_status($process)['running'] && \microtime(true) < $deadline) {
            \usleep(10000);
        }
        if (\proc_get_status($process)['running']) {
            \proc_terminate($process, 9);
            $deadline = \microtime(true) + 3;
            while (\proc_get_status($process)['running'] && \microtime(true) < $deadline) {
                \usleep(10000);
            }
        }
        $this->assertFalse(\proc_get_status($process)['running'], 'Every owned CLI/FPM process must be terminal before cleanup.');
        \proc_close($process);
    }

    private function write($stream, string $data): void
    {
        while ('' !== $data) {
            $written = \fwrite($stream, $data);
            $this->assertIsInt($written);
            $this->assertGreaterThan(0, $written);
            $data = \substr($data, $written);
        }
    }

    private static function length(int $length): string
    {
        return $length < 128 ? \chr($length) : \pack('N', $length | 0x80000000);
    }

    private static function record(int $type, string $data): string
    {
        $padding = -\strlen($data) & 7;

        return \pack('CCnnCC', 1, $type, 1, \strlen($data), $padding, 0).$data.\str_repeat("\0", $padding);
    }

    private function fastCgiOutput(string $records, bool $complete): string
    {
        $output = $errors = '';
        $end    = false;
        $offset = 0;
        while ($offset + 8 <= \strlen($records)) {
            $header = \unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', \substr($records, $offset, 8));
            $this->assertSame(1, $header['version']);
            $this->assertSame(1, $header['id']);
            $size = 8 + $header['length'] + $header['padding'];
            $this->assertGreaterThanOrEqual($offset + $size, \strlen($records), 'Ready is sent only after the backend flush has returned.');
            $body = \substr($records, $offset + 8, $header['length']);
            if (6 === $header['type']) {
                $output .= $body;
            } elseif (7 === $header['type']) {
                $errors .= $body;
            } elseif (3 === $header['type']) {
                $this->assertSame(8, \strlen($body));
                $exit = \unpack('Napplication/Cprotocol', \substr($body, 0, 5));
                $this->assertSame(['application' => 0, 'protocol' => 0], $exit);
                $end = true;
            }
            $offset += $size;
        }
        $this->assertSame(\strlen($records), $offset);
        $this->assertSame('', $errors, 'The fixture must not hide PHP/FastCGI errors.');
        $this->assertSame($complete, $end, 'The control barrier separates early delivery from request completion.');

        return \explode("\r\n\r\n", $output, 2)[1] ?? '';
    }
}
