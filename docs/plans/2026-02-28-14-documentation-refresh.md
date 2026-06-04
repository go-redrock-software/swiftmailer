# Documentation Refresh — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the minimal README with comprehensive documentation covering every feature added by the Redrock fork — 21 API transports, DSN factory, webhooks, new plugins, new events, tags/metadata, security hardening, and SMTP enhancements — so developers can adopt the fork without reading source code.

**Architecture:** The existing `doc/` directory contains legacy RST files from upstream SwiftMailer (messages, headers, plugins, sending). New documentation will be written as Markdown files in the same `doc/` directory, since the project has already moved to Markdown for README.md and CLAUDE.md. Each feature area gets its own focused document. The README.md becomes a landing page with quick-start examples and links to the detailed docs.

**Tech Stack:** Markdown documentation. No code changes.

---

## Task 1 — Rewrite README.md

**File:** `README.md`

**What to do:** Replace the entire file. Remove the "abandoned" framing. Add:
- Project name, badges placeholder, one-line description
- Fork context: maintained by Redrock Software Corporation, integrating Symfony Mailer features into SwiftMailer for legacy/enterprise apps
- Requirements: PHP 8.1+, ext-iconv, ext-mbstring, ext-intl, ext-openssl
- Installation via Composer: `composer require swiftmailer/swiftmailer`
- Quick-start: SMTP example (5 lines), API transport example (SendGrid via DSN), DSN factory with failover
- Feature overview section listing all 21 API transports, DSN system, tag/metadata support, webhook handling, new plugins, new events, security hardening, SMTP enhancements
- Links to each `doc/*.md` file for details
- Sponsors section (keep existing Redrock logo block)
- License: MIT

**Complete content:**

~~~markdown
# Swift Mailer

