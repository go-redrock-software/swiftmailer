# DSN Transport Factory

The DSN system lets you configure a transport from a single connection string, making configuration portable and environment-driven. `Swift_Transport_DsnTransportFactory` parses a DSN, validates its query parameters, and returns a ready-to-use `Swift_Transport`.

See also: [API transports](api-transports.md) for provider-specific details, and the [`swiftmailer-test` CLI](cli.md) for exercising a DSN from the command line.

## Usage

```php
$factory   = new Swift_Transport_DsnTransportFactory();
$transport = $factory->fromDsnString('sendgrid://YOUR_API_KEY@default');
$mailer    = new Swift_Mailer($transport);
```

The legacy convenience helper constructs the mailer directly:

```php
$mailer = Swift::createTransportFromDsn('sendgrid://YOUR_API_KEY@default');
$transport = $mailer->getTransport();
```

Despite its name, `Swift::createTransportFromDsn()` returns a `Swift_Mailer`.
It uses the same operational factory, supported schemes, decorators and option
validation as the explicit construction above. Use
`Swift_Transport_DsnTransportFactory::fromDsnString()` when you need a transport
alone. `Swift_Dsn_Configuration` remains a metadata parser rather than a
transport-construction API. See the scheme tables below for transports that
require explicit SDK/client construction.

## DSN Format

```
scheme://user:password@host:port?param1=value1&param2=value2
```

For single-key HTTP API transports the credential is taken from the DSN **user** (falling back to **password**), and used as the API key:

```
sendgrid://SG.xxxxxxxxxxxx@default
postmark://pmk-xxxxxxxxxxxx@default
```

`host` is usually `default` for API transports. Infobip and Postal require their API host; Mailgun supports a custom API host. See provider contracts below. For SMTP it is the mail server hostname. Literal `0` credentials are preserved, including percent-encoded zero.

### URL-encoding credentials

The DSN is a URL, so any credential containing reserved characters (`@ : / ? # % & + space`) **must** be percent-encoded, or the parser will split the DSN in the wrong place.

| Raw value | Encoded |
|-|-|
| `p@ss:word` | `p%40ss%3Aword` |
| `key/with+slash` | `key%2Fwith%2Bslash` |
| `a b` (space) | `a%20b` |

```
smtp://user:p%40ss%3Aword@smtp.example.com:587
```

## Parser failures and diagnostics

