# Swift Mailer: A feature-rich PHP Mailer

Swift Mailer is a component-based library for sending e-mails from PHP
applications, maintained by [Redrock Software Corporation](https://www.go-redrock.com/).

This fork continues Swiftmailer development for legacy and enterprise
applications that cannot migrate to Symfony Mailer. It integrates features from
Symfony Mailer -- including 21 HTTP API transports, a DSN factory, webhook
processing, retry/failover transports, and more -- while preserving full
backward compatibility with stock SwiftMailer 6.x. Classes keep the PSR-0
`Swift_` prefix (e.g. `Swift_Message`), so existing code works unchanged.

## System Requirements

Swift Mailer requires **PHP 8.3 or later** with the following extensions:

| Extension | Purpose |
|-|-|
| `iconv` | Character-set conversion |
| `mbstring` | Multi-byte string handling |
| `intl` | Internationalized domain names (IDN) for address encoding |
| `openssl` | TLS/SSL encryption for SMTP and API transports |

## Installation

The recommended way to install Swiftmailer is via [Composer](https://getcomposer.org/):

```bash
composer require go-redrock/swiftmailer
```

Composer generates a `vendor/autoload.php` autoloader. If you are not using
Composer, the library can be bootstrapped directly by requiring
`lib/swift_required.php`, which registers the PSR-0 autoloader and defines the
encryption-mode constants.

## Basic Usage

Here is the simplest way to send an email over SMTP:

```php
require_once '/path/to/vendor/autoload.php';

// Create the Transport
$transport = (new Swift_SmtpTransport('smtp.example.org', 587, CONNECTION_ENCRYPTION_MODE_STARTTLS))
    ->setUsername('your username')
    ->setPassword('your password')
;

// Create the Mailer using your created Transport
$mailer = new Swift_Mailer($transport);

// Create a message
$message = (new Swift_Message('Wonderful Subject'))
    ->setFrom(['john@doe.com' => 'John Doe'])
    ->setTo(['receiver@domain.org', 'other@domain.org' => 'A name'])
    ->setBody('Here is the message itself')
;

// Send the message; returns the number of accepted recipients
$result = $mailer->send($message);
```

> **Encryption constants.** The third `Swift_SmtpTransport` argument is one of
> `CONNECTION_ENCRYPTION_MODE_NONE` (plain SMTP, the default),
> `CONNECTION_ENCRYPTION_MODE_STARTTLS` (STARTTLS, string value `'tls'`), or
> `CONNECTION_ENCRYPTION_MODE_TLS` (SMTPS over TLS, string value `'ssl'`).
> These are the only encryption constants the library defines -- see
> [sending.md](sending.md) for details.

You can also use an HTTP API transport for faster, more reliable delivery on
supported providers:

```php
// Direct API call instead of SMTP
$transport = new Swift_Transport_Api_SendgridTransport('your-api-key');
$mailer = new Swift_Mailer($transport);
$mailer->send($message);
```

Or build any transport from a DSN connection string, which keeps configuration
in a single environment-driven value:

```php
$factory = new Swift_Transport_DsnTransportFactory();
$transport = $factory->fromDsnString('sendgrid://API_KEY@default');
$mailer = new Swift_Mailer($transport);
```

## Where to Go Next

| Guide | Contents |
|-|-|
| [sending.md](sending.md) | Transports, the `send()` method, envelopes, spooling, retry/failover |
| [messages.md](messages.md) | Building messages, attachments, embedded files, MIME parts |
| [headers.md](headers.md) | Working with message headers |
| [api-transports.md](api-transports.md) | All 21 HTTP API transports |
| [microsoft-graph.md](microsoft-graph.md) | Microsoft Graph: auth modes, large attachments, calendar invites |
| [dsn.md](dsn.md) | DSN connection strings and scheme reference |
| [cli.md](cli.md) | The `swiftmailer-test` command-line tool |
| [events.md](events.md) | Event lifecycle, `SentMessageEvent`, `FailedMessageEvent` |
| [plugins.md](plugins.md) | All 14 plugins, from AntiFlood to ReadReceipt |
| [webhooks.md](webhooks.md) | Processing delivery and engagement webhooks |
| [signers.md](signers.md) | DKIM, DomainKeys, and S/MIME signing |
| [security.md](security.md) | Security hardening: defaults and opt-in protections |
| [architecture.md](architecture.md) | Codebase internals for contributors |
| [upgrading.md](upgrading.md) | Migrating from stock SwiftMailer 6.x |

## Getting Help

For general support, use [Stack Overflow](https://stackoverflow.com).

For bug reports and feature requests, open a ticket on
[GitHub](https://github.com/go-redrock-software/swiftmailer/issues).