A component-based PHP mailing library, maintained by [Redrock Software Corporation](https://www.go-redrock.com/). This fork integrates features from Symfony Mailer back into SwiftMailer for legacy and enterprise applications that cannot migrate to Symfony Mailer.

## Requirements

- PHP 8.1+
- Extensions: `iconv`, `mbstring`, `intl`, `openssl`

## Installation

```bash
composer require swiftmailer/swiftmailer
```

## Quick Start

### SMTP

```php
$transport = new Swift_SmtpTransport('smtp.example.com', 587, 'tls');
$transport->setUsername('user@example.com');
$transport->setPassword('secret');

$mailer = new Swift_Mailer($transport);

$message = (new Swift_Message('Hello'))
    ->setFrom(['sender@example.com' => 'Sender'])
    ->setTo(['recipient@example.com'])
    ->setBody('<h1>Hello!</h1>', 'text/html');

$mailer->send($message);
```

### API Transport (SendGrid)

```php
$transport = new Swift_Transport_Api_SendgridTransport('your-sendgrid-api-key');
$mailer = new Swift_Mailer($transport);
$mailer->send($message);
```

### DSN Factory

```php
$factory = new Swift_Transport_DsnTransportFactory();

// Single transport
$transport = $factory->fromDsnString('sendgrid://API_KEY@default');

// Failover between two providers
$transport = $factory->fromDsnString('failover(sendgrid://KEY@default postmark://KEY@default)');

// Round-robin load balancing
$transport = $factory->fromDsnString('roundrobin(sendgrid://KEY@default postmark://KEY@default)');

$mailer = new Swift_Mailer($transport);
```

## Features

### 21 HTTP API Transports

SendGrid, Mailgun, Postmark, Brevo, Amazon SES (API + HTTP), Azure Communication Services, Google Gmail API, Microsoft Graph, Resend, Scaleway, InfoBip, MailPace, MailChimp/Mandrill, MailerSend, Mailjet, AhaSend, Mailomat, Mailtrap, Postal, Sweego.

See [doc/api-transports.md](doc/api-transports.md) for constructor arguments, authentication, and examples.

### DSN Transport Factory

Create any transport from a connection string. Supports `failover()` and `roundrobin()` wrappers for redundancy and load balancing.

See [doc/dsn.md](doc/dsn.md) for the full scheme reference.

### Tags and Metadata

Attach tags and metadata to messages via headers. API transports automatically map them to each provider's native format.

```php
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome-email');
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
```

See [doc/api-transports.md](doc/api-transports.md) for provider-specific mapping details.

### Webhook System

Process inbound webhooks from email providers to track deliveries, bounces, opens, clicks, and complaints.

See [doc/webhooks.md](doc/webhooks.md) for setup and provider-specific examples.

### New Plugins

- **AllowlistPlugin** — restrict delivery to allowed recipients/domains (dev/staging safety)
- **CssInlinerPlugin** — automatically inline CSS in HTML emails before sending
- **SentMessagePlugin** — capture `Swift_SentMessage` objects for post-send inspection

See [doc/plugins.md](doc/plugins.md) for configuration and usage.

### New Events

- **SentMessageEvent** — fired after successful send, carries `Swift_SentMessage` with provider message ID
- **FailedMessageEvent** — fired on send failure, carries exception and failed recipient list

See [doc/events.md](doc/events.md) for the complete event lifecycle.

### SMTP Enhancements

- **Auto TLS** — STARTTLS negotiation mode alongside traditional `ssl`/`tls`
- **Smart SMTPUTF8** — `AutoAddressEncoder` detects SMTPUTF8 server capability and switches encoding automatically
- **DSN parameters** — `verify_peer`, `peer_fingerprint`, `source_ip`, `smtputf8` via DSN query string

### Security Hardening

- `#[SensitiveParameter]` on all API key constructor parameters (PHP 8.2+)
- Guzzle exception chain sanitized to prevent API key leakage in stack traces
- Serialization blocked on transport objects (`__sleep`/`__wakeup` throw)

## Documentation

| Document | Description |
|-|-|
| [doc/dsn.md](doc/dsn.md) | DSN syntax reference and all supported schemes |
| [doc/api-transports.md](doc/api-transports.md) | All 21 API transports — constructors, auth, examples |
| [doc/webhooks.md](doc/webhooks.md) | Webhook processing for delivery and engagement events |
| [doc/plugins.md](doc/plugins.md) | AllowlistPlugin, CssInlinerPlugin, SentMessagePlugin |
| [doc/events.md](doc/events.md) | SentMessageEvent, FailedMessageEvent, event lifecycle |
| [doc/upgrading.md](doc/upgrading.md) | Migration guide from stock SwiftMailer 6.x |

Legacy RST documentation (messages, headers, sending) remains in `doc/` for reference.

## Sponsors

<div>
    <a href="https://www.go-redrock.com/">
        <img src="https://www.go-redrock.com/wp-content/uploads/2021/07/Redrock-Software-Corporation_TracSystems_logo_400px.png" alt="Redrock Software Corporation">
    </a>
</div>

## License

MIT License. See [LICENSE](LICENSE) for details.
~~~

---

## Task 2 — Create doc/dsn.md

**File:** `doc/dsn.md`

**What to do:** Document the DSN system end-to-end. Cover `Swift_Transport_DsnTransportFactory`, `Swift_Dsn`, the full scheme map, SMTP DSN parameters, meta-transport wrappers.

**Complete content:**

~~~markdown
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

Wrap multiple DSNs for redundancy or load balancing:

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

**Syntax:** `wrapper(dsn1 dsn2 dsn3)` — DSNs are separated by spaces inside parentheses.

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
```
~~~

---

## Task 3 — Create doc/api-transports.md

**File:** `doc/api-transports.md`

**What to do:** Document all 21 API transports. For each: class name, constructor signature, required dependencies, DSN scheme, and a code example. Include tags/metadata mapping for each provider.

**Complete content:**

~~~markdown
# API Transports

All HTTP API transports extend `Swift_Transport_AbstractHttpApiTransport` (which extends `Swift_Transport_AbstractApiTransport`). They share a common lifecycle: `start()`, `send()`, `stop()`, `ping()`.

Most transports accept `(string $apiKey, ?ClientInterface $httpClient, ?Swift_Events_EventDispatcher $eventDispatcher)`. Exceptions are noted below.

## Common Features

### Tags

Add tags to messages using `X-Mailer-Tag` headers. Multiple tags are supported. Tags are extracted and removed before sending — they never reach the recipient's inbox.

```php
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome-email');
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'onboarding');
```

### Metadata

Add key-value metadata using `X-Mailer-Metadata-{key}` headers. Metadata is extracted and removed before sending.

```php
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-campaign', 'feb-2026');
```

### How Tags and Metadata Map to Each Provider

| Provider | Tags become | Metadata becomes |
|-|-|-|
| SendGrid | `categories` (array) | `custom_args` in personalizations |
| Postmark | `Tag` (first tag only) | `Metadata` object |
| Mailgun | `o:tag` (multiple) | `v:{key}` variables |
| Brevo | Check transport source | Check transport source |
| MailerSend | Check transport source | Check transport source |
| Mailjet | Check transport source | Check transport source |
| Amazon SES | `EmailTags` with Name=`tag` | `EmailTags` with Name=key |

Other transports: check the `getPayload()` or `getFormData()` method in the transport class.

---

## Transport Reference

### SendGrid

| | |
|-|-|
| **Class** | `Swift_Transport_Api_SendgridTransport` |
| **DSN** | `sendgrid://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_SendgridTransport('SG.your-api-key');
```

### Postmark

| | |
|-|-|
| **Class** | `Swift_Transport_Api_PostMarkTransport` |
| **DSN** | `postmark://SERVER_TOKEN@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_PostMarkTransport('your-server-token');
```

### Mailgun

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailGunTransport` |
| **DSN** | `mailgun://API_KEY@default` (requires domain via constructor) |
| **Constructor** | `(string $apiKey, string $domain, string $host = 'https://api.mailgun.net', ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailGunTransport('key-xxx', 'mg.example.com');

// EU region
$transport = new Swift_Transport_Api_MailGunTransport('key-xxx', 'mg.example.com', 'https://api.eu.mailgun.net');
```

### Brevo (formerly Sendinblue)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_BrevoTransport` |
| **DSN** | `brevo://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_BrevoTransport('your-api-key');
```

