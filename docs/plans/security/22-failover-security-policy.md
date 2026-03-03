# Threat 22: Failover / Load-Balanced Transport Security Downgrade

**STRIDE Category:** Tampering, Information Disclosure
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-757 (Selection of Less-Secure Algorithm During Negotiation)

---

## Description

`FailoverTransport` and `LoadBalancedTransport` cycle through wrapped transports with no security policy enforcement. If a TLS-encrypted primary transport fails (due to network disruption, DNS poisoning, or SMTP server error), the system silently falls back to whatever transport is next in the list -- which may be a plaintext SMTP connection, a less-authenticated API transport, or a transport with `verify_peer=false`. There is no mechanism to enforce that all transports in a pool meet a minimum security level, and no logging or alerting when failover occurs.

## Attack Vectors

1. **Forced TLS downgrade via failover** -- Attacker disrupts the TLS SMTP connection (TCP RST injection, DNS poisoning). `FailoverTransport` catches the `Swift_TransportException`, silently kills the TLS transport, and tries the next one. If the next transport is `smtp://` (no TLS), credentials and email content are sent in plaintext.
2. **Predictable load balancing targeting** -- `LoadBalancedTransport` uses deterministic round-robin (`array_shift`/`array_push`). An attacker who can observe which transport handled a message (via email headers, DKIM signatures, source IPs) can predict rotation position and time attacks to target a specific weaker backend.
3. **Dead transport resurrection without validation** -- `LoadBalancedTransport::start()` merges dead transports back into the active pool without verifying they are functional. Previously failed transports may have stale TLS state, expired credentials, or corrupted connections.
4. **Silent exception swallowing** -- Both `FailoverTransport` and `LoadBalancedTransport` catch and discard `Swift_TransportException` during failover. No logging, no alerting. Plugins on inner transports may emit sensitive error details before the catch block executes.
5. **Retry amplification** -- `RetryTransport` wrapping a `FailoverTransport` multiplies retry attempts (N retries x M transports). With unvalidated parameters, this creates a tight loop that blocks the application.

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Transport/FailoverTransport.php:79-81` | Silent failover, no security check |
| `lib/classes/Swift/Transport/FailoverTransport.php:85-86` | Generic final exception hides individual failures |
| `lib/classes/Swift/Transport/LoadBalancedTransport.php:175-182` | Deterministic round-robin |
| `lib/classes/Swift/Transport/LoadBalancedTransport.php:92-93` | Dead transport resurrection |
| `lib/classes/Swift/Transport/LoadBalancedTransport.php:191-193` | `catch (Exception $e) {}` swallows all errors |
| `lib/classes/Swift/Transport/RetryTransport.php:40-49` | No parameter validation |

## Existing Controls

- `FailoverTransport` throws after exhausting all transports (final exception)
- `RetryTransport` has configurable `maxRetries` (default 3)
- Applications can manually order transports by security level

## Mitigation Plan

### Phase 1: Failover Logging (Immediate)
- Log when failover occurs and to which transport:
  ```php
  catch (Swift_TransportException $e) {
      $failedTransport = get_class($transport);
      error_log(sprintf(
          'Swiftmailer: Failover from %s due to: %s',
          $failedTransport,
          $e->getMessage()
      ));
      $transport->stop();
  }
  ```
- Fire a new `FailoverEvent` that security-conscious plugins can listen to

### Phase 2: Transport Security Policy (Short-term)
- Add an optional `SecurityPolicy` that validates transports in the pool:
  ```php
  class Swift_Transport_SecurityPolicy
  {
      public bool $requireTls = false;
      public bool $requireAuth = false;
      public ?string $minimumTlsVersion = null;

      public function validate(Swift_Transport $transport): void
      {
          if ($this->requireTls && $transport instanceof Swift_Transport_EsmtpTransport) {
              if ($transport->getEncryption() === CONNECTION_ENCRYPTION_MODE_NONE) {
                  throw new Swift_TransportException(
                      'Transport does not meet minimum security policy: TLS required'
                  );
              }
          }
      }
  }
  ```
- Apply the policy during `setTransports()` and before failover activation

### Phase 3: Parameter Validation (Short-term)
- Validate `RetryTransport` constructor parameters:
  ```php
  if ($maxRetries < 0 || $maxRetries > 10) {
      throw new InvalidArgumentException('maxRetries must be between 0 and 10');
  }
  if ($baseDelayMs < 0 || $baseDelayMs > 30000) {
      throw new InvalidArgumentException('baseDelayMs must be between 0 and 30000');
  }
  ```

### Phase 4: Dead Transport Validation (Medium-term)
- Before resurrecting dead transports in `LoadBalancedTransport::start()`, ping them:
  ```php
  foreach ($this->deadTransports as $key => $transport) {
      try {
          if ($transport->ping()) {
              $this->transports[] = $transport;
              unset($this->deadTransports[$key]);
          }
      } catch (\Exception $e) {
          // Leave in dead pool
      }
  }
  ```

### Phase 5: Non-Deterministic Load Balancing (Medium-term)
- Replace round-robin with randomized selection to prevent targeting:
  ```php
  private function getNextTransport(): ?Swift_Transport
  {
      if (empty($this->transports)) {
          return null;
      }
      $index = random_int(0, count($this->transports) - 1);
      return $this->transports[$index];
  }
  ```

## Test Cases

```php
// Failover should log when switching transports
$failover = new Swift_Transport_FailoverTransport([$tlsTransport, $plainTransport]);
// Force $tlsTransport to fail
// Assert log contains failover message

