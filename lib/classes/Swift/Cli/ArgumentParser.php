<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 */

/**
 * Parses CLI argv into a ParsedArguments value object.
 */
class Swift_Cli_ArgumentParser
{
    /**
     * @param list<string> $argv Raw $argv array (element 0 is the script name)
     */
    public function parse(array $argv): Swift_Cli_ParsedArguments
    {
        // Remove script name
        \array_shift($argv);

        $positional = [];
        $options    = [];

        foreach ($argv as $arg) {
            if ('--help' === $arg || '-h' === $arg) {
                throw new Swift_Cli_HelpRequestedException();
            }

            if (\str_starts_with($arg, '--')) {
                $eqPos = \strpos($arg, '=');
                if (false === $eqPos) {
                    throw new InvalidArgumentException(\sprintf('Option "%s" requires a value (use --option=value).', $arg));
                }
                $key           = \substr($arg, 2, $eqPos - 2);
                $value         = \substr($arg, $eqPos + 1);
                $options[$key] = $value;
            } else {
                $positional[] = $arg;
            }
        }

        // Validate required arguments
        if (empty($positional)) {
            throw new InvalidArgumentException('Missing required argument: DSN string. Run with --help for usage.');
        }

        if (!isset($options['to'])) {
            throw new InvalidArgumentException('Missing required option: --to=<recipient>. Run with --help for usage.');
        }

        return new Swift_Cli_ParsedArguments(
            dsn: $positional[0],
            to: $options['to'],
            from: $options['from']       ?? 'swiftmailer-test@localhost',
            subject: $options['subject'] ?? 'SwiftMailer Test Email',
            body: $options['body']       ?? 'This is a test email sent by the SwiftMailer CLI test tool.',
        );
    }
}
