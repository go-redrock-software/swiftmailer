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

> **Audit Status** (2026-03-03): All 26 threats verified against source code. 16 confirmed REAL,
> 10 found EXAGGERATED (code references accurate but severity/exploitability overstated), 0 fabricated.

| # | Threat | STRIDE | Severity | Likelihood | Audit | Implementation Status | Plan File |
|-|-|-|-|-|-|-|-|
| 1 | SMTP Header Injection | T, I | LOW | Low | EXAGGERATED | NOT STARTED | `01-header-injection.md` |
| 2 | Credential Exposure in Logs/Errors | I | CRITICAL | High | REAL | PARTIAL | `02-credential-exposure.md` |
| 3 | Sendmail Command Injection | T, E | HIGH | Low | REAL | PARTIAL | `03-sendmail-command-injection.md` |
| 4 | TLS Downgrade / Man-in-the-Middle | T, I | MEDIUM | Low | EXAGGERATED | PARTIAL | `04-tls-downgrade-mitm.md` |
| 5 | Insecure Deserialization (FileSpool) | T, E | CRITICAL | Medium | REAL | PARTIAL (Phase 1) | `05-insecure-deserialization.md` |
| 6 | Webhook Signature Bypass | S, T | HIGH | Medium | REAL | MOSTLY COMPLETE | `06-webhook-signature-bypass.md` |
| 7 | Attachment Filename Injection | T, I | MEDIUM | Medium | REAL | NOT STARTED | `07-attachment-filename-injection.md` |
| 8 | Weak Authentication Mechanisms | S, I | LOW | Low | EXAGGERATED | NOT STARTED | `08-weak-authentication.md` |
| 9 | API Transport SSRF | S, T | LOW | Low | EXAGGERATED | NOT STARTED | `09-api-ssrf.md` |
| 10 | Denial of Service via Resource Exhaustion | D | LOW | Low | EXAGGERATED | NOT STARTED | `10-denial-of-service.md` |
| 11 | Information Disclosure in Error Messages | I | MEDIUM | High | REAL | PARTIAL | `11-information-disclosure.md` |
| 12 | Supply Chain / Dependency Risk | T, E | LOW | Low | EXAGGERATED | PARTIAL | `12-supply-chain-risk.md` |
| 13 | Cryptographic Signing (DKIM/DomainKey/S-MIME) | T, I | HIGH | Medium | REAL | PARTIAL | `13-cryptographic-signing.md` |
| 14 | NTLM Implementation Vulnerabilities | S, I, E | HIGH | Medium | REAL | NOT STARTED | `14-ntlm-implementation.md` |
| 15 | Event System Abuse & Plugin-Mediated Attacks | T, I, D | LOW | Low | EXAGGERATED | NOT STARTED | `15-event-system-abuse.md` |
| 16 | Path Traversal in Cache & Stream Buffers | T, I | MEDIUM | Low | EXAGGERATED | NOT STARTED | `16-path-traversal-cache.md` |
| 17 | Plugin-Specific Security Issues | I, T, S | MEDIUM-HIGH | Medium | REAL | NOT STARTED | `17-plugin-security.md` |
| 18 | Email Address Validation Gaps | T, I | MEDIUM | Medium | REAL | NOT STARTED | `18-email-validation-gaps.md` |
| 19 | Content Encoding Bypass & MIME Attacks | T | LOW | Low | EXAGGERATED | NOT STARTED | `19-encoding-bypass.md` |
| 20 | Core Mailer Flow & Recipient Integrity | T, I | LOW | Low | EXAGGERATED | NOT STARTED | `20-core-mailer-flow.md` |
| 21 | CLI Credential Exposure & Terminal Injection | I, T | MEDIUM | Medium | REAL | NOT STARTED | `21-cli-credential-exposure.md` |
| 22 | Failover Transport Security Downgrade | T, I | LOW | Low | EXAGGERATED | NOT STARTED | `22-failover-security-policy.md` |
| 23 | ReDoS via PHRASE_PATTERN in Header Parsing | D | MEDIUM | Medium | REAL | NOT STARTED | `23-redos-header-parsing.md` |
| 24 | Unbounded API Response Body Consumption | D | MEDIUM | Low | REAL | NOT STARTED | `24-unbounded-api-response.md` |
| 25 | DSN Parameter Injection Disables TLS | T | HIGH | Medium | REAL | NOT STARTED | `25-dsn-parameter-injection.md` |
| 26 | Retry Transport Amplification | D | MEDIUM | Low | REAL | NOT STARTED | `26-retry-transport-amplification.md` |

---

## Implementation Progress (2026-03-03)

**Overall: 0 of 26 threats fully mitigated. 8 partially addressed. 18 not started.**

