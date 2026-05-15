<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 */

/**
 * Simple colorized console output helper.
 */
class Swift_Cli_ConsoleOutput
{
    private bool $colorEnabled;

    /** @var resource */
    private $stream;

    /** @var resource */
    private $errorStream;

    /**
     * @param resource $stream      Writable stream (default: STDOUT)
     * @param resource $errorStream Writable stream for errors/warnings (default: STDERR)
     */
    public function __construct($stream = null, $errorStream = null)
    {
        $this->stream       = $stream      ?? \STDOUT;
        $this->errorStream  = $errorStream ?? \fopen('php://stderr', 'w');
        $this->colorEnabled = $this->detectColor();
    }

    public function info(string $message): void
    {
        $this->writeln($this->colorize($message, '0;36')); // cyan
    }

    public function success(string $message): void
    {
        $this->writeln($this->colorize($message, '0;32')); // green
    }

    public function error(string $message): void
    {
        \fwrite($this->errorStream, $this->colorize($message, '0;31').\PHP_EOL); // red
    }

    public function warning(string $message): void
    {
        \fwrite($this->errorStream, $this->colorize($message, '1;33').\PHP_EOL); // yellow
    }

    public function writeln(string $message): void
    {
        \fwrite($this->stream, $this->sanitize($message).\PHP_EOL);
    }

    private function sanitize(string $message): string
    {
        return \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);
    }

    private function colorize(string $text, string $code): string
    {
        if (!$this->colorEnabled) {
            return $text;
        }

        return "\033[{$code}m{$text}\033[0m"; // @codeCoverageIgnore
    }

    private function detectColor(): bool
    {
        // Respect NO_COLOR convention (https://no-color.org/)
        if (isset($_SERVER['NO_COLOR']) || false !== \getenv('NO_COLOR')) {
            return false;
        }

        if (\function_exists('stream_isatty')) {
            return \stream_isatty($this->stream);
        }

        return false; // @codeCoverageIgnore
    }
}
