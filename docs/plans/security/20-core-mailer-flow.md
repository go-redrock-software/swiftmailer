# Threat 20: Core Mailer Flow and Recipient Integrity

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** MEDIUM
**Likelihood:** Low
**CWE:** CWE-284 (Improper Access Control), CWE-471 (Modification of Assumed-Immutable Data)

---

## Description

The core `Swift_Mailer` send flow has several security gaps: `createMessage()` allows container service lookup injection, the `Envelope` class enables full decoupling of SMTP recipients from message headers (invisible delivery), `SentMessage` exposes the transport object (with credentials), and the `Preferences` class allows arbitrary container alias resolution.

## Attack Vectors

1. **Service lookup injection** -- `Swift_Mailer::createMessage($service)` concatenates `$service` into `DependencyContainer::lookup('message.'.$service)`. Unsanitized input allows resolution of arbitrary container services, potentially leaking credentials from transport objects.
2. **Invisible recipient delivery** -- `send()` accepts `Swift_Envelope` overriding SMTP RCPT TO recipients independently of headers. No validation ensures envelope recipients relate to header recipients. An attacker controlling the envelope can deliver to addresses with no trace in message headers.
3. **SentMessage credential exposure** -- `SentMessage::getTransport()` returns the full transport object including public `$apiKey` property. Logging or serializing `SentMessage` leaks credentials.
4. **Preferences container aliasing** -- `Preferences::setCacheType($type)` builds `'cache.'.$type` for container lookup without validation. User-controlled `$type` resolves unintended container entries.
5. **No rate limiting by default** -- `ThrottlerPlugin` is opt-in. Default send path has no volume limits. Compromised application code can send unlimited email.
6. **Mutable message in send path** -- The same message object is passed through event listeners and transport. Any listener can modify the message (body, recipients, headers) before or during send.

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Mailer.php:41` | Container lookup injection |
| `lib/classes/Swift/Mailer.php:59` | Envelope override with no validation |
| `lib/classes/Swift/SentMessage.php:86-93` | Transport/credential exposure |
| `lib/classes/Swift/Preferences.php:77` | Container alias injection |
| `lib/classes/Swift/Mailer.php:59-78` | No rate limiting |

## Existing Controls

- `DependencyContainer` entries are typically set during bootstrap, not at runtime
- `Swift_Envelope` is a readonly class (immutable after construction)
- `SentMessage` clones the original message

## Mitigation Plan

### Phase 1: Input Validation (Immediate)
- Validate `$service` in `createMessage()`:
  ```php
  public function createMessage(string $service = 'message'): Swift_Mime_SimpleMessage
  {
      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
          throw new InvalidArgumentException('Invalid message service name');
      }
      return $this->container->lookup('message.' . $service);
  }
  ```
- Validate `$type` in `Preferences::setCacheType()`:
  ```php
  if (!in_array($type, ['array', 'disk', 'null'], true)) {
      throw new InvalidArgumentException('Invalid cache type');
  }
  ```

### Phase 2: SentMessage Credential Protection (Short-term)
- Remove `getTransport()` from `SentMessage` or return a transport identifier instead:
  ```php
  public function getTransportName(): string
  {
      return get_class($this->transport);
  }
  ```
- Add `__debugInfo()` to `SentMessage` that omits the transport object

### Phase 3: Envelope Auditing (Medium-term)
- Add optional envelope validation in `send()`:
  ```php
  if ($envelope !== null && $this->strictEnvelope) {
      $headerRecipients = array_merge(
          array_keys($message->getTo() ?? []),
          array_keys($message->getCc() ?? []),
          array_keys($message->getBcc() ?? []),
      );
      $envelopeRecipients = $envelope->getTo();
      $extra = array_diff($envelopeRecipients, $headerRecipients);
      if (!empty($extra)) {
          throw new Swift_SwiftException(
              'Envelope contains recipients not in message headers: ' . implode(', ', $extra)
          );
      }
  }
  ```
- Default `$strictEnvelope = false` for backwards compatibility

### Phase 4: Default Rate Limiting (Long-term)
- Add a configurable default rate limit in `Swift_Mailer`:
  ```php
  public function setMaxSendsPerMinute(int $limit): void
  ```
- When exceeded, throw `Swift_SwiftException` rather than silently queuing
- Document recommended rate limits per transport provider

## Test Cases

```php
// createMessage should reject traversal attempts
$this->expectException(InvalidArgumentException::class);
$mailer->createMessage('../../transport');

// SentMessage should not expose transport credentials
$sent = new Swift_SentMessage($message, $transport);
$debug = print_r($sent, true);
$this->assertStringNotContainsString('api-key-value', $debug);

// Strict envelope should reject extra recipients
$mailer->setStrictEnvelope(true);
$message->setTo(['legit@example.com' => 'Legit']);
$envelope = new Swift_Envelope('from@test.com', ['legit@example.com', 'extra@evil.com']);
$this->expectException(Swift_SwiftException::class);
$mailer->send($message, $envelope);

// Preferences should reject unknown cache types
$this->expectException(InvalidArgumentException::class);
Swift_Preferences::getInstance()->setCacheType('../../evil');
```

## Risk After Mitigation

**Residual Risk:** LOW -- With input validation, credential protection, envelope auditing, and rate limiting, the core send flow is hardened against abuse.
