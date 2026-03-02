# Threat 21: CLI Credential Exposure and Terminal Injection

**STRIDE Category:** Information Disclosure, Tampering
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-214 (Process Environment Info Leak), CWE-150 (Improper Neutralization of Escape Sequences)

---

## Description

The `bin/swiftmailer-test` CLI tool accepts DSN strings containing plaintext credentials as positional command-line arguments. These appear in the process list (`ps aux`, `/proc/PID/cmdline`) visible to all local users. Additionally, `ConsoleOutput` injects ANSI escape codes into user-controlled text without sanitization, enabling terminal escape sequence injection. CLI arguments (email addresses, subject, body) receive no validation before being passed to the message/transport layer.

## Attack Vectors

1. **Process list credential sniffing** -- `php bin/swiftmailer-test "smtp://user:password@host:587"` exposes the full DSN (including password) in the OS process list. Any local user running `ps aux` or reading `/proc/PID/cmdline` captures the credentials. The display masking at line 46 only affects console output, not the process arguments.
2. **Terminal escape injection** -- `ConsoleOutput::colorize()` wraps messages in ANSI escape codes without sanitizing the message content. If error messages contain attacker-controlled data (e.g., SMTP server banners, malformed DSN error responses), injected escape sequences can: manipulate terminal display (hide commands), overwrite previous output, or exploit terminal emulator vulnerabilities.
3. **No email address validation** -- `--to`, `--from` arguments are passed directly to `Swift_Message::setTo()` / `setFrom()` without format checking. Malformed addresses with CRLF or special characters flow through to the transport.
4. **No subject/body sanitization** -- `--subject` and `--body` values pass through to headers without any pre-validation at the CLI layer.

## Affected Files

| File | Risk |
|-|-|
| `bin/swiftmailer-test` | DSN as positional arg visible in process list |
| `lib/classes/Swift/Cli/ArgumentParser.php:19-56` | No input validation on any argument |
| `lib/classes/Swift/Cli/ConsoleOutput.php:53,62` | Unsanitized ANSI escape injection |
| `lib/classes/Swift/Cli/ParsedArguments.php` | Raw value passthrough |

## Existing Controls

- DSN display masking at line 46 of `swiftmailer-test` (regex redacts password in console output)
- `Swift_Message` and header classes perform some downstream validation

## Mitigation Plan

### Phase 1: Environment Variable DSN (Immediate)
- Accept DSN via environment variable `SWIFTMAILER_DSN` as the primary method:
  ```php
  $dsnString = getenv('SWIFTMAILER_DSN') ?: ($args->getPositional(0)
      ?? throw new InvalidArgumentException('DSN required via SWIFTMAILER_DSN env var or positional argument'));

  if (!getenv('SWIFTMAILER_DSN') && $args->getPositional(0)) {
      $output->warning('Passing DSN as a CLI argument exposes credentials in the process list. Use SWIFTMAILER_DSN environment variable instead.');
  }
  ```

### Phase 2: Terminal Output Sanitization (Immediate)
- Strip non-printable characters (especially `\033`) before outputting to terminal:
  ```php
  public function write(string $message): void
  {
      // Strip ANSI escape sequences from user-controlled content
      $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);
      fwrite($this->stream, $message . PHP_EOL);
  }
  ```
- Ensure `colorize()` only applies to framework-controlled format strings, not user data

### Phase 3: Input Validation (Short-term)
- Validate `--to` and `--from` as valid email addresses before passing to `Swift_Message`
- Validate `--subject` and `--body` for control characters
- Validate DSN format before passing to `DsnTransportFactory`

### Phase 4: Documentation
- Document that `SWIFTMAILER_DSN` is the recommended way to pass credentials
- Warn in CLI help output about process list exposure
- Document that the CLI tool is for testing only, not production use

## Test Cases

```php
// Environment variable should take precedence
putenv('SWIFTMAILER_DSN=smtp://user:pass@host');
$parser = new Swift_Cli_ArgumentParser();
$args = $parser->parse(['bin/swiftmailer-test']);
// DSN should come from env

// Terminal escape sequences should be stripped from output
$output = new Swift_Cli_ConsoleOutput(fopen('php://memory', 'w'));
$output->info("Server says: \033[2J\033[H injected");
// Output should not contain \033

// Invalid email should be rejected
$this->expectException(InvalidArgumentException::class);
$args = $parser->parse(['bin/swiftmailer-test', 'smtp://host', '--to=not-an-email']);
```

## Risk After Mitigation

**Residual Risk:** LOW -- With env-var DSN, terminal sanitization, and input validation, the CLI tool's attack surface is minimal. The tool is intended for testing only.
