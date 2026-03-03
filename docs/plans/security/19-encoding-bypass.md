# Threat 19: Content Encoding Bypass and MIME Boundary Attacks

**STRIDE Category:** Tampering
**Severity:** MEDIUM
**Likelihood:** Low
**CWE:** CWE-116 (Improper Encoding or Escaping of Output), CWE-502 (Deserialization)

---

## Description

The encoding subsystem contains several issues: `RawContentEncoder` and `NullContentEncoder` pass content through without transformation (enabling MIME boundary injection if misconfigured), charset case-sensitivity causes inconsistent encoder selection, `QpEncoder` contains a deserialization gadget (`__wakeup`), and the `PlainContentEncoder` doesn't wrap long unbreakable lines.

## Attack Vectors

1. **MIME boundary injection via passthrough encoders** -- `RawContentEncoder` and `NullContentEncoder` return input verbatim. If either is used for a MIME part declared as `7bit` but containing raw 8-bit content with `\r\n--boundary` sequences, an attacker can inject additional MIME parts, altering the message structure.
2. **Charset case-sensitivity routing** -- `QpContentEncoderProxy` compares charset with strict `'utf-8' === $this->charset`. Charset values `UTF-8`, `Utf-8` hit different code paths. `NativeQpContentEncoder` throws for `UTF-8` (uppercase).
3. **QpEncoder deserialization gadget** -- `QpEncoder` has `__sleep`/`__wakeup` methods. On deserialization, `__wakeup` populates shared state from `$charStream` and `$filter` properties. If these are attacker-controlled objects (via `unserialize()` on untrusted data), they can trigger method calls during wakeup.
4. **Oversized lines from negative offset** -- `QpEncoder`, `Base64Encoder`, and `Rfc2231Encoder` don't validate `firstLineOffset`. A negative value produces lines exceeding RFC 2045's 76-char limit and RFC 5322's 998-byte hard limit.
5. **PlainContentEncoder unbreakable lines** -- `safeWordwrap` splits only on whitespace. A long token with no spaces (Base64 blob, long URL) produces lines violating RFC 5322, causing MTA rejection or parser differential attacks.
6. **NativeQpContentEncoder memory exhaustion** -- Reads the entire stream into memory before encoding. No size limit.

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Mime/ContentEncoder/RawContentEncoder.php:34` | Passthrough |
| `lib/classes/Swift/Mime/ContentEncoder/NullContentEncoder.php:47` | Passthrough |
| `lib/classes/Swift/Mime/ContentEncoder/QpContentEncoderProxy.php:86` | Charset case bug |
| `lib/classes/Swift/Mime/ContentEncoder/NativeQpContentEncoder.php:55` | Charset case / memory |
| `lib/classes/Swift/Encoder/QpEncoder.php:126-134` | Deserialization gadget |
| `lib/classes/Swift/Encoder/QpEncoder.php:172` | Negative offset |
| `lib/classes/Swift/Encoder/Base64Encoder.php:45` | Negative offset |
| `lib/classes/Swift/Encoder/Rfc2231Encoder.php:58` | Negative offset |
| `lib/classes/Swift/Mime/ContentEncoder/PlainContentEncoder.php:138` | No word break |

## Existing Controls

- `SimpleMimeEntity` uses `Base64ContentEncoder` or `QpContentEncoder` for binary/text by default
- `NullContentEncoder` and `RawContentEncoder` are not used by default
- Content-Transfer-Encoding header is set to match the actual encoder

## Mitigation Plan

### Phase 1: Input Validation (Immediate)
- Validate `firstLineOffset` in all encoders:
  ```php
  if ($firstLineOffset < 0) {
      throw new InvalidArgumentException('firstLineOffset must be non-negative');
  }
  ```
- Normalize charset comparison to lowercase:
  ```php
  'utf-8' === strtolower($this->charset)
  ```

### Phase 2: Passthrough Encoder Safety (Short-term)
- Add deprecation notice to `RawContentEncoder` and `NullContentEncoder`
- Or add MIME boundary scanning:
  ```php
  public function encodeString($string, $firstLineOffset = 0, $maxLineLength = 0)
  {
      if (preg_match('/\r\n--[^\r\n]+\r\n/', $string)) {
          throw new Swift_SwiftException('Raw content appears to contain MIME boundary');
      }
      return $string;
  }
  ```

### Phase 3: Memory Safety (Short-term)
- Add streaming encoding in `NativeQpContentEncoder` (read in chunks):
  ```php
  while (false !== ($chunk = $os->read(8192))) {
      $encoded .= quoted_printable_encode($chunk);
  }
  ```
- Or add a size limit with clear error

### Phase 4: Deserialization Hardening (Medium-term)
- Remove `__sleep`/`__wakeup` from `QpEncoder` and convert `$safeMapShare` to lazy initialization
- Or throw in `__wakeup` (same pattern as transports)

### Phase 5: Line Length Enforcement (Medium-term)
- In `PlainContentEncoder::safeWordwrap`, force-break lines exceeding 998 bytes:
  ```php
  // After whitespace-based wrapping, check for oversized lines
  foreach ($lines as &$line) {
      if (strlen($line) > 998) {
          $line = wordwrap($line, 998, "\r\n", true);
      }
  }
  ```

## Test Cases

```php
// Negative firstLineOffset should throw
$this->expectException(InvalidArgumentException::class);
$encoder->encodeString('test', -1, 76);

// Charset comparison should be case-insensitive
$proxy = new Swift_Mime_ContentEncoder_QpContentEncoderProxy($safe, $native, 'UTF-8');
// Should not throw, should use native encoder

// Passthrough encoders should warn on boundary-like content
$raw = new Swift_Mime_ContentEncoder_RawContentEncoder();
$this->expectDeprecation();
$raw->encodeString("content\r\n--boundary\r\n");

// QpEncoder should not be deserializable
$serialized = serialize($encoder);
$this->expectException(BadMethodCallException::class);
unserialize($serialized);
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Default encoders (Base64/QP) used by SimpleMimeEntity | **EXISTING** | `RawContentEncoder`/`NullContentEncoder` not used by default |
| Content-Transfer-Encoding header matches encoder | **EXISTING** | Correct header set by default |
| `QpEncoder` has `__sleep()`/`__wakeup()` | **EXISTING** | `QpEncoder.php:121-126` -- but `__wakeup()` repopulates state rather than throwing |
| `firstLineOffset` negative value validation | **NOT DONE** | No validation in `QpEncoder`, `Base64Encoder`, or `Rfc2231Encoder` |
| Charset case normalization | **NOT DONE** | `QpContentEncoderProxy` uses strict `'utf-8' === $this->charset` comparison |
| MIME boundary scanning in passthrough encoders | **NOT DONE** | `RawContentEncoder.php:32` and `NullContentEncoder.php:46` return input verbatim |
| `NativeQpContentEncoder` memory limit | **NOT DONE** | Reads entire stream into memory before encoding |
| `QpEncoder` deserialization hardening | **NOT DONE** | `__wakeup()` repopulates state instead of throwing; usable as gadget |
| PlainContentEncoder line length enforcement | **NOT DONE** | No force-break for lines exceeding 998 bytes |

**Overall Status:** NOT STARTED -- All proposed mitigations are pending. `QpEncoder` deserialization gadget and charset case-sensitivity bug remain.

## Risk After Mitigation

**Residual Risk:** LOW -- With input validation, charset normalization, memory limits, and deserialization prevention, encoding-based attacks are mitigated.
