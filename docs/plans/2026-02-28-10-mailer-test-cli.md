# Mailer Test CLI Script — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Provide a `bin/swiftmailer-test` CLI command that accepts a DSN string and recipient address, creates a transport via the existing DSN factory, and sends a test email — giving developers a one-liner to verify their mail configuration.

**Architecture:** The script is a standalone PHP executable that reuses `Swift_Transport_DsnTransportFactory` to parse the DSN into a transport, constructs a `Swift_Message`, and delegates delivery to `Swift_Mailer::send()`. Argument parsing is extracted into a dedicated `Swift_Cli_ArgumentParser` class so it can be unit-tested independently of I/O. Colorized output is handled by a small `Swift_Cli_ConsoleOutput` helper that detects TTY support.

**Tech Stack:** PHP 8.1+, existing DSN factory and mailer. No new dependencies.

---

## Task 1 — Create `Swift_Cli_ArgumentParser`

**Why:** Isolate argument parsing from the bin script so it is unit-testable without invoking a process. This class takes `$argv` and returns a typed value object.

### 1a. Write the test first

**File:** `tests/unit/Swift/Cli/ArgumentParserTest.php`

```php
<?php

class Swift_Cli_ArgumentParserTest extends \PHPUnit\Framework\TestCase
{
    public function testParsesRequiredDsnAndTo()
    {
        $parser = new Swift_Cli_ArgumentParser();
        $args = $parser->parse([
            'bin/swiftmailer-test',
            'smtp://user:pass@smtp.example.com:587',
            '--to=test@example.com',
        ]);

        $this->assertSame('smtp://user:pass@smtp.example.com:587', $args->dsn);
        $this->assertSame('test@example.com', $args->to);
        $this->assertSame('swiftmailer-test@localhost', $args->from);
        $this->assertSame('SwiftMailer Test Email', $args->subject);
        $this->assertStringContainsString('test email', strtolower($args->body));
    }

    public function testParsesAllOptionalFlags()
    {
        $parser = new Swift_Cli_ArgumentParser();
        $args = $parser->parse([
            'bin/swiftmailer-test',
            'sendgrid://apikey',
            '--to=a@b.com',
            '--from=me@b.com',
            '--subject=Hello',
            '--body=Custom body',
        ]);

        $this->assertSame('sendgrid://apikey', $args->dsn);
        $this->assertSame('a@b.com', $args->to);
        $this->assertSame('me@b.com', $args->from);
        $this->assertSame('Hello', $args->subject);
        $this->assertSame('Custom body', $args->body);
    }

    public function testThrowsWhenDsnMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DSN');

        $parser = new Swift_Cli_ArgumentParser();
        $parser->parse(['bin/swiftmailer-test', '--to=a@b.com']);
    }

    public function testThrowsWhenToMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--to');

        $parser = new Swift_Cli_ArgumentParser();
        $parser->parse(['bin/swiftmailer-test', 'smtp://localhost']);
    }

    public function testPrintsUsageOnHelp()
    {
        $parser = new Swift_Cli_ArgumentParser();

        $this->expectException(\Swift_Cli_HelpRequestedException::class);

        $parser->parse(['bin/swiftmailer-test', '--help']);
    }
}
```

### 1b. Write the value object

**File:** `lib/classes/Swift/Cli/ParsedArguments.php`

```php
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
```

### 1c. Write the HelpRequestedException

**File:** `lib/classes/Swift/Cli/HelpRequestedException.php`

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 */

/**
 * Thrown when the user passes --help to signal the script should print usage and exit.
 */
class Swift_Cli_HelpRequestedException extends \RuntimeException
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
```

### 1d. Write the ArgumentParser

**File:** `lib/classes/Swift/Cli/ArgumentParser.php`

```php
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
        array_shift($argv);

        $positional = [];
        $options    = [];

        foreach ($argv as $arg) {
            if ('--help' === $arg || '-h' === $arg) {
                throw new Swift_Cli_HelpRequestedException();
            }

            if (str_starts_with($arg, '--')) {
                $eqPos = strpos($arg, '=');
                if (false === $eqPos) {
                    throw new \InvalidArgumentException(sprintf('Option "%s" requires a value (use --option=value).', $arg));
                }
                $key            = substr($arg, 2, $eqPos - 2);
                $value          = substr($arg, $eqPos + 1);
                $options[$key]  = $value;
            } else {
                $positional[] = $arg;
            }
        }

        // Validate required arguments
        if (empty($positional)) {
            throw new \InvalidArgumentException('Missing required argument: DSN string. Run with --help for usage.');
        }

        if (!isset($options['to'])) {
            throw new \InvalidArgumentException('Missing required option: --to=<recipient>. Run with --help for usage.');
        }

        return new Swift_Cli_ParsedArguments(
            dsn:     $positional[0],
            to:      $options['to'],
            from:    $options['from']    ?? 'swiftmailer-test@localhost',
            subject: $options['subject'] ?? 'SwiftMailer Test Email',
            body:    $options['body']    ?? 'This is a test email sent by the SwiftMailer CLI test tool.',
        );
    }
}
```

### 1e. Register autoloading

The project uses PSR-0 with `Swift_` prefix. Files in `lib/classes/Swift/Cli/` will autoload as `Swift_Cli_ArgumentParser` etc., because the existing `lib/swift_required.php` autoloader maps underscores to directory separators. No changes needed to `composer.json` autoloading.

### Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Cli/ArgumentParserTest.php --verbose
```

