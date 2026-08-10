# Sending Messages

## Quick Reference

Sending a message is straightforward: create a Transport, use it to create the
Mailer, then use the Mailer to send the message.

`send()` returns an integer -- the number of recipients accepted for delivery.
If none of the recipients could be delivered to, zero is returned, which
equates to a boolean `false`. If you set two `To:` recipients and three `Bcc:`
recipients and all five are accepted, the value `5` is returned.

```php
// Create the Transport
$transport = (new Swift_SmtpTransport('smtp.example.org', 25))
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

// Send the message
$result = $mailer->send($message);
```

## Transport Types

Transports are the classes responsible for communicating with a service to
deliver a message. Every transport implements the `Swift_Transport` interface
(`isStarted`, `start`, `stop`, `ping`, `send`, `registerPlugin`).

| Transport | Description |
|-|-|
| `Swift_SmtpTransport` | Sends over SMTP/ESMTP. Supports authentication and encryption. Portable, predictable, and provides good delivery feedback. |
| `Swift_SendmailTransport` | Communicates with a locally installed `sendmail`-compatible MTA. Fast to return, but usually provides weaker feedback than SMTP. |
| `Swift_Transport_Api_*` | 21 HTTP API transports for 20 providers (SendGrid, Mailgun, Postmark, Brevo, Amazon SES, Gmail API, Microsoft Graph, Resend, Mailtrap, and more). See [api-transports.md](api-transports.md). |
| `Swift_Transport_RetryTransport` | Wraps any transport with automatic retry and exponential backoff. |
| `Swift_FailoverTransport` | Wraps several transports for high availability -- tries each in order until one succeeds. |
| `Swift_LoadBalancedTransport` | Rotates across several transports to spread load. |
| `Swift_SpoolTransport` | Queues messages in a `Swift_Spool` for deferred delivery. |
| `Swift_NullTransport` | Pretends to send but discards the message (useful for testing). |

You can also build any transport from a DSN connection string with
`Swift_Transport_DsnTransportFactory`. See [dsn.md](dsn.md) for syntax.

## The SMTP Transport

`Swift_SmtpTransport` (backed by `Swift_Transport_EsmtpTransport`) sends over
the Simple Mail Transfer Protocol with ESMTP extensions. It is the most
commonly used transport because it works on almost any host that can reach a
remote (or local) SMTP server.

A connection to the SMTP server is established on the first call to `send()`.
Most options can be set through the constructor or with fluent setters:

```php
// Host + port via the constructor
$transport = new Swift_SmtpTransport('smtp.example.org', 25);

// Or fluently
$transport = (new Swift_SmtpTransport())
    ->setHost('smtp.example.org')
    ->setPort(25)
;
```

The constructor signature is:

```php
public function __construct(
    $host = 'localhost',
    $port = 25,
    $encryption = CONNECTION_ENCRYPTION_MODE_NONE
)
```

### Encryption

The library defines exactly three encryption-mode constants (in
`lib/swift_required.php`). Pass one as the third constructor argument or to
`setEncryption()`:

| Constant | String value | Meaning |
|-|-|-|
| `CONNECTION_ENCRYPTION_MODE_NONE` | `null` | Plain SMTP, no encryption (default) |
| `CONNECTION_ENCRYPTION_MODE_STARTTLS` | `'tls'` | SMTP with STARTTLS -- best-effort encryption negotiated after connecting |
| `CONNECTION_ENCRYPTION_MODE_TLS` | `'ssl'` | SMTPS -- SMTP over TLS, always encrypted from the first byte |

```php
// SMTPS = SMTP over TLS (always encrypted), typically port 465
$transport = new Swift_SmtpTransport('smtp.example.org', 465, CONNECTION_ENCRYPTION_MODE_TLS);

// SMTP with STARTTLS (best-effort encryption), typically port 587
$transport = new Swift_SmtpTransport('smtp.example.org', 587, CONNECTION_ENCRYPTION_MODE_STARTTLS);
```