### Amazon SES (API — async-aws)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AmazonSesApiTransport` |
| **DSN** | `amazon+api://...` (not DSN-constructible — requires SesClient) |
| **Constructor** | `(SesClient $sesClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `async-aws/ses` (included) |

```php
use AsyncAws\Ses\SesClient;

$ses = new SesClient([
    'region' => 'us-east-1',
    'accessKeyId' => 'AKIA...',
    'accessKeySecret' => 'secret',
]);
$transport = new Swift_Transport_Api_AmazonSesApiTransport($ses);
```

### Amazon SES (HTTP)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AmazonSesHttpTransport` |
| **DSN** | `amazon+http://...` (not DSN-constructible — requires SesClient) |
| **Constructor** | `($sesClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `async-aws/ses` (included) |

```php
$transport = new Swift_Transport_Api_AmazonSesHttpTransport($ses);
```

### Azure Communication Services

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AzureTransport` |
| **DSN** | `azure://CONNECTION_STRING@default` |
| **Constructor** | `(string $connectionString, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$connectionString = 'endpoint=https://your-resource.communication.azure.com/;accesskey=BASE64KEY';
$transport = new Swift_Transport_Api_AzureTransport($connectionString);
```

### Google Gmail API

| | |
|-|-|
| **Class** | `Swift_Transport_Api_GoogleTransport` |
| **DSN** | `gmail+api://...` (not DSN-constructible — requires Google Client) |
| **Constructor** | `(Google\Client $googleClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `google/apiclient` (included) |

```php
$client = new Google\Client();
$client->setAuthConfig('/path/to/credentials.json');
$client->addScope(Google\Service\Gmail::GMAIL_SEND);
$transport = new Swift_Transport_Api_GoogleTransport($client);
```

### Microsoft Graph

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MicrosoftGraphTransport` |
| **DSN** | `microsoft-graph://...` (not DSN-constructible — requires GraphServiceClient) |
| **Constructor** | `(GraphServiceClient $client, string $sendingAccountUserId, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `microsoft/microsoft-graph` (included) |

```php
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user@company.com');

// Or use the From address dynamically
$transport->useFromAddressAsSendingAccountUserId();
```

### Resend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_ResendTransport` |
| **DSN** | `resend://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_ResendTransport('re_xxxxxxxxxxxx');
```

### Scaleway

| | |
|-|-|
| **Class** | `Swift_Transport_Api_ScalewayTransport` |
| **DSN** | `scaleway://API_KEY@default` (requires projectId via constructor) |
| **Constructor** | `(string $apiKey, string $projectId, string $region = 'fr-par', ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_ScalewayTransport('scw-key', 'project-id-xxx', 'fr-par');
```

### InfoBip

| | |
|-|-|
| **Class** | `Swift_Transport_Api_InfoBipTransport` |
| **DSN** | `infobip://API_KEY@default` (requires baseUrl via constructor) |
| **Constructor** | `(string $apiKey, string $baseUrl, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_InfoBipTransport('your-api-key', 'https://xxx.api.infobip.com');
```

### MailPace

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailPaceTransport` |
| **DSN** | `mailpace://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailPaceTransport('your-api-token');
```

### MailChimp (Mandrill)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailChimpTransport` |
| **DSN** | `mailchimp://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailChimpTransport('your-mandrill-api-key');
```

### MailerSend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailerSendTransport` |
| **DSN** | `mailersend://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailerSendTransport('mlsn.xxxxxxxxxxxx');
```

### Mailjet

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailJetTransport` |
| **DSN** | `mailjet://PUBLIC_KEY@default` (not fully DSN-constructible — needs both keys) |
| **Constructor** | `(string $publicKey, string $privateKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailJetTransport('public-key', 'private-key');
```

### AhaSend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AhaSendTransport` |
| **DSN** | `ahasend://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_AhaSendTransport('your-api-key');
```

### Mailomat

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailomatTransport` |
| **DSN** | `mailomat://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailomatTransport('your-api-key');
```

### Mailtrap

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailtrapTransport` |
| **DSN** | `mailtrap://API_KEY@default` |
| **Constructor** | `(string $apiKey, bool $sandbox = false, ?string $inboxId = null, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
// Production
$transport = new Swift_Transport_Api_MailtrapTransport('your-api-key');

// Sandbox mode
$transport = new Swift_Transport_Api_MailtrapTransport('your-api-key', true, 'inbox-id');
```

### Postal

| | |
|-|-|
| **Class** | `Swift_Transport_Api_PostalTransport` |
| **DSN** | `postal://API_KEY@default` (requires host via constructor) |
| **Constructor** | `(string $apiKey, string $host, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_PostalTransport('your-api-key', 'https://postal.example.com');
```

### Sweego

| | |
|-|-|
| **Class** | `Swift_Transport_Api_SweegoTransport` |
| **DSN** | `sweego://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_SweegoTransport('your-api-key');
```

---

## Creating a Custom API Transport

Extend `Swift_Transport_AbstractHttpApiTransport` and implement five methods:

