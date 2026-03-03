# Threat #26: Retry Transport Amplification via Uncapped DSN Parameters

**Severity:** MEDIUM
**STRIDE Category:** Denial of Service
**Status:** NOT STARTED

---

## Description

The DSN factory reads `retries` and `retry_delay` from query parameters with no upper bound validation. A DSN like `smtp+tls://host?retries=999999&retry_delay=60000` creates a `RetryTransport` that will retry up to 999,999 times with 60-second delays. While `backoff()` caps individual delays at 60 seconds, it does not cap total retry count. Combined with the `baseDelayMs * 2^attempt` exponential backoff formula, even moderate retry counts cause long thread blocking.

## Affected Files

- `lib/classes/Swift/Transport/DsnTransportFactory.php` (lines 60-61, 84-85)
- `lib/classes/Swift/Transport/RetryTransport.php` (lines 40-49, 142-147)

## Attack Scenario

An attacker with control over DSN configuration (environment variable, config file, database) sets extreme retry parameters. The application thread blocks indefinitely on failed sends, causing resource exhaustion and effective denial of service. In web request contexts, this can tie up PHP-FPM workers.

## Recommended Mitigations

1. Enforce a maximum retry count (e.g., 10) in both `RetryTransport` constructor and DSN factory
2. Enforce a maximum base delay (e.g., 30 seconds)
3. Validate and clamp DSN parameters before use
4. Add a total timeout for the retry loop (e.g., 5 minutes max wall-clock time)

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Max retry count cap | NOT STARTED | — |
| Max base delay cap | NOT STARTED | — |
| DSN parameter validation | NOT STARTED | — |
| Total retry timeout | NOT STARTED | — |