### Commit

```bash
git add lib/classes/Swift/Cli/ArgumentParser.php \
        lib/classes/Swift/Cli/ParsedArguments.php \
        lib/classes/Swift/Cli/HelpRequestedException.php \
        tests/unit/Swift/Cli/ArgumentParserTest.php
git commit -m "feat: add CLI argument parser for mailer test command"
```

---

## Task 2 — Create `Swift_Cli_ConsoleOutput` helper

**Why:** Centralizes colorized output and TTY detection so the bin script stays clean.

### 2a. Write the class

**File:** `lib/classes/Swift/Cli/ConsoleOutput.php`

```php
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
        $this->stream       = $stream ?? \STDOUT;
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
        fwrite($this->stream, $message . \PHP_EOL);
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
```

### Verify

No dedicated test file needed for this helper — it is exercised through the integration test in Task 4. Manual check:

```bash
php -r "require 'lib/swift_required.php'; \$o = new Swift_Cli_ConsoleOutput(); \$o->success('OK'); \$o->error('FAIL');"
```

### Commit

```bash
git add lib/classes/Swift/Cli/ConsoleOutput.php
git commit -m "feat: add ConsoleOutput helper for colorized CLI output"
```

---

## Task 3 — Create the `bin/swiftmailer-test` script

**Why:** This is the user-facing entry point.

### 3a. Write the bin script

**File:** `bin/swiftmailer-test`

```php
#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * CLI tool to send a test email via any SwiftMailer-supported transport.
 *
 * Usage:
 *   php bin/swiftmailer-test "smtp://user:pass@host:587" --to=test@example.com
 */

// Autoload
(function () {
    $autoloadPaths = [
        __DIR__ . '/../vendor/autoload.php',        // when run from the package itself
        __DIR__ . '/../../../autoload.php',          // when installed as a Composer dependency
    ];

    foreach ($autoloadPaths as $path) {
        if (file_exists($path)) {
            require $path;
            return;
        }
    }

    fwrite(STDERR, "Cannot find Composer autoloader. Run 'composer install' first.\n");
    exit(1);
})();

$output = new Swift_Cli_ConsoleOutput();

// ── Parse arguments ──────────────────────────────────────────────
try {
    $parser = new Swift_Cli_ArgumentParser();
    $args   = $parser->parse($argv);
} catch (Swift_Cli_HelpRequestedException $e) {
    $output->writeln($e->getMessage());
    exit(0);
} catch (\InvalidArgumentException $e) {
    $output->error('Error: ' . $e->getMessage());
    exit(1);
}

// ── Create transport from DSN ────────────────────────────────────
$output->info('DSN:       ' . $args->dsn);
$output->info('From:      ' . $args->from);
$output->info('To:        ' . $args->to);
$output->info('Subject:   ' . $args->subject);
$output->writeln('');

try {
    $output->info('Creating transport from DSN...');
    $factory   = new Swift_Transport_DsnTransportFactory();
    $transport = $factory->fromDsnString($args->dsn);
    $output->success('Transport: ' . get_class($transport));
} catch (\Throwable $e) {
    $output->error('Failed to create transport: ' . $e->getMessage());
    exit(1);
}

// ── Connect / start transport ────────────────────────────────────
try {
    $output->info('Starting transport...');
    $transport->start();
    $output->success('Transport started successfully.');
} catch (\Throwable $e) {
    $output->error('Connection failed: ' . $e->getMessage());
    exit(1);
}

// ── Build and send message ───────────────────────────────────────
try {
    $message = (new Swift_Message($args->subject))
        ->setFrom($args->from)
        ->setTo($args->to)
        ->setBody($args->body, 'text/plain');

    $mailer           = new Swift_Mailer($transport);
    $failedRecipients = [];
    $sent             = $mailer->send($message, $failedRecipients);

    if ($sent > 0) {
        $output->success(sprintf('Email sent successfully to %d recipient(s).', $sent));
    } else {
        $output->error('Email was not accepted for delivery.');
        if (!empty($failedRecipients)) {
            $output->error('Failed recipients: ' . implode(', ', $failedRecipients));
        }
        exit(1);
    }
} catch (\Throwable $e) {
    $output->error('Send failed: ' . $e->getMessage());
    exit(1);
} finally {
    // ── Stop transport ───────────────────────────────────────────
    try {
        $transport->stop();
    } catch (\Throwable $e) {
        $output->warning('Warning: could not cleanly stop transport: ' . $e->getMessage());
    }
}

exit(0);
```

### 3b. Make executable

```bash
chmod +x bin/swiftmailer-test
```

### Verify

```bash
php bin/swiftmailer-test --help
php bin/swiftmailer-test "null://null" --to=test@example.com
```

The `null://null` DSN creates a `Swift_Transport_NullTransport` which accepts messages without actually sending, so this should print success output with exit code 0.

