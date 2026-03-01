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

    /**
     * @param resource $stream Writable stream (default: STDOUT)
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? \STDOUT;
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
        $this->writeln($this->colorize($message, '0;31')); // red
    }

    public function warning(string $message): void
    {
        $this->writeln($this->colorize($message, '1;33')); // yellow
    }

    public function writeln(string $message): void
    {
        fwrite($this->stream, $message.\PHP_EOL);
    }

    private function colorize(string $text, string $code): string
    {
        if (!$this->colorEnabled) {
            return $text;
        }

        return "\033[{$code}m{$text}\033[0m";
    }

    private function detectColor(): bool
    {
        // Respect NO_COLOR convention (https://no-color.org/)
        if (isset($_SERVER['NO_COLOR']) || false !== getenv('NO_COLOR')) {
            return false;
        }

        if (\function_exists('stream_isatty')) {
            return stream_isatty($this->stream);
        }

        return false;
    }
}
