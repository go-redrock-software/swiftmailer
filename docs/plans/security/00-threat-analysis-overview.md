# Swiftmailer Threat Analysis Overview

**Date:** 2026-03-02
**Package:** swiftmailer/swiftmailer (Redrock Software Corporation fork)
**Analysis Framework:** STRIDE + OWASP
**PHP Version:** 8.2+

---

## Asset Identification

| Asset | Value | Access |
|-|-|-|
| SMTP credentials (username/password) | Critical | Transport layer, DSN parser |
| API keys (SendGrid, Mailgun, etc.) | Critical | AbstractHttpApiTransport, DSN parser |
| DKIM/S-MIME private keys | Critical | DKIMSigner, SMimeSigner |
| Webhook signing secrets | High | RequestHandler, PayloadConverters |
| Email message content (PII, PHI, financial) | High | Swift_Message, transports, spools |
| Recipient lists (To/Cc/Bcc) | High | Envelope, message headers |
| SMTP session data | Medium | StreamBuffer, AbstractSmtpTransport |
| Server hostnames/IPs | Medium | DSN, transport config |
| Log output | Medium | LoggerPlugin, ArrayLogger |

---

## Threat Summary Table

| # | Threat | STRIDE | Severity | Likelihood | Plan File |
|-|-|-|-|-|-|
| 1 | SMTP Header Injection | T, I | HIGH | Medium | `01-header-injection.md` |
| 2 | Credential Exposure in Logs/Errors | I | CRITICAL | High | `02-credential-exposure.md` |
| 3 | Sendmail Command Injection | T, E | HIGH | Low | `03-sendmail-command-injection.md` |
| 4 | TLS Downgrade / Man-in-the-Middle | T, I | HIGH | Medium | `04-tls-downgrade-mitm.md` |
| 5 | Insecure Deserialization (FileSpool) | T, E | CRITICAL | Medium | `05-insecure-deserialization.md` |
| 6 | Webhook Signature Bypass | S, T | HIGH | Medium | `06-webhook-signature-bypass.md` |
| 7 | Attachment Filename Injection | T, I | MEDIUM | Medium | `07-attachment-filename-injection.md` |
| 8 | Weak Authentication Mechanisms | S, I | MEDIUM | Medium | `08-weak-authentication.md` |
| 9 | API Transport SSRF | S, T | MEDIUM | Low | `09-api-ssrf.md` |
| 10 | Denial of Service via Resource Exhaustion | D | MEDIUM | Medium | `10-denial-of-service.md` |
| 11 | Information Disclosure in Error Messages | I | MEDIUM | High | `11-information-disclosure.md` |
| 12 | Supply Chain / Dependency Risk | T, E | MEDIUM | Low | `12-supply-chain-risk.md` |
| 13 | Cryptographic Signing (DKIM/DomainKey/S-MIME) | T, I | HIGH | Medium | `13-cryptographic-signing.md` |
| 14 | NTLM Implementation Vulnerabilities | S, I, E | HIGH | Medium | `14-ntlm-implementation.md` |
| 15 | Event System Abuse & Plugin-Mediated Attacks | T, I, D | MEDIUM | Medium | `15-event-system-abuse.md` |
| 16 | Path Traversal in Cache & Stream Buffers | T, I | HIGH | Low | `16-path-traversal-cache.md` |
| 17 | Plugin-Specific Security Issues | I, T, S | MEDIUM-HIGH | Medium | `17-plugin-security.md` |
| 18 | Email Address Validation Gaps | T, I | MEDIUM | Medium | `18-email-validation-gaps.md` |
| 19 | Content Encoding Bypass & MIME Attacks | T | MEDIUM | Low | `19-encoding-bypass.md` |
| 20 | Core Mailer Flow & Recipient Integrity | T, I | MEDIUM | Low | `20-core-mailer-flow.md` |
| 21 | CLI Credential Exposure & Terminal Injection | I, T | MEDIUM | Medium | `21-cli-credential-exposure.md` |
| 22 | Failover Transport Security Downgrade | T, I | MEDIUM | Medium | `22-failover-security-policy.md` |

---

## Risk Score Summary

**Overall Risk Score: 74/100 (HIGH)**

```
| Severity | Count | Threats |
|-|-|-|
| CRITICAL | 2 | Credential Exposure (#2), Insecure Deserialization (#5) |
| HIGH | 7 | Header Injection (#1), Sendmail Cmd Injection (#3), TLS Downgrade (#4), |
|          |   | Webhook Bypass (#6), Crypto Signing (#13), NTLM (#14), Path Traversal (#16) |
| MEDIUM   | 13 | Attachment Filenames (#7), Weak Auth (#8), SSRF (#9), DoS (#10), |
|          |    | Info Disclosure (#11), Supply Chain (#12), Event System (#15), |
|          |    | Plugin Security (#17), Email Validation (#18), Encoding (#19), |
|          |    | Core Mailer Flow (#20), CLI Exposure (#21), Failover Downgrade (#22) |
| LOW | 0 | — |
```

---

## Existing Security Controls