When STARTTLS is selected, the transport issues `EHLO`, then `STARTTLS`, then
re-issues `EHLO` over the upgraded connection before sending mail.

> **Note.** For encryption to work, your PHP build must have the matching
> OpenSSL stream wrappers. Check with `stream_get_transports()` -- look for
> `tls` and/or `ssl`. When in doubt, prefer `CONNECTION_ENCRYPTION_MODE_TLS`
> (SMTPS) since the channel is encrypted from the start. Ports 587 (STARTTLS)
> and 465 (SMTPS) are the usual choices; confirm with your mail provider.

> **Do not use `CONNECTION_MODE_STARTTLS` / `CONNECTION_MODE_TLS`.** These names
> appear in some older docblocks but are **not defined** anywhere in the
> library -- referencing them raises an "undefined constant" error. Use the
> `CONNECTION_ENCRYPTION_MODE_*` constants above (or their string values
> `'tls'` / `'ssl'`).

### Authentication

Many servers require a username and password before relaying mail:

```php
$transport = (new Swift_SmtpTransport('smtp.example.org', 587, CONNECTION_ENCRYPTION_MODE_STARTTLS))
    ->setUsername('username')
    ->setPassword('password')
;
```

Credentials are used to authenticate on first connect (when `send()` is first
called). If authentication fails, a `Swift_TransportException` is thrown. To
fail fast -- before you build a message -- call `$transport->start()`
explicitly.

**Auth mechanisms and ordering.** The AUTH handler ships five authenticators:
`XOAUTH2`, `PLAIN`, `LOGIN`, `CRAM-MD5`, and `NTLM`. By default the handler
tries the mechanisms the server advertises in EHLO, in this preference order:

1. `XOAUTH2`
2. `PLAIN`
3. `LOGIN`
4. `CRAM-MD5`
5. `NTLM`

Force a single mechanism with `setAuthMode()`:

```php
$transport->setAuthMode('XOAUTH2'); // or PLAIN, LOGIN, CRAM-MD5, NTLM
```

An invalid mode throws `Swift_TransportException` (`Auth mode <x> is invalid`).

> **CRAM-MD5 is deprecated.** The CRAM-MD5 authenticator emits an
> `E_USER_DEPRECATED` warning because it relies on the cryptographically weak
> MD5 algorithm. Prefer `XOAUTH2`, or `PLAIN`/`LOGIN` over a TLS connection.
> `XOAUTH2` uses the username as the account/email and the password as the
> OAuth2 access token.

### Other SMTP options

| Method | Default | Description |
|-|-|-|
| `setTimeout($seconds)` | `30` | Connection/read timeout in seconds |
| `setSourceIp($ip)` | none | Local IP to bind the outgoing connection to (wrap IPv6 in `[]`) |
| `setLocalDomain($domain)` | `[127.0.0.1]` | Name used in the `EHLO`/`HELO` command; IP addresses are auto-wrapped in brackets per RFC 5321 |
| `setStreamOptions($options)` | `[]` | Stream context options passed to `stream_context_create()` |
| `setPipelining($bool)` | auto-detected | Override SMTP `PIPELINING`; by default it is enabled when the server advertises the extension |

TLS peer verification and certificate pinning are configured through the SSL
stream context:

```php
$transport->setStreamOptions([
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_fingerprint' => 'sha256-hex-fingerprint',
    ],
]);
```

When constructing from a DSN, the same settings are available as the
`verify_peer`, `peer_fingerprint`, and `source_ip` query parameters -- see
[dsn.md](dsn.md).

### SMTPUTF8 and internationalized addresses

The ESMTP transport defaults to `Swift_AddressEncoder_AutoAddressEncoder`.
After `EHLO`, it inspects the server's advertised capabilities and switches
automatically:

