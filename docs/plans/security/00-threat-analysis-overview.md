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

| # | Threat | STRIDE | Severity | Likelihood | Implementation Status | Plan File |
|-|-|-|-|-|-|-|
| 1 | SMTP Header Injection | T, I | HIGH | Medium | NOT STARTED | `01-header-injection.md` |
| 2 | Credential Exposure in Logs/Errors | I | CRITICAL | High | PARTIAL | `02-credential-exposure.md` |
| 3 | Sendmail Command Injection | T, E | HIGH | Low | PARTIAL | `03-sendmail-command-injection.md` |
| 4 | TLS Downgrade / Man-in-the-Middle | T, I | HIGH | Medium | PARTIAL | `04-tls-downgrade-mitm.md` |
| 5 | Insecure Deserialization (FileSpool) | T, E | CRITICAL | Medium | NOT STARTED | `05-insecure-deserialization.md` |
| 6 | Webhook Signature Bypass | S, T | HIGH | Medium | PARTIAL | `06-webhook-signature-bypass.md` |
| 7 | Attachment Filename Injection | T, I | MEDIUM | Medium | NOT STARTED | `07-attachment-filename-injection.md` |
| 8 | Weak Authentication Mechanisms | S, I | MEDIUM | Medium | NOT STARTED | `08-weak-authentication.md` |
| 9 | API Transport SSRF | S, T | MEDIUM | Low | NOT STARTED | `09-api-ssrf.md` |
| 10 | Denial of Service via Resource Exhaustion | D | MEDIUM | Medium | NOT STARTED | `10-denial-of-service.md` |
| 11 | Information Disclosure in Error Messages | I | MEDIUM | High | PARTIAL | `11-information-disclosure.md` |
| 12 | Supply Chain / Dependency Risk | T, E | MEDIUM | Low | PARTIAL | `12-supply-chain-risk.md` |
| 13 | Cryptographic Signing (DKIM/DomainKey/S-MIME) | T, I | HIGH | Medium | PARTIAL | `13-cryptographic-signing.md` |
| 14 | NTLM Implementation Vulnerabilities | S, I, E | HIGH | Medium | NOT STARTED | `14-ntlm-implementation.md` |
| 15 | Event System Abuse & Plugin-Mediated Attacks | T, I, D | MEDIUM | Medium | NOT STARTED | `15-event-system-abuse.md` |
| 16 | Path Traversal in Cache & Stream Buffers | T, I | HIGH | Low | NOT STARTED | `16-path-traversal-cache.md` |
| 17 | Plugin-Specific Security Issues | I, T, S | MEDIUM-HIGH | Medium | NOT STARTED | `17-plugin-security.md` |
| 18 | Email Address Validation Gaps | T, I | MEDIUM | Medium | NOT STARTED | `18-email-validation-gaps.md` |
| 19 | Content Encoding Bypass & MIME Attacks | T | MEDIUM | Low | NOT STARTED | `19-encoding-bypass.md` |
| 20 | Core Mailer Flow & Recipient Integrity | T, I | MEDIUM | Low | NOT STARTED | `20-core-mailer-flow.md` |
| 21 | CLI Credential Exposure & Terminal Injection | I, T | MEDIUM | Medium | NOT STARTED | `21-cli-credential-exposure.md` |
| 22 | Failover Transport Security Downgrade | T, I | MEDIUM | Medium | NOT STARTED | `22-failover-security-policy.md` |
| 23 | ReDoS via PHRASE_PATTERN in Header Parsing | D | MEDIUM | Medium | NOT STARTED | `23-redos-header-parsing.md` |
| 24 | Unbounded API Response Body Consumption | D | MEDIUM | Medium | NOT STARTED | `24-unbounded-api-response.md` |
| 25 | DSN Parameter Injection Disables TLS | T | HIGH | Medium | NOT STARTED | `25-dsn-parameter-injection.md` |
| 26 | Retry Transport Amplification | D | MEDIUM | Low | NOT STARTED | `26-retry-transport-amplification.md` |

---

## Implementation Progress (2026-03-02)

**Overall: 0 of 26 threats fully mitigated. 7 partially addressed. 19 not started.**

| Status | Count | Threats |
|-|-|-|
| PARTIAL | 7 | #2 (Credential Exposure), #3 (Sendmail), #4 (TLS), #6 (Webhook), #11 (Info Disclosure), #12 (Supply Chain), #13 (Crypto Signing) |
| NOT STARTED | 19 | #1, #5, #7, #8, #9, #10, #14, #15, #16, #17, #18, #19, #20, #21, #22, #23, #24, #25, #26 |
| COMPLETE | 0 | -- |

### Key Findings