```php
class Swift_Transport_Api_MyProviderTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        // Build payload, call API, return ['message_id' => ..., 'recipients' => ...]
    }

    protected function getEndpoint(): string
    {
        return 'https://api.myprovider.com/v1/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.myprovider.com/v1/health';
    }
}
```

Use `$this->extractTags($message)` and `$this->extractMetadata($message)` in `doSend()` to support tags and metadata.
~~~

---

## Task 4 — Create doc/webhooks.md

**File:** `doc/webhooks.md`

**What to do:** Document the webhook system: `Swift_Webhook_RequestHandler`, `Swift_Webhook_Event`, `PayloadConverterInterface`, and the four built-in converters (SendGrid, Mailgun, Postmark, Amazon SES). Include complete controller examples.

**Complete content:**

~~~markdown
# Webhook System

The webhook system processes inbound HTTP callbacks from email providers to track delivery status and engagement events (opens, clicks, bounces, complaints).

## Architecture

```
HTTP Request  -->  Swift_Webhook_RequestHandler
                         |
                         |  (signature verification + JSON decode)
                         v
               PayloadConverterInterface  (provider-specific)
                         |
                         v
               Swift_Webhook_Event[]  (normalized events)
```

**Key classes:**

| Class | Purpose |
|-|-|
| `Swift_Webhook_RequestHandler` | Orchestrates verification, decoding, conversion |
| `Swift_Webhook_PayloadConverterInterface` | Interface for provider converters |
| `Swift_Webhook_AbstractPayloadConverter` | Base class with HMAC helpers and event factories |
| `Swift_Webhook_Event` | Normalized event value object |

## Event Types

Events are categorized into two types:

### Delivery Events (`$event->isDelivery()`)

| Name | Description |
|-|-|
| `delivered` | Message accepted by recipient's mail server |
| `bounced` | Message bounced (hard or soft) |
| `deferred` | Delivery temporarily delayed |
| `dropped` | Message dropped by provider before delivery attempt |

### Engagement Events (`$event->isEngagement()`)

| Name | Description |
|-|-|
| `opened` | Recipient opened the email |
| `clicked` | Recipient clicked a link |
| `unsubscribed` | Recipient unsubscribed |
| `complained` | Recipient marked as spam |

## Swift_Webhook_Event API

```php
$event->getType();       // 'delivery' or 'engagement'
$event->getName();       // 'delivered', 'bounced', 'opened', etc.
$event->getMessageId();  // Provider's message ID
$event->getRecipient();  // Email address
$event->getMetadata();   // Array of extra data (reason, url, user_agent, ip, etc.)
$event->getTimestamp();  // DateTimeImmutable
$event->getRawPayload(); // Original provider payload array
$event->isDelivery();    // bool
$event->isEngagement();  // bool
```

## Basic Usage

```php
$handler = new Swift_Webhook_RequestHandler();

// In your controller / route handler:
$rawBody = file_get_contents('php://input');
$headers = getallheaders(); // or framework equivalent

$events = $handler->handle(
    new Swift_Webhook_Converter_SendgridConverter(),
    $rawBody,
    $headers,
    'your-signing-secret' // null to skip verification
);

foreach ($events as $event) {
    if ($event->isDelivery() && $event->getName() === 'bounced') {
        // Handle bounce
        $recipient = $event->getRecipient();
        $reason = $event->getMetadata()['reason'] ?? 'unknown';
        // Mark address as invalid in your database
    }
}
```

## Provider-Specific Setup

### SendGrid

**Converter:** `Swift_Webhook_Converter_SendgridConverter`

**Signature verification:** Uses ECDSA with SendGrid's public verification key. Headers: `X-Twilio-Email-Event-Webhook-Signature` and `X-Twilio-Email-Event-Webhook-Timestamp`.

**Secret:** Your SendGrid Event Webhook verification key (begins with `MFkw...`).

```php
$converter = new Swift_Webhook_Converter_SendgridConverter();
$events = $handler->handle($converter, $rawBody, $headers, $verificationKey);
```

**Event mapping:**

| SendGrid Event | Webhook Event |
|-|-|
| `bounce` | delivery / bounced |
| `deferred` | delivery / deferred |
| `delivered` | delivery / delivered |
| `dropped` | delivery / dropped |
| `open` | engagement / opened |
| `click` | engagement / clicked |
| `unsubscribe` | engagement / unsubscribed |
| `spamreport` | engagement / complained |

### Mailgun

**Converter:** `Swift_Webhook_Converter_MailgunConverter`

**Signature verification:** HMAC-SHA256. Mailgun sends `timestamp`, `token`, and `signature` in the payload.

**Secret:** Your Mailgun webhook signing key.

```php
$converter = new Swift_Webhook_Converter_MailgunConverter();
$events = $handler->handle($converter, $rawBody, $headers, $signingKey);
```

### Postmark

**Converter:** `Swift_Webhook_Converter_PostmarkConverter`

**Signature verification:** Provider-specific. Check the converter source for details.

```php
$converter = new Swift_Webhook_Converter_PostmarkConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Amazon SES

**Converter:** `Swift_Webhook_Converter_AmazonSesConverter`

**Signature verification:** SNS message signature verification.

```php
$converter = new Swift_Webhook_Converter_AmazonSesConverter();
$events = $handler->handle($converter, $rawBody, $headers, null);
```

## Framework Integration Examples

### Laravel

```php
// routes/api.php
Route::post('/webhooks/sendgrid', [WebhookController::class, 'sendgrid']);