### Commit

```bash
git add bin/swiftmailer-test
git commit -m "feat: add bin/swiftmailer-test CLI script for sending test emails"
```

---

## Task 4 — Register in `composer.json` bin section

**Why:** Allows `vendor/bin/swiftmailer-test` to work when this package is installed as a dependency.

### 4a. Edit `composer.json`

Add the `"bin"` key after the `"autoload-dev"` section:

```json
"bin": [
    "bin/swiftmailer-test"
],
```

### Verify

```bash
composer validate --strict
```

### Commit

```bash
git add composer.json
git commit -m "chore: register bin/swiftmailer-test in composer.json"
```

---

## Task 5 — Add integration-style unit test for the full CLI flow

**Why:** Verifies that the argument parser, DSN factory, and mailer wire up correctly end-to-end using the null transport (no real mail server needed).

### 5a. Write the test

**File:** `tests/unit/Swift/Cli/MailerTestCommandTest.php`

```php
<?php

/**
 * Integration test that exercises the CLI flow without actually invoking a process.
 * Uses the null:// transport so no real mail server is required.
 */
class Swift_Cli_MailerTestCommandTest extends \PHPUnit\Framework\TestCase
{
    public function testFullFlowWithNullTransport()
    {
        // Parse
        $parser = new Swift_Cli_ArgumentParser();
        $args   = $parser->parse([
            'bin/swiftmailer-test',
            'null://null',
            '--to=test@example.com',
            '--from=sender@example.com',
            '--subject=Integration test',
            '--body=Hello from test',
        ]);

        // Create transport
        $factory   = new Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString($args->dsn);

        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);

        // Build message
        $message = (new Swift_Message($args->subject))
            ->setFrom($args->from)
            ->setTo($args->to)
            ->setBody($args->body, 'text/plain');

        // Send
        $mailer = new Swift_Mailer($transport);
        $failed = [];
        $sent   = $mailer->send($message, $failed);

        $this->assertSame(1, $sent);
        $this->assertEmpty($failed);
    }

    public function testBinScriptExitsZeroWithNullTransport()
    {
        $binPath = realpath(__DIR__ . '/../../../../bin/swiftmailer-test');
        if (!$binPath) {
            $this->markTestSkipped('bin/swiftmailer-test not found.');
        }

        $cmd    = sprintf('php %s "null://null" --to=test@example.com 2>&1', escapeshellarg($binPath));
        $output = [];
        $exit   = null;
        exec($cmd, $output, $exit);

        $this->assertSame(0, $exit, 'Expected exit code 0. Output: ' . implode("\n", $output));
    }

    public function testBinScriptExitsOneOnMissingArgs()
    {
        $binPath = realpath(__DIR__ . '/../../../../bin/swiftmailer-test');
        if (!$binPath) {
            $this->markTestSkipped('bin/swiftmailer-test not found.');
        }

        $cmd    = sprintf('php %s 2>&1', escapeshellarg($binPath));
        $output = [];
        $exit   = null;
        exec($cmd, $output, $exit);

        $this->assertSame(1, $exit);
    }

    public function testHelpFlagExitsZero()
    {
        $binPath = realpath(__DIR__ . '/../../../../bin/swiftmailer-test');
        if (!$binPath) {
            $this->markTestSkipped('bin/swiftmailer-test not found.');
        }

        $cmd    = sprintf('php %s --help 2>&1', escapeshellarg($binPath));
        $output = [];
        $exit   = null;
        exec($cmd, $output, $exit);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Usage:', implode("\n", $output));
    }
}
```

### Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Cli/ --verbose
```

### Commit

```bash
git add tests/unit/Swift/Cli/MailerTestCommandTest.php
git commit -m "test: add unit and integration tests for mailer test CLI"
```

---

## Task 6 — Run code style fixer and full test suite

### 6a. Fix style

```bash
composer php-cs-fixer
```

### 6b. Run full unit tests

```bash
vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose
```

### Commit (if fixer made changes)

```bash
git add -u
git commit -m "style: apply php-cs-fixer to new CLI classes"
```

---

## File inventory

| File | Action |
|-|-|
| `lib/classes/Swift/Cli/ArgumentParser.php` | Create |
| `lib/classes/Swift/Cli/ParsedArguments.php` | Create |
| `lib/classes/Swift/Cli/HelpRequestedException.php` | Create |
| `lib/classes/Swift/Cli/ConsoleOutput.php` | Create |
| `bin/swiftmailer-test` | Create |
| `composer.json` | Edit (add `bin` key) |
| `tests/unit/Swift/Cli/ArgumentParserTest.php` | Create |
| `tests/unit/Swift/Cli/MailerTestCommandTest.php` | Create |

## Dependency graph

```
Task 1 (ArgumentParser + tests)
   |
   v
Task 2 (ConsoleOutput)
   |
   v
Task 3 (bin script) --- depends on Task 1 + Task 2
   |
   v
Task 4 (composer.json bin registration)
   |
   v
Task 5 (integration tests) --- depends on Task 3 + Task 4
   |
   v
Task 6 (code style + full suite)
```
