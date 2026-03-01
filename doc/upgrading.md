# Upgrading from Stock SwiftMailer 6.x

This guide covers migrating from the original `swiftmailer/swiftmailer` (abandoned November 2021) to the Redrock Software Corporation fork.

## Step 1 -- Update Composer

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

## Step 2 -- Verify PHP Version

The fork requires **PHP 8.1+** (the original required PHP 7.0+). Ensure your environment meets this requirement.

### Required Extensions

| Extension | Purpose |
|-|-|
| `iconv` | Character encoding conversion |
| `mbstring` | Multi-byte string handling |
| `intl` | Internationalized domain names (IDN) |
| `openssl` | TLS/SSL encryption |

## Step 3 -- Check for Breaking Changes

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

4. **DKIM Signer Enhancements:** The DKIM signer now supports Ed25519-SHA256 in addition to RSA-SHA256, and includes oversigning capabilities. Existing RSA-SHA256 configurations continue to work unchanged.

## Step 4 -- Adopt New Features (Optional)

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

See [doc/webhooks.md](webhooks.md) for complete examples with all 14 supported providers.

### Use RetryTransport for Resilience

```php
// Wrap any transport with automatic retry
$transport = $factory->fromDsnString('retry(sendgrid://KEY@default)');
```

### Use DKIM with Ed25519

```php
$signer = new Swift_Signers_DKIMSigner($ed25519PrivateKey, 'example.com', 'selector');
$signer->setSignatureAlgorithm('ed25519-sha256');
$message->attachSigner($signer);
```

### Control the SMTP Envelope

```php
$envelope = new Swift_Envelope('bounce@example.com', ['recipient@example.com']);
$mailer->send($message, $failedRecipients, $envelope);
```

### Validate Configuration with the CLI Tool

```bash
./bin/swiftmailer-test 'sendgrid://SG.your-key@default'
```

## SwiftMailer vs. Symfony Mailer

If you are deciding between this fork and migrating to Symfony Mailer:

| Consideration | This Fork | Symfony Mailer |
|-|-|-|
| Namespace style | PSR-0 (`Swift_Message`) | PSR-4 (`Symfony\Component\Mime\Email`) |
| Migration effort | Drop-in replacement | Full rewrite of mail code |
| API transports | 21 built-in | Via symfony/* bridges |
| DSN support | Yes | Yes |
| Webhook handling | Built-in (14 providers) | Via symfony/webhook |
| Active development | Yes (Redrock) | Yes (Symfony) |
| Best for | Legacy apps, minimal migration | New apps, Symfony ecosystem |

This fork is the right choice when migration to Symfony Mailer is impractical due to codebase size, technical debt, or the need for backward compatibility.
