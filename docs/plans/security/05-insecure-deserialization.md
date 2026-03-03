# Threat 05: Insecure Deserialization in FileSpool

**STRIDE Category:** Tampering, Elevation of Privilege
**Severity:** CRITICAL
**Likelihood:** Medium
**CWE:** CWE-502 (Deserialization of Untrusted Data)

---

## Description

`Swift_FileSpool` uses PHP's native `serialize()`/`unserialize()` to queue and dequeue email messages. If an attacker can write to the spool directory or manipulate spooled files, they can inject malicious serialized objects that execute arbitrary code during deserialization via PHP's magic methods (`__wakeup()`, `__destruct()`, `__toString()`, etc.).

This is a **known critical vulnerability class** in PHP applications and is consistently rated as one of the most dangerous attack vectors.

## Attack Vectors

1. **Spool directory write access** — If the spool directory permissions are too permissive, an attacker with local access can replace `.message` files with malicious serialized payloads
2. **Race condition** — Between `queueMessage()` writing and `flushQueue()` reading, an attacker could swap the file contents
3. **Shared hosting** — In shared environments, other tenants may be able to access the spool directory
4. **Gadget chains** — Swiftmailer includes `__wakeup()` methods in several classes; combined with framework classes (Guzzle, Monolog, etc.), POP chains are likely available
5. **Symlink attack** — Spool file path construction could be targeted with directory symlinks (filename randomness increased from 10 to 32 chars)

## Affected Files

| File | Line | Risk | Status |
|-|-|-|-|
| `lib/classes/Swift/FileSpool.php:94` | `serialize($message)` — Serializes message to disk | Unchanged |
| `lib/classes/Swift/FileSpool.php:243` | `@unserialize(...)` with `allowed_classes` — **Deserializes from disk** | **FIXED** |
| `lib/classes/Swift/FileSpool.php:95` | Random filename generation (32 chars) | **FIXED** |

## Existing Controls

- Transport classes (`AbstractApiTransport`, `AbstractSmtpTransport`) throw `BadMethodCallException` in `__sleep()`/`__wakeup()` to prevent their serialization
- `TemporaryFileByteStream` similarly prevents serialization
- Atomic file operations using `fopen(..., 'xb')` for exclusive creation
- Rename-based locking (`rename($file, $file.'.sending')`) for concurrency

## Control Gaps

1. ~~**`unserialize()` called without `allowed_classes`**~~ — **FIXED:** Allowlist of ~47 verified classes with `instanceof` type check
2. **No integrity verification** — No HMAC or signature on spool files to detect tampering
3. **No file permission enforcement** — Spool directory permissions not validated at construction time
4. ~~**Short random filenames**~~ — **FIXED:** Increased from 10 to 32 characters
5. ~~**Gadget chain availability**~~ — **MITIGATED:** `ByteStream_*` classes excluded from allowlist (prevents `TemporaryFileByteStream` file deletion gadget); try/catch prevents `__wakeup()` exceptions from crashing the queue
6. **No alternative spool format** — No JSON-based or database-backed spool option

## Mitigation Plan

### Phase 1: Restrict Allowed Classes (Immediate) — IMPLEMENTED

Replaced raw `unserialize()` with restricted deserialization using a comprehensive allowlist of ~47 classes verified by serializing a real `Swift_Message` with attachments, embedded images, and Return-Path headers. Key design decisions:

- **`Swift_ByteStream_*` classes intentionally excluded.** They never appear in legitimately serialized messages (their `__sleep()` throws). `TemporaryFileByteStream` has a `__destruct()` that calls `@unlink($this->getPath())` — an arbitrary file deletion gadget even with the allowlist.
- **Type check after deserialization.** If the result is not `instanceof Swift_Mime_SimpleMessage`, the `.sending` file is cleaned up and the loop continues.
- **try/catch/finally around the entire unserialize+send block.** Prevents `__wakeup()` exceptions or transport failures from crashing the queue or orphaning `.sending` files.
- **Filename randomness increased from 10 to 32 characters.**

See `UNSERIALIZE_ALLOWED_CLASSES` constant in `FileSpool.php` for the full allowlist.

