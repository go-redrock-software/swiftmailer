# Threat 04: TLS Downgrade and Man-in-the-Middle Attacks

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** HIGH
**Likelihood:** Medium
**CWE:** CWE-319 (Cleartext Transmission), CWE-295 (Improper Certificate Validation)

---

## Description

SMTP transports can be configured to skip TLS certificate verification (`verify_peer=false`) via DSN parameters, or to use unencrypted connections by default. HTTP API transports rely entirely on Guzzle's default TLS settings with no certificate pinning. An attacker in a network position (e.g., corporate proxy, compromised DNS, BGP hijack) can intercept credentials and email content.

## Attack Vectors

1. **Explicit `verify_peer=false`** — DSN string `smtp+tls://user:pass@host?verify_peer=false` disables all cert validation
2. **Plain SMTP (no TLS)** — `smtp://user:pass@host:25` scheme uses no encryption by default
3. **STARTTLS stripping** — Active network attacker removes EHLO STARTTLS capability from server greeting
4. **Self-signed certificate acceptance** — With `verify_peer=false`, any certificate is accepted
5. **HTTP API MITM** — While endpoints use `https://`, Guzzle's default `verify` option could be overridden by a custom HttpClient
6. **DNS spoofing** — Attacker redirects API hostnames to a proxy that presents a valid cert for a different domain

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Transport/DsnTransportFactory.php:115-118` | `verify_peer=false` sets both `verify_peer` and `verify_peer_name` to false |
| `lib/classes/Swift/Transport/EsmtpTransport.php:360-378` | STARTTLS handshake, no mandatory enforcement |
| `lib/classes/Swift/Transport/StreamBuffer.php:89-93` | `stream_socket_enable_crypto()` with TLS 1.2/1.3 |
| `lib/classes/Swift/Transport/AbstractHttpApiTransport.php:31-38` | Guzzle client with no explicit verify config |
| All 21 API transports in `lib/classes/Swift/Transport/Api/` | Hardcoded `https://` endpoints but no cert pinning |

## Existing Controls

- TLS 1.2/1.3 enforced when encryption is enabled (`STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | TLSv1_3_CLIENT`)
- Peer fingerprint support via DSN `peer_fingerprint` parameter
- All HTTP API transport endpoints hardcoded with `https://`
- Guzzle 7+ defaults to `verify => true` (uses system CA bundle)

## Control Gaps

1. **No default TLS enforcement for SMTP** — `smtp://` scheme creates an unencrypted transport
2. **`verify_peer=false` has no warning** — Trivially set in DSN with no deprecation notice or log warning
3. **No STARTTLS requirement option** — No `require_tls` parameter to fail if STARTTLS is not offered
4. **No certificate pinning for HTTP APIs** — Relies entirely on system CA store
5. **Auto-TLS not yet implemented** — Plan exists (`docs/plans/2026-02-26-auto-tls.md`) but not shipped
6. **No DANE/TLSA verification** — No DNS-based certificate validation

## Mitigation Plan

### Phase 1: Warnings and Defaults (Immediate)
- Log a warning when `verify_peer=false` is set:
  ```php
  if (isset($params['verify_peer']) && !$val) {
      trigger_error('Swiftmailer: verify_peer=false disables TLS certificate verification. This is insecure.', E_USER_WARNING);
  }
  ```
- Add `verify_peer=true` and `verify_peer_name=true` as default SSL context options for SMTP transports

### Phase 2: Auto-TLS Implementation (Short-term)
- Implement the existing auto-TLS plan (`docs/plans/2026-02-26-auto-tls.md`):
  - Opportunistic STARTTLS when server advertises it
  - `auto_tls=true` by default on `EsmtpTransport`
  - `require_tls=true` option to mandate STARTTLS or fail

### Phase 3: HTTP API Hardening (Medium-term)
- Pass explicit `verify => true` in all Guzzle requests in `AbstractHttpApiTransport`
- Add option for custom CA bundle path: `$transport->setCaBundle('/path/to/ca.pem')`
- Consider certificate pinning for known API endpoints (Guzzle middleware)

### Phase 4: Documentation
- Document TLS best practices: always use `smtp+tls://` or `smtp+ssl://`
- Deprecate plain `smtp://` scheme for production use
- Document that `verify_peer=false` should ONLY be used for local development/testing
- Add security checklist for production deployments

## Test Cases

```php
// verify_peer=false should trigger warning
$factory = new Swift_Transport_DsnTransportFactory();
$this->expectWarning();
$factory->fromDsnString('smtp+tls://user:pass@host?verify_peer=false');

// Default transport should have verify_peer=true
$transport = $factory->fromDsnString('smtp+tls://user:pass@host');
$options = $transport->getStreamOptions();
$this->assertTrue($options['ssl']['verify_peer'] ?? true);

// HTTP API transports should pass verify=true to Guzzle
// (mock Guzzle client and verify options)
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| TLS 1.2/1.3 enforcement | **IMPLEMENTED** | `StreamBuffer.php:89-92`: `STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT \| TLSv1_3_CLIENT` |
| `verify_peer` DSN parsing | **IMPLEMENTED** | `DsnTransportFactory.php:115-118`: parses and applies `verify_peer` |
| Peer fingerprint support | **IMPLEMENTED** | DSN `peer_fingerprint` parameter supported |
| HTTPS for all API endpoints | **IMPLEMENTED** | All 21 API transports use hardcoded `https://` URLs |
| Guzzle defaults to `verify => true` | **IMPLEMENTED** | Guzzle 7+ default behavior |
| Warning when `verify_peer=false` set | **PENDING** | No `trigger_error()` or log warning when `verify_peer=false` is used |
| Auto-TLS implementation | **PENDING** | Plan exists at `docs/plans/2026-02-26-auto-tls.md` but not shipped |
| `require_tls` parameter | **PENDING** | No STARTTLS requirement option |
| Explicit `verify => true` in API transports | **PENDING** | `AbstractHttpApiTransport` does not explicitly set `verify` on Guzzle requests |

**Overall Status:** PARTIALLY IMPLEMENTED -- TLS 1.2/1.3 is enforced when enabled, and API endpoints use HTTPS. However, no warning for `verify_peer=false`, no auto-TLS, and no STARTTLS requirement.

## Risk After Mitigation

**Residual Risk:** LOW-MEDIUM — With default `verify_peer=true`, auto-TLS, and warnings, the attack surface is limited to deliberate misconfiguration.
