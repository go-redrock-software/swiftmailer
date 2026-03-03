# Threat 13: Cryptographic Signing Vulnerabilities (DKIM, DomainKeys, S/MIME)

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** HIGH
**Likelihood:** Medium
**CWE:** CWE-327 (Use of Broken Crypto Algorithm), CWE-347 (Improper Verification of Crypto Signature)

---

## Description

The email signing subsystem contains multiple cryptographic weaknesses: `DomainKeySigner` hardcodes SHA-1 (which has practical collision attacks), `DKIMSigner` allows body length limits that enable content injection after the signed portion, oversigning is disabled by default enabling header replay attacks, and `SMimeSigner` performs no certificate validation whatsoever.

## Attack Vectors

1. **DomainKey SHA-1 collision** -- `DomainKeySigner::setHashAlgorithm()` ignores its argument and always uses `rsa-sha1`. An attacker crafts a colliding message body that passes DomainKey verification, enabling phishing under a trusted domain.
2. **DKIM body length content injection** -- `DKIMSigner::setBodySignedLen()` with `l=` tag signs only the first N bytes. An attacker at a relay appends malicious content (phishing links, scripts) after the signed portion; the DKIM signature still validates.
3. **DKIM header replay** -- With oversigning disabled (default), an attacker at a relay adds a second `From:` or `Subject:` header. Many mail clients display the last occurrence, enabling spoofing while DKIM validates.
4. **DKIM rsa-sha1 still permitted** -- A deprecation notice is triggered but `rsa-sha1` remains functional. RFC 8301 says MUST NOT use for signing.
5. **S/MIME no certificate validation** -- `setSignCertificate()` and `setEncryptCertificate()` load certs via `realpath()` with no expiry, revocation, chain, or key strength checks.
6. **S/MIME private key path exposure** -- `getSignPrivateKey()` is public and returns the full filesystem path including passphrase array.
7. **Private key material in exceptions** -- Both `DKIMSigner` and `DomainKeySigner` include `openssl_error_string()` in thrown exceptions, which may reveal key-related internal state.

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Signers/DomainKeySigner.php:255` | `setHashAlgorithm()` always uses SHA-1 |
| `lib/classes/Swift/Signers/DomainKeySigner.php:346-351` | `ignoreHeader('from')` not blocked |
| `lib/classes/Swift/Signers/DKIMSigner.php:330-344` | `setBodySignedLen()` enables `l=` tag |
| `lib/classes/Swift/Signers/DKIMSigner.php:72` | `$oversigning = false` by default |
| `lib/classes/Swift/Signers/DKIMSigner.php:247-253` | `rsa-sha1` still accepted with deprecation |
| `lib/classes/Swift/Signers/DKIMSigner.php:126` | OpenSSL error string in exception |
| `lib/classes/Swift/Signers/SMimeSigner.php:88-105` | No certificate validation |
| `lib/classes/Swift/Signers/SMimeSigner.php:73` | Default cipher AES-128-CBC |
| `lib/classes/Swift/Signers/SMimeSigner.php:148-151` | Public getter exposes key path+passphrase |

## Existing Controls

- DKIM `rsa-sha256` is the default hash algorithm
- DKIM `ignoreHeader('from')` is blocked in `DKIMSigner` (but not `DomainKeySigner`)
- Ed25519 signing is supported as a modern alternative
- `#[SensitiveParameter]` on DKIM passphrase constructor parameter

## Mitigation Plan

### Phase 1: Remove Broken Algorithms (Immediate)
- **Remove `DomainKeySigner` entirely** or rewrite to support only SHA-256. DomainKeys is obsolete (superseded by DKIM in 2007).
- **Hard-fail `rsa-sha1`** in `DKIMSigner::setHashAlgorithm()` -- throw exception instead of deprecation notice.

### Phase 2: Disable Dangerous Features (Short-term)
- **Remove `setBodySignedLen()`** or make it throw. RFC 8301 Section 5 warns against `l=` tag. No legitimate use case justifies the content injection risk.
- **Enable oversigning by default** -- Change `$oversigning = true` as default. This prevents header replay attacks.
- **Block `ignoreHeader('from')` in `DomainKeySigner`** -- match `DKIMSigner` behavior.