1. **CRITICAL: FileSpool deserialization (#5) is completely unmitigated.** `unserialize()` at `FileSpool.php:166` has no `allowed_classes` restriction -- direct RCE vector.
2. **CRITICAL: Credential exposure (#2) is only partially addressed.** `#[SensitiveParameter]` on constructors, but LoggerPlugin still logs AUTH commands verbatim and no `__debugInfo()` exists.
3. **HIGH: NTLM (#14) has zero mitigations.** NTLMv1 code paths, unbounded Type 2 parsing, and the `debug()` credential leak all remain.
4. **HIGH: DomainKeySigner (#13) still hardcodes SHA-1** and ignores `setHashAlgorithm()` argument. DKIM oversigning is off by default.
5. **HIGH: Webhook (#6) null-secret bypass remains.** Mailjet `verify()` always returns `true`. Amazon SES only checks header existence.
6. **Positive: `roave/security-advisories` installed.** `#[SensitiveParameter]` on API transports. TLS 1.2/1.3 enforced when encryption enabled. `escapeshellarg()` on sendmail `-f` flag.

---

## Risk Score Summary

**Overall Risk Score: 74/100 (HIGH)**

```
| Severity | Count | Threats |
|-|-|-|
| CRITICAL | 2 | Credential Exposure (#2), Insecure Deserialization (#5) |
| HIGH | 8 | Header Injection (#1), Sendmail Cmd Injection (#3), TLS Downgrade (#4), |
|          |   | Webhook Bypass (#6), Crypto Signing (#13), NTLM (#14), Path Traversal (#16), |
|          |   | DSN Parameter Injection (#25) |
| MEDIUM   | 16 | Attachment Filenames (#7), Weak Auth (#8), SSRF (#9), DoS (#10), |
|          |    | Info Disclosure (#11), Supply Chain (#12), Event System (#15), |
|          |    | Plugin Security (#17), Email Validation (#18), Encoding (#19), |
|          |    | Core Mailer Flow (#20), CLI Exposure (#21), Failover Downgrade (#22), |
|          |    | ReDoS Header Parsing (#23), API Response DoS (#24), Retry Amplification (#26) |
| LOW | 0 | — |
```

---

## Existing Security Controls

### Strengths (Verified 2026-03-02)
- `#[SensitiveParameter]` on API key constructors (PHP 8.2+) -- verified on `AbstractHttpApiTransport`, MailGun, Mailtrap, InfoBip, Postal, Azure, Scaleway, MailJet
- `escapeshellarg()` used for sendmail `-f` flag -- verified at `SendmailTransport.php:127`
- RFC 2822 email validation via `egulias/email-validator` -- verified in `MailboxHeader::assertValidAddress()`
- HMAC signature verification with `hash_equals()` (timing-safe) in webhooks -- verified in `AbstractPayloadConverter::verifyHmac()`
- `__sleep()`/`__wakeup()` throw exceptions on transports (prevents serialization) -- verified on `AbstractSmtpTransport` and `AbstractApiTransport`
- ~~DKIM oversigning prevents replay attacks~~ **CORRECTION: oversigning is disabled by default** (`DKIMSigner.php:72: $oversigning = false`)
- TLS 1.2/1.3 enforcement in StreamBuffer -- verified at `StreamBuffer.php:89-92`
- Readonly `Swift_Dsn` and `Swift_Envelope` classes -- verified
- `roave/security-advisories` installed as dev dependency -- verified at `composer.json:51`
- `$_SERVER['SERVER_NAME']` validated with regex before use in EHLO and Message-ID -- verified in `transport_deps.php:5-8` and `mime_deps.php:14-17`

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
- `PHRASE_PATTERN` regex in `AbstractHeader` is vulnerable to catastrophic backtracking (ReDoS)
- API transports read entire response body into memory with no size limit (OOM vector)
- DSN `verify_peer=false` silently disables TLS certificate verification with no warning
- DSN `retries`/`retry_delay` parameters have no upper bound (thread blocking DoS)

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
13. **DSN Parameter Injection (#25)** — Silent TLS disable via verify_peer=false
14. **Retry Amplification (#26)** — Uncapped retry/delay DoS via DSN params
15. **ReDoS Header Parsing (#23)** — Catastrophic backtracking in PHRASE_PATTERN
16. **API Response DoS (#24)** — Unbounded response body memory consumption
17. **Remaining MEDIUM threats (#7-12, #19-20)** — Address in order of deployment context

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
14. Log warning or block `verify_peer=false` in DSN strings (#25)
15. Cap retry count and delay in DSN factory and RetryTransport (#26)
16. Add response body size limits to all API transports (#24)
17. Add input length pre-validation before PHRASE_PATTERN regex (#23)