### Phase 2: Add Integrity Verification (Short-term)
Add HMAC signing to spool files:
```php
// In queueMessage():
$ser = serialize($message);
$hmac = hash_hmac('sha256', $ser, $this->signingKey);
fwrite($fp, $hmac . "\n" . $ser);

// In flushQueue():
$contents = file_get_contents($file.'.sending');
[$storedHmac, $ser] = explode("\n", $contents, 2);
if (!hash_equals(hash_hmac('sha256', $ser, $this->signingKey), $storedHmac)) {
    unlink($file.'.sending');
    throw new Swift_IoException('Spool file integrity check failed.');
}
$message = unserialize($ser, ['allowed_classes' => [...]]);
```

### Phase 3: Alternative Spool Format (Medium-term)
- Implement a `JsonFileSpool` or `DatabaseSpool` that avoids `serialize()` entirely
- Use a message-to-array converter and `json_encode()`/`json_decode()` for safe serialization
- Deprecate `FileSpool` in favor of the new implementation

### Phase 4: File System Hardening
- Validate spool directory permissions in constructor: reject if group/world writable
- Use longer random filenames (32+ chars from `random_bytes()`)
- Set `umask(0077)` before creating spool files
- Add documentation requiring exclusive directory ownership

## Test Cases

Tests implemented in `tests/unit/Swift/FileSpoolTest.php` (8 tests):

1. **Round-trip** — `queueMessage()` then `flushQueue()` with mock transport, assert `send()` called with valid message
2. **Malicious class rejection** — Serialized `stdClass` in spool dir, assert `send()` never called, `.sending` file cleaned up
3. **Corrupt file handling** — Garbage data in `.message` file, assert no crash, legitimate messages still send
4. **Message limit** — Queue 5, limit 2, assert exactly 2 sent
5. **Time limit** — Queue 3, time limit -1, assert only 1 sent
6. **ByteStream gadget blocked** — Hand-crafted serialized `TemporaryFileByteStream` targeting a file, assert target file NOT deleted
7. **Transport exception resilience** — Transport throws on first message, assert second message still sends and no orphaned `.sending` files
8. **Deserialization exception resilience** — Truncated serialized string, assert legitimate messages still send and `.sending` files cleaned up

## Implementation Status (2026-03-03)

| Mitigation | Status | Evidence |
|-|-|-|
| Transport `__sleep()`/`__wakeup()` throw | **IMPLEMENTED** | `AbstractSmtpTransport.php:581-586`, `AbstractApiTransport.php:95-100` |
| Atomic file operations | **IMPLEMENTED** | `FileSpool.php` uses `fopen(..., 'xb')` for exclusive creation |
| Rename-based locking | **IMPLEMENTED** | `FileSpool.php:240`: `rename($file, $file.'.sending')` |
| `unserialize()` with `allowed_classes` | **IMPLEMENTED** | `FileSpool.php:243`: `@unserialize(..., ['allowed_classes' => self::UNSERIALIZE_ALLOWED_CLASSES])` with ~47 verified classes |
| Type check after deserialization | **IMPLEMENTED** | `FileSpool.php:248`: `if (!$message instanceof Swift_Mime_SimpleMessage)` — skips invalid messages |
| ByteStream gadget prevention | **IMPLEMENTED** | `Swift_ByteStream_*` excluded from allowlist — prevents `TemporaryFileByteStream::__destruct()` file deletion gadget |
| Exception-safe queue processing | **IMPLEMENTED** | `FileSpool.php:242-260`: try/catch/finally ensures `.sending` cleanup and queue continuation |
| Longer random filenames | **IMPLEMENTED** | `FileSpool.php:95`: `getRandomString(32)` (was 10) |
| HMAC integrity verification | **NOT IMPLEMENTED** | No signing key or HMAC on spool files |
| File permission enforcement | **NOT IMPLEMENTED** | No permission validation on spool directory |
| Alternative spool format (JSON) | **NOT IMPLEMENTED** | No `JsonFileSpool` or `DatabaseSpool` exists |

**Overall Status:** PHASE 1 COMPLETE — The critical `allowed_classes` restriction is implemented with a verified allowlist, gadget chain prevention (ByteStream exclusion), exception-safe processing, and 8 unit tests. Phases 2-4 (HMAC, JSON spool, permission hardening) remain future work.

## Risk After Phase 1 Mitigation

**Residual Risk:** MEDIUM — RCE via arbitrary class instantiation is eliminated. Remaining risks:
- Without HMAC (Phase 2), an attacker with spool directory write access can still craft messages using allowlisted classes to send emails with attacker-controlled content/recipients
- Without file permission enforcement (Phase 4), spool directory access is not validated
- Allowlisted classes are limited to those verified in real serialized messages; gadget classes (`TemporaryFileByteStream`) are excluded