| Status | Count | Threats |
|-|-|-|
| MOSTLY COMPLETE | 1 | #6 (Webhook — mandatory verification, all converters fixed, timestamp validation remaining) |
| PARTIAL | 7 | #2 (Credential Exposure), #3 (Sendmail), #4 (TLS), #5 (Deserialization — Phase 1), #11 (Info Disclosure), #12 (Supply Chain), #13 (Crypto Signing) |
| NOT STARTED | 18 | #1, #7, #8, #9, #10, #14, #15, #16, #17, #18, #19, #20, #21, #22, #23, #24, #25, #26 |
| COMPLETE | 0 | -- |

### Key Findings

1. **CRITICAL: FileSpool deserialization (#5) Phase 1 complete.** `unserialize()` now uses `allowed_classes` with ~47 verified classes, `ByteStream_*` gadget classes excluded, try/catch/finally for exception safety, `instanceof` type check, and 32-char filenames. HMAC signing (Phase 2) and JSON spool (Phase 3) remain future work.
2. **CRITICAL: Credential exposure (#2) is only partially addressed.** `#[SensitiveParameter]` on constructors, but LoggerPlugin still logs AUTH commands verbatim and no `__debugInfo()` exists.
3. **HIGH: NTLM (#14) has zero mitigations.** NTLMv1 code paths, unbounded Type 2 parsing, and the `debug()` credential leak all remain.
4. **HIGH: DomainKeySigner (#13) still hardcodes SHA-1** and ignores `setHashAlgorithm()` argument. DKIM oversigning is off by default.
5. ~~**HIGH: Webhook (#6) null-secret bypass remains.**~~ **FIXED (2026-03-04):** Secret now mandatory (non-nullable), verify() always called. Mailjet uses Basic Auth verification. Amazon SES uses full SNS RSA signature verification with Topic ARN and cert URL validation. Timestamp validation (replay prevention) remains future work.
6. **Positive: `roave/security-advisories` installed.** `#[SensitiveParameter]` on API transports. TLS 1.2/1.3 enforced when encryption enabled. `escapeshellarg()` on sendmail `-f` flag.

### Audit Notes (2026-03-03)

All 26 threats were verified against source code. Key audit adjustments:

- **#1 Header Injection downgraded HIGH → LOW.** `AbstractHeader::tokenNeedsEncoding()` explicitly matches `\r\n` and triggers RFC 2047 encoding, neutralizing CRLF injection at output time. Defense-in-depth gap only.
- **#4 TLS Downgrade downgraded HIGH → MEDIUM.** `verify_peer=false` requires explicit developer opt-in via DSN. Missing auto-TLS is a feature gap, not a vulnerability.
- **#8 Weak Auth downgraded MEDIUM → LOW.** CRAM-MD5 using MD5 is per RFC 2195. PLAIN/LOGIN over TLS is industry standard. These are protocol behaviors, not library bugs.
- **#9 SSRF downgraded MEDIUM → LOW.** Unvalidated base URLs are developer-supplied config, not user input.
- **#10 DoS downgraded MEDIUM → LOW.** Swiftmailer is a library; PHP `memory_limit` and calling app own input validation. Retry amplification mitigated by 60s cap.
- **#12 Supply Chain downgraded MEDIUM → LOW.** Process recommendations (CI audit, Dependabot), not exploitable code vulnerabilities.
- **#15 Event System downgraded MEDIUM → LOW.** Requires malicious plugin installed by the developer -- at that point the entire app is already compromised.
- **#16 Path Traversal downgraded HIGH → MEDIUM.** Cache keys are internally generated (hex-encoded message IDs). External exploitation requires unusual code path.
- **#19 Encoding Bypass downgraded MEDIUM → LOW.** Requires non-default encoder selection or untrusted deserialization -- unlikely preconditions.
- **#20 Core Mailer Flow downgraded MEDIUM → LOW.** Envelope override is standard SMTP behavior. Container lookup requires developer-controlled strings.
- **#22 Failover Downgrade downgraded MEDIUM → LOW.** Admin explicitly configures which transports are in the pool. Silent exception swallowing is a design weakness, not a TLS vulnerability.

---

## Risk Score Summary

**Overall Risk Score: 58/100 (MEDIUM)** *(revised from 74 after audit)*

```
| Severity | Count | Threats |
|-|-|-|
| CRITICAL | 2 | Credential Exposure (#2), Insecure Deserialization (#5) |
| HIGH | 5 | Sendmail Cmd Injection (#3), Webhook Bypass (#6), Crypto Signing (#13), |
|          |   | NTLM (#14), DSN Parameter Injection (#25) |
| MEDIUM   | 10 | TLS Config (#4), Attachment Filenames (#7), Info Disclosure (#11), |
|          |    | Path Traversal (#16), Plugin Security (#17), Email Validation (#18), |
|          |    | CLI Exposure (#21), ReDoS (#23), API Response DoS (#24), |
|          |    | Retry Amplification (#26) |
| LOW      | 9  | Header Injection (#1), Weak Auth (#8), SSRF (#9), DoS (#10), |
|          |    | Supply Chain (#12), Event System (#15), Encoding (#19), |
|          |    | Core Mailer Flow (#20), Failover Downgrade (#22) |
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
- ~~`FileSpool` uses `serialize()`/`unserialize()` on untrusted data~~ **FIXED (Phase 1):** `allowed_classes` allowlist, type check, gadget prevention, exception safety
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

*Revised after 2026-03-03 audit. LOW-severity items moved to optional backlog.*

### CRITICAL / HIGH — Fix before release

1. **Insecure Deserialization (#5)** — Direct RCE vector via FileSpool. Trivial fix (`allowed_classes`).
2. **Sendmail Command Injection (#3)** — RCE via DSN command parameter. Allowlist validation needed.
3. **Credential Exposure (#2)** — LoggerPlugin logs AUTH verbatim, API keys are public properties.
4. **Webhook Signature Bypass (#6)** — Mailjet `verify()` is literally `return true`. SES checks header existence only.
5. **Cryptographic Signing (#13)** — DomainKeySigner hardcodes SHA-1 ignoring `setHashAlgorithm()`. DKIM oversigning off.
6. **NTLM Implementation (#14)** — NTLMv1 paths reachable, `debug()` echoes credentials as HTML, unbounded Type 2 parsing.
7. **DSN Parameter Injection (#25)** — `verify_peer=false` silently disables TLS cert verification with no warning.

### MEDIUM — Address in order of deployment context

8. **Plugin Security (#17)** — Bcc leakage in `X-Swift-Bcc`/`X-Original-To`, PopBeforeSmtp plaintext password in exceptions.
9. **Email Validation (#18)** — `Utf8AddressEncoder` returns input verbatim. `idn_to_ascii()` failure unchecked.
10. **Path Traversal (#16)** — DiskKeyCache key concatenation unsanitized (low exploitability due to internal key generation).
11. **TLS Config (#4)** — `verify_peer=false` opt-in too easy via DSN. Feature gap, not vulnerability.
12. **Attachment Filenames (#7)** — No sanitization in `setFilename()`, passed raw to API transports.
13. **Information Disclosure (#11)** — LoggerPlugin dumps full log into exception messages.
14. **CLI Credential Exposure (#21)** — DSN visible in process list. Test utility, not production component.
15. **ReDoS Header Parsing (#23)** — Recursive PHRASE_PATTERN regex. Mitigated by `pcre.backtrack_limit`.
16. **API Response DoS (#24)** — No size limit on response body. Requires MITM to exploit.
17. **Retry Amplification (#26)** — Uncapped retry count via DSN. Individual delay capped at 60s.

### LOW — Optional backlog (exaggerated or minimal risk)

18. **Header Injection (#1)** — Encoding layer already neutralizes CRLF. Defense-in-depth only.
19. **Weak Auth (#8)** — Standard SMTP protocol behaviors per RFC 2195.
20. **SSRF (#9)** — Developer-supplied config values, not user input.
21. **DoS (#10)** — Library-level; PHP `memory_limit` and calling app own input validation.
22. **Supply Chain (#12)** — Process recommendations, not code vulnerabilities.
23. **Event System (#15)** — Requires malicious plugin installed by developer.
24. **Encoding Bypass (#19)** — Requires non-default encoder selection.
25. **Core Mailer Flow (#20)** — Standard SMTP envelope behavior and developer-facing APIs.
26. **Failover Downgrade (#22)** — Admin-configured transport pools working as intended.

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

1. ~~Replace `FileSpool` deserialization with a safe format (JSON or `allowed_classes`)~~ **DONE (Phase 1)** — HMAC signing still recommended
2. Validate/sanitize DSN sendmail `command` parameter against an allowlist
3. Audit all logging paths for credential leakage; redact AUTH commands
4. Enforce `verify_peer=true` as default with explicit opt-out documentation
5. ~~Make webhook signature verification mandatory (no null-secret bypass)~~ **DONE** — `$secret` is non-nullable, `verify()` always called
6. ~~Fix Amazon SES webhook verification (implement real SNS signature checking)~~ **DONE** — full RSA signature verification with TopicArn and cert URL validation
7. ~~Fix Mailjet `verify()` (implement actual HMAC verification, not always-true)~~ **DONE** — Basic Auth header verification with `hash_equals()`
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