Malformed input, disallowed parser functions and non-URL operational DSNs throw
a static global `InvalidArgumentException` from Swiftmailer's entry points.
The original Nyholm failure is not retained as a previous exception because it
contains the raw DSN. Applications that previously caught a vendor-specific
parser subclass or inspected its cause should catch `InvalidArgumentException`
and use the static diagnostic instead. Valid metadata functions/paths and
operational wrappers retain their existing behavior. This does not sanitize
exceptions produced by direct calls to Nyholm or arbitrary caller/provider code.
The [CLI](cli.md#credential-masking-in-output) also uses a fixed redacted preview.

## Supported Schemes

Every scheme below is registered in `Swift_Dsn`'s transport class map. An unknown scheme throws `InvalidArgumentException` listing the supported schemes.

### Local / built-in

| Scheme | Transport Class | Notes |
|-|-|-|
| `null` | `Swift_Transport_NullTransport` | Discards mail; no network. Host portion ignored (e.g. `null://default`). |
| `sendmail` | `Swift_Transport_SendmailTransport` | Local sendmail binary. Command via `?command=`; defaults to `/usr/sbin/sendmail -bs`. |
| `native` | `Swift_Transport_SendmailTransport` | Like `sendmail`, but the command comes from PHP's `sendmail_path` ini setting (the `command` parameter is ignored). |

The sendmail binary is checked against an allowlist (`/usr/sbin/sendmail`, `/usr/lib/sendmail`, `/usr/bin/sendmail`, `/usr/local/sbin/sendmail`, `/usr/local/bin/sendmail`). A binary outside the allowlist throws `InvalidArgumentException`.

```
sendmail://default?command=/usr/sbin/sendmail%20-bs
native://default
```

### SMTP

| Scheme | Transport Class | Encryption |
|-|-|-|
| `smtp` | `Swift_Transport_EsmtpTransport` | None set (plaintext unless the server/port implies TLS) |
| `smtp+tls` | `Swift_Transport_EsmtpTransport` | Forces `tls` |
| `smtp+ssl` | `Swift_Transport_EsmtpTransport` | Forces `ssl` (default port 465) |

```
smtp://user:pass@smtp.example.com:587
smtp+tls://user:pass@smtp.example.com:587
smtp+ssl://user:pass@smtp.example.com:465
```

When no port is given, `smtp+ssl` defaults to 465 and the others to 587.

### HTTP API

HTTP providers use their own constructor contracts. SDK-backed transports require explicit client construction and reject operational DSNs with a credential-safe `InvalidArgumentException`; registration in the metadata map does not imply construction support.

| Scheme | Transport Class | DSN-constructible | Credential / notes |
|-|-|-|-|
| `azure` | `Swift_Transport_Api_AzureTransport` | Yes | Full Azure connection string as the user (URL-encode it) |
| `brevo` | `Swift_Transport_Api_BrevoTransport` | Yes | API key |
| `mailpace` | `Swift_Transport_Api_MailPaceTransport` | Yes | API token |
| `mailchimp` | `Swift_Transport_Api_MailChimpTransport` | Yes | API key |
| `mailersend` | `Swift_Transport_Api_MailerSendTransport` | Yes | API key |
| `mailomat` | `Swift_Transport_Api_MailomatTransport` | Yes | API key |
| `postmark` | `Swift_Transport_Api_PostMarkTransport` | Yes | Server token |
| `resend` | `Swift_Transport_Api_ResendTransport` | Yes | API key |
| `sendgrid` | `Swift_Transport_Api_SendgridTransport` | Yes | API key |
| `ahasend` | `Swift_Transport_Api_AhaSendTransport` | Yes | API key |
| `sweego` | `Swift_Transport_Api_SweegoTransport` | Yes | API key |
| `postal` | `Swift_Transport_Api_PostalTransport` | Yes | API key as user (password fallback); host is the Postal API endpoint |
| `infobip` | `Swift_Transport_Api_InfoBipTransport` | Yes | API key as user (password fallback); host is the account API endpoint |
| `mailgun` | `Swift_Transport_Api_MailGunTransport` | Yes | User = API key; password = sending domain |
| `mailjet` | `Swift_Transport_Api_MailJetTransport` | Yes | User = public key; password = private key |
| `scaleway` | `Swift_Transport_Api_ScalewayTransport` | Yes | User = project ID; password = API key |
| `gmail+api` | `Swift_Transport_Api_GoogleTransport` | No | Requires a Google `Client` object |
| `microsoft-graph` | `Swift_Transport_Api_MicrosoftGraphTransport` | No | Requires a `GraphServiceClient` object |
| `amazon+api` | `Swift_Transport_Api_AmazonSesApiTransport` | No | Requires an async-aws `SesClient` object |
| `amazon+http` | `Swift_Transport_Api_AmazonSesHttpTransport` | No | Requires an SES client object |
| `mailtrap` | `Swift_Transport_Api_MailtrapTransport` | Yes | API token; live sending endpoint |

### HTTP provider configuration

```text
mailgun://API_KEY:DOMAIN@default
mailgun://API_KEY:DOMAIN@default?region=eu
mailjet://PUBLIC_KEY:PRIVATE_KEY@default
scaleway://PROJECT_ID:API_KEY@default?region=fr-par
infobip://API_KEY@account.api.infobip.com
postal://API_KEY@postal.example.com:8443/base-path
mailtrap://API_TOKEN@default
```

Mailgun defaults to the US API; `region` accepts exactly `us` or `eu`.
Scaleway defaults to `fr-par`, the only region currently documented for its
Transactional Email API. Its project ID is the DSN user and API key the password.
Mailgun and Mailjet also require both user and password; a missing component
rejects rather than borrowing the other field.

Infobip and Postal require a real host in place of `default`. Mailgun accepts
an explicit host instead of `default`, including an optional port and base path;
`region` cannot accompany an explicit Mailgun host. These custom endpoints always
use HTTPS and retain the public-address guard, which rejects local/private or
reserved addresses. Base paths are appended before the native provider route;
fragments, control characters, dot segments and ambiguous path delimiters reject.
Use trusted endpoint hosts: the configured API credentials are sent to that host.

Mailjet, Scaleway and live Mailtrap use fixed native endpoints and require
`@default` without a port or base path (a single `/` is allowed). The previously
working single-key providers retain their ignored-host behavior. `region` is
exclusive to Mailgun and Scaleway; `inbox_id` is exclusive to Mailtrap sandbox.
Both options require a canonical scalar spelling and exactly one occurrence,
using PHP's configured query separators. Invalid options fail before HTTP I/O.

Google, Microsoft Graph and both SES SDK-backed routes still require explicitly
constructed client objects in code. DSNs do not yet configure their authentication
or client lifecycle. Their rejection does not change direct SDK-backed transports.

### Provider `+smtp` convenience schemes

These map to `Swift_Transport_EsmtpTransport` with the provider's SMTP host, port, and encryption preset, so you only supply credentials. Supply the credentials as `user:pass`; use `@default` for the host to accept the preset, or pass a real host to override it (e.g. a different AWS SES region). Port can be overridden via the DSN port; **encryption is fixed** to the value below.

| Scheme | Host | Port | Encryption |
|-|-|-|-|
| `gmail+smtp` | `smtp.gmail.com` | 465 | ssl |
| `amazon+smtp` | `email-smtp.us-east-1.amazonaws.com` | 587 | tls |
| `brevo+smtp` | `smtp-relay.brevo.com` | 587 | tls |
| `infobip+smtp` | `smtp-api.infobip.com` | 587 | tls |
| `mandrill+smtp` | `smtp.mandrillapp.com` | 587 | tls |
| `mailersend+smtp` | `smtp.mailersend.net` | 587 | tls |
| `mailgun+smtp` | `smtp.mailgun.org` | 587 | tls |
| `mailjet+smtp` | `in-v3.mailjet.com` | 587 | tls |
| `postmark+smtp` | `smtp.postmarkapp.com` | 587 | tls |
| `resend+smtp` | `smtp.resend.com` | 465 | ssl |
| `scaleway+smtp` | `smtp.tem.scw.cloud` | 587 | tls |
| `sendgrid+smtp` | `smtp.sendgrid.net` | 587 | tls |
| `ahasend+smtp` | `smtp.ahasend.com` | 587 | tls |
| `mailomat+smtp` | `smtp.mailomat.at` | 587 | tls |
| `mailtrap+smtp` | `live.smtp.mailtrap.io` | 587 | tls |
| `sweego+smtp` | `smtp.sweego.io` | 587 | tls |

```
sendgrid+smtp://apikey:apikey@default
amazon+smtp://AKIA...:secret@email-smtp.eu-west-1.amazonaws.com:587
```

> Note: `mandrill` is available **only** as `mandrill+smtp` — there is no `mandrill` API scheme.

### Mailtrap sandbox

`mailtrap+sandbox` targets Mailtrap's sandbox (email-testing) inbox rather than live delivery. The API key is the DSN user (or password). Supply a positive decimal inbox ID as `?inbox_id=` with `@default`, or as the DSN host. Leading zeros are normalized. If both forms are given they must identify the same inbox; missing, zero, malformed or conflicting IDs reject:

```
mailtrap+sandbox://APITOKEN@12345
mailtrap+sandbox://APITOKEN@default?inbox_id=12345
```

> The `inbox_id` parameter is **not** in the allowed-parameter whitelist, so passing it emits an "unknown DSN parameter" warning (the value is still applied). Putting the inbox ID in the host position avoids the warning.

## DSN Query Parameters

Only a fixed whitelist of parameters is accepted (`Swift_Transport_DsnTransportFactory::ALLOWED_DSN_PARAMETERS`):

`verify_peer`, `peer_fingerprint`, `source_ip`, `smtputf8`, `command`, `retries`, `retry_delay`, `click_tracking`, `click_tracking_text`, `open_tracking`, `timeout`, `auth_mode`, `local_domain`

Any parameter outside this list is **not** silently dropped and does **not** throw — it triggers an `E_USER_WARNING` (`unknown DSN parameter "x" will be ignored`) and is then ignored. This validation runs for every scheme.

### SMTP parameters

These apply to the SMTP schemes (`smtp`, `smtp+tls`, `smtp+ssl`, and the provider `+smtp` variants):

| Parameter | Type | Description |
|-|-|-|
| `verify_peer` | bool | TLS peer verification (also sets `verify_peer_name`). **Setting `false` emits an `E_USER_WARNING`** — it disables certificate verification and is insecure; use only for local development. |
| `peer_fingerprint` | string | Expected TLS peer certificate fingerprint (pinning). |
| `source_ip` | string | Local IP address to bind the outgoing connection to. |
| `smtputf8` | bool | Set `false` to disable SMTPUTF8 and fall back to IDN address encoding. Omitted/`true` keeps the default auto encoder. |
| `timeout` | positive integer seconds | Sets the native connection/read timeout; omitted keeps 30 seconds. Zero, fractions, signs and overflow are rejected. |
| `auth_mode` | authenticator keyword | Selects an installed authenticator, case-insensitively. Defaults include `PLAIN`, `LOGIN`, `CRAM-MD5`, `NTLM` and `XOAUTH2`; custom registered authenticators are supported. Omitted keeps automatic selection. |
| `local_domain` | ASCII hostname or IP | Sets the EHLO/HELO identity. IPv4 and IPv6 addresses are formatted as SMTP address literals. Omitted keeps the native local domain. |

```
smtp+tls://user:pass@mail.example.com:587?verify_peer=true&source_ip=10.0.0.1&smtputf8=false
```

The three connection options `timeout`, `auth_mode` and `local_domain` are
specific to SMTP. They reject other schemes, duplicate/array values and
normalized aliases such as `auth.mode`. Occurrence checks follow PHP's
`arg_separator.input`, including semicolon separators when configured. Invalid
values raise `InvalidArgumentException` before a connection is opened.

```text
smtp+tls://user:password@mail.example.com:587?timeout=5&auth_mode=login&local_domain=mailer.example.com
gmail+smtp://user:ACCESS_TOKEN@default?timeout=10&auth_mode=xoauth2&local_domain=2001%3Adb8%3A%3A1
retry(failover(smtp://user:password@primary.example.com?timeout=5 smtp://user:password@backup.example.com?timeout=8)?retry_period=15)
```

`local_domain` accepts ordinary hostnames (including `localhost` and a trailing
DNS dot), raw IP addresses, `[IPv4]` and `[IPv6:address]` literals. Use an ASCII
name, such as its punycode representation, for an internationalized hostname.
Whitespace, control characters and malformed domain/address values are rejected.
This setting changes the SMTP greeting identity; it does not change the message
sender or recipients.

Literal and percent-encoded `0` SMTP usernames/passwords are preserved by the
operational factory, including through the public mailer helper and decorators.
A non-empty username `0` performs AUTH; omitted or empty usernames retain their
existing unauthenticated behavior. The factory compensates for Nyholm 2.0.1's
raw-zero userinfo parsing without changing vendor files or its query parser.
This compatibility step applies to transport construction; the raw metadata
helper continues to use the underlying parser's representation.

### `command` parameter

Only meaningful for the `sendmail` scheme (see [Local / built-in](#local--built-in)). Ignored by `native`.

### SendGrid tracking parameters

These options are supported by the `sendgrid` HTTP API scheme:

| Parameter | Values | Default | SendGrid JSON field |
| --- | --- | --- | --- |
| `click_tracking` | `true`, `false`, `1`, `0` | Omitted | `tracking_settings.click_tracking.enable` |
| `click_tracking_text` | `true`, `false`, `1`, `0` | Omitted | `tracking_settings.click_tracking.enable_text` |
| `open_tracking` | `true`, `false`, `1`, `0` | Omitted | `tracking_settings.open_tracking.enable` |

Boolean words are case-insensitive; ordinary URL decoding applies. Omitting an
option preserves the provider request's existing behavior, rather than forcing
tracking on or off. Explicit `0`/`false` sends a JSON boolean false. Plain-text
click tracking remains independent: set both click options to `1` when you want
to request tracking for both content types.

Empty, array, duplicate, normalized-alias (such as `click.tracking`) and other
values throw `InvalidArgumentException`. Options must use their canonical names. Duplicate checks use the same
[`arg_separator.input`](https://www.php.net/manual/en/ini.core.php#ini.arg-separator.input) characters as PHP's underlying query parser, including
semicolon separators when configured.
These options also throw on other schemes, including `sendgrid+smtp`; their
support is specific to the HTTP Mail Send payload.

```text
sendgrid://KEY@default?click_tracking=0&open_tracking=0
sendgrid://KEY@default?click_tracking=1&click_tracking_text=1&retries=2&retry_delay=0
retry(sendgrid://KEY@default?open_tracking=false)
failover(sendgrid://KEY@default?click_tracking=0 postmark://OTHER@default)?retry_period=15
```

Options inside a pool belong to their individual leaf DSN. Pool-level queries
continue to accept only `retry_period`. Runtime nullable getters/setters are
available on [SendGrid transports](api-transports.md#sendgrid).

### Retry parameters (any scheme)

A positive `retries` value wraps the transport in a `Swift_Transport_RetryTransport` with exponential backoff + jitter. This is an alternative to the [`retry(...)` wrapper](#retry) and gives explicit control over count and delay.

| Parameter | Type | Default | Description |
|-|-|-|-|
| `retries` | int | 0 | Retry attempts. `<= 0` applies no retry wrapper. |
| `retry_delay` | int | 1000 | Base backoff delay in **milliseconds**. |

```
sendgrid://SG.your-key@default?retries=3&retry_delay=1000
smtp+tls://user:pass@mail.example.com:587?retries=2&retry_delay=500
```

**Hardened caps** — the values are bounded and out-of-range values throw `InvalidArgumentException`:

| Cap | Value |
|-|-|
| Max `retries` | 10 |
| Max `retry_delay` | 30000 ms |
| Total retry budget | 300 s (retries stop once elapsed) |
| Per-sleep ceiling | 60000 ms |

Backoff formula: `retry_delay * 2^attempt + random(0, retry_delay/2)`. Only transient failures (connection timeouts, SMTP 4xx, HTTP 429/5xx) are retried; permanent failures are thrown immediately.

Retry parameters also work on the inner DSNs of a `failover(...)`/`roundrobin(...)` wrapper, since each inner DSN is resolved independently.

## Meta-Transport Wrappers

Wrap multiple DSNs (or one) for redundancy, load balancing, or retry logic. Inner DSNs are separated by spaces inside the parentheses.

Explicit non-retryable markers and PHP Error causes stop the current send across
all wrappers, including nested wrappers and permissive custom retry classifiers.
They preserve the original exception and prevent another backend from replaying
an uncertain delivery. Ordinary backend failures retain their fallback/retry
behavior. See [terminal failures](sending.md#terminal-failures-and-pool-replacement).

### Failover

Tries each transport in order; on failure, falls through to the next.

```php
$transport = $factory->fromDsnString('failover(sendgrid://KEY@default postmark://KEY@default)');
```

### Round-robin

Distributes sends across the transports (load balancing).

```php
$transport = $factory->fromDsnString('roundrobin(sendgrid://KEY@default postmark://KEY@default brevo://KEY@default)');
```

### Pool retry period

Both pool wrappers accept `retry_period` after the closing parenthesis:

```php
$transport = $factory->fromDsnString(
    'roundrobin(sendgrid://KEY@default postmark://KEY@default)?retry_period=15'
);
$transport = $factory->fromDsnString(
    'failover(sendgrid://KEY@default postmark://KEY@default)?retry_period=0'
);
```

| Option | Default | Meaning |
| --- | --- | --- |
| `retry_period` | `60` | Seconds before a failed backend can return to the pool. `0` permits recovery on the next operation. |

The value must be a non-negative decimal integer within PHP's integer range.
Unknown, duplicate, array, negative, fractional and overflowing wrapper options
are rejected before inner transports are constructed. An empty wrapper query
uses the default. Cooldown recovery occurs at entry to `send()`, `ping()` or
`start()` and does not sleep. A healthy failover selection remains sticky.
See [pool recovery](sending.md#restoring-and-checking-a-pool) for runtime controls
and the changed manual-start behavior.

This option controls backend availability. Per-transport `retries` and
`retry_delay` continue to control message retry attempts and backoff.

### Retry

Wraps a single inner transport with automatic retry using the `RetryTransport` **defaults** (3 retries, 1000 ms base delay). For custom counts/delays, use the `?retries=` / `?retry_delay=` query parameters instead.

```php
$transport = $factory->fromDsnString('retry(sendgrid://KEY@default)');
```

### Nesting

`retry(...)` is matched first, so it can wrap a failover/round-robin group, and each inner DSN of a group may itself carry retry query parameters:

```
retry(failover(sendgrid://KEY@default postmark://KEY@default)?retry_period=15)
failover(smtp+tls://u:p@a.example.com:587?retries=2 smtp+tls://u:p@b.example.com:587)?retry_period=30
```

> Limitation: inner DSNs of `failover(...)`/`roundrobin(...)` are split on whitespace, so you **cannot** nest one space-separated group inside another (e.g. `failover(failover(a b) c)`). Wrap with `retry(...)`, or use per-DSN `?retries=` parameters, to combine behaviours.

**Syntax:** `wrapper(dsn1 dsn2 dsn3)?retry_period=N` for pools — space-separated DSNs inside the parentheses, followed by an optional pool query.

### Reading wrapper metadata

`Swift_Dsn_Configuration` exposes raw query metadata for its existing flat
function configurations, separately from individual DSN parameters:

```php
$config = Swift_Dsn_Configuration::parse(
    'failover(null://first?retries=2 null://second)?retry_period=15'
);
$config->getParameters(); // ['retry_period' => '15']
$config->getParameter('retry_period'); // '15'
$config->getParameter('missing', 60); // 60
$config->getDsn(0)->getParameter('retries'); // '2'
```

This helper retains the underlying parser's metadata representation and flat
configuration limit. Use `Swift_Transport_DsnTransportFactory::fromDsnString()`
for transport construction and strict operational option validation, including
supported outer `retry(...)` combinations. A plain DSN's query belongs to that
DSN, rather than the wrapper metadata.

## Environment-Driven Configuration

Read the DSN from an environment variable so credentials stay out of source:

```php
$dsn       = getenv('MAILER_DSN') ?: 'smtp://localhost:1025';
$factory   = new Swift_Transport_DsnTransportFactory();
$transport = $factory->fromDsnString($dsn);
$mailer    = new Swift_Mailer($transport);
```

```bash
# .env
MAILER_DSN=sendgrid://SG.your-key-here@default
# with failover
MAILER_DSN="failover(sendgrid://SG.key@default postmark://pmk-key@default)"
# with retry
MAILER_DSN="retry(sendgrid://SG.key@default)"
```

The bundled CLI reads its DSN from `SWIFTMAILER_DSN` — see [cli.md](cli.md).
