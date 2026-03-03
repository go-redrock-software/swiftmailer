# Threat 01: SMTP Header Injection (CRLF Injection)

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** HIGH
**Likelihood:** Medium
**CWE:** CWE-93 (Improper Neutralization of CRLF Sequences)

---

## Description

An attacker who controls header values (e.g., display names, custom headers, or subject lines) could inject CRLF sequences (`\r\n`) to add arbitrary SMTP headers or even inject additional message body content. This can lead to BCC injection (adding hidden recipients), spoofed headers, or message body manipulation.

## Attack Vectors

1. **Custom header values** — `Swift_Message::getHeaders()->addTextHeader('X-Custom', $userInput)` with unvalidated user input containing `\r\n`
2. **Display names in From/To** — `$message->setFrom(['attacker@evil.com' => "Name\r\nBcc: victim@target.com"])`
3. **Subject line** — `$message->setSubject("Hello\r\nBcc: victim@target.com")`
4. **Reply-To header** — User-controlled reply addresses with injected CRLF

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Mime/Headers/UnstructuredHeader.php` | No CRLF stripping on raw values |
| `lib/classes/Swift/Mime/Headers/MailboxHeader.php` | Display name concatenation |
| `lib/classes/Swift/Mime/Headers/ParameterizedHeader.php` | Parameter value injection |
| `lib/classes/Swift/Mime/SimpleHeaderSet.php` | Header registration entry point |

## Existing Controls

- RFC 2822 email address validation via `egulias/email-validator` in `MailboxHeader::assertValidAddress()`
- Header encoding (Base64/QP) in `Swift_Mime_HeaderEncoder` naturally encodes some control characters
- SMTP commands wrap addresses in angle brackets `<...>` preventing bare CRLF in MAIL FROM/RCPT TO
- Dot-stuffing (`\r\n.` -> `\r\n..`) prevents message body termination injection

## Control Gaps

1. **No explicit CRLF filtering** in `UnstructuredHeader::setValue()` or `TextHeader`
2. **Display names** pass through encoding but raw values may bypass if encoding is not triggered
3. **Custom X-headers** added by API transports (`extractTags()`, `extractMetadata()`) rely on caller sanitization
4. **No regression tests** specifically for header injection attempts

## Mitigation Plan

### Phase 1: Detection (Immediate)
- Add unit tests that attempt CRLF injection in:
  - Subject headers
  - From/To display names
  - Custom X-headers
  - ParameterizedHeader parameters
- Verify current encoding layer catches all injection vectors

### Phase 2: Prevention (Short-term)
- Add CRLF stripping in `AbstractHeader::setFieldBody()` or equivalent base method
- Strip `\r`, `\n`, `\0` from all header field names in `SimpleHeaderSet::addHeader()`
- Add explicit validation: reject or strip `[\r\n]` from all header values before encoding
- Consider a `HeaderSanitizer` utility that all header types call

### Phase 3: Hardening (Medium-term)
- Add `@dataProvider` PHPUnit tests with comprehensive injection payloads
- Add static analysis rule (PHPStan/Psalm) flagging unsanitized user input in header methods
- Document safe usage patterns in developer documentation

## Test Cases

```php
// Should strip or reject CRLF in subject
$message->setSubject("Test\r\nBcc: hidden@evil.com");
// Expected: subject = "Test Bcc: hidden@evil.com" (stripped) or exception

// Should strip CRLF in display name
$message->setFrom(['test@example.com' => "Name\r\nX-Injected: yes"]);
// Expected: display name = "Name X-Injected: yes" or exception

// Should strip CRLF in custom headers
$message->getHeaders()->addTextHeader('X-Custom', "value\r\nBcc: hidden@evil.com");
// Expected: value = "value Bcc: hidden@evil.com" or exception
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| CRLF stripping in UnstructuredHeader | **PENDING** | No CRLF filtering found in `lib/classes/Swift/Mime/Headers/UnstructuredHeader.php` |
| CRLF stripping in SimpleHeaderSet | **PENDING** | No control character filtering in header registration |
| Header injection regression tests | **PENDING** | No dedicated injection test cases found |
| Encoding layer provides partial mitigation | **EXISTING** | Base64/QP encoding in `Swift_Mime_HeaderEncoder` naturally encodes some control characters |
| RFC 2822 email validation | **EXISTING** | `egulias/email-validator` used in `MailboxHeader::assertValidAddress()` |

**Overall Status:** NOT STARTED -- All Phase 1-3 mitigations remain pending. Existing encoding layer provides partial but incomplete protection.

## Risk After Mitigation

**Residual Risk:** LOW — With CRLF stripping and comprehensive tests, injection becomes infeasible.