### Phase 3: S/MIME Hardening (Medium-term)
- Add certificate expiry check in `setSignCertificate()` and `setEncryptCertificate()`
- Add minimum key strength check (RSA >= 2048 bits)
- Change default cipher to `OPENSSL_CIPHER_AES_256_CBC`
- Make `getSignPrivateKey()` protected or remove it
- Add file permission check for private key files (warn if not 0600)

### Phase 4: Error Message Sanitization
- Remove `openssl_error_string()` from user-facing exception messages
- Store OpenSSL errors in a debug-level log or as exception metadata, not in the message string

## Test Cases

```php
// DKIMSigner should reject rsa-sha1
$this->expectException(InvalidArgumentException::class);
$signer->setHashAlgorithm('rsa-sha1');

// DKIMSigner oversigning should be enabled by default
$signer = new Swift_Signers_DKIMSigner($key, 'example.com', 'selector');
// Verify From header is signed with oversigning
$headers = $signer->getSignedHeaders();
$fromCount = count(array_filter($headers, fn($h) => strtolower($h) === 'from'));
$this->assertGreaterThan(1, $fromCount); // oversigned

// DomainKeySigner should block ignoring From
$this->expectException(Swift_SwiftException::class);
$domainKeySigner->ignoreHeader('from');

// S/MIME should reject expired certificates
$this->expectException(Swift_SwiftException::class);
$signer->setSignCertificate('/path/to/expired.pem', '/path/to/key.pem');
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| DKIM `rsa-sha256` as default | **IMPLEMENTED** | `DKIMSigner.php` defaults to `rsa-sha256` |
| DKIM `rsa-sha1` deprecation notice | **IMPLEMENTED** | `DKIMSigner.php:247-252`: `trigger_error()` with `E_USER_DEPRECATED` when `rsa-sha1` selected |
| Ed25519 signing support | **IMPLEMENTED** | `ed25519-sha256` option in `setHashAlgorithm()` |
| `#[SensitiveParameter]` on DKIM passphrase | **IMPLEMENTED** | Present on constructor parameter |
| DKIM `ignoreHeader('from')` blocked | **IMPLEMENTED** | Blocked in `DKIMSigner` (but NOT in `DomainKeySigner`) |
| `DomainKeySigner` always uses SHA-1 | **NOT FIXED** | `DomainKeySigner.php:255`: `setHashAlgorithm()` ignores argument, hardcodes `'rsa-sha1'` |
| `DomainKeySigner` `ignoreHeader('from')` not blocked | **NOT FIXED** | `DomainKeySigner.php:346-351`: no protection |
| DKIM oversigning disabled by default | **NOT FIXED** | `DKIMSigner.php:72`: `$oversigning = false` |
| DKIM `rsa-sha1` still functional | **NOT FIXED** | `DKIMSigner.php:252`: deprecation only, still accepted and usable |
| DKIM `setBodySignedLen()` still available | **NOT FIXED** | `DKIMSigner.php:330-344`: `l=` tag enabling content injection risk |
| S/MIME no certificate validation | **NOT FIXED** | `SMimeSigner.php:88-105`: no expiry, revocation, or key strength checks |
| S/MIME `getSignPrivateKey()` public | **NOT FIXED** | `SMimeSigner.php:148`: public getter exposes key path + passphrase |
| OpenSSL error string in exceptions | **NOT FIXED** | `DKIMSigner.php:126`: `openssl_error_string()` in exception messages |
| S/MIME default cipher AES-128-CBC | **NOT FIXED** | `SMimeSigner.php:73`: could be upgraded to AES-256-CBC |

**Overall Status:** PARTIALLY IMPLEMENTED -- DKIM `rsa-sha256` default and deprecation notice for `rsa-sha1` are in place. However, DomainKeySigner is entirely broken (SHA-1 only), oversigning is off by default, `l=` tag is still available, and S/MIME has no certificate validation.

## Risk After Mitigation

**Residual Risk:** LOW -- With SHA-1 removal, `l=` tag removal, default oversigning, and S/MIME validation, signing provides strong authenticity guarantees.
