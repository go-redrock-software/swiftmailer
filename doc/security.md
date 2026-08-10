# Security

This fork of SwiftMailer ships a set of defensive features aimed at the ways a mail library is typically attacked: header injection, credential leakage, SSRF, insecure deserialization, TLS downgrade, and denial of service. Most protections are **on by default and automatic**; a few are **opt-in** because they need a key or a policy you have to supply.

## Quick reference

| Protection | Default | How to configure |
|-|-|-|
| Header CRLF-injection stripping | On, automatic | -- |
| Attachment filename sanitization | On, automatic | -- |
| Credential redaction in logs (`LoggerPlugin`) | On when plugin used | Register `Swift_Plugins_LoggerPlugin` |
| `#[\SensitiveParameter]` on keys/passwords | On, automatic | -- |
| Guzzle request/response error leakage | On, automatic (`http_errors => false`) | -- |
| API response body size cap (1 MiB) | On, automatic | -- |
| Sendmail metacharacter rejection | On, automatic | `setCommand()` |
| Sendmail binary allowlist (DSN) | On when using DSN factory | -- |
| DSN parameter validation | On, automatic | -- |
| API endpoint URL / SSRF validation | On for custom base URLs | -- |
| TLS certificate verification | On (PHP secure defaults) | `verify_peer`, `peer_fingerprint` |
| CRAM-MD5 deprecation + auth ordering | On, automatic | `setAuthMode()` to force one |
| NTLM: v2 only (v1 removed) | On, automatic | -- |
| Transport serialization blocked | On, automatic | -- |
| Unserialize class allowlist (spool) | On, automatic | -- |
| FileSpool HMAC integrity | Opt-in | Pass a signing key |
| DiskKeyCache path-traversal guard | On, automatic | -- |
| `Swift_MessageLimits` DoS validator | Opt-in | Call `->validate()` |
| Webhook signature verification | On (never skipped) | Supply the signing secret |
| Webhook IP allowlist | Opt-in | Pass `$allowedIps` + `$remoteIp` |

---

## Header injection

Email header injection happens when attacker-controlled input containing `\r`/`\n` is placed in a header, letting the attacker inject extra headers or a body. Both header **names** and **values** are stripped of line breaks:

- `Swift_Mime_Headers_UnstructuredHeader::setValue()` removes `\r` and `\n` from header values.
- `Swift_Mime_Headers_AbstractHeader::setFieldName()` removes `\r`, `\n`, and `\0` from header names.

This is automatic -- there is nothing to enable. Any CR/LF in a subject, custom header, etc. is dropped before rendering.

## Attachment filename sanitization

`Swift_Mime_Attachment::setFilename()` sanitizes the filename before it is placed in the `Content-Disposition`/`Content-Type` headers:

| Removed | Why |
|-|-|
| `/` and `\` | Path separators (directory traversal on save) |
| `\0` (null byte) | Null-byte truncation tricks |
| `\x00`--`\x1F`, `\x7F` | Control characters (header/terminal injection) |
| U+202A--U+202E, U+2066--U+2069 | Unicode bidirectional overrides (RTLO extension-spoofing, e.g. `photo‮gnp.exe`) |

The result is also truncated to 255 characters, preserving the extension. `setFile()` runs the source path through `basename()` first. All automatic.

## Credential protection

Three independent layers keep secrets out of logs, traces, and error output:

**1. SMTP AUTH redaction in the logger.** `Swift_Plugins_LoggerPlugin` recognizes the `AUTH` command and redacts it and the base64 credential exchange that follows:

```
>> AUTH [REDACTED]
>> [REDACTED]
```

Redaction continues until the next recognizable SMTP verb (`EHLO`, `HELO`, `MAIL`, `RCPT`, `DATA`, `QUIT`, `RSET`, `NOOP`, `STARTTLS`), so usernames/passwords never reach the log. Register it normally:

```php
$mailer->registerPlugin(new Swift_Plugins_LoggerPlugin(new Swift_Plugins_Loggers_EchoLogger()));
```

**2. `#[\SensitiveParameter]` on secrets.** API keys, DKIM passphrases, the FileSpool signing key, and the webhook secret are declared `#[\SensitiveParameter]`, so PHP replaces their values with `SensitiveParameterValue` in stack traces and `getTraceAsString()` output. For example, every HTTP API transport takes `#[\SensitiveParameter] string $apiKey`.

**3. Guzzle error leakage prevention.** HTTP API transports issue Guzzle requests with `'http_errors' => false`. That stops Guzzle from throwing a `RequestException` whose message embeds the full outbound request -- including the `Authorization` header that carries the API key. Transports inspect the status code themselves and raise a `Swift_TransportException` containing only the provider's error message, never the request.