- **Server advertises `SMTPUTF8`** -- addresses are passed through verbatim in
  UTF-8 (RFC 6531/6532), allowing non-ASCII characters in both the local-part
  and domain.
- **Server does not advertise `SMTPUTF8`** -- the domain is IDN-encoded via
  `idn_to_ascii()`, and a non-ASCII local-part raises
  `Swift_AddressEncoderException`.

Override the encoder with `setAddressEncoder()` if you need a fixed behavior
(`Swift_AddressEncoder_IdnAddressEncoder` or
`Swift_AddressEncoder_Utf8AddressEncoder`). When using a DSN, set
`smtputf8=false` to force IDN encoding.

## The Sendmail Transport

`Swift_SendmailTransport` sends by piping the message to a locally installed
MTA (`sendmail`, or a compatible wrapper such as Exim or Postfix). It does not
connect to any remote service. Note that a fast return does **not** mean fast
delivery -- `sendmail` spools to disk and delivers over SMTP afterwards.

```php
$transport = new Swift_SendmailTransport('/usr/sbin/sendmail -bs');
$mailer = new Swift_Mailer($transport);
```

The command defaults to `/usr/sbin/sendmail -bs`. Two operational modes are
supported:

| Mode | Behavior |
|-|-|
| `-bs` (default) | Runs an interactive SMTP session, so per-recipient failures can be reported. A local SMTP session is started on the first `send()`. |
| `-t` | Reads recipients from the message headers and pipes the message with no feedback -- `send()` always reports 100% success. Include `-i` or `-oi` to stop `.`-only lines from terminating input early. |

In `-t` mode the transport appends a `-f<sender>` flag (shell-escaped) if one
is not already present. Any other command flags raise a
`Swift_TransportException` (`Unsupported sendmail command flags ...`).