// Security policy should reject plaintext transports
$policy = new Swift_Transport_SecurityPolicy(requireTls: true);
$plainTransport = new Swift_SmtpTransport('host', 25);
$this->expectException(Swift_TransportException::class);
$policy->validate($plainTransport);

// RetryTransport should reject invalid parameters
$this->expectException(InvalidArgumentException::class);
new Swift_Transport_RetryTransport($inner, maxRetries: -1);

// Dead transports should be pinged before resurrection
$deadTransport = $this->createMock(Swift_Transport::class);
$deadTransport->method('ping')->willReturn(false);
$lb = new Swift_Transport_LoadBalancedTransport([$deadTransport]);
$lb->start();
// $deadTransport should still be in dead pool
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| `FailoverTransport` throws after exhausting all transports | **EXISTING** | `FailoverTransport.php:85`: final exception thrown |
| `RetryTransport` configurable max retries | **EXISTING** | `RetryTransport.php:42`: default 3 retries |
| Failover logging | **NOT DONE** | `FailoverTransport.php:79-81`: `catch (Swift_TransportException $e)` with no logging |
| Transport security policy | **NOT DONE** | No `Swift_Transport_SecurityPolicy` class |
| `RetryTransport` parameter validation | **NOT DONE** | `maxRetries` and `baseDelayMs` accept any integer (no range check) |
| Dead transport ping-before-resurrection | **NOT DONE** | `LoadBalancedTransport.php:92-93`: dead transports merged back without validation |
| Silent exception swallowing | **NOT DONE** | `LoadBalancedTransport.php:191-193`: `catch (Exception $e) {}` swallows all errors |
| Non-deterministic load balancing | **NOT DONE** | Deterministic round-robin via `array_shift`/`array_push` |
| FailoverEvent for plugins | **NOT DONE** | No failover event type exists |

**Overall Status:** NOT STARTED -- All proposed mitigations remain pending. Failover silently swallows exceptions with no logging, and no security policy enforcement exists.

## Risk After Mitigation

**Residual Risk:** LOW -- With security policy enforcement, failover logging, parameter validation, and randomized selection, transport downgrade attacks require both network-level access and knowledge of the transport pool configuration.
