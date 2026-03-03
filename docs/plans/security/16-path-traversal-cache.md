# Threat 16: Path Traversal in DiskKeyCache and Stream Buffers

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** HIGH
**Likelihood:** Low
**CWE:** CWE-22 (Path Traversal), CWE-377 (Insecure Temporary File)

---

## Description

`DiskKeyCache` concatenates `$nsKey` and `$itemKey` parameters directly into filesystem paths without sanitization. If attacker-controlled data reaches these parameters (e.g., via crafted email headers used as cache keys), directory traversal sequences like `../../etc/passwd` enable arbitrary file read, write, and delete operations. Additionally, `StreamBuffer` accepts `stream_context_options` directly from configuration, allowing injection of arbitrary PHP stream context settings.

## Attack Vectors

1. **DiskKeyCache path traversal** -- `$nsKey` and `$itemKey` concatenated into `$this->path.'/'.$nsKey.'/'.$itemKey` with no sanitization. `hasKey()`, `clearKey()`, `getHandle()` all affected.
2. **DiskKeyCache arbitrary file deletion** -- `clearAll($nsKey)` iterates and `unlink()`s all files in `$this->path.'/'.$nsKey`, combined with path traversal could delete arbitrary files.
3. **DiskKeyCache insecure directory creation** -- `mkdir($cacheDir)` with no explicit permissions (inherits umask).
4. **Stream context injection** -- `StreamBuffer.php:258-259` merges `stream_context_options` from params directly into the stream context, allowing `ssl.verify_peer=false`, proxy configuration, etc.
5. **Temporary file race condition** -- `TemporaryFileByteStream` uses `tempnam()` with a predictable prefix in a shared directory. Between creation and use, a local attacker could exploit a symlink race (TOCTOU).
6. **ArrayByteStream memory amplification** -- `write()` splits input into single-character array entries. Each PHP array element uses ~72 bytes of overhead, creating a 72x memory amplification factor.

## Affected Files

| File | Line | Risk |
|-|-|-|
| `lib/classes/Swift/KeyCache/DiskKeyCache.php:197` | `hasKey()` | Path traversal read |
| `lib/classes/Swift/KeyCache/DiskKeyCache.php:210` | `clearKey()` | Arbitrary file delete |
| `lib/classes/Swift/KeyCache/DiskKeyCache.php:261` | `getHandle()` | Arbitrary file write |
| `lib/classes/Swift/KeyCache/DiskKeyCache.php:241` | `prepareCache()` | Insecure directory |
| `lib/classes/Swift/KeyCache/DiskKeyCache.php:283` | `__destruct()` | POP chain gadget |
| `lib/classes/Swift/Transport/StreamBuffer.php:258-259` | Stream context merge | Config injection |
| `lib/classes/Swift/ByteStream/TemporaryFileByteStream.php:18` | `tempnam()` | TOCTOU race |
| `lib/classes/Swift/ByteStream/ArrayByteStream.php:105-108` | `str_split($bytes)` | 72x memory amplification |

## Existing Controls

- Cache keys are typically generated internally (hex-encoded message IDs)
- `TemporaryFileByteStream` uses `tempnam()` for unique names
- `__destruct` cleanup for temporary files
- Transport `__sleep()`/`__wakeup()` prevent serialization (limits POP chain exploitation)

## Mitigation Plan

### Phase 1: Path Sanitization (Immediate)
- Validate `$nsKey` and `$itemKey` in DiskKeyCache:
  ```php
  private function sanitizeKey(string $key): string
  {
      if (preg_match('/[^a-zA-Z0-9._-]/', $key)) {
          throw new Swift_KeyCache_KeyCacheException(
              'Cache key contains invalid characters: ' . $key
          );
      }
      return $key;
  }
  ```
- Apply `sanitizeKey()` in `hasKey()`, `getString()`, `importString()`, `importFromByteStream()`, `getHandle()`, `clearKey()`, `clearAll()`

### Phase 2: Directory Permissions (Short-term)
- Set explicit permissions on cache directory creation:
  ```php
  mkdir($cacheDir, 0700, true);
  ```
- Set permissions on temp files after creation:
  ```php
  $filePath = tempnam(sys_get_temp_dir(), 'FileByteStream');
  chmod($filePath, 0600);
  ```

### Phase 3: Stream Context Hardening (Short-term)
- Validate stream context options against an allowlist of safe keys:
  ```php
  private const ALLOWED_SSL_OPTIONS = [
      'verify_peer', 'verify_peer_name', 'peer_name',
      'cafile', 'capath', 'peer_fingerprint',
  ];
  ```
- Reject unknown or dangerous options (e.g., `proxy`, `request_fulluri`)
- Always force `verify_peer=true` unless explicitly overridden with a warning

### Phase 4: Memory Safety (Medium-term)
- Replace `str_split($bytes)` in `ArrayByteStream::write()` with a more memory-efficient approach:
  ```php
  // Store as string chunks instead of per-byte array
  $this->chunks[] = $bytes;
  $this->arraySize += strlen($bytes);
  ```
- Add a configurable size limit to prevent unbounded growth

## Test Cases

```php
// Path traversal should be rejected
$this->expectException(Swift_KeyCache_KeyCacheException::class);
$cache->hasKey('../../etc', 'passwd');

// Null bytes should be rejected
$this->expectException(Swift_KeyCache_KeyCacheException::class);
$cache->hasKey("test\0evil", 'key');

// Cache directory should have 0700 permissions
$cache = new Swift_KeyCache_DiskKeyCache(..., '/tmp/test-cache');
$cache->importString('ns', 'key', 'data', Swift_KeyCache::MODE_WRITE);
$perms = fileperms('/tmp/test-cache') & 0777;
$this->assertSame(0700, $perms);

// ArrayByteStream should reject oversized writes
$stream = new Swift_ByteStream_ArrayByteStream();
$this->expectException(Swift_IoException::class);
$stream->write(str_repeat('x', 100 * 1024 * 1024)); // 100 MB
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Cache keys internally generated (hex-encoded) | **EXISTING** | Typical usage uses hex-encoded message IDs |
| `tempnam()` for unique names | **EXISTING** | `TemporaryFileByteStream` uses `tempnam()` |
| `__destruct` cleanup | **EXISTING** | Temporary file cleanup in destructors |
| Transport `__sleep()`/`__wakeup()` throw | **EXISTING** | Limits POP chain exploitation |
| DiskKeyCache key sanitization | **NOT DONE** | `DiskKeyCache.php:195,206,257`: `$nsKey`/`$itemKey` concatenated into paths without sanitization |
| Directory permission hardening | **NOT DONE** | `DiskKeyCache.php:241`: `prepareCache()` uses `mkdir()` with no explicit permissions |
| Stream context option allowlist | **NOT DONE** | `StreamBuffer.php:258-259`: arbitrary `stream_context_options` accepted |
| `ArrayByteStream` memory limit | **NOT DONE** | `str_split($bytes)` creates 72x memory amplification with no size limit |
| Temp file permission setting | **NOT DONE** | No `chmod(0600)` after `tempnam()` |

**Overall Status:** NOT STARTED -- DiskKeyCache path traversal via unsanitized keys remains the primary vulnerability. All proposed mitigations are pending.

## Risk After Mitigation

**Residual Risk:** LOW -- With key sanitization, permission hardening, context validation, and memory limits, path traversal and injection attacks are blocked.
