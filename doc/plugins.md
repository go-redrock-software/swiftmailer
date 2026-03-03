# Plugins

Plugins hook into SwiftMailer's event system to modify behavior before, during, or after sending. Register plugins on the mailer instance:

```php
$mailer->registerPlugin($plugin);
```

## AllowlistPlugin

Restricts email delivery to a configured set of recipients. Designed for dev/staging environments to prevent accidental sends to real users.

**Class:** `Swift_Plugins_AllowlistPlugin`
**Implements:** `Swift_Events_SendListener`

### Basic Usage -- Filter Recipients

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

### Rejection with Descriptive Reasons

When recipients are rejected by the allowlist, the plugin integrates with the event system to provide descriptive rejection reasons. The `reject()` method provides details about why each recipient was filtered, making it easier to debug delivery issues in staging environments.

### Behavior Details

- Patterns are case-insensitive
- Domain wildcards use `*@domain` syntax
- Original recipients are restored on the message object after sending (the message is not permanently modified)
- If no recipients remain after filtering (and no redirect is configured), the send event bubble is cancelled

## CssInlinerPlugin

Automatically inlines `<style>` CSS into HTML email bodies before sending. Essential for email client compatibility -- many email clients strip `<style>` tags.

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

## Upstream Plugins

These plugins exist in the original SwiftMailer and continue to work in the Redrock fork.

### AntiFloodPlugin

Disconnects and reconnects the transport after a configurable number of emails to stay within server connection limits.

**Class:** `Swift_Plugins_AntiFloodPlugin`
**Implements:** `Swift_Events_SendListener`

```php
// Restart transport every 100 emails
$mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(100));

// Restart every 100 emails, pausing 30 seconds between reconnects
$mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(100, 30));
```

Constructor: `__construct(int $threshold = 99, int $sleep = 0, ?Swift_Plugins_Sleeper $sleeper = null)`

### ThrottlerPlugin

Rate-limits sending to avoid exceeding server quotas. Supports three modes:

**Class:** `Swift_Plugins_ThrottlerPlugin` (extends `Swift_Plugins_BandwidthMonitorPlugin`)
**Implements:** `Swift_Events_SendListener`, `Swift_Plugins_Sleeper`, `Swift_Plugins_Timer`

| Constant | Description |
|-|-|
| `BYTES_PER_MINUTE` | Throttle by total bytes transferred per minute |
| `MESSAGES_PER_MINUTE` | Throttle by number of messages per minute |
| `MESSAGES_PER_SECOND` | Throttle by number of messages per second (useful for Amazon SES) |

```php
// 100 emails per minute
$mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin(
    100, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE
));

// 10 MB per minute
$mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin(
    1024 * 1024 * 10, Swift_Plugins_ThrottlerPlugin::BYTES_PER_MINUTE
));

// 14 messages per second (Amazon SES limit)
$mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin(
    14, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_SECOND
));
```

Constructor: `__construct(int $rate, int $mode = self::BYTES_PER_MINUTE, ?Swift_Plugins_Sleeper $sleeper = null, ?Swift_Plugins_Timer $timer = null)`

### LoggerPlugin

Logs SMTP commands, responses, transport lifecycle events, sent messages, and failed messages. Enriches exception messages with the full SMTP transcript for debugging.

**Class:** `Swift_Plugins_LoggerPlugin`
**Implements:** `Swift_Events_CommandListener`, `Swift_Events_ResponseListener`, `Swift_Events_TransportChangeListener`, `Swift_Events_TransportExceptionListener`, `Swift_Events_SendListener`, `Swift_Events_SentMessageListener`, `Swift_Events_FailedMessageListener`, `Swift_Plugins_Logger`

Available loggers:
- `Swift_Plugins_Loggers_ArrayLogger` -- stores log entries in an array; retrieve with `dump()`, clear with `clear()`
- `Swift_Plugins_Loggers_EchoLogger` -- prints log entries to stdout in real time

```php
// Array logger (capture for later)
$logger = new Swift_Plugins_Loggers_ArrayLogger();
$mailer->registerPlugin(new Swift_Plugins_LoggerPlugin($logger));

$mailer->send($message);
echo $logger->dump();

// Echo logger (real-time output)
$logger = new Swift_Plugins_Loggers_EchoLogger();
$mailer->registerPlugin(new Swift_Plugins_LoggerPlugin($logger));
```

Constructor: `__construct(Swift_Plugins_Logger $logger)`

### DecoratorPlugin

Per-recipient message personalization using template placeholders. The plugin intercepts sending, looks up the To address in a replacement map, and substitutes placeholders in the body, subject, and headers.

**Class:** `Swift_Plugins_DecoratorPlugin`
**Implements:** `Swift_Events_SendListener`, `Swift_Plugins_Decorator_Replacements`

