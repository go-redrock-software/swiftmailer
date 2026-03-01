<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 */

/**
 * Thrown when the user passes --help to signal the script should print usage and exit.
 */
class Swift_Cli_HelpRequestedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(self::usageText());
    }

    public static function usageText(): string
    {
        return <<<'USAGE'
Usage:
  php bin/swiftmailer-test <dsn> --to=<recipient> [options]

Arguments:
  dsn                  Transport DSN string (e.g. smtp://user:pass@host:587)

Options:
  --to=<address>       Recipient email address (required)
  --from=<address>     Sender address (default: swiftmailer-test@localhost)
  --subject=<text>     Email subject (default: "SwiftMailer Test Email")
  --body=<text>        Email body (default: generic test message)
  --help               Show this help message

Examples:
  php bin/swiftmailer-test "smtp://user:pass@smtp.example.com:587" --to=test@example.com
  php bin/swiftmailer-test "sendgrid://API_KEY@default" --to=test@example.com --from=noreply@myapp.com
  php bin/swiftmailer-test "null://null" --to=test@example.com
USAGE;
    }
}
