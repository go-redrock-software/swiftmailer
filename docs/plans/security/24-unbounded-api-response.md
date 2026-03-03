# Threat #24: Unbounded API Response Body Consumption

**Severity:** MEDIUM
**STRIDE Category:** Denial of Service
**Status:** NOT STARTED

---

## Description

Every API transport calls `(string) $response->getBody()` or `$response->getBody()->getContents()` with no size limit, then passes the entire string to `json_decode()`. A compromised or malicious API endpoint (e.g., via DNS hijacking or MITM on a self-hosted instance) can return a multi-gigabyte response body, exhausting PHP memory.

## Affected Files

All 17 `AbstractHttpApiTransport` subclasses in `lib/classes/Swift/Transport/Api/`, specifically every `parseResponse()` method:

- `SendgridTransport.php:47`
- `MailGunTransport.php:78`
- `AzureTransport.php:114`
- And all other API transports

Also affects the 4 `AbstractApiTransport` subclasses (Amazon SES x2, Google, Microsoft Graph).

## Attack Scenario

An attacker who controls DNS or performs network-level MITM against a self-hosted mail API (Postal, InfoBip, Scaleway — which all accept user-provided hostnames) responds with an extremely large body. The transport reads the entire body into memory, causing OOM and crashing the PHP process. This is amplified by the retry transport which would repeat the attempt.

## Recommended Mitigations

1. Add a `read_timeout` and body size limit to all Guzzle requests (e.g., `'stream' => true` with manual size-checked reading, or Guzzle's `'sink'` option)
2. Set a maximum response size constant in `AbstractHttpApiTransport` (e.g., 1 MB) and abort if exceeded
3. Add response size limit to `AbstractApiTransport` subclasses as well

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Response body size limit | NOT STARTED | — |
| Guzzle read_timeout config | NOT STARTED | — |
| AbstractApiTransport size guard | NOT STARTED | — |