### Strengths
- `#[SensitiveParameter]` on API key constructors (PHP 8.2+)
- `escapeshellarg()` used for sendmail -f flag
- RFC 2822 email validation via `egulias/email-validator`
- HMAC signature verification with `hash_equals()` (timing-safe) in webhooks
- `__sleep()`/`__wakeup()` throw exceptions on transports (prevents serialization)
- DKIM oversigning prevents replay attacks
- TLS 1.2/1.3 enforcement in StreamBuffer
- Readonly `Swift_Dsn` and `Swift_Envelope` classes

### Gaps
- No CRLF injection prevention in raw header values
- `FileSpool` uses `serialize()`/`unserialize()` on untrusted data
- Webhook signature verification is optional (null secret skips it)
- No attachment filename sanitization
- API keys exposed as public properties on transport objects
- No message size limits anywhere in the pipeline
- CRAM-MD5 uses cryptographically broken MD5
- Error messages can leak credential fragments
- No audit logging for security events
- `verify_peer=false` easily set via DSN with no warning
- `DomainKeySigner` hardcodes SHA-1 (broken algorithm)
- DKIM oversigning disabled by default, body length limit enables content injection
- NTLMv1 code paths still present and callable, no Type 2 message bounds checking
- `DiskKeyCache` path traversal via unsanitized `$nsKey`/`$itemKey`
- Event system allows silent send cancellation and recipient redirection via mutable events
- Plugins leak Bcc recipients via `X-Swift-*` headers during redirect
- `Utf8AddressEncoder` performs zero validation (returns input verbatim)
- `RawContentEncoder`/`NullContentEncoder` bypass encoding (MIME boundary injection risk)
- `createMessage()` and `Preferences::setCacheType()` allow container lookup injection
- S/MIME signer has no certificate validation (expiry, revocation, key strength)

---

## Recommended Priority Order

1. **Insecure Deserialization (#5)** — Direct RCE vector via FileSpool
2. **Sendmail Command Injection (#3)** — RCE via DSN command parameter
3. **Credential Exposure (#2)** — API keys/passwords in logs, errors, var_dump
4. **TLS Downgrade (#4)** — verify_peer=false too easy to set, no auto-TLS
5. **Webhook Signature Bypass (#6)** — Forged delivery events (Amazon SES fake, Mailjet always-true)
6. **Header Injection (#1)** — Classic email security vulnerability
7. **Cryptographic Signing (#13)** — DomainKey SHA-1, DKIM body length injection, S/MIME gaps
8. **NTLM Implementation (#14)** — NTLMv1 removal, bounds checking, debug credential leak
9. **Path Traversal (#16)** — DiskKeyCache arbitrary file read/write/delete
10. **Plugin Security (#17)** — Bcc leakage, EchoLogger XSS, PopBeforeSmtp plaintext
11. **Email Validation (#18)** — Utf8AddressEncoder passthrough, IDN corruption
12. **Event System (#15)** — Silent send cancellation, recipient redirection
13. **Remaining MEDIUM threats (#7-12, #19-20)** — Address in order of deployment context

---

## Compliance Considerations

- **OWASP Top 10 2021**: A01 (Broken Access Control), A02 (Cryptographic Failures), A03 (Injection), A08 (Software Integrity)
- **CWE**: CWE-93 (CRLF Injection), CWE-502 (Deserialization), CWE-78 (OS Command Injection), CWE-319 (Cleartext Transmission), CWE-532 (Info Exposure Through Log Files), CWE-327 (Broken Crypto), CWE-22 (Path Traversal), CWE-125 (Out-of-bounds Read), CWE-918 (SSRF), CWE-20 (Improper Input Validation)
- **GDPR**: Email content often contains personal data; credential and message security directly impact compliance

---

## Incident Response

If any of these threats materialize:

1. **Severity mapping**: CRITICAL = P1 (1hr response), HIGH = P2 (4hr), MEDIUM = P3 (24hr)
2. **Containment**: Disable affected transport immediately; rotate compromised credentials
3. **Notification**: Inform downstream consumers of any credential compromise
4. **Recovery**: Patch, upgrade, and revalidate all active transport configurations

---

## Go/No-Go Recommendation

**Conditional Go** — The package is suitable for production use with the following mandatory mitigations:

1. Replace `FileSpool` deserialization with a safe format (JSON or `allowed_classes`)
2. Validate/sanitize DSN sendmail `command` parameter against an allowlist
3. Audit all logging paths for credential leakage; redact AUTH commands
4. Enforce `verify_peer=true` as default with explicit opt-out documentation
5. Make webhook signature verification mandatory (no null-secret bypass)
6. Fix Amazon SES webhook verification (implement real SNS signature checking)
7. Fix Mailjet `verify()` (implement actual HMAC verification, not always-true)
8. Add CRLF injection tests and prevention in header construction
9. Remove DomainKeySigner SHA-1 hardcoding (or remove DomainKeySigner entirely)
10. Remove NTLMv1 code paths; add Type 2 message bounds checking
11. Sanitize DiskKeyCache keys against path traversal
12. Fix Bcc leakage in RedirectingPlugin/AllowlistPlugin
13. Add validation to `Utf8AddressEncoder` and handle `idn_to_ascii()` failure
