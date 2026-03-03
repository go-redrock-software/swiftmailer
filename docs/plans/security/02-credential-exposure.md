# Threat 02: Credential Exposure in Logs, Errors, and Memory

**STRIDE Category:** Information Disclosure
**Severity:** CRITICAL
**Likelihood:** High
**CWE:** CWE-532 (Info Exposure Through Log Files), CWE-209 (Error Message Info Leak)

---

## Description

SMTP credentials, API keys, and OAuth tokens may be inadvertently exposed through log output, exception messages, `var_dump()`, serialization, or stack traces. The `#[SensitiveParameter]` attribute (PHP 8.2) is used on some constructors but not consistently, and the `LoggerPlugin` logs SMTP commands including authentication exchanges.

## Attack Vectors

1. **Log file exposure** — `LoggerPlugin` logs SMTP AUTH commands containing base64-encoded credentials
2. **Exception messages** — Transport exceptions may include DSN strings with embedded credentials
3. **Stack traces** — `$apiKey` stored as a `public protected(set)` property on `AbstractHttpApiTransport`, visible in crash dumps
4. **Object inspection** — `var_dump()`, `print_r()`, or debugger inspection reveals credential properties
5. **Error handler output** — Unhandled exceptions displayed to end users in development mode
6. **DSN strings in config** — DSN format `smtp://user:password@host` means credentials appear in config files

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Plugins/LoggerPlugin.php` | Logs `>> AUTH` commands with base64 credentials |
| `lib/classes/Swift/Transport/Esmtp/AuthHandler.php` | Stores username/password in plain properties |
| `lib/classes/Swift/Transport/AbstractHttpApiTransport.php` | `$apiKey` is `public protected(set)` |
| `lib/classes/Swift/Transport/DsnTransportFactory.php` | Parses credentials from DSN, passes to constructors |
| `lib/classes/Swift/Dsn.php` | Stores user/password from DSN |
| `lib/classes/Swift/Transport/Esmtp/Auth/PlainAuthenticator.php` | Sends base64 credentials |
| `lib/classes/Swift/Transport/Esmtp/Auth/LoginAuthenticator.php` | Sends base64 credentials |

## Existing Controls

- `#[SensitiveParameter]` on `AbstractHttpApiTransport::__construct($apiKey)` (hides from stack traces)
- `#[SensitiveParameter]` on `Swift_Webhook_RequestHandler::handle($secret)` and `AbstractPayloadConverter::verifyHmac($secret)`
- `__sleep()` throws `BadMethodCallException` on transports (prevents serialization)
- Readonly `Swift_Dsn` class limits mutation

## Control Gaps

1. **LoggerPlugin logs AUTH commands** — base64-encoded credentials are logged verbatim when `commandSent()` fires
2. **No `#[SensitiveParameter]`** on SMTP `AuthHandler` constructor or `setPassword()`/`setUsername()`
3. **API key is `public` readable** — `$transport->apiKey` exposes the key to any code holding a transport reference
4. **Exception message leakage** — `TransportException` messages include raw error strings from providers that may echo back auth tokens
5. **No `__debugInfo()`** implementation — `var_dump($transport)` exposes all properties including credentials
6. **DSN password getter** — `Swift_Dsn::getPassword()` returns the raw password

## Mitigation Plan

### Phase 1: Log Sanitization (Immediate)
- Modify `LoggerPlugin::commandSent()` to redact lines matching AUTH patterns:
  ```php
  if (preg_match('/^AUTH\s/i', $command) || $this->inAuthSequence) {
      $command = '>> AUTH [REDACTED]';
  }
  ```
- Add a `SensitiveLogFilter` that strips base64-encoded credential patterns

### Phase 2: Attribute Coverage (Short-term)
- Add `#[SensitiveParameter]` to:
  - `AuthHandler::setPassword(string $password)`
  - `AuthHandler::setUsername(string $username)`
  - All authenticator `authenticate()` method parameters
  - `DsnTransportFactory::createTransport()` internal credential handling
- Implement `__debugInfo()` on transport classes to hide sensitive fields:
  ```php
  public function __debugInfo(): array {
      return ['apiKey' => '[REDACTED]', ...];
  }
  ```

### Phase 3: Property Visibility (Medium-term)
- Change `AbstractHttpApiTransport::$apiKey` from `public protected(set)` to `private` with no getter (or a redacted getter)
- Consider storing credentials in a `Credentials` value object that implements `__debugInfo()` and `__toString()` with redaction
- Add error message sanitization in `throwException()` to strip patterns matching API keys

### Phase 4: Documentation
- Document that DSN strings should use environment variables, not hardcoded values
- Warn against logging transports at DEBUG level in production
- Provide example of safe logging configuration

## Test Cases

```php
// LoggerPlugin should not log raw AUTH credentials
$logger = new Swift_Plugins_ArrayLogger();
$plugin = new Swift_Plugins_LoggerPlugin($logger);
// ... trigger AUTH sequence ...
$dump = $logger->dump();
$this->assertStringNotContainsString(base64_encode('password'), $dump);

// __debugInfo should redact sensitive fields
$transport = new Swift_Transport_Api_SendgridTransport('sk-test-key-123');
$info = print_r($transport, true);
$this->assertStringNotContainsString('sk-test-key-123', $info);
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| `#[SensitiveParameter]` on API key constructors | **IMPLEMENTED** | `lib/classes/Swift/Transport/AbstractHttpApiTransport.php:32`, plus per-transport constructors (MailGun:27, Mailtrap:27, InfoBip:25, Postal:25, Azure:30, Scaleway:19, MailJet:25-26) |
| `#[SensitiveParameter]` on webhook secrets | **IMPLEMENTED** | `lib/classes/Swift/Webhook/RequestHandler.php:40`, `AbstractPayloadConverter.php:21` |
| `__sleep()`/`__wakeup()` on transports | **IMPLEMENTED** | `AbstractSmtpTransport.php:581-586`, `AbstractApiTransport.php:95-100` |
| Readonly `Swift_Dsn` class | **IMPLEMENTED** | `Swift_Dsn` is readonly |
| LoggerPlugin AUTH command redaction | **PENDING** | `LoggerPlugin::commandSent()` at line 64 still logs commands verbatim; no AUTH filtering |
| `#[SensitiveParameter]` on AuthHandler | **PENDING** | No `#[SensitiveParameter]` on `setPassword()`/`setUsername()` in AuthHandler |
| `__debugInfo()` on transport classes | **PENDING** | No `__debugInfo()` found on any transport class |
| API key visibility change | **PENDING** | `$apiKey` remains `public protected(set)` on `AbstractHttpApiTransport` |
| Exception message sanitization | **PENDING** | No credential redaction in `TransportException` messages |

**Overall Status:** PARTIALLY IMPLEMENTED -- PHP 8.2 `#[SensitiveParameter]` and serialization prevention are in place. Log redaction, `__debugInfo()`, and property visibility changes are pending.

## Risk After Mitigation

**Residual Risk:** LOW — With log redaction, `__debugInfo()`, and `#[SensitiveParameter]` coverage, credential exposure requires deliberate circumvention.
