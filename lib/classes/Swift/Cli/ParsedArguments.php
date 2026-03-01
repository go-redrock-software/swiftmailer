<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 */

/**
 * Value object holding parsed CLI arguments for the mailer test command.
 */
class Swift_Cli_ParsedArguments
{
    public function __construct(
        public readonly string $dsn,
        public readonly string $to,
        public readonly string $from = 'swiftmailer-test@localhost',
        public readonly string $subject = 'SwiftMailer Test Email',
        public readonly string $body = 'This is a test email sent by the SwiftMailer CLI test tool.',
    ) {
    }
}
