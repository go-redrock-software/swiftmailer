# Plugins

Plugins extend or modify SwiftMailer's behaviour before, during, and after a
message is sent. Every plugin is an *event listener*: it implements one or more
of the listener interfaces described in [events.md](events.md) and reacts when
the mailer or transport dispatches the matching event.

## Registering plugins

Register a plugin on the mailer (preferred) or directly on a transport:

```php
$mailer = new Swift_Mailer(new Swift_SmtpTransport('smtp.example.org', 25));

$mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(100));

// Transport-level events (start/stop, SMTP command/response) can also be
// listened for by registering on the transport itself:
$transport->registerPlugin($myPlugin);
```

A plugin may implement several listener interfaces at once, so a single class
can react to different events. Nothing else is required — once registered, the
plugin is invoked automatically at the right point in the send lifecycle.

## How plugins relate to events

Each section below lists the interface(s) a plugin implements and the events it
listens for. The full event catalogue, dispatch order, and API for each event
object live in [events.md](events.md). In short:

| Interface | Method(s) | Event |
|-|-|-|
| `Swift_Events_SendListener` | `beforeSendPerformed`, `sendPerformed` | `SendEvent` |
| `Swift_Events_SentMessageListener` | `sentMessage` | `SentMessageEvent` |
| `Swift_Events_FailedMessageListener` | `failedMessage` | `FailedMessageEvent` |
| `Swift_Events_CommandListener` | `commandSent` | `CommandEvent` |
| `Swift_Events_ResponseListener` | `responseReceived` | `ResponseEvent` |
| `Swift_Events_TransportChangeListener` | `beforeTransportStarted`, `transportStarted`, `beforeTransportStopped`, `transportStopped` | `TransportChangeEvent` |
| `Swift_Events_TransportExceptionListener` | `exceptionThrown` | `TransportExceptionEvent` |

Plugins that reject a send do so from `beforeSendPerformed` via
`$evt->reject($reason)` (see [Pre-Send Rejection](events.md)). Rejected sends
never reach the transport, and `sendPerformed` still fires with a rejected
result.

## Plugin catalogue

