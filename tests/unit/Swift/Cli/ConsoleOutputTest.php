<?php

use PHPUnit\Framework\TestCase;

class Swift_Cli_ConsoleOutputTest extends TestCase
{
    public function testWriteln(): void
    {
        $stream    = \fopen('php://memory', 'r+');
        $errStream = \fopen('php://memory', 'r+');
        // Force NO_COLOR so colorize() returns plain text
        $_SERVER['NO_COLOR'] = '1';

        try {
            $output = new Swift_Cli_ConsoleOutput($stream, $errStream);

            $output->writeln('hello');
            \rewind($stream);
            $this->assertSame("hello\n", \stream_get_contents($stream));
        } finally {
            unset($_SERVER['NO_COLOR']);
            \fclose($stream);
            \fclose($errStream);
        }
    }

    public function testInfo(): void
    {
        $stream              = \fopen('php://memory', 'r+');
        $errStream           = \fopen('php://memory', 'r+');
        $_SERVER['NO_COLOR'] = '1';

        try {
            $output = new Swift_Cli_ConsoleOutput($stream, $errStream);
            $output->info('info msg');
            \rewind($stream);
            $this->assertStringContainsString('info msg', \stream_get_contents($stream));
        } finally {
            unset($_SERVER['NO_COLOR']);
            \fclose($stream);
            \fclose($errStream);
        }
    }

    public function testSuccess(): void
    {
        $stream              = \fopen('php://memory', 'r+');
        $errStream           = \fopen('php://memory', 'r+');
        $_SERVER['NO_COLOR'] = '1';

        try {
            $output = new Swift_Cli_ConsoleOutput($stream, $errStream);
            $output->success('ok msg');
            \rewind($stream);
            $this->assertStringContainsString('ok msg', \stream_get_contents($stream));
        } finally {
            unset($_SERVER['NO_COLOR']);
            \fclose($stream);
            \fclose($errStream);
        }
    }

    public function testError(): void
    {
        $stream              = \fopen('php://memory', 'r+');
        $errStream           = \fopen('php://memory', 'r+');
        $_SERVER['NO_COLOR'] = '1';

        try {
            $output = new Swift_Cli_ConsoleOutput($stream, $errStream);
            $output->error('err msg');
            \rewind($errStream);
            $this->assertStringContainsString('err msg', \stream_get_contents($errStream));
        } finally {
            unset($_SERVER['NO_COLOR']);
            \fclose($stream);
            \fclose($errStream);
        }
    }

    public function testWarning(): void
    {
        $stream              = \fopen('php://memory', 'r+');
        $errStream           = \fopen('php://memory', 'r+');
        $_SERVER['NO_COLOR'] = '1';

        try {
            $output = new Swift_Cli_ConsoleOutput($stream, $errStream);
            $output->warning('warn msg');
            \rewind($errStream);
            $this->assertStringContainsString('warn msg', \stream_get_contents($errStream));
        } finally {
            unset($_SERVER['NO_COLOR']);
            \fclose($stream);
            \fclose($errStream);
        }
    }

    public function testColorDisabledByNoColorEnv(): void
    {
        $stream              = \fopen('php://memory', 'r+');
        $errStream           = \fopen('php://memory', 'r+');
        $_SERVER['NO_COLOR'] = '1';

        try {
            $output = new Swift_Cli_ConsoleOutput($stream, $errStream);
            $output->info('plain');
            \rewind($stream);
            $content = \stream_get_contents($stream);
            // No ANSI escape codes when NO_COLOR is set
            $this->assertStringNotContainsString("\033[", $content);
            $this->assertStringContainsString('plain', $content);
        } finally {
            unset($_SERVER['NO_COLOR']);
            \fclose($stream);
            \fclose($errStream);
        }
    }

    public function testDetectColorWithoutNoColor(): void
    {
        $saved = $_SERVER['NO_COLOR'] ?? null;
        unset($_SERVER['NO_COLOR']);
        \putenv('NO_COLOR');

        try {
            $stream    = \fopen('php://memory', 'r+');
            $errStream = \fopen('php://memory', 'r+');
            $output    = new Swift_Cli_ConsoleOutput($stream, $errStream);
            $output->info('test');
            \rewind($stream);
            $content = \stream_get_contents($stream);
            $this->assertStringContainsString('test', $content);
            \fclose($stream);
            \fclose($errStream);
        } finally {
            if (null !== $saved) {
                $_SERVER['NO_COLOR'] = $saved;
            }
        }
    }

    public function testDefaultStreams(): void
    {
        $_SERVER['NO_COLOR'] = '1';

        try {
            // Test constructor with default streams (null)
            $output = new Swift_Cli_ConsoleOutput();
            // Just verify it can be created without errors
            $this->assertInstanceOf(Swift_Cli_ConsoleOutput::class, $output);
        } finally {
            unset($_SERVER['NO_COLOR']);
        }
    }
}
