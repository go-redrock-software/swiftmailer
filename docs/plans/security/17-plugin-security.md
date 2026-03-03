# Threat 17: Plugin-Specific Security Issues

**STRIDE Category:** Information Disclosure, Tampering, Spoofing
**Severity:** MEDIUM-HIGH
**Likelihood:** Medium
**CWE:** CWE-200 (Info Exposure), CWE-79 (XSS), CWE-319 (Cleartext Transmission)

---

## Description

Several plugins have security issues independent of the event system: `RedirectingPlugin` and `AllowlistPlugin` leak Bcc recipients via custom headers, `ImpersonatePlugin` enables unconstrained sender spoofing, `EchoLogger` is vulnerable to XSS, `PopBeforeSmtpPlugin` sends passwords over plaintext POP3, and `MessageLogger`/`SentMessagePlugin` store full messages indefinitely without bounds.

## Attack Vectors

1. **Bcc leakage via RedirectingPlugin** -- Original To/Cc/Bcc addresses stored in `X-Swift-To`, `X-Swift-Cc`, `X-Swift-Bcc` headers during transit. If the transport sends before `sendPerformed` restores them (e.g., exception mid-send), all Bcc recipients are exposed to the redirect target.
2. **Bcc leakage via AllowlistPlugin** -- All original recipients (including Bcc) concatenated into `X-Original-To` header, visible to the redirect target.
3. **ImpersonatePlugin sender spoofing** -- Replaces `Return-Path` with any configured sender string. No validation. If user input reaches the `$sender` parameter, arbitrary SPF/DMARC-bypassing spoofing is possible.
4. **EchoLogger XSS** -- When `$isHtml=false`, `printf('%s%s', $entry, PHP_EOL)` outputs unescaped. SMTP server banners or email addresses containing `<script>` tags render as HTML in browser context.
5. **PopBeforeSmtpPlugin plaintext POP3** -- Password sent as `PASS <password>\r\n` over `fsockopen()`. Default port 110 is unencrypted. Password also appears in exception messages from `command()` method.
6. **Unbounded message storage** -- `MessageLogger` and `SentMessagePlugin` accumulate full message clones indefinitely with no cap. In bulk-send scenarios: OOM risk and sensitive data retention.
7. **DecoratorPlugin template injection** -- Replacement values `str_replace`'d into headers (Subject, From, Reply-To). If values originate from untrusted input, header injection or content spoofing is possible.
8. **RedirectingPlugin regex bypass** -- Whitelist patterns use `preg_match($pattern, $recipient)` with caller-supplied patterns. Unanchored patterns (e.g., `/example\.com/`) match partial domains (e.g., `evil@example.com.attacker.org`).

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Plugins/RedirectingPlugin.php:84-94` | Bcc in X-Swift-Bcc header |
| `lib/classes/Swift/Plugins/RedirectingPlugin.php:155-158` | Unanchored regex matching |
| `lib/classes/Swift/Plugins/AllowlistPlugin.php:136-138` | Bcc in X-Original-To header |
| `lib/classes/Swift/Plugins/ImpersonatePlugin.php:39-49` | Unconstrained Return-Path |
| `lib/classes/Swift/Plugins/Loggers/EchoLogger.php:37-43` | XSS when not HTML mode |
| `lib/classes/Swift/Plugins/PopBeforeSmtpPlugin.php:152-153` | Plaintext POP3 password |
| `lib/classes/Swift/Plugins/PopBeforeSmtpPlugin.php:218-219` | Password in exception |
| `lib/classes/Swift/Plugins/MessageLogger.php:61` | Unbounded message storage |
| `lib/classes/Swift/Plugins/SentMessagePlugin.php:21` | Unbounded sent message storage |
| `lib/classes/Swift/Plugins/DecoratorPlugin.php:99-123` | Template value injection |

## Mitigation Plan

### Phase 1: Bcc Privacy (Immediate)
- In `RedirectingPlugin` and `AllowlistPlugin`, strip `X-Swift-*` and `X-Original-To` headers before transport sends, and restore after (not after send):
  ```php
  // Store internally, not in message headers
  $this->storedRecipients = [
      'to' => $message->getTo(),
      'cc' => $message->getCc(),
      'bcc' => $message->getBcc(),
  ];
  // Do NOT add X-Swift-* headers to the message
  ```

### Phase 2: XSS Prevention (Immediate)
- In `EchoLogger`, always escape output:
  ```php
  $entry = htmlspecialchars($entry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  printf('%s%s', $entry, PHP_EOL);
  ```
- Or better: make `EchoLogger` always use HTML mode, or add `strip_tags()` for non-HTML mode

### Phase 3: PopBeforeSmtp Hardening (Short-term)
- Require TLS by default (`$crypto = 'tls'`)
- Redact password from exception messages:
  ```php
  if (str_starts_with($command, 'PASS ')) {
      $command = 'PASS [REDACTED]';
  }
  ```
- Add deprecation notice for POP-before-SMTP (obsolete authentication pattern)

### Phase 4: Storage Limits (Short-term)
- Add `$maxMessages` to `MessageLogger` and `SentMessagePlugin`:
  ```php
  public function __construct(int $maxMessages = 100) { ... }
  ```
- When limit is exceeded, discard oldest messages (FIFO)

### Phase 5: Regex Safety (Medium-term)
- In `RedirectingPlugin`, enforce anchored patterns or auto-anchor:
  ```php
  if (!str_starts_with($pattern, '/^')) {
      trigger_error('Unanchored redirect whitelist pattern is insecure', E_USER_WARNING);
  }
  ```
- Document that patterns MUST be anchored

## Test Cases

```php
// Bcc should not leak in X-Swift-Bcc header during send
$message->setBcc(['secret@example.com']);
$plugin = new Swift_Plugins_RedirectingPlugin('dev@test.com');
// Trigger send
$headers = $message->getHeaders();
$this->assertFalse($headers->has('X-Swift-Bcc'));

// EchoLogger should escape HTML
ob_start();
$logger->add('<script>alert(1)</script>');
$output = ob_get_clean();
$this->assertStringNotContainsString('<script>', $output);

// PopBeforeSmtp exception should not contain password
$plugin = new Swift_Plugins_PopBeforeSmtpPlugin('host', 110, 'tls');
// Trigger connection failure
$this->assertStringNotContainsString('mypassword', $exception->getMessage());
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| RedirectingPlugin Bcc leak via `X-Swift-Bcc` | **NOT FIXED** | `RedirectingPlugin.php:93`: Bcc stored in `X-Swift-Bcc` message header during transit |
| AllowlistPlugin Bcc leak via `X-Original-To` | **NOT FIXED** | `AllowlistPlugin.php:136-138`: all original recipients (including Bcc) concatenated into `X-Original-To` header |
| ImpersonatePlugin unconstrained spoofing | **NOT FIXED** | `ImpersonatePlugin.php:39-49`: no validation on sender string |
| EchoLogger XSS in non-HTML mode | **NOT FIXED** | `EchoLogger.php:42`: `printf('%s%s', $entry, PHP_EOL)` -- unescaped output when `$isHtml=false` |
| EchoLogger XSS in HTML mode | **EXISTING** | `EchoLogger.php:40`: uses `htmlspecialchars()` when `$isHtml=true` |
| PopBeforeSmtpPlugin plaintext password | **NOT FIXED** | `PopBeforeSmtpPlugin.php:153`: `PASS` sent over unencrypted `fsockopen()` |
| PopBeforeSmtpPlugin password in exceptions | **NOT FIXED** | Password may appear in exception messages from `command()` |
| MessageLogger/SentMessagePlugin unbounded | **NOT FIXED** | No `$maxMessages` limit on stored messages |
| DecoratorPlugin template injection | **NOT FIXED** | No input sanitization on replacement values |
| RedirectingPlugin unanchored regex | **NOT FIXED** | `preg_match($pattern, $recipient)` with caller-supplied unanchored patterns |

**Overall Status:** NOT STARTED -- All plugin security issues remain unfixed. Bcc leakage, XSS in EchoLogger, and plaintext POP3 passwords are the highest-priority items.

## Risk After Mitigation

**Residual Risk:** LOW -- With Bcc privacy fix, XSS prevention, POP3 TLS enforcement, and storage limits, plugin-mediated attacks are prevented.