## Plugin output escaping

Escaping in the debug/reporting plugins is **not uniform** -- know which output is safe to render in a browser:

| Plugin | Output escaping |
|-|-|
| `Swift_Plugins_Loggers_EchoLogger` | Escapes every entry with `htmlspecialchars()` (`ENT_QUOTES`) in both its HTML and plain paths. Safe to render. |
| `Swift_Plugins_ReadReceiptPlugin` | Escapes the tracking URL with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. |
| `Swift_Plugins_Reporters_HtmlReporter` | **Does not escape.** `notify()` echoes the recipient address directly (`echo 'PASS '.$address`). |

`HtmlReporter` is trusted-input only: do not render its output in a browser when recipient addresses can be attacker-controlled, or wrap the addresses yourself. (`Swift_Plugins_RedirectingPlugin` keeps original recipients in private properties and adds no `X-Swift-*` headers, so it does not leak them into the message.)

## Transport hardening

### Sendmail command validation and allowlist

`Swift_Transport_SendmailTransport::setCommand()` rejects any command containing shell metacharacters `; & | ` $ ( ) { }` with an `InvalidArgumentException`, blocking command injection. Only `-bs` and `-t` operating modes are accepted at send time; anything else throws a `Swift_TransportException`.

When a Sendmail transport is built from a DSN, the binary is additionally checked against an allowlist:

```
/usr/sbin/sendmail   /usr/lib/sendmail   /usr/bin/sendmail
/usr/local/sbin/sendmail   /usr/local/bin/sendmail
```

A binary outside this list throws `InvalidArgumentException`.

### DSN parameter validation

`Swift_Transport_DsnTransportFactory` only honours a fixed set of DSN query parameters: `verify_peer`, `peer_fingerprint`, `source_ip`, `smtputf8`, `command`, `retries`, `retry_delay`. Any unknown parameter is ignored and raises an `E_USER_WARNING` so typos and injection attempts surface instead of being silently applied.

### API endpoint URL validation (SSRF)

Transports that accept a **custom base URL** (`Swift_Transport_Api_InfoBipTransport`, `Swift_Transport_Api_PostalTransport`) validate it at construction via `Swift_Transport_UrlValidator::validate()`, which:

- requires the `https` scheme,
- blocks `127.0.0.1`, `0.0.0.0`, `localhost`, `::1`, `[::1]`,
- rejects any host that is -- or whose DNS name resolves to -- a private or reserved IP range.

This prevents a misconfigured or attacker-supplied endpoint from turning the mailer into an SSRF vector.

### API response body size cap

`Swift_Transport_AbstractHttpApiTransport::getResponseBody()` refuses to buffer a provider response larger than `MAX_RESPONSE_SIZE` = **1048576 bytes (1 MiB)**, throwing `Swift_TransportException`. Both the advertised size and the actual streamed length are checked, so a hostile or malfunctioning API cannot exhaust memory.

## TLS

SMTP+TLS connections use PHP's stream defaults, under which `verify_peer` and `verify_peer_name` are enabled -- certificate verification is **on by default**. The library never silently disables it.

Two DSN parameters let you tune TLS:

| Parameter | Effect |
|-|-|
| `verify_peer=false` | Disables certificate verification. Emits an `E_USER_WARNING`; use only for local development. |
| `peer_fingerprint=<hash>` | Pins the peer certificate fingerprint (certificate pinning). |

```php
$factory = new Swift_Transport_DsnTransportFactory();

