# Threat 18: Email Address Validation and Encoding Gaps

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-20 (Improper Input Validation), CWE-838 (Inappropriate Encoding for Output)

---

## Description

The email address validation and encoding pipeline has several gaps: `Utf8AddressEncoder` performs zero validation (returns input verbatim), `IdnAddressEncoder` silently corrupts addresses when `idn_to_ascii()` fails, IDN homograph attacks are not mitigated, the address encoder is not consistently passed to all header types, and the permissive `RFCValidation` mode allows potentially dangerous address formats.

## Attack Vectors

1. **Utf8AddressEncoder passthrough** -- `encodeString()` returns input verbatim with no UTF-8 validity check, no control character filtering, no length check. If validation is bypassed, arbitrary bytes reach SMTP commands.
2. **IDN silent corruption** -- `idn_to_ascii()` returns `false` on failure. The return value is never checked, producing `user@` (empty domain) from `sprintf('%s@%s', $local, false)`.
3. **IDN homograph attacks** -- No UTS #39 confusable character detection. Domains like `xn--80ak6aa92e.com` (Cyrillic apple.com) pass validation, enabling phishing via visually identical sender domains.
4. **Null bytes in IDN encoder** -- The regex `/[^\x00-\x7F]/` permits `\x00` through `\x1F` in the local-part.
5. **Factory encoder inconsistency** -- `SimpleHeaderFactory` passes `$addressEncoder` to `MailboxHeader` but not to `PathHeader` or `IdentificationHeader`, causing encoding mismatches when SMTPUTF8 is configured.
6. **Permissive RFCValidation** -- Allows quoted local-parts like `"user\r\nINJECTION"@domain.com` which are technically RFC-valid but dangerous.
7. **No length enforcement** -- RFC 5321 limits local-part to 64 octets and domain to 255 octets. Neither encoders nor headers enforce this.
8. **Utf8Reader overlong sequences** -- `$length_map` maps 5-6 byte sequences (prohibited since RFC 3629) as valid.

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/AddressEncoder/Utf8AddressEncoder.php:33-36` | Zero validation |
| `lib/classes/Swift/AddressEncoder/IdnAddressEncoder.php:40` | Null bytes accepted |
| `lib/classes/Swift/AddressEncoder/IdnAddressEncoder.php:45` | `idn_to_ascii()` failure unchecked |
| `lib/classes/Swift/AddressEncoder/AutoAddressEncoder.php` | Delegates without additional validation |
| `lib/classes/Swift/Mime/SimpleHeaderFactory.php:138,157` | Encoder not passed to PathHeader/IdHeader |
| `lib/classes/Swift/Mime/Headers/MailboxHeader.php:356` | Uses permissive RFCValidation |
| `lib/classes/Swift/Mime/Headers/PathHeader.php:151` | Uses permissive RFCValidation |
| `lib/classes/Swift/CharacterReader/Utf8Reader.php:37` | 5-6 byte overlong sequences accepted |

## Existing Controls

- `egulias/email-validator` with `RFCValidation` in `MailboxHeader::assertValidAddress()`
- `MailboxHeader` calls `assertValidAddress()` for all addresses
- `AutoAddressEncoder` switches between IDN and UTF-8 based on server capability
- RFC 2047 encoding for non-ASCII header values

## Mitigation Plan

### Phase 1: Utf8AddressEncoder Validation (Immediate)
```php
public function encodeString(string $address): string
{
    if (!mb_check_encoding($address, 'UTF-8')) {
        throw new Swift_AddressEncoderException('Invalid UTF-8 in address');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $address)) {
        throw new Swift_AddressEncoderException('Control characters in address');
    }
    return $address;
}
```

### Phase 2: IDN Error Handling (Immediate)
```php
$ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
if (false === $ascii) {
    throw new Swift_AddressEncoderException(
        sprintf('IDN conversion failed for domain: %s', $domain)
    );
}
```

### Phase 3: Factory Consistency (Short-term)
- Pass `$this->addressEncoder` to `createIdHeader()` and `createPathHeader()` in `SimpleHeaderFactory`
- Add the encoder parameter to `IdentificationHeader` and `PathHeader` constructors

### Phase 4: Stricter Validation (Medium-term)
- Consider using `NoRFCWarningsValidation` instead of `RFCValidation` to reject more edge cases
- Add length enforcement for local-part (64 bytes) and domain (255 bytes)
- Add null byte rejection in `IdnAddressEncoder` regex: `/[\x00-\x1F\x7F]/` should be rejected, not accepted
- Fix `Utf8Reader` to reject 5-6 byte sequences (cap `$length_map` at 4)

### Phase 5: IDN Homograph Mitigation (Long-term)
- Add optional mixed-script detection using ICU's `Spoofchecker` class (PHP intl extension):
  ```php
  if (class_exists('Spoofchecker')) {
      $checker = new Spoofchecker();
      $checker->setChecks(Spoofchecker::MIXED_SCRIPT_CONFUSABLE);
      if ($checker->isSuspicious($domain)) {
          trigger_error("Suspicious IDN domain: $domain", E_USER_WARNING);
      }
  }
  ```
- Document IDN homograph risks in developer documentation

## Test Cases

```php
// Utf8AddressEncoder should reject invalid UTF-8
$this->expectException(Swift_AddressEncoderException::class);
$encoder->encodeString("user@\xFF\xFEdomain.com");

// Utf8AddressEncoder should reject control characters
$this->expectException(Swift_AddressEncoderException::class);
$encoder->encodeString("user\r\n@domain.com");

// IdnAddressEncoder should throw on IDN failure
$this->expectException(Swift_AddressEncoderException::class);
$encoder->encodeString('user@' . str_repeat('x', 64) . '.com'); // label too long

// PathHeader should use configured encoder
$factory = new Swift_Mime_SimpleHeaderFactory($encoder, ...);
$header = $factory->createPathHeader('Return-Path');
// Verify encoder is used
```

## Risk After Mitigation

**Residual Risk:** LOW -- With validation in all encoders, IDN error handling, factory consistency, and stricter validation, address-based injection is blocked.
