# Symfony Mailer Feature Parity Design

## Goal

Bring Swiftmailer to feature parity with Symfony Mailer while preserving the `Swift_` PSR-0 class naming, zero breaking changes to existing APIs, and keeping the library framework-agnostic.

Two separate branches:
- **Feature parity** — all new capabilities
- **Security hardening** — separate PR

---

## 1. SentMessage (Event-Based)

`send()` keeps returning `int`. No signature changes.

New class `Swift_SentMessage`:
- `getOriginalMessage(): Swift_Mime_SimpleMessage`
- `getMessageId(): ?string` (provider-assigned ID)
- `getTransport(): Swift_Transport`
- `getRecipientCount(): int`
- `getDebug(): array` (raw API response, HTTP status)
- `getFailedRecipients(): array`

Accessible through the event system only. Transports populate it internally and attach it to `SentMessageEvent`. A convenience plugin `Swift_Plugins_SentMessagePlugin` captures the last one for easy access.

## 2. Post-Send Events

Two new event classes alongside existing `SendEvent` (not replacing it):

**`Swift_Events_SentMessageEvent`** — dispatched on success
- `getSentMessage(): Swift_SentMessage`
- `getTransport(): Swift_Transport`

**`Swift_Events_FailedMessageEvent`** — dispatched on failure
- `getMessage(): Swift_Mime_SimpleMessage`
- `getException(): Swift_TransportException`
- `getFailedRecipients(): array`
- `getTransport(): Swift_Transport`

New listener interfaces:
- `Swift_Events_SentMessageListener` (method: `sentMessage()`)
- `Swift_Events_FailedMessageListener` (method: `failedMessage()`)

`SimpleEventDispatcher` gets factory methods for both. `AbstractHttpApiTransport::send()` dispatches both in addition to existing `sendPerformed`. SMTP transports get the same treatment. `LoggerPlugin` updated to listen to both.

## 3. Tagging & Metadata

Header-based convention, no changes to `Swift_Message`:

```php
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'password-reset');
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
```

Each API transport reads these in `doSend()`, maps to provider-native format, strips from outgoing message.

`AbstractHttpApiTransport` gets helper methods:
- `extractTags(Swift_Mime_SimpleMessage $message): array`
- `extractMetadata(Swift_Mime_SimpleMessage $message): array`

## 4. DSN Enhancements

### 4a. NullTransport DSN
Add `'null' => Swift_Transport_NullTransport::class` to the map.

### 4b. Meta-transport DSN syntax
```
failover(sendgrid://KEY@default postmark://KEY@default)
roundrobin(dsn1 dsn2)
```

New `Swift_Transport_DsnTransportFactory` handles construction logic:
- Detects wrapper syntax
- Recursively parses inner DSNs
- Constructs child transports
- Wraps in `FailoverTransport` or `LoadBalancedTransport`

`Swift_Dsn` gains static `fromString(string $dsn): self` factory.

### 4c. TLS & connection parameters
SMTP DSN parameters: `verify_peer`, `auto_tls`, `require_tls`, `peer_fingerprint`, `source_ip`, `ping_threshold`.

Mapped to `EsmtpTransport` stream options during construction.

### 4d. SMTP scheme entries
Add `smtp`, `smtp+tls`, `smtp+ssl` to DSN map pointing to `EsmtpTransport`.

## 5. Missing Transport Providers

Five new transports extending `AbstractHttpApiTransport`:

| Provider | Scheme(s) | Auth | Notes |
|-|-|-|-|
| AhaSend | `ahasend` | API key | Simple REST API |
| Mailomat | `mailomat` | API key | Austrian provider |
| Mailtrap | `mailtrap`, `mailtrap+sandbox` | API key | Separate live/sandbox endpoints |
| Postal | `postal` | API key + server URL | Self-hosted, host from DSN |
| Sweego | `sweego` | API key | French provider |

Each gets tag/metadata support and unit tests.

## 6. CSS Inlining

New plugin `Swift_Plugins_CssInlinerPlugin`:
- Listens to `beforeSendPerformed`
- Parses HTML body for `<style>` blocks
- Inlines CSS rules as style attributes on elements
- Uses a lightweight CSS-to-inline library (e.g. `tijsverkoyen/css-to-inline-styles` — already commonly used in PHP)
- Optional: added as a `suggest` in composer.json, plugin gracefully degrades if library not installed

## 7. Security Hardening (Separate Branch)

### 7a. Symfony Mailer's security improvements
- TLS controls: `auto_tls` default on, `require_tls` option, `verify_peer` defaults to true
- Header injection prevention: validate header values for CR/LF
- Address validation: stricter input validation using `egulias/email-validator`

### 7b. Full audit scope
- API transports: ensure API keys never appear in exception messages, log output, or serialized state
- DKIM/S/MIME: review against current best practices
- Serialization: verify `__sleep`/`__wakeup` throw on all transports holding secrets
- Stream options: verify TLS defaults are secure (no SSLv3, proper cert verification)
- Exception messages: scrub sensitive data before throwing

---

## Non-Goals

- Symfony Messenger queue integration
- Twig templating
- Web Debug Toolbar / Profiler
- Framework-specific service container integration
- Changing `send()` return type
- Breaking existing plugin interfaces
