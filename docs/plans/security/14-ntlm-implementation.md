# Threat 14: NTLM Authentication Implementation Vulnerabilities

**STRIDE Category:** Spoofing, Information Disclosure, Elevation of Privilege
**Severity:** HIGH
**Likelihood:** Medium
**CWE:** CWE-327 (Broken Crypto), CWE-125 (Out-of-bounds Read), CWE-200 (Info Exposure)

---

## Description

The NTLM authenticator (`NTLMAuthenticator.php`) contains a custom implementation of the NTLMv2 protocol with a dormant NTLMv1 fallback path. The implementation has multiple issues: NTLMv1 code paths using DES, no bounds checking on server-supplied Type 2 messages, a `debug()` method that echoes credential material to stdout, integer precision issues, and missing protocol security features (MIC, channel binding).

## Attack Vectors

1. **NTLMv1 activation** -- `sendMessage3()` has a `$v2` parameter (default `true`) but is `protected`, allowing subclasses to call with `$v2=false`. NTLMv1 uses DES-based LM hashes trivially crackable via rainbow tables. `createLMPassword()` truncates to 14 chars and uppercases.
2. **Type 2 message parsing buffer overread** -- `parseMessage2()` (line 119-146) uses fixed-offset `substr()` and `hex2bin()` with no length validation. A malicious SMTP server sends a truncated Type 2 response, causing `substr()` to return false and `hex2bin()` to produce garbage. The `$offset` at line 128 is server-controlled.
3. **`readSubBlock` unbounded read** -- Line 162 trusts server-supplied `$blockLength` without capping it, enabling reads past the end of the buffer.
4. **Debug method credential leak** -- `debug()` (line 649-721) is `protected` and uses `echo` to dump raw NTLM handshake data including LM/NTLM response hashes, usernames, and workstation names as HTML.
5. **LMv2 silent degradation** -- For passwords >15 characters (line 421), `$lmPass` is silently set to null bytes, changing authentication behavior with no logging.
6. **Integer precision loss** -- `si2bin()` (line 71-95) uses `2 ** 63` which exceeds PHP integer precision, and `base_convert()` loses precision for large numbers.
7. **Username parsing injection** -- `getDomainAndUsername()` (line 307-321) uses `explode('\\', $name)` without limit; usernames with multiple backslashes or mixed `\`/`@` are parsed inconsistently.
8. **No MIC (Message Integrity Code)** -- Type 3 message has hardcoded empty session key header. No protection against NTLM message tampering in transit.
9. **No channel binding / EPA** -- No support for Extended Protection for Authentication, leaving the implementation vulnerable to NTLM relay attacks.

## Affected Files

| File | Line | Risk |
|-|-|-|
| `lib/classes/Swift/Transport/Esmtp/Auth/NTLMAuthenticator.php:203` | `$v2=false` path exists | NTLMv1 fallback |
| `NTLMAuthenticator.php:119-146` | `parseMessage2()` | Out-of-bounds read |
| `NTLMAuthenticator.php:153-176` | `readSubBlock()` | Unbounded read |
| `NTLMAuthenticator.php:331-357` | `createLMPassword()` | DES + 14-char truncation |
| `NTLMAuthenticator.php:590` | `new DES('ecb')` | DES in ECB mode |
| `NTLMAuthenticator.php:649-721` | `debug()` | Credential echo to stdout |
| `NTLMAuthenticator.php:419-428` | LMv2 long password | Silent degradation |
| `NTLMAuthenticator.php:71-95` | `si2bin()` | Integer precision loss |
| `NTLMAuthenticator.php:307-321` | `getDomainAndUsername()` | Parsing inconsistency |

## Existing Controls

- NTLMv2 is the default (`$v2 = true`)
- `phpseclib` handles DES/MD4 operations (avoiding manual crypto)
- NTLM is typically used only in Active Directory environments

## Mitigation Plan

### Phase 1: Remove NTLMv1 Code (Immediate)
- Delete `createLMPassword()`, the `$v2 = false` branch in `sendMessage3()`, and all DES-based code paths
- Remove the `$v2` parameter entirely -- only support NTLMv2
- Delete the `debug()` method or make it `private` and gate behind an explicit debug flag

### Phase 2: Bounds Checking (Short-term)
- Add length validation in `parseMessage2()`:
  ```php
  if (strlen($response) < 56) { // minimum Type 2 message size
      throw new Swift_TransportException('NTLM Type 2 message too short');
  }
  ```
- Cap `$blockLength` in `readSubBlock()` to the remaining buffer size
- Validate `$offset` before using it to index into the response

### Phase 3: Integer and Parsing Fixes (Short-term)
- Replace `2 ** ($bits - 1)` with `bcpow('2', (string)($bits - 1))` for arbitrary precision
- Fix `getDomainAndUsername()` to use `explode('\\', $name, 2)` with a limit of 2
- Add validation that parsed domain/username don't contain control characters

### Phase 4: Protocol Improvements (Medium-term)
- Add MIC computation in Type 3 messages
- Consider channel binding support if the PHP stream layer supports it
- Log the selected NTLMv2 sub-protocol for audit purposes
- Add deprecation notice for NTLM in favor of XOAUTH2 where possible

## Test Cases

```php
// NTLMv1 should not be callable
$this->assertFalse(method_exists($authenticator, 'createLMPassword'));

// Short Type 2 message should throw
$this->expectException(Swift_TransportException::class);
$authenticator->parseMessage2('SHORT');

// Usernames with multiple backslashes should parse correctly
[$domain, $user] = $authenticator->getDomainAndUsername('DOMAIN\\sub\\user');
$this->assertSame('DOMAIN', $domain);
$this->assertSame('sub\\user', $user);

// debug() should not be accessible
$ref = new ReflectionMethod($authenticator, 'debug');
$this->assertTrue($ref->isPrivate());
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| NTLMv2 as default | **EXISTING** | `NTLMAuthenticator.php:197`: `$v2 = true` default parameter |
| `phpseclib` for crypto operations | **EXISTING** | DES/MD4 delegated to phpseclib |
| NTLMv1 code path removal | **NOT DONE** | `NTLMAuthenticator.php:203`: `if (!$v2)` branch still exists; `createLMPassword()` at line 331 still present |
| Type 2 message bounds checking | **NOT DONE** | `parseMessage2()` at line 119 uses fixed-offset `substr()` with no length validation |
| `readSubBlock` unbounded read fix | **NOT DONE** | Line 153: `$blockLength` not capped to remaining buffer size |
| `debug()` method removal/restriction | **NOT DONE** | Line 649: `protected function debug()` still exists, echoes HTML with credential data |
| Integer precision fix (`si2bin`) | **NOT DONE** | Uses `2 ** 63` which exceeds PHP integer precision |
| `getDomainAndUsername()` parsing fix | **NOT DONE** | `explode('\\', $name)` without limit parameter |
| MIC support | **NOT DONE** | No Message Integrity Code in Type 3 messages |
| Channel binding / EPA | **NOT DONE** | No Extended Protection for Authentication |

**Overall Status:** NOT STARTED -- All proposed mitigations remain pending. NTLMv1 code paths, unbounded reads, and the `debug()` credential leak are all still present.

## Risk After Mitigation

**Residual Risk:** MEDIUM -- NTLM is inherently weak (no forward secrecy, relay-vulnerable). With NTLMv1 removed and bounds checking added, implementation-level risks are addressed, but protocol-level risks remain. Recommend migration to XOAUTH2 where possible.