// app/Http/Controllers/WebhookController.php
class WebhookController extends Controller
{
    public function sendgrid(Request $request)
    {
        $handler = new Swift_Webhook_RequestHandler();
        $events = $handler->handle(
            new Swift_Webhook_Converter_SendgridConverter(),
            $request->getContent(),
            $request->headers->all(),
            config('services.sendgrid.webhook_secret'),
        );

        foreach ($events as $event) {
            // Dispatch to a queue job for processing
            ProcessWebhookEvent::dispatch($event);
        }

        return response('OK', 200);
    }
}
```

### Plain PHP

```php
<?php
require 'vendor/autoload.php';

$handler = new Swift_Webhook_RequestHandler();

try {
    $events = $handler->handle(
        new Swift_Webhook_Converter_SendgridConverter(),
        file_get_contents('php://input'),
        array_change_key_case(getallheaders(), CASE_LOWER),
        $_ENV['SENDGRID_WEBHOOK_SECRET'],
    );

    foreach ($events as $event) {
        error_log(sprintf(
            '[%s] %s: %s -> %s',
            $event->getTimestamp()->format('Y-m-d H:i:s'),
            $event->getType(),
            $event->getName(),
            $event->getRecipient(),
        ));
    }

    http_response_code(200);
    echo 'OK';
} catch (Swift_Webhook_SignatureVerificationException $e) {
    http_response_code(401);
    echo 'Invalid signature';
}
```

## Creating a Custom Converter

Extend `Swift_Webhook_AbstractPayloadConverter`:

```php
class MyProviderConverter extends Swift_Webhook_AbstractPayloadConverter
{
    public function getProviderName(): string
    {
        return 'myprovider';
    }

    public function verify(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['x-myprovider-signature'] ?? '';
        return $this->verifyHmac($rawBody, $signature, $secret, 'sha256');
    }

    public function convert(array $payload, array $headers): array
    {
        $events = [];
        foreach ($payload['events'] as $entry) {
            $events[] = $this->createDeliveryEvent(
                name: $entry['type'],
                messageId: $entry['message_id'],
                recipient: $entry['email'],
                metadata: [],
                timestamp: $this->parseTimestamp($entry['timestamp']),
                rawPayload: $entry,
            );
        }
        return $events;
    }
}
```

The base class provides:
- `verifyHmac(string $data, string $signature, string $secret, string $algo)` — timing-safe HMAC comparison
- `createDeliveryEvent(...)` — factory for delivery events
- `createEngagementEvent(...)` — factory for engagement events
- `parseTimestamp(int|string $timestamp)` — parses Unix timestamps, ISO 8601, and common formats
~~~

---

## Task 5 — Create doc/plugins.md

**File:** `doc/plugins.md`

**What to do:** Document AllowlistPlugin, CssInlinerPlugin, and SentMessagePlugin with complete usage examples. Reference existing plugins (AntiFlood, Throttler, Logger, Redirecting, Decorator) briefly.

**Complete content:**

~~~markdown
# Plugins

Plugins hook into SwiftMailer's event system to modify behavior before, during, or after sending. Register plugins on the mailer instance:

```php
$mailer->registerPlugin($plugin);
```

## AllowlistPlugin

Restricts email delivery to a configured set of recipients. Designed for dev/staging environments to prevent accidental sends to real users.

**Class:** `Swift_Plugins_AllowlistPlugin`
**Implements:** `Swift_Events_SendListener`

### Basic Usage — Filter Recipients

```php
$plugin = new Swift_Plugins_AllowlistPlugin([
    '*@mycompany.com',       // Allow all addresses at this domain
    'tester@gmail.com',      // Allow this specific address
]);
$mailer->registerPlugin($plugin);

// This message will only be sent to recipients matching the allowlist.
// Non-matching recipients are silently removed.
// If no recipients remain, the send is cancelled entirely.
$mailer->send($message);
```

### Redirect Mode

Non-allowed recipients are redirected to a catch-all address instead of being removed:

```php
$plugin = new Swift_Plugins_AllowlistPlugin(
    ['*@mycompany.com'],
    'catch-all@mycompany.com'  // redirect target
);
$mailer->registerPlugin($plugin);
```

When redirecting:
- Allowed recipients receive the email normally
- An `X-Original-To` header is added with the original recipient list
- Cc and Bcc are cleared
- The catch-all address receives the redirected copy

### Behavior Details

- Patterns are case-insensitive
- Domain wildcards use `*@domain` syntax
- Original recipients are restored on the message object after sending (the message is not permanently modified)
- If no recipients remain after filtering (and no redirect is configured), the send event bubble is cancelled

## CssInlinerPlugin

Automatically inlines `<style>` CSS into HTML email bodies before sending. Essential for email client compatibility — many email clients strip `<style>` tags.

**Class:** `Swift_Plugins_CssInlinerPlugin`
**Implements:** `Swift_Events_SendListener`
**Requires:** `tijsverkoyen/css-to-inline-styles` (optional dependency)

### Installation

```bash
composer require tijsverkoyen/css-to-inline-styles
```

### Usage

```php
$plugin = new Swift_Plugins_CssInlinerPlugin();
$mailer->registerPlugin($plugin);

