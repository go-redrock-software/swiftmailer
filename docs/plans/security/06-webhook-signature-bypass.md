# Threat 06: Webhook Signature Bypass

**STRIDE Category:** Spoofing, Tampering
**Severity:** HIGH
**Likelihood:** Medium
**CWE:** CWE-345 (Insufficient Verification of Data Authenticity)

---

## Description

`Swift_Webhook_RequestHandler` accepts an optional `$secret` parameter. When `null`, signature verification is completely skipped, allowing any HTTP client to submit forged webhook payloads. An attacker can inject fake delivery events (bounces, opens, clicks) that corrupt delivery tracking data or trigger unintended application behavior.

## Attack Vectors

1. **Missing secret configuration** — Developer deploys webhook handler without setting a signing secret, leaving verification disabled
2. **Null secret bypass** — `RequestHandler::handle()` explicitly skips `$converter->verify()` when `$secret === null`
3. **Forged delivery events** — Attacker sends fake "delivered" events to suppress bounce processing
4. **Forged bounce events** — Attacker sends fake "bounced" events to trigger recipient suppression in the consuming application
5. **Replay attacks** — No timestamp validation means captured legitimate webhooks can be replayed indefinitely
6. **Provider-specific verification gaps** — Individual converters may implement `verify()` differently or incorrectly

## Affected Files

| File | Line | Risk |
|-|-|-|
| `lib/classes/Swift/Webhook/RequestHandler.php:46-49` | Null-secret check skips verification entirely |
| `lib/classes/Swift/Webhook/AbstractPayloadConverter.php:18-27` | HMAC verification with `hash_equals()` (good) |
| Each converter in `lib/classes/Swift/Webhook/Converter/*.php` | Provider-specific `verify()` implementations |
| `lib/classes/Swift/Webhook/PayloadConverterInterface.php` | Contract defines `verify()` method |

## Existing Controls

- Timing-safe HMAC comparison via `hash_equals()` in `AbstractPayloadConverter::verifyHmac()`
- `#[SensitiveParameter]` on secret parameters
- `SignatureVerificationException` thrown for invalid signatures
- JSON decoding validation with `json_last_error()` check

## Control Gaps

1. **Null secret allows complete bypass** — No warning or error when `$secret === null`
2. **No timestamp validation** — Replayed webhooks are accepted regardless of age
3. **No IP allowlisting** — Webhook endpoints don't validate source IP against provider ranges
4. **No request deduplication** — Same webhook event can be processed multiple times
5. **No rate limiting** — Unlimited webhook submissions accepted
6. **Provider verification coverage** — Not all 15 converters may implement `verify()` correctly or at all

## Mitigation Plan

### Phase 1: Mandatory Signature Verification (Immediate)
Change `RequestHandler::handle()` to require a secret:
```php
public function handle(
    Swift_Webhook_PayloadConverterInterface $converter,
    string $rawBody,
    array $headers,
    #[SensitiveParameter] string $secret,  // Remove nullable
): array {
    if ('' === $secret) {
        throw new InvalidArgumentException('Webhook signing secret must not be empty.');
    }

    if (!$converter->verify($rawBody, $headers, $secret)) {
        throw new Swift_Webhook_SignatureVerificationException($converter->getProviderName());
    }
    // ...
}
```

### Phase 2: Timestamp Validation (Short-term)
Add optional timestamp validation to prevent replay attacks:
```php
public function handle(
    // ...
    int $maxAge = 300,  // 5 minutes
): array {
    // After signature verification
    $timestamp = $converter->extractTimestamp($rawBody, $headers);
    if (null !== $timestamp && abs(time() - $timestamp) > $maxAge) {
        throw new Swift_Webhook_SignatureVerificationException(
            $converter->getProviderName() . ': webhook timestamp expired'
        );
    }
    // ...
}
```

### Phase 3: Converter Audit (Short-term)
- Audit all 15 converter `verify()` implementations:
  - Ensure each one actually validates the signature (not just returning `true`)
  - Verify correct HMAC algorithm for each provider
  - Test with known-good and known-bad signatures from provider documentation

### Phase 4: Additional Controls (Medium-term)
- Add event deduplication interface (optional `EventIdStore` for tracking processed event IDs)
- Document IP allowlisting recommendations for each provider
- Add rate limiting guidance in webhook documentation
- Consider adding a `StrictRequestHandler` that enforces all controls by default

## Test Cases

```php
// Empty secret should throw
$this->expectException(InvalidArgumentException::class);
$handler->handle($converter, $rawBody, $headers, '');

// Missing signature header should throw
$this->expectException(Swift_Webhook_SignatureVerificationException::class);
$handler->handle($converter, $rawBody, [], 'valid-secret');

// Invalid signature should throw
$this->expectException(Swift_Webhook_SignatureVerificationException::class);
$handler->handle($converter, $rawBody, ['x-signature' => 'invalid'], 'valid-secret');

// Expired timestamp should throw
$oldPayload = json_encode(['timestamp' => time() - 600]);
$this->expectException(Swift_Webhook_SignatureVerificationException::class);
$handler->handle($converter, $oldPayload, $validHeaders, 'valid-secret', maxAge: 300);
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| `hash_equals()` in HMAC verification | **IMPLEMENTED** | `AbstractPayloadConverter.php:24`: timing-safe comparison |
| `#[SensitiveParameter]` on secrets | **IMPLEMENTED** | `RequestHandler.php:40`, all converter `verify()` methods |
| `SignatureVerificationException` | **IMPLEMENTED** | Thrown when signature is invalid |
| JSON decoding validation | **IMPLEMENTED** | `json_last_error()` check present |
| Null secret bypass still possible | **NOT FIXED** | `RequestHandler.php:46`: `if (null !== $secret)` skips verification entirely |
| Mailjet `verify()` always returns true | **NOT FIXED** | `MailjetConverter.php:41`: `return true;` -- no actual verification |
| Amazon SES `verify()` is header-only check | **NOT FIXED** | `AmazonSesConverter.php:34`: only checks `isset($headers['x-amz-sns-message-type'])` -- no real SNS signature verification |
| Timestamp validation | **NOT IMPLEMENTED** | No replay prevention |
| IP allowlisting | **NOT IMPLEMENTED** | No source IP validation |
| Event deduplication | **NOT IMPLEMENTED** | No deduplication interface |

**Overall Status:** PARTIALLY IMPLEMENTED -- Core HMAC infrastructure is solid. However, null-secret bypass remains, Mailjet has no real verification, and Amazon SES only checks for a header presence (trivially forged).

## Risk After Mitigation

**Residual Risk:** LOW — With mandatory verification, timestamp validation, and converter auditing, forged webhooks require possession of the signing secret.