```php
$replacements = [
    'alice@example.com' => ['{name}' => 'Alice', '{code}' => '1234'],
    'bob@example.com'   => ['{name}' => 'Bob',   '{code}' => '5678'],
];

$mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin($replacements));

$message = (new Swift_Message('Hello {name}'))
    ->setBody('Your code is {code}.');
```

You can also pass a `Swift_Plugins_Decorator_Replacements` implementation for on-the-fly lookups (e.g., from a database):

```php
class DbReplacements implements Swift_Plugins_Decorator_Replacements {
    public function getReplacementsFor($address) {
        // Query your database and return ['{placeholder}' => 'value', ...]
    }
}

$mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin(new DbReplacements()));
```

Constructor: `__construct(array|Swift_Plugins_Decorator_Replacements $replacements)`

### RedirectingPlugin

Redirects all emails to a specified recipient. Useful in development/staging to prevent accidental sends. Supports a whitelist of regex patterns for addresses that should still receive mail normally.

**Class:** `Swift_Plugins_RedirectingPlugin`
**Implements:** `Swift_Events_SendListener`

```php
// Redirect everything to the dev team
$mailer->registerPlugin(new Swift_Plugins_RedirectingPlugin('dev@example.com'));

// Redirect everything except @mycompany.com addresses
$mailer->registerPlugin(new Swift_Plugins_RedirectingPlugin(
    'dev@example.com',
    ['/.*@mycompany\.com$/']
));
```

Original recipients are stored in `X-Swift-To`, `X-Swift-Cc`, and `X-Swift-Bcc` headers during sending, then restored on the message object afterward.

Constructor: `__construct(string|array $recipient, array $whitelist = [])`

### ImpersonatePlugin

Overrides the message's return-path (envelope sender) with a fixed address. The original return-path is restored after sending.

**Class:** `Swift_Plugins_ImpersonatePlugin`
**Implements:** `Swift_Events_SendListener`

```php
$mailer->registerPlugin(new Swift_Plugins_ImpersonatePlugin('bounces@example.com'));
```

Constructor: `__construct(string $sender)`

### BandwidthMonitorPlugin

Tracks the total bytes sent to and received from the SMTP server. Used internally by `ThrottlerPlugin` and can be used standalone for monitoring.

**Class:** `Swift_Plugins_BandwidthMonitorPlugin`
**Implements:** `Swift_Events_SendListener`, `Swift_Events_CommandListener`, `Swift_Events_ResponseListener`, `Swift_InputByteStream`

```php
$monitor = new Swift_Plugins_BandwidthMonitorPlugin();
$mailer->registerPlugin($monitor);

$mailer->send($message);

echo $monitor->getBytesOut(); // Total bytes sent
echo $monitor->getBytesIn();  // Total bytes received

$monitor->reset(); // Reset counters to zero
```

### MessageLogger

Stores clones of all messages passed to the transport. Useful for testing or auditing the exact message content that was sent.

**Class:** `Swift_Plugins_MessageLogger`
**Implements:** `Swift_Events_SendListener`

```php
$messageLogger = new Swift_Plugins_MessageLogger();
$mailer->registerPlugin($messageLogger);

$mailer->send($message);

$messages = $messageLogger->getMessages();  // Swift_Mime_SimpleMessage[]
$count    = $messageLogger->countMessages();
$messageLogger->clear();                    // Empty the stored messages
```

### ReporterPlugin

Reports per-recipient pass/fail delivery results to a `Swift_Plugins_Reporter` backend after each send.

**Class:** `Swift_Plugins_ReporterPlugin`
**Implements:** `Swift_Events_SendListener`

Available reporters:
- `Swift_Plugins_Reporters_HitReporter` -- collects failed recipient addresses
- `Swift_Plugins_Reporters_HtmlReporter` -- generates HTML-formatted delivery reports

```php
$reporter = new Swift_Plugins_Reporters_HitReporter();
$mailer->registerPlugin(new Swift_Plugins_ReporterPlugin($reporter));

$mailer->send($message);

// Get addresses that failed delivery
$failures = $reporter->getFailedRecipients();
```

Constructor: `__construct(Swift_Plugins_Reporter $reporter)`

### PopBeforeSmtpPlugin

Authenticates with a POP3 server before starting the SMTP transport. Some legacy mail servers require this authentication sequence.

**Class:** `Swift_Plugins_PopBeforeSmtpPlugin`
**Implements:** `Swift_Events_TransportChangeListener`

```php
$pop = new Swift_Plugins_PopBeforeSmtpPlugin('pop.example.com', 110);
$pop->setUsername('user');
$pop->setPassword('pass');

$mailer->registerPlugin($pop);
```

Constructor: `__construct(string $host, int $port = 110, ?string $crypto = null)`