$message = (new Swift_Message('Styled Email'))
    ->setFrom(['sender@example.com'])
    ->setTo(['recipient@example.com'])
    ->setBody('
        <html>
        <head>
            <style>
                h1 { color: blue; }
                .content { font-size: 14px; }
            </style>
        </head>
        <body>
            <h1>Hello</h1>
            <p class="content">This will have inline styles.</p>
        </body>
        </html>
    ', 'text/html');

// CSS is automatically inlined before sending:
// <h1 style="color: blue;">Hello</h1>
$mailer->send($message);
```

The plugin processes:
- The main message body (if `text/html`)
- Any `Swift_MimePart` children with `text/html` content type
- If `tijsverkoyen/css-to-inline-styles` is not installed, the plugin silently does nothing

## SentMessagePlugin

Captures `Swift_SentMessage` objects after each successful send for post-send inspection (e.g., retrieving provider message IDs).

**Class:** `Swift_Plugins_SentMessagePlugin`
**Implements:** `Swift_Events_SentMessageListener`

### Usage

```php
$sentPlugin = new Swift_Plugins_SentMessagePlugin();
$mailer->registerPlugin($sentPlugin);

$mailer->send($message);

// Get the last sent message
$sent = $sentPlugin->getLastSentMessage();
if ($sent) {
    echo $sent->getMessageId();       // Provider's message ID
    echo $sent->getRecipientCount();   // Number of recipients
    print_r($sent->getDebug());        // Raw transport result
}

// Get all sent messages (useful in batch scenarios)
$allSent = $sentPlugin->getSentMessages();

// Reset the collection
$sentPlugin->reset();
```

### Swift_SentMessage API

| Method | Returns | Description |
|-|-|-|
| `getOriginalMessage()` | `Swift_Mime_SimpleMessage` | The message that was sent |
| `getTransport()` | `Swift_Transport` | The transport that sent it |
| `getMessageId()` | `?string` | Provider-assigned message ID |
| `getRecipientCount()` | `int` | Number of successful recipients |
| `getDebug()` | `array` | Raw result data from the transport |
| `getFailedRecipients()` | `array` | List of failed recipient addresses |

## Existing Plugins (from upstream)

These plugins exist in the original SwiftMailer and continue to work:

| Plugin | Purpose |
|-|-|
| `Swift_Plugins_AntiFloodPlugin` | Restart transport after N messages to avoid connection limits |
| `Swift_Plugins_ThrottlerPlugin` | Rate-limit sending (messages/min or bytes/min) |
| `Swift_Plugins_LoggerPlugin` | Log SMTP commands and transport events |
| `Swift_Plugins_RedirectingPlugin` | Redirect all emails to a specific address |
| `Swift_Plugins_DecoratorPlugin` | Per-recipient message personalization (template variables) |
| `Swift_Plugins_ImpersonatePlugin` | Override the From address |
| `Swift_Plugins_BandwidthMonitorPlugin` | Track bytes sent/received |
| `Swift_Plugins_MessageLogger` | Log full message content |

See the legacy [doc/plugins.rst](plugins.rst) for detailed documentation of these plugins.
~~~

---

## Task 6 — Create doc/events.md

**File:** `doc/events.md`

**What to do:** Document the complete event lifecycle including the two new events (SentMessageEvent, FailedMessageEvent), listener interfaces, and how API transports dispatch events. Include a diagram of event flow.

**Complete content:**

~~~markdown
# Event System

SwiftMailer uses an event-driven architecture. Transports and the mailer dispatch events at key points in the send lifecycle. Plugins subscribe to these events via listener interfaces.

## Event Lifecycle

When `$mailer->send($message)` is called, events fire in this order:

```
1. beforeSendPerformed  (SendEvent)
   - Plugins can modify the message or cancel sending

2. Transport sends the message

3a. ON SUCCESS:
    sentMessage           (SentMessageEvent)    [NEW]
    - Carries Swift_SentMessage with provider message ID

3b. ON FAILURE:
    failedMessage         (FailedMessageEvent)  [NEW]
    - Carries exception and failed recipient list

4. sendPerformed          (SendEvent)
   - Always fires (success or failure), carries result code
```

Transport lifecycle events:

```
beforeTransportStarted   (TransportChangeEvent)
transportStarted         (TransportChangeEvent)
beforeTransportStopped   (TransportChangeEvent)
transportStopped         (TransportChangeEvent)
exceptionThrown          (TransportExceptionEvent)
```

## New Events

### SentMessageEvent

Fired after a message is successfully sent. Only dispatched by transports that extend `Swift_Transport_AbstractHttpApiTransport`.

**Class:** `Swift_Events_SentMessageEvent`

```php
class MyListener implements Swift_Events_SentMessageListener
{
    public function sentMessage(Swift_Events_SentMessageEvent $evt): void
    {
        $sent = $evt->getSentMessage();

        echo $sent->getMessageId();        // e.g., "abc-123-def"
        echo $sent->getRecipientCount();   // e.g., 3
        echo get_class($evt->getTransport()); // transport that sent it
    }
}

$mailer->registerPlugin(new MyListener());
```

**SentMessageEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getSentMessage()` | `Swift_SentMessage` | The sent message value object |
| `getTransport()` | `Swift_Transport` | The transport that dispatched the event |
| `getSource()` | `Swift_Transport` | Alias for `getTransport()` (inherited) |

### FailedMessageEvent

Fired when a message fails to send. Only dispatched by transports that extend `Swift_Transport_AbstractHttpApiTransport`.

**Class:** `Swift_Events_FailedMessageEvent`

```php
class MyFailureListener implements Swift_Events_FailedMessageListener
{
    public function failedMessage(Swift_Events_FailedMessageEvent $evt): void
    {
        $exception = $evt->getException();
        $failed = $evt->getFailedRecipients();
        $message = $evt->getMessage();

        error_log(sprintf(
            'Failed to send "%s" to %s: %s',
            $message->getSubject(),
            implode(', ', $failed),
            $exception->getMessage(),
        ));
    }
}

$mailer->registerPlugin(new MyFailureListener());
```

**FailedMessageEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getMessage()` | `Swift_Mime_SimpleMessage` | The message that failed |
| `getException()` | `Swift_TransportException` | The exception that caused the failure |
| `getFailedRecipients()` | `string[]` | Email addresses that were not delivered |
| `getTransport()` | `Swift_Transport` | The transport that dispatched the event |

## Existing Events

### SendEvent

Fired before and after every send attempt. Used by most plugins.

```php
class MySendListener implements Swift_Events_SendListener
{
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        // Modify message, or cancel:
        // $evt->cancelBubble(true);
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        $result = $evt->getResult();
        // Swift_Events_SendEvent::RESULT_SUCCESS
        // Swift_Events_SendEvent::RESULT_TENTATIVE
        // Swift_Events_SendEvent::RESULT_FAILED
    }
}
```

### Pre-Send Rejection

Plugins can cancel sending in `beforeSendPerformed` by calling `$evt->cancelBubble(true)`. The transport will not attempt to send, and `sendPerformed` will fire with `RESULT_FAILED`. This is how `AllowlistPlugin` cancels sends when no recipients remain.

```php
public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
{
    if ($this->shouldReject($evt->getMessage())) {
        $evt->cancelBubble(true); // Message will not be sent
    }
}
```

### ResponseEvent

Fired by SMTP transports when a server response is received.

### TransportChangeEvent

Fired when a transport starts or stops.

### TransportExceptionEvent

Fired when a transport encounters an error. Listeners can suppress the exception by cancelling the bubble.

## Listener Interfaces

| Interface | Method(s) | Event |
|-|-|-|
| `Swift_Events_SendListener` | `beforeSendPerformed`, `sendPerformed` | `SendEvent` |
| `Swift_Events_SentMessageListener` | `sentMessage` | `SentMessageEvent` |
| `Swift_Events_FailedMessageListener` | `failedMessage` | `FailedMessageEvent` |
| `Swift_Events_ResponseListener` | `responseReceived` | `ResponseEvent` |
| `Swift_Events_TransportChangeListener` | `beforeTransportStarted`, `transportStarted`, `beforeTransportStopped`, `transportStopped` | `TransportChangeEvent` |
| `Swift_Events_TransportExceptionListener` | `exceptionThrown` | `TransportExceptionEvent` |
| `Swift_Events_CommandListener` | `commandSent` | `CommandEvent` |

## Registering Listeners

Listeners (plugins) are registered via the mailer or directly on a transport:

```php
// Via mailer (preferred)
$mailer->registerPlugin($myPlugin);