// Pin the certificate fingerprint
$transport = $factory->fromDsnString('smtp+tls://user:pass@mail.example.com?peer_fingerprint=AB:CD:...');
```

Equivalently, on a transport built directly, pass stream context options via `setStreamOptions(['ssl' => ['verify_peer' => true, 'peer_fingerprint' => '...']])`.

## SMTP authentication

### CRAM-MD5 deprecated

`Swift_Transport_Esmtp_Auth_CramMd5Authenticator` still works but triggers `E_USER_DEPRECATED`, because CRAM-MD5 relies on the cryptographically weak MD5 algorithm. Prefer XOAUTH2, or PLAIN over TLS.

### Authentication mechanism ordering

When no explicit auth mode is set, `Swift_Transport_Esmtp_AuthHandler` tries the server-advertised mechanisms in a **security-ordered** preference (lower number tried first):

| Priority | Mechanism |
|-|-|
| 0 | XOAUTH2 |
| 1 | PLAIN |
| 2 | LOGIN |
| 3 | CRAM-MD5 |
| 4 | NTLM |

Stronger mechanisms are attempted before the weaker/legacy ones. Pin a single mechanism with `setAuthMode()` (an invalid mode throws `Swift_TransportException`).

### NTLM: v2 only

`Swift_Transport_Esmtp_Auth_NTLMAuthenticator` performs **NTLMv2 only** -- the weaker NTLMv1 response path has been removed. The Type-2 message parser enforces bounds checks (minimum length, offset/length within the buffer) and throws `Swift_TransportException` on malformed server responses rather than reading out of bounds.

## Serialization safety

### Transports cannot be serialized

Both transport base classes -- `Swift_Transport_AbstractSmtpTransport` and `Swift_Transport_AbstractApiTransport` -- override `__sleep()` and `__wakeup()` to throw `BadMethodCallException`. A transport holds live credentials and connection state; blocking serialization stops it from being written into a session, cache, or queue where the credentials could leak or be tampered with.

### Spooled-message unserialization is allowlisted

`Swift_FileSpool` unserializes queued messages with an explicit `allowed_classes` allowlist (`UNSERIALIZE_ALLOWED_CLASSES`) covering only the classes a legitimate `Swift_Message` contains. Arbitrary object instantiation (CWE-502) is blocked. Notably, `Swift_ByteStream_*` classes are **deliberately excluded** -- `TemporaryFileByteStream` has a `__destruct()` that deletes files, which would otherwise be an arbitrary-file-deletion gadget. A message that fails to unserialize or throws from `__wakeup()` is skipped so one poisoned file cannot crash the whole queue flush.

### FileSpool HMAC integrity (opt-in)

Give `Swift_FileSpool` a signing key and every queued file is protected with an HMAC-SHA256 tag; on flush the tag is re-checked with `hash_equals()` and any file that fails verification is skipped (not sent). This detects tampering with the on-disk spool.

```php
$spool = new Swift_FileSpool('/var/spool/swiftmailer', $signingKey); // key: #[\SensitiveParameter]
// or later:
$spool->setSigningKey($signingKey);
```

Without a key, messages are spooled unsigned (backward-compatible) but still unserialized through the class allowlist.

### Disk cache path-traversal guard

`Swift_KeyCache_DiskKeyCache` runs every namespace and item key through `sanitizeKey()`, which rejects an empty key or any key containing a character outside `[a-zA-Z0-9._-]` with `Swift_IoException`. This blocks `/`, `..`, and null bytes from steering cache writes outside the cache directory (cache directories are created mode `0700`).

## Denial-of-service limits (opt-in)

`Swift_MessageLimits` is an opt-in validator that rejects oversized or over-broad messages before they are sent. Call `validate()` and it throws `Swift_SwiftException` on the first limit exceeded.

| Property | Default (bytes) | Meaning |
|-|-|-|
| `maxBodySize` | `10485760` (10 MiB) | Maximum message body size |
| `maxAttachmentSize` | `26214400` (25 MiB) | Maximum size of any single attachment |
| `maxTotalSize` | `52428800` (50 MiB) | Maximum body + attachments combined |
| `maxAttachmentCount` | `50` | Maximum number of attachments/images |
| `maxRecipientCount` | `1000` | Maximum To + Cc + Bcc recipients |

```php
$limits = new Swift_MessageLimits();
$limits->maxRecipientCount = 200;      // tighten any limit you like
$limits->validate($message);           // throws Swift_SwiftException if exceeded
$mailer->send($message);
```

Because it is opt-in, wire the `validate()` call into your own send path (e.g. a wrapper or a plugin) where you want the ceiling enforced.

## Webhooks

`Swift_Webhook_RequestHandler::handle()` **always** verifies the provider signature -- there is no bypass. An empty signing secret throws `InvalidArgumentException`; a bad signature throws `Swift_Webhook_SignatureVerificationException`. It also performs a replay-prevention timestamp check (default `maxAge` 300 seconds) for providers that expose a timestamp.

An **optional IP allowlist** adds a second gate: pass `$allowedIps` and `$remoteIp`, and a request whose source IP is not in the list is rejected before signature verification.

```php
$handler = new Swift_Webhook_RequestHandler();
$events = $handler->handle(
    $converter,
    $rawBody,
    $headers,
    $secret,                 // #[\SensitiveParameter]; must not be empty
    300,                     // maxAge seconds (0 disables the timestamp check)
    ['203.0.113.10'],        // optional IP allowlist
    $_SERVER['REMOTE_ADDR'], // the request's source IP
);
```

See [webhooks.md](webhooks.md) for the full webhook processing flow, provider converters, and event model.

## See also

- [Message Signing](signers.md) -- DKIM, DomainKeys, and S/MIME signing/encryption.
- [DSN Transport Factory](dsn.md) -- transport configuration and TLS parameters.
- [Webhook System](webhooks.md) -- inbound event processing and signature verification.
