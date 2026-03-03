# Threat 03: Sendmail Command Injection

**STRIDE Category:** Tampering, Elevation of Privilege
**Severity:** HIGH
**Likelihood:** Low
**CWE:** CWE-78 (OS Command Injection)

---

## Description

`SendmailTransport` executes a system command via `proc_open()` in `StreamBuffer::establishProcessConnection()`. While the `-f` flag uses `escapeshellarg()`, the base command string is configurable via `setCommand()` and through the DSN `command` parameter, creating a potential command injection vector if user-controlled input reaches these methods.

## Attack Vectors

1. **DSN-driven command injection** — `sendmail://default?command=/usr/sbin/sendmail+-bs;+rm+-rf+/`
   - `DsnTransportFactory` passes the `command` DSN parameter directly to `Swift_SendmailTransport`
2. **Programmatic command injection** — `$transport->setCommand($unsafeUserInput)` if application code passes unvalidated input
3. **Reverse path manipulation** — Although `escapeshellarg()` protects the `-f` flag, the command string is concatenated at runtime

## Affected Files

| File | Line | Risk |
|-|-|-|
| `lib/classes/Swift/Transport/SendmailTransport.php` | 73-78 | `setCommand()` accepts arbitrary string |
| `lib/classes/Swift/Transport/SendmailTransport.php` | 127 | `-f` flag appended with `escapeshellarg()` |
| `lib/classes/Swift/Transport/StreamBuffer.php` | 301 | `proc_open($command, ...)` executes the command |
| `lib/classes/Swift/Transport/DsnTransportFactory.php` | 71 | `$dsn->getParameter('command')` passed to constructor |

## Existing Controls

- `escapeshellarg()` on reverse path when appending `-f` flag (line 127)
- Default command is hardcoded: `/usr/sbin/sendmail -bs`
- Only `-bs` and `-t` modes are supported; other flags throw `Swift_TransportException`
- `proc_open()` uses descriptor-based I/O (not `exec()` or `shell_exec()`)

## Control Gaps

1. **No validation of command path** — `setCommand()` accepts any string without verifying it points to a valid sendmail binary
2. **DSN `command` parameter is unvalidated** — `DsnTransportFactory` passes it directly
3. **No allowlist for command flags** — While `-bs` and `-t` are checked at send time, the command is set earlier without validation
4. **No character filtering** — Shell metacharacters (`;`, `|`, `&&`, backticks) in the command string are not rejected

## Mitigation Plan

### Phase 1: Input Validation (Immediate)
- Validate the command in `setCommand()`:
  ```php
  public function setCommand(string $command): static
  {
      // Reject shell metacharacters
      if (preg_match('/[;&|`$(){}]/', $command)) {
          throw new InvalidArgumentException('Sendmail command contains disallowed shell characters.');
      }

      // Verify the binary path exists and is executable
      $binary = explode(' ', $command)[0];
      if (!is_executable($binary)) {
          throw new Swift_TransportException(sprintf('Sendmail binary "%s" is not executable.', $binary));
      }

      $this->params['command'] = $command;
      return $this;
  }
  ```

### Phase 2: DSN Parameter Hardening (Short-term)
- In `DsnTransportFactory`, validate the `command` parameter against an allowlist of known sendmail paths:
  ```php
  $allowedPaths = ['/usr/sbin/sendmail', '/usr/lib/sendmail', '/usr/bin/sendmail'];
  $command = $dsn->getParameter('command') ?: '/usr/sbin/sendmail -bs';
  $binary = explode(' ', $command)[0];
  if (!in_array($binary, $allowedPaths, true)) {
      throw new InvalidArgumentException('DSN sendmail command path not in allowlist.');
  }
  ```

### Phase 3: Mode Validation (Short-term)
- Move the `-bs`/`-t` mode validation from `send()` to `setCommand()` so invalid modes are rejected early
- Ensure the flag check is robust against obfuscation (e.g., `-bs -t` combining both)

### Phase 4: Documentation
- Document that `setCommand()` should never receive user input
- Document the allowlisted binary paths
- Add a security note in DSN documentation about the `command` parameter

## Test Cases

```php
// Should reject shell metacharacters
$this->expectException(InvalidArgumentException::class);
$transport->setCommand('/usr/sbin/sendmail -bs; rm -rf /');

// Should reject pipe injection
$this->expectException(InvalidArgumentException::class);
$transport->setCommand('/usr/sbin/sendmail -bs | cat /etc/passwd');

// Should reject backtick injection
$this->expectException(InvalidArgumentException::class);
$transport->setCommand('/usr/sbin/sendmail `whoami` -bs');

// Should accept valid commands
$transport->setCommand('/usr/sbin/sendmail -bs');
$this->assertSame('/usr/sbin/sendmail -bs', $transport->getCommand());
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| `escapeshellarg()` on reverse path | **IMPLEMENTED** | `SendmailTransport.php:127`: `-f` flag uses `\escapeshellarg()` |
| Default hardcoded command | **IMPLEMENTED** | Default `/usr/sbin/sendmail -bs` is hardcoded |
| `-bs`/`-t` mode validation | **IMPLEMENTED** | Mode checking exists at send time |
| Shell metacharacter rejection in `setCommand()` | **PENDING** | `setCommand()` at line 73 accepts arbitrary string with no validation |
| DSN `command` parameter validation/allowlist | **PENDING** | `DsnTransportFactory.php:71` passes `command` parameter directly |
| Binary path validation (`is_executable()`) | **PENDING** | No binary path verification |

**Overall Status:** PARTIALLY IMPLEMENTED -- `escapeshellarg()` protects the `-f` flag, but `setCommand()` and DSN `command` parameter lack input validation.

## Risk After Mitigation

**Residual Risk:** LOW — With command validation, allowlisted binaries, and shell metacharacter rejection, injection requires modifying code rather than input.
