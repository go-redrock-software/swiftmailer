# Threat 11: Information Disclosure in Error Messages and Debug Output

**STRIDE Category:** Information Disclosure
**Severity:** MEDIUM
**Likelihood:** High
**CWE:** CWE-209 (Generation of Error Message Containing Sensitive Info)

---

## Description

Error messages, exception traces, and debug output from Swiftmailer can reveal sensitive information including server hostnames, internal IP addresses, software versions, email addresses, and partial credential data. This information aids reconnaissance for further attacks.

## Attack Vectors

1. **Exception messages with server info** — `TransportException` includes server hostname and response codes
2. **SMTP server banners in logs** — Server greeting reveals software version (e.g., "220 mail.example.com ESMTP Postfix 3.7.1")
3. **Failed recipient lists** — `failedRecipients` array exposed in exceptions and events, revealing valid email addresses
4. **DSN scheme in error messages** — `Swift_Dsn::getTransportClass()` includes the full scheme name, revealing provider choice
5. **Stack traces** — Include file paths, class names, method arguments
6. **LoggerPlugin verbose output** — Logs SMTP dialogue including EHLO response (reveals server capabilities), RCPT responses, etc.
7. **`$_SERVER['SERVER_NAME']`** — Used in EHLO domain and Message-ID generation, leaks internal hostname

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Transport/AbstractSmtpTransport.php` | Server responses in exceptions |
| `lib/classes/Swift/Plugins/LoggerPlugin.php` | Verbose SMTP dialogue logging |
| `lib/dependency_maps/transport_deps.php:8` | `$_SERVER['SERVER_NAME']` in EHLO domain |
| `lib/dependency_maps/mime_deps.php:17` | `$_SERVER['SERVER_NAME']` in Message-ID right part |
| `lib/classes/Swift/Dsn.php:114-116` | Scheme name in error message |
| `lib/classes/Swift/Transport/StreamBuffer.php:310-327` | Connection description includes host/port |
| `lib/classes/Swift/Plugins/LoggerPlugin.php:126-134` | Exception message includes full log dump |

## Existing Controls

- Exceptions use Swiftmailer-specific exception classes (not raw strings)
- `#[SensitiveParameter]` on some constructor parameters
- `LoggerPlugin::exceptionThrown()` wraps the exception with log context

## Control Gaps

1. **No error message sanitization** — Raw SMTP server responses passed to exceptions
2. **No log level filtering** — All SMTP commands/responses logged at the same verbosity
3. **`$_SERVER['SERVER_NAME']` used without validation** — Internal hostname exposed in EHLO and Message-ID
4. **Failed recipient exposure** — `failedMessage` event includes recipient list in clear text
5. **`LoggerPlugin::exceptionThrown()`** — Appends full log dump to exception message (line 132: `$this->logger->dump()`)
6. **Provider API error messages** — Error responses from SendGrid, Mailgun, etc. may include account-specific info

## Mitigation Plan

### Phase 1: Error Message Sanitization (Immediate)
- Strip or truncate SMTP server responses in `TransportException` messages
- Limit DSN error messages to the scheme name, not the full DSN string
- Sanitize provider API error responses before including in exceptions

### Phase 2: Log Level Differentiation (Short-term)
- Add log levels to `LoggerPlugin`: INFO for connection events, DEBUG for SMTP commands
- Default to INFO level in production configurations
- Redact EHLO server capabilities at INFO level
- Provide a `ProductionLogger` preset that filters sensitive data

### Phase 3: EHLO Domain Hardening (Short-term)
- Improve `$_SERVER['SERVER_NAME']` validation in `transport_deps.php`:
  ```php
  // Current: uses SERVER_NAME directly, falls back to 127.0.0.1
  // Improved: validate against an allowlist or use a configured domain
  ```
- Allow explicit EHLO domain configuration via DSN parameter
- Use `localhost` or a non-revealing default instead of the real hostname

### Phase 4: Message-ID Privacy (Medium-term)
- Replace `$_SERVER['SERVER_NAME']` in Message-ID right part with a configurable domain or hash
- Ensure Message-IDs don't reveal internal infrastructure

### Phase 5: Documentation
- Document information disclosure risks in logging configuration
- Recommend production logging settings
- Document EHLO domain configuration for privacy

## Test Cases

```php
// Exception should not contain raw SMTP greeting
try {
    // Trigger connection to invalid server
} catch (Swift_TransportException $e) {
    $this->assertStringNotContainsString('Postfix', $e->getMessage());
}

// LoggerPlugin should support log levels
$logger = new Swift_Plugins_LoggerPlugin($inner, level: 'info');
// Trigger SMTP dialogue
$dump = $inner->dump();
$this->assertStringNotContainsString('EHLO', $dump);

// Message-ID should not reveal SERVER_NAME
$message = new Swift_Message('Test');
$messageId = $message->getId();
$this->assertStringNotContainsString($_SERVER['SERVER_NAME'] ?? '', $messageId);
```

## Risk After Mitigation

**Residual Risk:** LOW — With sanitized error messages, log level filtering, and hostname hardening, information leakage is limited to what is inherently necessary for SMTP protocol operation.
