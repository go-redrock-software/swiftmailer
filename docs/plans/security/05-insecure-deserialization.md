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
5. **Symlink attack** — Spool file path construction (`$this->path.'/'.$this->getRandomString(10)`) could be targeted with directory symlinks

## Affected Files

| File | Line | Risk |
|-|-|-|
| `lib/classes/Swift/FileSpool.php:94` | `serialize($message)` — Serializes message to disk |
| `lib/classes/Swift/FileSpool.php:166` | `unserialize(file_get_contents($file.'.sending'))` — **Deserializes from disk** |
| `lib/classes/Swift/FileSpool.php:95` | Random filename generation with only 10 chars |

## Existing Controls

- Transport classes (`AbstractApiTransport`, `AbstractSmtpTransport`) throw `BadMethodCallException` in `__sleep()`/`__wakeup()` to prevent their serialization
- `TemporaryFileByteStream` similarly prevents serialization
- Atomic file operations using `fopen(..., 'xb')` for exclusive creation
- Rename-based locking (`rename($file, $file.'.sending')`) for concurrency

## Control Gaps

1. **`unserialize()` called without `allowed_classes`** — No restriction on which classes can be instantiated during deserialization
2. **No integrity verification** — No HMAC or signature on spool files to detect tampering
3. **No file permission enforcement** — Spool directory permissions not validated at construction time
4. **Short random filenames** — 10 chars of random data may be brute-forceable
5. **Gadget chain availability** — Classes like `Swift_Message::__wakeup()`, `DiskKeyCache::__wakeup()`, `QpEncoder::__wakeup()` exist as potential chain links
6. **No alternative spool format** — No JSON-based or database-backed spool option

## Mitigation Plan

### Phase 1: Restrict Allowed Classes (Immediate)
Replace raw `unserialize()` with restricted deserialization:
```php
// In flushQueue(), line 166:
$message = unserialize(
    file_get_contents($file.'.sending'),
    ['allowed_classes' => [
        Swift_Message::class,
        Swift_Mime_SimpleMessage::class,
        Swift_Mime_MimePart::class,
        Swift_Attachment::class,
        Swift_Image::class,
        Swift_Mime_SimpleMimeEntity::class,
        Swift_Mime_Headers_MailboxHeader::class,
        Swift_Mime_Headers_UnstructuredHeader::class,
        Swift_Mime_Headers_DateHeader::class,
        Swift_Mime_Headers_IdentificationHeader::class,
        Swift_Mime_Headers_ParameterizedHeader::class,
        Swift_Mime_Headers_PathHeader::class,
        Swift_Mime_SimpleHeaderSet::class,
        Swift_Mime_ContentEncoder_QpContentEncoder::class,
        Swift_Mime_ContentEncoder_Base64ContentEncoder::class,
        Swift_CharacterReaderFactory_SimpleCharacterReaderFactory::class,
    ]]
);
```

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

```php
// unserialize should reject unexpected classes
$malicious = 'O:19:"SomeDangerousClass":0:{}';
file_put_contents($spoolDir.'/test.message.sending', $malicious);
$this->expectException(Swift_IoException::class);
$spool->flushQueue($transport);

// HMAC verification should catch tampered files
$spool->queueMessage($message);
// Tamper with the file
$files = glob($spoolDir.'/*.message');
file_put_contents($files[0], 'tampered' . file_get_contents($files[0]));
$this->expectException(Swift_IoException::class);
$spool->flushQueue($transport);
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Transport `__sleep()`/`__wakeup()` throw | **IMPLEMENTED** | `AbstractSmtpTransport.php:581-586`, `AbstractApiTransport.php:95-100` |
| Atomic file operations | **IMPLEMENTED** | `FileSpool.php` uses `fopen(..., 'xb')` for exclusive creation |
| Rename-based locking | **IMPLEMENTED** | `FileSpool.php:165`: `rename($file, $file.'.sending')` |
| `unserialize()` with `allowed_classes` | **NOT IMPLEMENTED** | `FileSpool.php:166`: raw `\unserialize(\file_get_contents($file.'.sending'))` with NO `allowed_classes` restriction |
| HMAC integrity verification | **NOT IMPLEMENTED** | No signing key or HMAC on spool files |
| File permission enforcement | **NOT IMPLEMENTED** | No permission validation on spool directory |
| Alternative spool format (JSON) | **NOT IMPLEMENTED** | No `JsonFileSpool` or `DatabaseSpool` exists |
| Longer random filenames | **NOT IMPLEMENTED** | Still uses 10-char random strings |

**Overall Status:** NOT STARTED -- The critical `allowed_classes` restriction on `unserialize()` has NOT been implemented. This remains a **CRITICAL** vulnerability. `FileSpool.php:166` deserializes untrusted data without any class restriction.

## Risk After Mitigation

**Residual Risk:** LOW — With `allowed_classes` restriction, HMAC integrity checks, and file permission hardening, deserialization-based RCE is effectively eliminated.
