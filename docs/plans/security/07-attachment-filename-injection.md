# Threat 07: Attachment Filename Injection

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-73 (External Control of File Name), CWE-22 (Path Traversal)

---

## Description

Attachment filenames are set by the caller and passed through to email recipients and API payloads without sanitization. A malicious filename can exploit recipient mail clients (path traversal on download, XSS in webmail), or be used to construct misleading Content-Disposition headers that trick users into executing malicious files.

## Attack Vectors

1. **Path traversal in filename** — `../../.bashrc` or `..\windows\system32\cmd.exe` as an attachment filename could cause recipient mail clients to save files to unexpected locations
2. **Extension masquerading** — `document.pdf.exe` or `invoice.pdf                                  .exe` (with Unicode RTL override) to trick recipients
3. **CRLF in Content-Disposition** — Newlines in the filename parameter could inject additional MIME headers
4. **Null byte injection** — `file.php\0.jpg` could bypass file type checks in older systems
5. **XSS in webmail** — Filenames like `<script>alert(1)</script>.html` could execute in webmail clients that display filenames unsanitized
6. **Oversized filenames** — Extremely long filenames could cause buffer issues in receiving systems

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Mime/Attachment.php` | `setFilename()` accepts arbitrary strings |
| `lib/classes/Swift/Attachment.php` | Convenience factory, delegates to Mime\Attachment |
| `lib/classes/Swift/Transport/AbstractHttpApiTransport.php:272-288` | `getMessageAttachments()` passes filename directly to API payload |
| `lib/classes/Swift/Mime/Headers/ParameterizedHeader.php` | Content-Disposition filename parameter encoding |
| All API transports | Filename used in JSON/multipart payloads sent to provider APIs |

## Existing Controls

- `ParameterizedHeader` RFC 2231 encoding handles non-ASCII characters in filenames
- Token regex validation in parameter values prevents some special characters
- Base64/QP encoding for MIME headers naturally encodes control characters
- API transports send filenames as JSON string values (escaped by `json_encode()`)

## Control Gaps

1. **No path traversal prevention** — Directory separators (`/`, `\`) not stripped from filenames
2. **No null byte filtering** — `\0` not explicitly rejected
3. **No filename length limit** — Arbitrarily long filenames accepted
4. **No extension validation** — No mechanism to warn about dangerous extensions
5. **No Unicode normalization** — RTL override characters (U+202E) not detected or stripped
6. **No Content-Type/filename consistency check** — A `.exe` with `application/pdf` Content-Type is accepted

## Mitigation Plan

### Phase 1: Filename Sanitization (Immediate)
Add sanitization in `Swift_Mime_Attachment::setFilename()`:
```php
public function setFilename($filename)
{
    // Strip path separators
    $filename = str_replace(['/', '\\'], '', $filename);

    // Strip null bytes
    $filename = str_replace("\0", '', $filename);

    // Strip control characters (U+0000-U+001F, U+007F)
    $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);

    // Strip Unicode directional overrides (RTL/LTR)
    $filename = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $filename);

    // Enforce maximum length (255 bytes, common filesystem limit)
    if (strlen($filename) > 255) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $filename = substr($name, 0, 255 - strlen($ext) - 1) . '.' . $ext;
    }

    return parent::setFilename($filename);
}
```

### Phase 2: API Transport Sanitization (Short-term)
- Ensure `getMessageAttachments()` in `AbstractHttpApiTransport` applies the same sanitization
- For multipart form uploads (Mailgun), ensure filename is properly escaped in Content-Disposition

### Phase 3: Optional Extension Warnings (Medium-term)
- Add an optional `DangerousAttachmentPlugin` that warns or blocks known dangerous extensions (`.exe`, `.bat`, `.cmd`, `.scr`, `.pif`, `.js`, `.vbs`)
- This should be opt-in to avoid breaking legitimate use cases

### Phase 4: Documentation
- Document filename sanitization behavior
- Document that filenames from user uploads should be sanitized before passing to Swiftmailer
- Provide examples of safe attachment handling

## Test Cases

```php
// Path traversal should be stripped
$attachment = Swift_Attachment::fromPath('/tmp/file.txt');
$attachment->setFilename('../../etc/passwd');
$this->assertSame('etcpasswd', $attachment->getFilename()); // or 'passwd'

// Null bytes should be stripped
$attachment->setFilename("file.php\0.jpg");
$this->assertSame('file.php.jpg', $attachment->getFilename());

// RTL override should be stripped
$attachment->setFilename("invoice\u{202E}fdp.exe");
$this->assertSame('invoicefdp.exe', $attachment->getFilename());

// Long filenames should be truncated
$attachment->setFilename(str_repeat('a', 300) . '.pdf');
$this->assertLessThanOrEqual(255, strlen($attachment->getFilename()));
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| RFC 2231 encoding in ParameterizedHeader | **EXISTING** | Non-ASCII characters encoded |
| Token regex validation | **EXISTING** | Some special characters prevented |
| JSON encoding for API payloads | **EXISTING** | `json_encode()` escapes special characters |
| Path traversal prevention (`/`, `\` stripping) | **PENDING** | `Attachment.php:91`: `setFilename()` accepts arbitrary strings |
| Null byte filtering | **PENDING** | No `\0` rejection |
| Filename length limit | **PENDING** | No length check |
| Unicode directional override detection | **PENDING** | No RTL override stripping |
| DangerousAttachmentPlugin | **PENDING** | No extension-based blocking |

**Overall Status:** NOT STARTED -- `setFilename()` accepts arbitrary strings without sanitization. All proposed mitigations are pending.

## Risk After Mitigation

**Residual Risk:** LOW — With path traversal prevention, control character stripping, and length limits, filename-based attacks are mitigated. Downstream mail client vulnerabilities remain outside scope.
