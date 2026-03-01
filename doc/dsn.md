# DSN Transport Factory

The DSN system lets you create any transport from a single connection string, making configuration portable and environment-driven.

## Usage

```php
$factory = new Swift_Transport_DsnTransportFactory();
$transport = $factory->fromDsnString('sendgrid://YOUR_API_KEY@default');
$mailer = new Swift_Mailer($transport);
```

## DSN Format

```
scheme://user:password@host:port?param1=value1&param2=value2
```

For API transports, the `user` portion of the DSN is used as the API key:

```
sendgrid://SG.xxxxxxxxxxxx@default
postmark://pmk-xxxxxxxxxxxx@default
```

## Supported Schemes

| Scheme | Transport Class | Auth |
|-|-|-|
| `null` | `Swift_Transport_NullTransport` | None |
| `smtp` | `Swift_Transport_EsmtpTransport` | user:password in DSN |
| `smtp+tls` | `Swift_Transport_EsmtpTransport` | user:password, forced TLS |
| `smtp+ssl` | `Swift_Transport_EsmtpTransport` | user:password, forced SSL (port 465) |
| `gmail+smtp` | `Swift_Transport_EsmtpTransport` | OAuth2/app password |
| `gmail+api` | `Swift_Transport_Api_GoogleTransport` | Google Client object (not DSN-constructible) |
| `microsoft-graph` | `Swift_Transport_Api_MicrosoftGraphTransport` | GraphServiceClient (not DSN-constructible) |
| `amazon+api` | `Swift_Transport_Api_AmazonSesApiTransport` | SesClient object (not DSN-constructible) |
| `amazon+http` | `Swift_Transport_Api_AmazonSesHttpTransport` | SesClient object (not DSN-constructible) |
| `azure` | `Swift_Transport_Api_AzureTransport` | Connection string as API key |
| `brevo` | `Swift_Transport_Api_BrevoTransport` | API key |
| `infobip` | `Swift_Transport_Api_InfoBipTransport` | API key (requires base URL via constructor) |
| `mailpace` | `Swift_Transport_Api_MailPaceTransport` | API key |
| `mailchimp` | `Swift_Transport_Api_MailChimpTransport` | API key |
| `mailersend` | `Swift_Transport_Api_MailerSendTransport` | API key |
| `mailgun` | `Swift_Transport_Api_MailGunTransport` | API key (requires domain via constructor) |
| `mailjet` | `Swift_Transport_Api_MailJetTransport` | Public + private key (constructor only) |
| `postmark` | `Swift_Transport_Api_PostMarkTransport` | Server token |
| `resend` | `Swift_Transport_Api_ResendTransport` | API key |
| `scaleway` | `Swift_Transport_Api_ScalewayTransport` | API key (requires project ID via constructor) |
| `sendgrid` | `Swift_Transport_Api_SendgridTransport` | API key |
| `ahasend` | `Swift_Transport_Api_AhaSendTransport` | API key |
| `mailomat` | `Swift_Transport_Api_MailomatTransport` | API key |
| `mailtrap` | `Swift_Transport_Api_MailtrapTransport` | API key |
| `postal` | `Swift_Transport_Api_PostalTransport` | API key (requires host via constructor) |
| `sweego` | `Swift_Transport_Api_SweegoTransport` | API key |

**Note:** Transports marked "not DSN-constructible" require SDK client objects and must be instantiated directly. The DSN factory passes the DSN user/password as the API key for all other transports.

## SMTP DSN Parameters

SMTP schemes accept query-string parameters:

```
smtp+tls://user:pass@mail.example.com:587?verify_peer=false&source_ip=10.0.0.1&smtputf8=false
```

| Parameter | Type | Description |
|-|-|-|
| `verify_peer` | bool | Enable/disable TLS peer verification (also sets `verify_peer_name`) |
| `peer_fingerprint` | string | Expected TLS peer certificate fingerprint |
| `source_ip` | string | Local IP address to bind the SMTP connection to |
| `smtputf8` | bool | Set to `false` to disable SMTPUTF8 and use IDN encoding instead |

## Meta-Transport Wrappers

Wrap multiple DSNs for redundancy, load balancing, or retry logic:

### Failover

Tries each transport in order. If the first fails, tries the next.

```php
$transport = $factory->fromDsnString('failover(sendgrid://KEY@default postmark://KEY@default)');
```

### Round-Robin

Distributes sends across transports.

```php
$transport = $factory->fromDsnString('roundrobin(sendgrid://KEY@default postmark://KEY@default brevo://KEY@default)');
```

### Retry

Wraps a single transport with automatic retry and exponential backoff.

```php
$transport = $factory->fromDsnString('retry(sendgrid://KEY@default)');
```

**Syntax:** `wrapper(dsn1 dsn2 dsn3)` -- DSNs are separated by spaces inside parentheses.

## Environment-Driven Configuration

A common pattern is to read the DSN from an environment variable:

```php
$dsn = getenv('MAILER_DSN') ?: 'smtp://localhost:1025';
$factory = new Swift_Transport_DsnTransportFactory();
$transport = $factory->fromDsnString($dsn);
$mailer = new Swift_Mailer($transport);
```

```bash
# .env
MAILER_DSN=sendgrid://SG.your-key-here@default
# or with failover
MAILER_DSN="failover(sendgrid://SG.key@default postmark://pmk-key@default)"
# or with retry
MAILER_DSN="retry(sendgrid://SG.key@default)"
```
