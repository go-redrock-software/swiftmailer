# Swift Mailer

A component-based PHP mailing library, maintained by [Redrock Software Corporation](https://www.go-redrock.com/). This fork integrates features from Symfony Mailer back into SwiftMailer for legacy and enterprise applications that cannot migrate to Symfony Mailer.

## About This Fork

The original SwiftMailer was abandoned by its creators in November 2021. [Redrock Software Corporation](https://www.go-redrock.com/) picked up maintenance in 2022 to serve the many legacy and enterprise PHP applications that depend on SwiftMailer and cannot practically migrate to Symfony Mailer.

### What We've Done

Since taking over, Redrock has shipped a major modernization of SwiftMailer (v6.4.0) that back-ports the most valuable Symfony Mailer capabilities while preserving full backward compatibility:

- **21 HTTP API transports** -- SendGrid, Mailgun, Postmark, Brevo, Amazon SES, Azure, Gmail API, Microsoft Graph, Resend, Scaleway, InfoBip, MailPace, Mandrill, MailerSend, Mailjet, AhaSend, Mailomat, Mailtrap, Postal, Sweego, and more
- **DSN transport factory** -- create any transport from a connection string, with `failover()`, `roundrobin()`, and `retry()` wrappers
- **Webhook system** -- process inbound delivery/bounce/engagement webhooks from 14 providers with signature verification
- **New events** -- `SentMessageEvent` and `FailedMessageEvent` for post-send tracking
- **New plugins** -- `AllowlistPlugin` (dev/staging safety), `CssInlinerPlugin` (auto CSS inlining), `SentMessagePlugin` (post-send inspection)
- **DKIM enhancements** -- Ed25519-SHA256 signing, header oversigning
- **SMTP improvements** -- Auto TLS, Smart SMTPUTF8, explicit envelope control via `Swift_Envelope`
- **RetryTransport** -- automatic retries with exponential backoff for any transport
- **Security hardening** -- `#[SensitiveParameter]` on API keys, Guzzle exception sanitization, serialization blocking
- **Modern tooling** -- PHP 8.1+ baseline, PHPStan static analysis, CI pipeline with GitHub Actions, Infection mutation testing, PHP-CS-Fixer
- **CLI test tool** -- `bin/swiftmailer-test` for validating transport configuration from the command line

### The Future of SwiftMailer

SwiftMailer is not dead -- it's actively maintained and developed. Our roadmap includes:

- **Continued Symfony Mailer parity** -- closing the remaining feature gaps (see [docs/SYMFONY_MAILER_PARITY.md](docs/SYMFONY_MAILER_PARITY.md))
- **Additional API transports** -- expanding provider coverage as new services emerge
- **PHP version support** -- staying current with the latest PHP releases
- **Community contributions** -- we welcome pull requests, bug reports, and feature requests

If your application uses SwiftMailer, you don't have to migrate. Upgrade to this fork and get modern features with zero code changes to your existing mail code.

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

// Retry with exponential backoff
$transport = $factory->fromDsnString('retry(sendgrid://KEY@default)');

$mailer = new Swift_Mailer($transport);
```

## Features

### 21 HTTP API Transports

SendGrid, Mailgun, Postmark, Brevo, Amazon SES (API + HTTP), Azure Communication Services, Google Gmail API, Microsoft Graph, Resend, Scaleway, InfoBip, MailPace, MailChimp/Mandrill, MailerSend, Mailjet, AhaSend, Mailomat, Mailtrap, Postal, Sweego.

See [doc/api-transports.md](doc/api-transports.md) for constructor arguments, authentication, and examples.

### DSN Transport Factory

Create any transport from a connection string. Supports `failover()`, `roundrobin()`, and `retry()` wrappers for redundancy, load balancing, and automatic retries with exponential backoff.

See [doc/dsn.md](doc/dsn.md) for the full scheme reference.

### RetryTransport

Wrap any transport with automatic retry logic featuring exponential backoff:

```php
$inner = new Swift_Transport_Api_SendgridTransport('SG.your-key');
$transport = new Swift_RetryTransport($inner, maxRetries: 3, baseDelay: 1000);

// Or via DSN
$transport = $factory->fromDsnString('retry(sendgrid://KEY@default)');
```

### Tags and Metadata

Attach tags and metadata to messages via headers. API transports automatically map them to each provider's native format.

```php
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome-email');
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
```

See [doc/api-transports.md](doc/api-transports.md) for provider-specific mapping details.

### Webhook System

Process inbound webhooks from 14 email providers to track deliveries, bounces, opens, clicks, and complaints. Supported providers: SendGrid, Mailgun, Postmark, Amazon SES, Brevo, Resend, MailerSend, Mailjet, Mandrill, AhaSend, Mailomat, Mailtrap, Sweego, MailPace.

See [doc/webhooks.md](doc/webhooks.md) for setup and provider-specific examples.

### New Plugins

- **AllowlistPlugin** -- restrict delivery to allowed recipients/domains with descriptive rejection reasons (dev/staging safety)
- **CssInlinerPlugin** -- automatically inline CSS in HTML emails before sending
- **SentMessagePlugin** -- capture `Swift_SentMessage` objects for post-send inspection

See [doc/plugins.md](doc/plugins.md) for configuration and usage.

### New Events

- **SentMessageEvent** -- fired after successful send, carries `Swift_SentMessage` with provider message ID
- **FailedMessageEvent** -- fired on send failure, carries exception and failed recipient list

See [doc/events.md](doc/events.md) for the complete event lifecycle.

### DKIM Signing

Enhanced DKIM signer with support for:

- **Ed25519-SHA256** algorithm (`ed25519-sha256`) in addition to RSA-SHA256
- **Oversigning** of From, Subject, and other headers to prevent header injection
- Modernized API with fluent configuration

```php
$signer = new Swift_Signers_DKIMSigner($privateKey, 'example.com', 'selector');
$signer->setSignatureAlgorithm('ed25519-sha256');
$signer->setOversignedHeaders(['From', 'Subject', 'To']);
$message->attachSigner($signer);
```

### Swift_Envelope

Explicit SMTP envelope control, separating the SMTP envelope sender/recipients from the message headers:

```php
$envelope = new Swift_Envelope('bounce-handler@example.com', ['actual-recipient@example.com']);
$mailer->send($message, $failedRecipients, $envelope);
```

### SMTP Enhancements

- **Auto TLS** -- STARTTLS negotiation mode alongside traditional `ssl`/`tls`
- **Smart SMTPUTF8** -- `AutoAddressEncoder` detects SMTPUTF8 server capability and switches encoding automatically
- **DSN parameters** -- `verify_peer`, `peer_fingerprint`, `source_ip`, `smtputf8` via DSN query string

### Security Hardening

- `#[SensitiveParameter]` on all API key constructor parameters (PHP 8.2+)
- Guzzle exception chain sanitized to prevent API key leakage in stack traces
- Serialization blocked on transport objects (`__sleep`/`__wakeup` throw)

### CLI Testing Tool

The `bin/swiftmailer-test` command-line tool validates your transport configuration:

```bash
./bin/swiftmailer-test 'sendgrid://SG.your-key@default'
```

### CI & Quality

- GitHub Actions CI pipeline with PHP 8.1--8.4 test matrix
- PHPStan level 5 static analysis
- Infection mutation testing
- PHP-CS-Fixer code style enforcement

## Documentation

| Document | Description |
|-|-|
| [doc/dsn.md](doc/dsn.md) | DSN syntax reference and all supported schemes |
| [doc/api-transports.md](doc/api-transports.md) | All 21 API transports -- constructors, auth, examples |
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
