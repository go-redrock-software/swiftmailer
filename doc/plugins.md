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
