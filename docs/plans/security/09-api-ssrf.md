# Threat 09: Server-Side Request Forgery via API Transports

**STRIDE Category:** Spoofing, Tampering
**Severity:** MEDIUM
**Likelihood:** Low
**CWE:** CWE-918 (Server-Side Request Forgery)

---

## Description

Some API transports construct endpoint URLs dynamically from configuration or DSN parameters. If an attacker can influence the host, domain, or endpoint configuration, they could redirect API requests to internal services, cloud metadata endpoints, or attacker-controlled servers, potentially leaking API keys and email content.

## Attack Vectors

1. **InfoBip configurable host** — `InfoBipTransport` accepts a `baseUrl` parameter, which is used directly in the endpoint URL: `https://{baseUrl}/email/3/send`
2. **Azure connection string parsing** — `AzureTransport` parses a connection string with `endpoint=...` that determines the target URL
3. **Postal configurable host** — Self-hosted Postal instances use a configurable API endpoint
4. **Custom Guzzle client** — `AbstractHttpApiTransport` accepts an optional `ClientInterface`, which could be pre-configured with a malicious base URI
5. **DSN host manipulation** — If DSN strings are constructed from user input, the host component could be set to an internal IP

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Transport/Api/InfoBipTransport.php` | `baseUrl` from constructor used in URL |
| `lib/classes/Swift/Transport/Api/AzureTransport.php` | Connection string `endpoint=` determines URL |
| `lib/classes/Swift/Transport/Api/PostalTransport.php` | Self-hosted, endpoint from config |
| `lib/classes/Swift/Transport/Api/MailtrapTransport.php` | Sandbox vs production URL based on host |
| `lib/classes/Swift/Transport/AbstractHttpApiTransport.php` | Accepts custom `ClientInterface` |
| `lib/classes/Swift/Transport/DsnTransportFactory.php` | Parses DSN strings to create transports |

## Existing Controls

- Most API transport endpoints are **hardcoded** (`SendGrid`, `Mailgun`, `Brevo`, etc.)
- Guzzle default configuration uses system TLS settings
- DSN scheme validation against a fixed allowlist in `Swift_Dsn`

## Control Gaps

1. **No URL validation for configurable endpoints** — InfoBip, Azure, Postal accept arbitrary URLs
2. **No internal network protection** — No check against `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254`, etc.
3. **No protocol enforcement** — Could theoretically be redirected to non-HTTPS
4. **Custom HttpClient not validated** — Caller could pass a client with a base URI pointing anywhere
5. **API key sent to arbitrary endpoints** — Auth headers (including Bearer tokens) are sent to whatever URL is configured

## Mitigation Plan

### Phase 1: URL Validation Helper (Short-term)
Create a URL validation utility that rejects internal/private addresses:
```php
class Swift_Transport_UrlValidator
{
    private const BLOCKED_HOSTS = [
        '127.0.0.1', '0.0.0.0', 'localhost', '::1',
    ];

    private const BLOCKED_RANGES = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '169.254.0.0/16',  // AWS metadata
    ];

    public static function validate(string $url): void
    {
        $parsed = parse_url($url);
        if ('https' !== ($parsed['scheme'] ?? '')) {
            throw new InvalidArgumentException('API endpoint must use HTTPS.');
        }

        $host = $parsed['host'] ?? '';
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new InvalidArgumentException('API endpoint must not point to localhost.');
        }

        $ip = gethostbyname($host);
        foreach (self::BLOCKED_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                throw new InvalidArgumentException('API endpoint must not point to internal network.');
            }
        }
    }
}
```

### Phase 2: Apply to Configurable Transports (Short-term)
- Validate endpoints in `InfoBipTransport`, `AzureTransport`, `PostalTransport`, and `MailtrapTransport` constructors
- Validate custom `getEndpoint()` return values in `AbstractHttpApiTransport::send()`

### Phase 3: HTTPS Enforcement (Short-term)
- Add `https://` prefix validation for all API endpoints
- Reject HTTP endpoints in production (allow override for local testing)

### Phase 4: Documentation
- Document SSRF risks when using configurable endpoints
- Document that DSN strings should not be constructed from user input
- Recommend environment variable-based configuration

## Test Cases

```php
// Internal IP should be rejected
$this->expectException(InvalidArgumentException::class);
new Swift_Transport_Api_InfoBipTransport('key', '169.254.169.254');

// localhost should be rejected
$this->expectException(InvalidArgumentException::class);
new Swift_Transport_Api_InfoBipTransport('key', '127.0.0.1');

// HTTP (non-HTTPS) should be rejected
// (test via custom endpoint that returns http:// URL)

// Valid external URL should be accepted
$transport = new Swift_Transport_Api_InfoBipTransport('key', 'api.infobip.com');
$this->assertStringStartsWith('https://', $transport->getEndpoint());
```

## Implementation Status (2026-03-02)

| Mitigation | Status | Evidence |
|-|-|-|
| Most API endpoints hardcoded | **EXISTING** | SendGrid, Mailgun, Brevo, etc. use hardcoded `https://` URLs |
| DSN scheme validation | **EXISTING** | `Swift_Dsn` validates against known scheme allowlist |
| InfoBip configurable `baseUrl` unvalidated | **NOT FIXED** | `InfoBipTransport.php:22-31`: `$baseUrl` accepted directly, only `rtrim()` applied |
| Azure connection string unvalidated | **NOT FIXED** | `AzureTransport.php:30`: `$connectionString` parsed without URL validation |
| Postal configurable endpoint unvalidated | **NOT FIXED** | `PostalTransport.php:25`: self-hosted endpoint accepted without validation |
| URL validation helper | **PENDING** | No `Swift_Transport_UrlValidator` class exists |
| Internal network blocking | **PENDING** | No checks against `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254` |
| HTTPS enforcement for custom endpoints | **PENDING** | InfoBip hardcodes `https://` prefix in `getEndpoint()` (line 66), but baseUrl is not validated against internal IPs |

**Overall Status:** NOT STARTED -- Configurable endpoints (InfoBip, Azure, Postal) accept arbitrary values without URL validation or internal network blocking.

## Risk After Mitigation

**Residual Risk:** LOW — With URL validation, internal network blocking, and HTTPS enforcement, SSRF is limited to valid external endpoints.
