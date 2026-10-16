# Upgrading from Stock SwiftMailer 6.x

This guide covers migrating from the original `swiftmailer/swiftmailer` (abandoned November 2021) to the Redrock Software Corporation fork.

## Step 1 -- Update Composer

The fork is published on Packagist as [`go-redrock/swiftmailer`](https://packagist.org/packages/go-redrock/swiftmailer). Replace the abandoned package:

```bash
composer remove swiftmailer/swiftmailer
composer require go-redrock/swiftmailer
```

Class names (`Swift_Message`, `Swift_Mailer`, ...) and autoloading are unchanged. Review the behavioral differences below before upgrading.

> **Note:** If other packages in your dependency tree still require `swiftmailer/swiftmailer`, Composer will install the abandoned original alongside the fork and the duplicate class definitions will conflict. Run `composer why swiftmailer/swiftmailer` to find them and update their constraints first.

If you previously consumed this fork through a VCS `repositories` entry in `composer.json`, remove that entry -- the Packagist package replaces it.

## Step 2 -- Verify PHP Version

The fork requires **PHP 8.3+** (the original required PHP 7.0+). Ensure your environment meets this requirement.

### Required Extensions

| Extension | Purpose |
|-|-|
| `iconv` | Character encoding conversion |
| `mbstring` | Multi-byte string handling |
| `intl` | Internationalized domain names (IDN) |
| `openssl` | TLS/SSL encryption |

## Step 3 -- Check for Breaking Changes

### Existing APIs

The fork preserves these stock SwiftMailer 6.x APIs:

- `Swift_SmtpTransport`, `Swift_SendmailTransport`, `Swift_NullTransport` work identically
- `Swift_Message` API is unchanged
- `Swift_Mailer::send()` return value is unchanged; the signature adds an optional third `?Swift_Envelope $envelope` parameter (backward compatible)
- All existing plugins (AntiFlood, Throttler, Logger, Redirecting, Decorator) work as before
- PSR-0 autoloading with `Swift_` prefix is preserved

### New Dependencies

The fork adds required dependencies that were not in the original:

| Package | Purpose |
|-|-|
| `egulias/email-validator` | Email address validation |
| `nyholm/dsn` | DSN string parsing |
| `google/apiclient` | Gmail API transport |
| `microsoft/microsoft-graph` | Microsoft Graph transport |
| `stevenmaguire/oauth2-microsoft` | OAuth2 for Microsoft Graph |
| `league/oauth2-client` | OAuth2 client library |
| `league/oauth2-google` | OAuth2 for Google/Gmail |
| `async-aws/ses` | Amazon SES transport |
| `guzzlehttp/guzzle` | HTTP client for API transports |
| `phpseclib/phpseclib` | Cryptographic operations |

These are pulled in automatically by Composer. If you have dependency conflicts, you may need to adjust version constraints.

### Behavioral Differences

1. **SMTPUTF8 Auto-Detection:** The ESMTP transport now uses `AutoAddressEncoder` by default, which detects whether the server supports SMTPUTF8 and switches between UTF-8 and IDN encoding automatically. This is transparent but may result in different address encoding than the original.

2. **Encryption Mode Constants:** Global constants are available for the SMTP encryption modes: `CONNECTION_ENCRYPTION_MODE_STARTTLS` (`'tls'`, STARTTLS negotiation on a plain connection), `CONNECTION_ENCRYPTION_MODE_TLS` (`'ssl'`, SMTPS -- TLS from the first byte), and `CONNECTION_ENCRYPTION_MODE_NONE` (`null`). The string values `'tls'`/`'ssl'` accepted by stock SwiftMailer 6.x continue to work unchanged.

3. **Serialization Blocked:** SMTP and API transport objects throw `BadMethodCallException` on `__sleep()`/`__wakeup()`. Pool metadata retains its serialized storage format, but this does not make SMTP/API backends serializable.

4. **DKIM Signer Enhancements:** The DKIM signer now supports Ed25519-SHA256 in addition to RSA-SHA256, and includes oversigning capabilities. Existing RSA-SHA256 configurations continue to work unchanged.

5. **Pool Recovery:** Unconfigured failover and load-balanced pools preserve manual recovery: `start()` immediately restores retired backends. Enable timed recovery by passing a non-negative constructor period, calling `setRetryPeriod(N)`, or specifying `?retry_period=N` after a pool DSN's closing parenthesis. With that explicit configuration, startup by `Swift_Mailer` and retry wrappers respects the deadline. Zero permits recovery on the next operation, bounded within each send. Changing the period uses original failure times; replacing the pool clears failure history. Older serialized pools without timestamps start their cooldown when first configured. See [pool recovery](sending.md#restoring-and-checking-a-pool) and [DSN options](dsn.md#pool-retry-period).

6. **Delivery safeguards:** HTTP API and SDK operations refuse redirects, including token exchanges. Custom SES clients/credential providers and Graph request adapters must support the documented scoped request policy; unsupported implementations fail before delivery rather than bypassing it. Successful or ambiguous send responses cannot be replayed automatically, even by a permissive retry classifier. See [API transports](api-transports.md).

7. **Recipient privacy and explicit envelopes:** Sweego and Scaleway preserve separate To/Cc/Bcc fields; Sweego requires a public To recipient. SMTP strips Bcc from transmitted MIME even with an explicit envelope. API transports reject genuine explicit envelope overrides they cannot represent, while accepting an equivalent audience (including addresses repeated across To/Cc/Bcc). Allowlist and redirect plugins also filter explicit delivery recipients. See [recipient policies](plugins.md#allowlistplugin).

8. **Graph calendar policies:** Recipient policies apply to calendar attendees and lookup audiences. Policy-controlled updates are blocked without a verified conditional PATCH contract; cancellation requires a known organizer, safe stored audience and exact ETag. See [API transports](api-transports.md).

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

For a configured mailer, the repaired legacy helper is also available:

```php
$mailer = Swift::createTransportFromDsn(getenv('MAILER_DSN'));
```

This helper returns `Swift_Mailer`, matching its original intended behavior.
Use the factory above when you want a transport object. The helper now shares
its DSN validation and decorator support with that factory.

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
$signer->setHashAlgorithm('ed25519-sha256');
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