// Via transport (for transport-level events)
$transport->registerPlugin($myPlugin);
```

A single class can implement multiple listener interfaces to react to different events.
~~~

---

## Task 7 — Create doc/upgrading.md

**File:** `doc/upgrading.md`

**What to do:** Write a migration guide from stock SwiftMailer 6.x to the Redrock fork. Cover Composer changes, PHP version requirement, new features available, behavioral differences, and deprecation notes.

**Complete content:**

~~~markdown
# Upgrading from Stock SwiftMailer 6.x

This guide covers migrating from the original `swiftmailer/swiftmailer` (abandoned November 2021) to the Redrock Software Corporation fork.

## Step 1 — Update Composer

The fork uses the same Composer package name. Update your `composer.json` to point to the Redrock repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/redrock/swiftmailer"
        }
    ],
    "require": {
        "swiftmailer/swiftmailer": "^6.3"
    }
}
```

Then run:

```bash
composer update swiftmailer/swiftmailer
```

## Step 2 — Verify PHP Version

The fork requires **PHP 8.1+** (the original required PHP 7.0+). Ensure your environment meets this requirement.

### Required Extensions

| Extension | Purpose |
|-|-|
| `iconv` | Character encoding conversion |
| `mbstring` | Multi-byte string handling |
| `intl` | Internationalized domain names (IDN) |
| `openssl` | TLS/SSL encryption |

