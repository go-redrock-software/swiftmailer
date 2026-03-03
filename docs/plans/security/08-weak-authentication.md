# Threat 08: Weak SMTP Authentication Mechanisms

**STRIDE Category:** Spoofing, Information Disclosure
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-327 (Use of Broken Crypto Algorithm), CWE-523 (Unprotected Transport of Credentials)

---

## Description

Swiftmailer supports five SMTP authentication mechanisms. Two of them (PLAIN, LOGIN) transmit credentials encoded in base64 (trivially reversible) — which is only safe over TLS. CRAM-MD5 uses HMAC-MD5, which relies on the cryptographically broken MD5 hash function. The `AuthHandler` tries all supported mechanisms by default, potentially allowing a downgrade attack where a MITM server advertises only the weakest mechanism.

## Attack Vectors

1. **Auth mechanism downgrade** — MITM server advertises only `PLAIN` (no TLS), client sends credentials in cleartext
2. **CRAM-MD5 collision** — While HMAC-MD5 is more resistant than bare MD5, it is cryptographically deprecated
3. **Base64 credential interception** — PLAIN/LOGIN over unencrypted SMTP exposes credentials via network sniffing
4. **NTLM relay** — NTLM authentication is vulnerable to relay attacks in certain configurations
5. **No auth mechanism pinning** — Application cannot enforce that only a specific mechanism is used

## Affected Files

| File | Mechanism | Risk |
|-|-|-|
| `lib/classes/Swift/Transport/Esmtp/Auth/PlainAuthenticator.php` | PLAIN | Base64 credentials, safe only over TLS |
| `lib/classes/Swift/Transport/Esmtp/Auth/LoginAuthenticator.php` | LOGIN | Base64 credentials, safe only over TLS |
| `lib/classes/Swift/Transport/Esmtp/Auth/CramMd5Authenticator.php` | CRAM-MD5 | HMAC-MD5 (deprecated algorithm) |
| `lib/classes/Swift/Transport/Esmtp/Auth/XOAuth2Authenticator.php` | XOAUTH2 | Bearer token (secure if TLS enforced) |
| `lib/classes/Swift/Transport/Esmtp/Auth/NtlmAuthenticator.php` | NTLM | Complex protocol, relay risks |
| `lib/classes/Swift/Transport/Esmtp/AuthHandler.php` | Selection | Tries all by default, allows downgrade |

## Existing Controls

- `AuthHandler` supports explicit auth mode setting via `setAuthMode()`
- XOAUTH2 uses bearer tokens (no password transmission)
- TLS 1.2/1.3 when explicitly configured protects all mechanisms

## Control Gaps

1. **No TLS enforcement before auth** — `AuthHandler` will attempt authentication even without TLS
2. **CRAM-MD5 still offered** — Should be deprecated in favor of modern mechanisms
3. **Default "try all" behavior** — If server supports PLAIN and CRAM-MD5, client tries both without preference
4. **No mechanism priority ordering** — Cannot specify preferred mechanism order
5. **No warning for non-TLS auth** — Silent credential exposure if TLS is not active

## Mitigation Plan

### Phase 1: TLS Enforcement Before Auth (Immediate)
- Add a check in `AuthHandler::afterEhlo()` that warns or fails if authentication is attempted without TLS:
  ```php
  if (!$agent->getBuffer()->isTlsActive() && $this->requireTlsForAuth) {
      throw new Swift_TransportException(
          'Authentication refused: TLS is not active. Set requireTlsForAuth=false to override.'
      );
  }
  ```

### Phase 2: Mechanism Priority and Deprecation (Short-term)
- Set default mechanism preference order: `XOAUTH2 > PLAIN (TLS) > LOGIN (TLS) > CRAM-MD5 > NTLM`
- Deprecate CRAM-MD5 with a `trigger_error(E_USER_DEPRECATED)` when it is selected
- Add `setPreferredAuthMechanisms(array $mechanisms)` method for explicit control

### Phase 3: Anti-Downgrade Protection (Medium-term)
- If TLS is active and server advertises PLAIN, prefer it over CRAM-MD5 (PLAIN over TLS is more secure than CRAM-MD5)
- Log the selected authentication mechanism for audit purposes
- Consider removing CRAM-MD5 in a future major version

### Phase 4: Documentation
- Document recommended authentication configuration for each provider
- Document that PLAIN/LOGIN require TLS
- Document XOAUTH2 setup for Gmail and other OAuth providers
- Add migration guide from CRAM-MD5 to modern mechanisms

## Test Cases

```php
// Auth without TLS should fail by default
$handler = new Swift_Transport_Esmtp_AuthHandler([...]);
$handler->setUsername('user');
$handler->setPassword('pass');
// Mock agent with no TLS active
$this->expectException(Swift_TransportException::class);
$handler->afterEhlo($agent);

// CRAM-MD5 selection should trigger deprecation
$this->expectDeprecation();
$handler->setAuthMode('CRAM-MD5');

// Mechanism priority should prefer XOAUTH2 when available
$handler->setPreferredAuthMechanisms(['XOAUTH2', 'PLAIN']);
// Mock server advertising both
// Assert XOAUTH2 is selected
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| `setAuthMode()` for explicit mode selection | **EXISTING** | `AuthHandler.php:128` |
| XOAUTH2 bearer token support | **EXISTING** | `XOAuth2Authenticator.php` present |
| TLS 1.2/1.3 when configured | **EXISTING** | `StreamBuffer.php:89-92` |
| TLS enforcement before auth | **PENDING** | `AuthHandler::afterEhlo()` (line 168) attempts auth without checking TLS status |
| CRAM-MD5 deprecation notice | **PENDING** | No deprecation warning when CRAM-MD5 is selected |
| Mechanism priority ordering | **PENDING** | No `setPreferredAuthMechanisms()` method |
| Anti-downgrade protection | **PENDING** | Default "try all" behavior in `getAuthenticatorsForAgent()` (line 262) |

**Overall Status:** NOT STARTED -- Existing controls are minimal. No TLS-before-auth enforcement, no CRAM-MD5 deprecation, and no mechanism priority ordering.

## Risk After Mitigation

**Residual Risk:** LOW — With TLS enforcement before auth, mechanism deprecation, and priority ordering, credential exposure via weak auth is prevented.