| Plugin | Purpose | Listens for |
|-|-|-|
| [AntiFloodPlugin](#antifloodplugin) | Restart the transport every N messages | `SendEvent` |
| [ThrottlerPlugin](#throttlerplugin) | Rate-limit sending (bytes/msgs per minute/second) | `SendEvent`, `CommandEvent`, `ResponseEvent` |
| [BandwidthMonitorPlugin](#bandwidthmonitorplugin) | Count bytes sent/received | `SendEvent`, `CommandEvent`, `ResponseEvent` |
| [LoggerPlugin](#loggerplugin) | Log the full transport transcript (credentials redacted) | Command / Response / TransportChange / TransportException / Send / SentMessage / FailedMessage |
| [MessageLogger](#messagelogger) | Keep clones of sent messages | `SendEvent` |
| [SentMessagePlugin](#sentmessageplugin) | Capture `Swift_SentMessage` results | `SentMessageEvent` |
| [RedirectingPlugin](#redirectingplugin) | Redirect all mail to one recipient (dev/staging) | `SendEvent` |
| [AllowlistPlugin](#allowlistplugin) | Restrict delivery to an allowlist (dev/staging) | `SendEvent` |
| [ImpersonatePlugin](#impersonateplugin) | Override the envelope sender (return-path) | `SendEvent` |
| [DecoratorPlugin](#decoratorplugin) | Per-recipient placeholder replacement | `SendEvent` |
| [CssInlinerPlugin](#cssinlinerplugin) | Inline `<style>` CSS into HTML bodies | `SendEvent` |
| [ReadReceiptPlugin](#readreceiptplugin) | Read-receipt tracking (MDN header and/or pixel) | `SendEvent` |
| [ReporterPlugin](#reporterplugin) | Per-recipient pass/fail reporting | `SendEvent` |
| [PopBeforeSmtpPlugin](#popbeforesmtpplugin) | POP3 auth before SMTP connect | `TransportChangeEvent` |

---

## Rate limiting and flood control

### AntiFloodPlugin

Disconnects and immediately reconnects the transport after a threshold number
of messages, staying within per-connection limits some SMTP servers impose
(commonly 100 messages per connection).

**Class:** `Swift_Plugins_AntiFloodPlugin`
**Implements:** `Swift_Events_SendListener`, `Swift_Plugins_Sleeper`
**Listens for:** [`SendEvent`](events.md) (`sendPerformed`)

```php
// Restart the transport after every 100 messages
$mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(100));

// Restart every 100 messages, pausing 30 seconds each time so the server can
// drain its queue
$mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(100, 30));

foreach ($recipients as $recipient) {
    $mailer->send($message);
}
```

**Constructor:** `__construct(int $threshold = 99, int $sleep = 0, ?Swift_Plugins_Sleeper $sleeper = null)`

Also exposes `setThreshold()` / `getThreshold()` and `setSleepTime()` /
`getSleepTime()`.

**Gotchas:**
- The default threshold is **99**, not 100 — pass an explicit value if a
  specific server limit matters.
- The counter is incremented in `sendPerformed`, so the reconnect happens
  *after* the Nth message is sent.

### ThrottlerPlugin

Rate-limits sending, calling `sleep()` as needed so the average send rate stays
under a target. Extends `BandwidthMonitorPlugin`, so it also tracks byte counts.

**Class:** `Swift_Plugins_ThrottlerPlugin` (extends `Swift_Plugins_BandwidthMonitorPlugin`)
**Implements:** `Swift_Plugins_Sleeper`, `Swift_Plugins_Timer` (and, via the parent, `Swift_Events_SendListener`, `Swift_Events_CommandListener`, `Swift_Events_ResponseListener`)
**Listens for:** [`SendEvent`](events.md) (throttles in `beforeSendPerformed`), plus `CommandEvent` / `ResponseEvent` for byte accounting

| Constant | Value | Meaning |
|-|-|-|
| `BYTES_PER_MINUTE` | `0x01` | Throttle by total bytes transferred per minute |
| `MESSAGES_PER_MINUTE` | `0x10` | Throttle by messages per minute |
| `MESSAGES_PER_SECOND` | `0x11` | Throttle by messages per second (useful for Amazon SES) |

```php
// 100 messages per minute
$mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin(
    100, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE
));

// 10 MB per minute
$mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin(
    1024 * 1024 * 10, Swift_Plugins_ThrottlerPlugin::BYTES_PER_MINUTE
));

// 14 messages per second (Amazon SES)
$mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin(
    14, Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_SECOND
));
```

**Constructor:** `__construct(int $rate, int $mode = self::BYTES_PER_MINUTE, ?Swift_Plugins_Sleeper $sleeper = null, ?Swift_Plugins_Timer $timer = null)`

**Gotchas:**
- The default mode is `BYTES_PER_MINUTE`; always pass the mode you mean.
- The `$sleeper` / `$timer` arguments exist mainly so tests can inject fakes;
  production code leaves them null (real `sleep()` / `time()` are used).

### BandwidthMonitorPlugin

Counts the total bytes written to and read from the transport. Used internally
by `ThrottlerPlugin`, but usable standalone for monitoring.

**Class:** `Swift_Plugins_BandwidthMonitorPlugin`
**Implements:** `Swift_Events_SendListener`, `Swift_Events_CommandListener`, `Swift_Events_ResponseListener`, `Swift_InputByteStream`
**Listens for:** [`SendEvent`](events.md), [`CommandEvent`](events.md), [`ResponseEvent`](events.md)

```php
$monitor = new Swift_Plugins_BandwidthMonitorPlugin();
$mailer->registerPlugin($monitor);

$mailer->send($message);

echo $monitor->getBytesOut(); // total bytes sent
echo $monitor->getBytesIn();  // total bytes received

$monitor->reset(); // zero both counters
```

**Constructor:** no arguments.

---

## Logging and auditing

### LoggerPlugin

Logs SMTP commands, server responses, transport start/stop, sent and failed
messages, and rejections. When a `TransportException` is thrown it is
re-thrown after logging, so the full transcript is available for debugging.

**Class:** `Swift_Plugins_LoggerPlugin`
**Implements:** `Swift_Events_CommandListener`, `Swift_Events_ResponseListener`, `Swift_Events_TransportChangeListener`, `Swift_Events_TransportExceptionListener`, `Swift_Events_SendListener`, `Swift_Events_SentMessageListener`, `Swift_Events_FailedMessageListener`, `Swift_Plugins_Logger`
**Listens for:** essentially every event — see the interface list above

Two loggers ship with the library:

- `Swift_Plugins_Loggers_ArrayLogger` — stores entries in an array; read with
  `dump()`, empty with `clear()`. Bounded to a **ring buffer of 50 entries by
  default** (`__construct(int $size = 50)`); the oldest entries drop off once
  the cap is reached.
- `Swift_Plugins_Loggers_EchoLogger` — prints entries as they happen.
  `__construct(bool $isHtml = true)`; in HTML mode each line is appended with
  `<br />`. **Every entry is passed through `htmlspecialchars()`** before
  output, so logged server data cannot inject markup into an HTML page.

```php
// Capture the transcript for later inspection
$logger = new Swift_Plugins_Loggers_ArrayLogger();
$mailer->registerPlugin(new Swift_Plugins_LoggerPlugin($logger));

$mailer->send($message);
echo $logger->dump();

// Or print in real time (pass false for plain-text output, e.g. on the CLI)
$mailer->registerPlugin(
    new Swift_Plugins_LoggerPlugin(new Swift_Plugins_Loggers_EchoLogger(false))
);
```

**Constructor:** `__construct(Swift_Plugins_Logger $logger)`

**Gotchas:**
- **Credentials are redacted.** The `AUTH` command is logged as
  `>> AUTH [REDACTED]`, and every line of the base64 credential exchange that
  follows is logged as `>> [REDACTED]` until the next recognised SMTP verb
  (`EHLO`, `HELO`, `MAIL`, `RCPT`, `DATA`, `QUIT`, `RSET`, `NOOP`, `STARTTLS`).
  Usernames and passwords never reach the log.
- `exceptionThrown` logs the error, cancels the event bubble, and re-throws a
  `Swift_TransportException`. The plugin does not swallow the failure — it
  enriches and rethrows it.
- Implementing `Swift_Plugins_Logger` itself, the plugin proxies `add()`,
  `clear()`, and `dump()` straight through to the underlying logger.

### MessageLogger

Stores a `clone` of every message handed to the transport — handy for tests or
auditing exactly what was sent.

**Class:** `Swift_Plugins_MessageLogger`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (`beforeSendPerformed`)

```php
$messageLogger = new Swift_Plugins_MessageLogger();
$mailer->registerPlugin($messageLogger);

$mailer->send($message);

$messages = $messageLogger->getMessages();  // Swift_Mime_SimpleMessage[]
$count    = $messageLogger->countMessages();
$messageLogger->clear();                    // drop all stored messages
```

**Constructor:** `__construct(int $maxMessages = 100)`

**Gotcha:** the store is a **ring buffer capped at 100 messages by default**;
once full, the oldest clone is discarded. Raise the constructor argument for
long batch runs where you need every message retained.

### SentMessagePlugin

Captures the `Swift_SentMessage` value object produced after each successful
send, so you can read provider message IDs and recipient counts afterwards.
Only HTTP API transports (subclasses of
`Swift_Transport_AbstractHttpApiTransport`) dispatch the underlying
`SentMessageEvent`.

**Class:** `Swift_Plugins_SentMessagePlugin`
**Implements:** `Swift_Events_SentMessageListener`
**Listens for:** [`SentMessageEvent`](events.md)

```php
$sentPlugin = new Swift_Plugins_SentMessagePlugin();
$mailer->registerPlugin($sentPlugin);

$mailer->send($message);

$sent = $sentPlugin->getLastSentMessage();
if (null !== $sent) {
    echo $sent->getMessageId();      // provider message ID
    echo $sent->getRecipientCount(); // accepted recipients
    print_r($sent->getDebug());      // raw transport result
}

$allSent = $sentPlugin->getSentMessages(); // Swift_SentMessage[]
$sentPlugin->reset();                      // clear the collection
```

**Constructor:** `__construct(int $maxMessages = 100)`

**Gotcha:** like `MessageLogger`, the collection is a **ring buffer capped at
100 by default**.

#### Swift_SentMessage API

| Method | Returns | Description |
|-|-|-|
| `getOriginalMessage()` | `Swift_Mime_SimpleMessage` | The message that was sent |
| `getTransport()` | `Swift_Transport` | The transport that sent it |
| `getMessageId()` | `?string` | Provider-assigned message ID |
| `getRecipientCount()` | `int` | Number of accepted recipients |
| `getDebug()` | `array` | Raw result data from the transport |
| `getFailedRecipients()` | `array` | Addresses that failed delivery |

---

## Development and staging safety

### RedirectingPlugin

Redirects **all** outgoing mail to one or more fixed recipients, so a staging
environment never mails real users by accident. A whitelist of regular
expressions lets specific addresses through untouched.

**Class:** `Swift_Plugins_RedirectingPlugin`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (before/after)

```php
// Redirect everything to the dev team
$mailer->registerPlugin(new Swift_Plugins_RedirectingPlugin('dev@example.com'));

// Redirect everything except @mycompany.com addresses, which still deliver
$mailer->registerPlugin(new Swift_Plugins_RedirectingPlugin(
    'dev@example.com',
    ['/.*@mycompany\.com$/']
));

// Multiple redirect recipients are also accepted
$mailer->registerPlugin(new Swift_Plugins_RedirectingPlugin(
    ['dev1@example.com', 'dev2@example.com']
));
```

**Constructor:** `__construct(string|array $recipient, array $whitelist = [])`

Also exposes `setRecipient()` / `getRecipient()` and `setWhitelist()` /
`getWhitelist()`.

**How it works:** on `beforeSendPerformed` the original To/Cc/Bcc are saved,
every recipient field is filtered down to whitelisted addresses, and the
redirect recipient(s) are added to To. The originals are restored on
`sendPerformed`.

**Gotcha — no recipient leakage:** the original recipients are held in
**private plugin properties, not in `X-Swift-To` / `X-Swift-Cc` / `X-Swift-Bcc`
headers**. Earlier versions stashed them in those headers, which travelled with
the redirected message and leaked the real recipient list (including Bcc). They
no longer touch the outgoing headers — only whitelisted addresses and the
redirect target appear on the wire.

### AllowlistPlugin

Restricts delivery to a configured allowlist of recipients — the inverse of
RedirectingPlugin's "send it all to one place". Non-matching recipients are
either removed or redirected to a catch-all.

**Class:** `Swift_Plugins_AllowlistPlugin`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (before/after)

```php
// Filter mode: keep only allowlisted recipients
$plugin = new Swift_Plugins_AllowlistPlugin([
    '*@mycompany.com',   // domain wildcard
    'tester@gmail.com',  // exact address
]);
$mailer->registerPlugin($plugin);

// Redirect mode: non-allowed recipients go to a catch-all instead of being dropped
$plugin = new Swift_Plugins_AllowlistPlugin(
    ['*@mycompany.com'],
    'catch-all@mycompany.com'
);
$mailer->registerPlugin($plugin);
```

**Constructor:** `__construct(array $allowedPatterns, ?string $redirectTo = null)`

**Filter mode (no `$redirectTo`):** each To/Cc/Bcc address is checked against
the allowlist and non-matches are removed. If **no** recipients survive, the
send is rejected with a descriptive reason naming every original address:

```
AllowlistPlugin: all recipients removed by allowlist filter (a@x.com, b@y.com)
```

That reason is available via `$evt->getRejectionReason()` (and is logged by
[LoggerPlugin](#loggerplugin)).

**Redirect mode (with `$redirectTo`):** allowed To recipients pass through; if
any To recipient was filtered out, the catch-all is appended to To. Cc and Bcc
are always cleared.

**Gotchas:**
- Patterns are case-insensitive. Wildcards use `*@domain` syntax; anything else
  is matched as an exact address.
- Original recipients are restored on the message after sending — the message
  object is not permanently modified.
- Redirect mode does **not** add an `X-Original-To` header (older docs claimed
  it did); it only clears Cc/Bcc and appends the catch-all to To.

### ImpersonatePlugin

Overrides the message return-path (the envelope sender / bounce address) with a
fixed address, restoring the original afterwards.

**Class:** `Swift_Plugins_ImpersonatePlugin`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (before/after)

```php
$mailer->registerPlugin(new Swift_Plugins_ImpersonatePlugin('bounces@example.com'));
```

**Constructor:** `__construct(string $sender)`

**Gotcha:** the original return-path is stashed in a temporary
`X-Swift-Return-Path` header for the duration of the send and removed again on
`sendPerformed`, so the delivered message is unchanged apart from the envelope
sender.

---

## Message transformation

### DecoratorPlugin

Personalises a single message per recipient by substituting placeholder tokens
in the subject, body, headers, and text child parts. Register it **once** with
the full recipient→replacement map, then add every recipient to the message —
not once per recipient in a loop.

**Class:** `Swift_Plugins_DecoratorPlugin`
**Implements:** `Swift_Events_SendListener`, `Swift_Plugins_Decorator_Replacements`
**Listens for:** [`SendEvent`](events.md) (before/after)

```php
$replacements = [
    'alice@example.com' => ['{name}' => 'Alice', '{code}' => '1234'],
    'bob@example.com'   => ['{name}' => 'Bob',   '{code}' => '5678'],
];

$mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin($replacements));

$message = (new Swift_Message('Hello {name}'))
    ->setFrom('app@example.com')
    ->setBody('Your code is {code}.');
$message->addTo('alice@example.com');
$message->addTo('bob@example.com');

$mailer->send($message); // Alice and Bob each get their own values
```

**Constructor:** `__construct(array|Swift_Plugins_Decorator_Replacements $replacements)`

**On-the-fly lookups:** instead of an array, pass any implementation of
`Swift_Plugins_Decorator_Replacements`. Its single method
`getReplacementsFor(string $address): ?array` is called once per recipient and
returns the replacement map (or `null` for none) — ideal for database-backed
lookups:

```php
class DbReplacements implements Swift_Plugins_Decorator_Replacements
{
    public function getReplacementsFor($address)
    {
        // return ['{name}' => ..., '{code}' => ...] or null
    }
}

$mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin(new DbReplacements()));
```

**Gotchas:**
- Replacements are keyed by the message's **first To address**, so the plugin
  is designed for one recipient per send in a batch loop.
- Only `text/*` child parts are decorated; binary attachments are left alone.
- The plugin restores the original subject/body/headers after each send, so the
  same `Swift_Message` object can be reused across recipients.
- If your lookup is case-sensitive, normalise `$address` (e.g. `strtolower()`)
  inside `getReplacementsFor()`.

### CssInlinerPlugin

Inlines `<style>` CSS into HTML bodies before sending — many email clients
strip `<style>` blocks, so inline `style="…"` attributes are far more reliable.

**Class:** `Swift_Plugins_CssInlinerPlugin`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (`beforeSendPerformed`)
**Requires:** `tijsverkoyen/css-to-inline-styles` (suggested optional dependency)

```bash
composer require tijsverkoyen/css-to-inline-styles
```

```php
$mailer->registerPlugin(new Swift_Plugins_CssInlinerPlugin());

$message = (new Swift_Message('Styled Email'))
    ->setFrom('sender@example.com')
    ->setTo('recipient@example.com')
    ->setBody('<html><head><style>h1 { color: blue; }</style></head>'
        .'<body><h1>Hello</h1></body></html>', 'text/html');

// Sent as: <h1 style="color: blue;">Hello</h1>
$mailer->send($message);
```

**Constructor:** no arguments.

**Gotchas:**
- Inlines the main body (when its content type is `text/html`) and any
  `Swift_MimePart` child whose content type is `text/html`.
- If `tijsverkoyen/css-to-inline-styles` is **not** installed the plugin is a
  no-op — it silently passes the message through unchanged. Confirm the
  dependency is present in environments where inlining is required.

### ReadReceiptPlugin

Adds read-receipt tracking to outgoing messages, via a Message Disposition
Notification (MDN) header, an injected tracking pixel, or both. The mode is
toggleable and all changes are reverted after the send, so the source message
is left untouched.

**Class:** `Swift_Plugins_ReadReceiptPlugin`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (before/after)

| Constant | Value | Effect |
|-|-|-|
| `MODE_MDN` | `1` | Request an MDN via a `Disposition-Notification-To` header |
| `MODE_PIXEL` | `2` | Inject a 1×1 tracking pixel into the HTML body |
| `MODE_BOTH` | `3` | Both of the above |

```php
// MDN only — receipts go to the From address by default
$mailer->registerPlugin(new Swift_Plugins_ReadReceiptPlugin());

// MDN to an explicit address
$mailer->registerPlugin(new Swift_Plugins_ReadReceiptPlugin(
    Swift_Plugins_ReadReceiptPlugin::MODE_MDN,
    'receipts@example.com'
));

// Tracking pixel (and MDN) — supply a generator that returns the pixel URL
$mailer->registerPlugin(new Swift_Plugins_ReadReceiptPlugin(
    Swift_Plugins_ReadReceiptPlugin::MODE_BOTH,
    'receipts@example.com',
    function (Swift_Mime_SimpleMessage $message): ?string {
        return 'https://track.example.com/open/'.rawurlencode($message->getId());
    }
));
```

**Constructor:** `__construct(int $mode = self::MODE_MDN, ?string $address = null, ?callable $pixelUrlGenerator = null)`

Runtime setters: `setMode()` / `getMode()`, `setAddress()` / `getAddress()`,
`setPixelUrlGenerator()` / `getPixelUrlGenerator()`.

**Gotchas:**
- `setMode()` (and the constructor) throw `Swift_SwiftException` for any value
  outside 1–3.
- **MDN mode** sets the receipt address to `$address`, falling back to the
  first `From` address; if neither exists, no header is added. Read receipts
  are advisory — recipients' clients may ignore or decline them.
- **Pixel mode** does nothing unless a `$pixelUrlGenerator` is supplied and
  returns a non-empty URL. The pixel is injected just before `</body>` (or
  appended if there is no `</body>`), into the HTML body and any `text/html`
  child parts. The URL is escaped with `htmlspecialchars(…, ENT_QUOTES)`.
- All modifications (body, child bodies, and the MDN header) are restored on
  `sendPerformed`.

---

## Delivery reporting

### ReporterPlugin

Reports a pass/fail result for every recipient to a `Swift_Plugins_Reporter`
backend after each send, based on the transport's failed-recipient list.

**Class:** `Swift_Plugins_ReporterPlugin`
**Implements:** `Swift_Events_SendListener`
**Listens for:** [`SendEvent`](events.md) (`sendPerformed`)

Two reporters ship with the library:

- `Swift_Plugins_Reporters_HitReporter` — collects the addresses that failed
  (deduplicated). Read with `getFailedRecipients()`, empty with `clear()`.
- `Swift_Plugins_Reporters_HtmlReporter` — echoes a green/red HTML block per
  recipient in real time.

```php
$reporter = new Swift_Plugins_Reporters_HitReporter();
$mailer->registerPlugin(new Swift_Plugins_ReporterPlugin($reporter));

$mailer->send($message);

$failures = $reporter->getFailedRecipients(); // addresses that failed
```

**Constructor:** `__construct(Swift_Plugins_Reporter $reporter)`

**Reporter interface** — implement `Swift_Plugins_Reporter` for a custom
backend:

| Member | Description |
|-|-|
| `RESULT_PASS` (`0x01`) | Recipient accepted for delivery |
| `RESULT_FAIL` (`0x10`) | Recipient could not be accepted |
| `notify(Swift_Mime_SimpleMessage $message, string $address, int $result)` | Called once per To/Cc/Bcc recipient |

**Gotcha — `HtmlReporter` and untrusted input:** the HTML reporter echoes each
recipient address **without escaping** it into the HTML output. It is a
debugging/CLI-style aid; do not render its output in a context where recipient
addresses could be attacker-controlled, or wrap/escape the output yourself.
(`EchoLogger`, by contrast, does escape its entries — see
[LoggerPlugin](#loggerplugin).)

---

## Connection

### PopBeforeSmtpPlugin

Performs a POP3 login before the SMTP transport starts, satisfying legacy
"POP-before-SMTP" servers that authorise an IP for SMTP only after a successful
POP3 authentication.

**Class:** `Swift_Plugins_PopBeforeSmtpPlugin`
**Implements:** `Swift_Events_TransportChangeListener`, `Swift_Plugins_Pop_Pop3Connection`
**Listens for:** [`TransportChangeEvent`](events.md) (`beforeTransportStarted`)

```php
$pop = new Swift_Plugins_PopBeforeSmtpPlugin('pop.example.com', 110);
$pop->setUsername('user');
$pop->setPassword('pass');

$mailer->registerPlugin($pop);
```

**Constructor:** `__construct(string $host, int $port = 110, ?string $crypto = null)`

The optional `$crypto` selects an encryption mode (e.g. `'tls'` / `'ssl'`).
Fluent setters: `setUsername()`, `setPassword()`, `setTimeout()` (default 10s),
`setConnection()` (inject a `Swift_Plugins_Pop_Pop3Connection` for testing), and
`bindSmtp()`.

**Gotchas:**
- Just before the transport starts, the plugin connects to POP3, authenticates,
  and immediately disconnects — the POP3 session exists only to authorise the
  subsequent SMTP connection.
- Call `bindSmtp($transport)` to scope the plugin to a single transport;
  otherwise it fires on any transport that starts.
- The `PASS` command is redacted (`PASS [REDACTED]`) in any exception message,
  so the POP3 password does not leak into logs. Connection failures raise
  `Swift_Plugins_Pop_Pop3Exception`.
- `$host` accepts a hostname or IP; wrap literal IPv6 addresses in square
  brackets.

---

## Writing your own plugin

Because every plugin is just an event listener, a custom plugin only needs to
implement the relevant interface and be registered. For example, a listener
that rejects mail outside business hours:

```php
class BusinessHoursPlugin implements Swift_Events_SendListener
{
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        $hour = (int) date('G');
        if ($hour < 9 || $hour >= 17) {
            $evt->reject('Outside business hours (09:00-17:00)');
        }
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
    }
}

$mailer->registerPlugin(new BusinessHoursPlugin());
```

See [events.md](events.md) for every event, its dispatch order, the full API of
each event object, and the rules for rejecting a send.