## Step 3 — Check for Breaking Changes

### Fully Backward Compatible

The fork maintains full backward compatibility with stock SwiftMailer 6.x for existing code:

- `Swift_SmtpTransport`, `Swift_SendmailTransport`, `Swift_NullTransport` work identically
- `Swift_Message` API is unchanged
- `Swift_Mailer::send()` signature and return value are unchanged
- All existing plugins (AntiFlood, Throttler, Logger, Redirecting, Decorator) work as before
- PSR-0 autoloading with `Swift_` prefix is preserved

### New Dependencies

The fork adds required dependencies that were not in the original:

| Package | Purpose |
|-|-|
| `nyholm/dsn` | DSN string parsing |
| `google/apiclient` | Gmail API transport |
| `microsoft/microsoft-graph` | Microsoft Graph transport |
| `async-aws/ses` | Amazon SES transport |
| `guzzlehttp/guzzle` | HTTP client for API transports |
| `phpseclib/phpseclib` | Cryptographic operations |

These are pulled in automatically by Composer. If you have dependency conflicts, you may need to adjust version constraints.

### Behavioral Differences

1. **SMTPUTF8 Auto-Detection:** The ESMTP transport now uses `AutoAddressEncoder` by default, which detects whether the server supports SMTPUTF8 and switches between UTF-8 and IDN encoding automatically. This is transparent but may result in different address encoding than the original.

2. **STARTTLS Mode:** A new `CONNECTION_MODE_STARTTLS` encryption constant is available for explicit STARTTLS negotiation.

3. **Serialization Blocked:** Transport objects now throw `BadMethodCallException` on `__sleep()`/`__wakeup()`. If you were serializing transports (unusual), this will break.

## Step 4 — Adopt New Features (Optional)

These features are all opt-in and do not affect existing code:

### Use API Transports Instead of SMTP

```php
// Before: SMTP through SendGrid
$transport = new Swift_SmtpTransport('smtp.sendgrid.net', 587, 'tls');
$transport->setUsername('apikey');
$transport->setPassword('SG.your-key');

// After: Direct API (faster, more reliable)
$transport = new Swift_Transport_Api_SendgridTransport('SG.your-key');
```

See [doc/api-transports.md](api-transports.md) for all 21 providers.

### Use DSN Strings for Configuration

```php
// Before: hardcoded transport construction
$transport = new Swift_SmtpTransport('smtp.example.com', 587, 'tls');
$transport->setUsername('user');
$transport->setPassword('pass');

// After: single connection string (great for env vars)
$factory = new Swift_Transport_DsnTransportFactory();
$transport = $factory->fromDsnString(getenv('MAILER_DSN'));
```

See [doc/dsn.md](dsn.md) for DSN syntax.

### Add Tags and Metadata

```php
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'password-reset');
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '42');
```

### Track Send Results

```php
$plugin = new Swift_Plugins_SentMessagePlugin();
$mailer->registerPlugin($plugin);

$mailer->send($message);
$messageId = $plugin->getLastSentMessage()?->getMessageId();
```

### Protect Dev/Staging Environments

```php
if ('production' !== getenv('APP_ENV')) {
    $mailer->registerPlugin(new Swift_Plugins_AllowlistPlugin(
        ['*@mycompany.com'],
        'dev-catchall@mycompany.com',
    ));
}
```

### Inline CSS Automatically

```bash
composer require tijsverkoyen/css-to-inline-styles
```

```php
$mailer->registerPlugin(new Swift_Plugins_CssInlinerPlugin());
```

### Handle Webhooks

```php
$handler = new Swift_Webhook_RequestHandler();
$events = $handler->handle(
    new Swift_Webhook_Converter_SendgridConverter(),
    $rawBody,
    $headers,
    $secret,
);
```

See [doc/webhooks.md](webhooks.md) for complete examples.

## SwiftMailer vs. Symfony Mailer

If you are deciding between this fork and migrating to Symfony Mailer:

| Consideration | This Fork | Symfony Mailer |
|-|-|-|
| Namespace style | PSR-0 (`Swift_Message`) | PSR-4 (`Symfony\Component\Mime\Email`) |
| Migration effort | Drop-in replacement | Full rewrite of mail code |
| API transports | 21 built-in | Via symfony/* bridges |
| DSN support | Yes | Yes |
| Webhook handling | Built-in | Via symfony/webhook |
| Active development | Yes (Redrock) | Yes (Symfony) |
| Best for | Legacy apps, minimal migration | New apps, Symfony ecosystem |

This fork is the right choice when migration to Symfony Mailer is impractical due to codebase size, technical debt, or the need for backward compatibility.
~~~

---

## Task 8 — Commit All Documentation

**What to do:** Stage and commit all new and modified documentation files:
- `README.md` (modified)
- `doc/dsn.md` (new)
- `doc/api-transports.md` (new)
- `doc/webhooks.md` (new)
- `doc/plugins.md` (new)
- `doc/events.md` (new)
- `doc/upgrading.md` (new)

**Commit message:** `docs: comprehensive documentation refresh covering fork features, API transports, DSN, webhooks, plugins, and events`
