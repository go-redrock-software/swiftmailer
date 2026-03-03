# Threat #25: DSN Parameter Injection Disables TLS Verification

**Severity:** HIGH
**STRIDE Category:** Tampering
**Status:** NOT STARTED

---

## Description

The `createSmtpTransport()` method in `DsnTransportFactory` reads `verify_peer` from DSN query parameters and directly sets `ssl.verify_peer` and `ssl.verify_peer_name` stream context options. A DSN string like `smtp+tls://user:pass@smtp.example.com?verify_peer=false` completely disables certificate verification. There is no warning, logging, or configuration guard against this.

If DSN strings are sourced from environment variables, config files, or databases that could be tampered with, an attacker can silently downgrade TLS security.

## Affected Files

- `lib/classes/Swift/Transport/DsnTransportFactory.php` (lines 115-119)

## Attack Scenario

An attacker with write access to an environment variable, database config row, or configuration file modifies the DSN string to append `?verify_peer=false`. All subsequent SMTP connections silently skip certificate validation, enabling MITM attacks to intercept credentials and email content.

## Recommended Mitigations

1. Log a warning when `verify_peer=false` is used
2. Consider requiring an explicit opt-in flag separate from the DSN string (e.g., a constructor parameter or dedicated config key)
3. At minimum, document the risk prominently and consider a runtime deprecation notice
4. Add an allowlist of safe DSN query parameters

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Warning log on verify_peer=false | NOT STARTED | — |
| Separate opt-in mechanism | NOT STARTED | — |
| DSN parameter allowlist | NOT STARTED | — |
| Documentation of risk | NOT STARTED | — |