> **Command hardening.** `setCommand()` rejects any command containing shell
> metacharacters (`;`, `&`, `|`, `` ` ``, `$`, `(`, `)`, `{`, `}`) by throwing
> an `InvalidArgumentException`. This is a denylist that blocks command
> injection through the sendmail command string -- pass the binary path and its
> flags only.

> **Note.** Prefer `-bs` unless you have a specific reason not to; `-t` gives
> no failure feedback.

## The Null Transport

`Swift_NullTransport` discards every message but still dispatches the send
events and returns the recipient count, so it behaves like a working transport
in tests and dev environments.

```php
$mailer = new Swift_Mailer(new Swift_NullTransport());
```

## Meta-Transports

### Failover

`Swift_FailoverTransport` provides high availability. It tries each wrapped
transport in order and returns as soon as one accepts the message. Dead
transports are set aside for the remainder of the run.

```php
$transport = new Swift_FailoverTransport([
    $primaryTransport,
    $backupTransport,
]);
```

When a transport throws, the failover is logged via `trigger_error()` at
`E_USER_WARNING` level (`Swiftmailer: Failover from <class>: <message>`), then
the next transport is tried. If every transport fails or none are configured, a
`Swift_TransportException` is thrown.

### Load balancing

`Swift_LoadBalancedTransport` spreads sends across a pool by rotating the
transport list (round-robin), returning on the first success within a send.

```php
$transport = new Swift_LoadBalancedTransport([
    $transportA,
    $transportB,
    $transportC,
]);
```

A transport that throws is retired for the rest of the run; retiring it is
logged via `trigger_error()` at `E_USER_WARNING`
(`Swiftmailer: LoadBalancedTransport error from <class>: <message>`). If all
transports are exhausted, a `Swift_TransportException` is thrown.

### Retry

`Swift_Transport_RetryTransport` decorates any transport and retries transient
failures with exponential backoff and jitter.

```php
$inner = new Swift_SmtpTransport('smtp.example.com', 587, CONNECTION_ENCRYPTION_MODE_STARTTLS);
$retry = new Swift_Transport_RetryTransport($inner, maxRetries: 3, baseDelayMs: 1000);
$mailer = new Swift_Mailer($retry);
```

Constructor: `__construct(Swift_Transport $inner, int $maxRetries = 3, int $baseDelayMs = 1000, ?Swift_Transport_RetryClassifier $classifier = null)`.

- `maxRetries` must be between `0` and `10`; `baseDelayMs` between `0` and
  `30000` -- out-of-range values throw `InvalidArgumentException`.
- **Backoff formula:** `baseDelayMs * 2^attempt + random(0, baseDelayMs / 2)`
  milliseconds, capped at 60000 ms per wait.
- A retry only happens when the exception is classified retryable, `attempt <
  maxRetries`, and less than **300 seconds** total have elapsed; otherwise the
  exception is re-thrown. Between attempts the inner transport is restarted if
  it dropped its connection.

**Classification.** The default `Swift_Transport_DefaultRetryClassifier`
decides retryability in this order:

1. Permanent message patterns (e.g. `authentication failed`, `invalid api
   key`, `mailbox not found`, `relay access denied`) -- never retried.
2. Retryable message patterns (e.g. `connection reset`, `rate limit`,
   `service unavailable`, `internal server error`) -- retried.
3. Permanent codes (SMTP `500`, `501`, `530`, `535`, `550`-`554`; HTTP `401`,
   `403`) -- not retried.
4. Retryable codes (SMTP `421`, `450`, `451`, `452`; HTTP `429`, `502`, `503`,
   `504`) -- retried.
5. Code `0` (connection-level failure with no SMTP/HTTP code) -- retried.
6. Any other `4xx` code -- retried; everything else is treated as permanent.

Implement `Swift_Transport_RetryClassifier` to customize this. You can also
configure retry through a DSN (`retry(...)` wrapper or `retries`/`retry_delay`
query params) -- see [dsn.md](dsn.md).

## Spooling

Spooling queues messages for deferred delivery -- for example, to move mail
sending out of the request/response cycle. `Swift_SpoolTransport` wraps a
`Swift_Spool` implementation; each `send()` enqueues the message (dispatching
`beforeSendPerformed`/`sendPerformed` with a `SPOOLED` result) and returns `1`
(or the envelope recipient count when an envelope is supplied).

```php
$spool = new Swift_FileSpool('/var/spool/swiftmailer');
$mailer = new Swift_Mailer(new Swift_SpoolTransport($spool));
$mailer->send($message); // queued, not delivered yet
```

Deliver the queued messages later by calling `flushQueue()` with a real
transport (for example, from a cron job):

```php
$realTransport = new Swift_SmtpTransport('smtp.example.org', 587, CONNECTION_ENCRYPTION_MODE_STARTTLS);
$failedRecipients = [];
$sent = $spool->flushQueue($realTransport, $failedRecipients);
```

### Spool implementations

| Class | Storage | Notes |
|-|-|-|
| `Swift_MemorySpool` | In-memory array | Clones each queued message. On flush, retries up to 3 times (`setFlushRetries()`), re-queuing a failing message and waiting 0.5 s between rounds. Not persistent across requests. |
| `Swift_FileSpool` | Filesystem | Serializes messages to a spool directory. Supports optional HMAC integrity signing and hardened unserialization (see below). |

`Swift_MemorySpool` and `Swift_FileSpool` both extend
`Swift_ConfigurableSpool`, which adds two flush limits honored by
`flushQueue()`:

```php
$spool->setMessageLimit(10); // stop after 10 messages per flush
$spool->setTimeLimit(30);    // stop after ~30 seconds per flush
```

### FileSpool integrity and safety

`Swift_FileSpool` hardens the on-disk queue against tampering and unsafe
deserialization:

- **HMAC integrity.** Pass a signing key to the constructor (or
  `setSigningKey()`). Queued files are prefixed with an HMAC-SHA256 of the
  serialized payload; on flush the HMAC is recomputed and compared with
  `hash_equals()`. A file whose HMAC does not match is skipped, never sent.

  ```php
  $spool = new Swift_FileSpool('/var/spool/swiftmailer', $signingKey);
  ```

- **Allowlisted unserialization.** Messages are restored with a fixed
  `allowed_classes` allowlist (CWE-502 mitigation). Notably,
  `Swift_ByteStream_*` classes are excluded, closing an arbitrary-file-deletion
  gadget. A payload that does not deserialize to a
  `Swift_Mime_SimpleMessage` is skipped.
- **Crash isolation.** Exceptions from `__wakeup()` or a transport failure are
  caught per message so one bad file cannot abort the whole flush.
- **Recovery.** `recover($timeout = 900)` re-queues messages that have been
  stuck in the `.sending` state longer than the timeout (in seconds).

## Limiting Message Size (opt-in)

`Swift_MessageLimits` is an opt-in validator that guards against
denial-of-service via oversized payloads. It is **not** wired into `send()`
automatically -- call `validate()` yourself before sending. It throws
`Swift_SwiftException` when a limit is exceeded.

```php
$limits = new Swift_MessageLimits();
$limits->maxRecipientCount = 500; // override any default
$limits->validate($message);      // throws on violation
$mailer->send($message);
```

| Property | Default | Limit on |
|-|-|-|
| `maxBodySize` | 10485760 (10 MiB) | Message body length in bytes |
| `maxAttachmentSize` | 26214400 (25 MiB) | Any single attachment/image |
| `maxTotalSize` | 52428800 (50 MiB) | Body + all attachments combined |
| `maxAttachmentCount` | 50 | Number of attachments/images |
| `maxRecipientCount` | 1000 | To + Cc + Bcc combined |

## The `send()` Method

The `Swift_Mailer` class exposes a single method for sending -- `send()`:

```php
public function send(
    Swift_Mime_SimpleMessage $message,
    &$failedRecipients = null,
    ?Swift_Envelope $envelope = null
)
```

- **Return value** -- an `int`, the number of recipients accepted for delivery.
  Zero (falsey) means the message reached nobody.
- **`$failedRecipients`** -- an optional by-reference array populated with
  rejected addresses (see below).
- **`$envelope`** -- an optional `Swift_Envelope` overriding the SMTP envelope
  (see below).

The mailer starts the transport automatically if it has not been started. If a
`Swift_RfcComplianceException` is raised while sending (an invalid address
reaches the transport), every `To:` address is added to `$failedRecipients` and
`send()` returns `0` instead of letting the exception escape.

```php
$transport = new Swift_SmtpTransport('localhost', 25);
$mailer = new Swift_Mailer($transport);

$message = (new Swift_Message('Wonderful Subject'))
    ->setFrom(['john@doe.com' => 'John Doe'])
    ->setTo(['receiver@domain.org', 'other@domain.org' => 'A name'])
    ->setBody('Here is the message itself')
;

$numSent = $mailer->send($message);
printf("Sent %d messages\n", $numSent);
```

### Controlling the SMTP Envelope

By default the SMTP envelope is derived from the message headers -- the sender
from `Return-Path` > `Sender` > `From`, and the recipients from `To` + `Cc` +
`Bcc` merged. Pass a `Swift_Envelope` as the third argument to override this
without touching the message, which is useful for bounce (sender) rewriting or
recipient overriding:

```php
$envelope = new Swift_Envelope('bounce@example.org', [
    'actual-recipient@example.org',
]);
$numSent = $mailer->send($message, $failures, $envelope);
```

`Swift_Envelope` is a readonly value object: the sender must be a non-empty
string and there must be at least one string recipient, otherwise the
constructor throws `InvalidArgumentException`. `Swift_Envelope::fromMessage()`
builds one using the same header-derivation logic the transports use. When an
explicit envelope is supplied, the SMTP transport uses it verbatim -- it does
**not** strip `Bcc` or inspect headers. A `beforeSendPerformed` listener may
replace the envelope via `setEnvelope()`; the transport re-reads it after the
event fires.

### Finding Rejected Addresses

As the transport attempts each recipient, any it rejects is appended to the
by-reference array you pass as the second argument. This is useful for pruning
mailing lists.

```php
$message = (new Swift_Message('Subject'))
    ->setFrom(['john@doe.com' => 'John Doe'])
    ->setTo([
        'receiver@bad-domain.org'       => 'Receiver Name',
        'other@domain.org'              => 'A name',
        'other-receiver@bad-domain.org' => 'Other Name',
    ])
    ->setBody('...')
;

if (!$mailer->send($message, $failures)) {
    echo 'Failures:';
    print_r($failures);
}

/*
Failures:
Array (
    0 => receiver@bad-domain.org,
    1 => other-receiver@bad-domain.org
)
*/
```

If the variable does not exist yet it is initialized to an empty array; if it
already exists it is cast to an array and failures are appended.

## Sending in Batch

To send a separate copy to each recipient -- so only their own address appears
in `To:` -- iterate the recipients and call `send()` once per address:

```php
$transport = new Swift_SmtpTransport('localhost', 25);
$mailer = new Swift_Mailer($transport);

$message = (new Swift_Message('Wonderful Subject'))
    ->setFrom(['john@doe.com' => 'John Doe'])
    ->setBody('Here is the message itself')
;

$failedRecipients = [];
$numSent = 0;
$to = ['receiver@domain.org', 'other@domain.org' => 'A name'];

foreach ($to as $address => $name) {
    if (is_int($address)) {
        $message->setTo($name);
    } else {
        $message->setTo([$address => $name]);
    }

    $numSent += $mailer->send($message, $failedRecipients);
}

printf("Sent %d messages\n", $numSent);
```

Add only valid addresses. `setTo()`, `setCc()`, and `setBcc()` throw
`Swift_RfcComplianceException` on an invalid address -- important when batching,
since a single bad address would otherwise abort the loop. When adding
addresses from an untrusted data source, validate them first with the bundled
`Egulias\EmailValidator\EmailValidator`, or wrap the setter calls in a
try/catch and skip addresses that fail.

## Send Results and Events

On a successful send, HTTP API transports produce a `Swift_SentMessage` value
object (available through the `SentMessageEvent`) exposing the
provider-assigned message ID, accepted-recipient count, raw debug data, and any
failed recipients:

```php
class MyListener implements Swift_Events_SentMessageListener
{
    public function sentMessage(Swift_Events_SentMessageEvent $evt): void
    {
        $sent = $evt->getSentMessage();
        echo $sent->getMessageId();      // provider message ID
        echo $sent->getRecipientCount(); // accepted recipients
    }
}
$mailer->registerPlugin(new MyListener());
```

The outcome of a send is also represented by the `Swift_SendResult` enum
(`PENDING = 0x0001`, `SPOOLED = 0x0011`, `SUCCESS = 0x0010`,
`TENTATIVE = 0x0100`, `FAILED = 0x1000`) -- the values match the legacy
`Swift_Events_SendEvent::RESULT_*` constants, so bitmask checks keep working
via `->value`.

Plugins can also cancel a send in `beforeSendPerformed` (via `reject()`),
inspect SMTP responses, and observe the transport start/stop lifecycle. See
[events.md](events.md) for the full event lifecycle, listener interfaces, and
`FailedMessageEvent` details.

## See Also

- [dsn.md](dsn.md) -- build any of these transports from a connection string.
- [api-transports.md](api-transports.md) -- the 21 HTTP API transports.
- [events.md](events.md) -- events fired during a send and how to hook them.
- [plugins.md](plugins.md) -- cross-cutting plugins (allowlist, throttling,
  logging, CSS inlining).
