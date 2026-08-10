# Webhook System

The webhook system processes inbound HTTP callbacks from email providers to track delivery status and engagement events (opens, clicks, bounces, complaints). It complements the send-side [API transports](api-transports.md): transports deliver mail, webhooks report what happened to it afterwards.

## Architecture

```
HTTP Request  -->  Swift_Webhook_RequestHandler::handle()
                         |
                         |  1. reject empty secret            (InvalidArgumentException)
                         |  2. IP allowlist check (optional)  (SignatureVerificationException)
                         |  3. verify signature -- always     (SignatureVerificationException)
                         |  4. replay / timestamp check       (SignatureVerificationException)
                         |  5. JSON-decode the body           (InvalidArgumentException)
                         v
               PayloadConverterInterface::convert()  (provider-specific)
                         |
                         v
               Swift_Webhook_Event[]  (normalized events)
```

**Key classes:**

| Class | Purpose |
|-|-|
| `Swift_Webhook_RequestHandler` | Orchestrates verification, replay + IP checks, decoding, conversion |
| `Swift_Webhook_PayloadConverterInterface` | Interface for provider converters (`convert`, `verify`, `getProviderName`) |
| `Swift_Webhook_TimestampExtractorInterface` | Optional interface a converter implements to expose a timestamp for replay protection |
| `Swift_Webhook_AbstractPayloadConverter` | Base class with HMAC helpers, event factories, and a default (null) timestamp extractor |
| `Swift_Webhook_Event` | Normalized event value object (readonly) |
| `Swift_Webhook_SignatureVerificationException` | Thrown on signature failure, expired timestamp, or IP not in allowlist |

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
    $signingSecret, // required and non-empty -- see the secret reference below
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

### `RequestHandler::handle()` signature

```php
public function handle(
    Swift_Webhook_PayloadConverterInterface $converter,
    string $rawBody,
    array $headers,
    #[SensitiveParameter] string $secret,
    int $maxAge = 300,
    ?array $allowedIps = null,
    ?string $remoteIp = null,
): array
```

| Parameter | Description |
|-|-|
| `$converter` | Provider-specific converter (see [Supported Providers](#supported-providers)) |
| `$rawBody` | Raw HTTP request body, exactly as received (needed for HMAC/signature checks) |
| `$headers` | Request headers; keys are normalized to lowercase internally |
| `$secret` | Signing secret -- **required and non-empty**. Its meaning is provider-specific (HMAC key, shared token, PEM public key, SNS Topic ARN, ...); see the [Secret reference](#secret-reference). An empty string throws `InvalidArgumentException` |
| `$maxAge` | Maximum accepted webhook age in seconds for replay protection (default `300`; pass `0` to disable) |
| `$allowedIps` | Optional list of source IPs permitted to call the endpoint; `null` disables the check |
| `$remoteIp` | The caller's IP (e.g. from your framework/request). The allowlist check only runs when **both** `$allowedIps` and `$remoteIp` are non-null |

Verification is **mandatory** -- there is no "skip verification" mode. The handler
throws (see [Errors](#errors)) whenever a check fails, and only returns events
once the signature, replay window, and optional IP allowlist all pass.

### IP allowlist (optional)

`handle()` can reject callers whose source IP is not in an allowlist -- a
defense-in-depth layer on top of signature verification, added in the security
series. It runs **before** signature verification, and only when both
`$allowedIps` and `$remoteIp` are supplied:

```php
$events = $handler->handle(
    new Swift_Webhook_Converter_MailgunConverter(),
    $rawBody,
    $headers,
    $signingKey,
    maxAge: 300,
    allowedIps: ['3.19.44.0', '52.35.106.123'], // provider's documented egress IPs
    remoteIp: $_SERVER['REMOTE_ADDR'] ?? null,
);
```

If `$remoteIp` is not found in `$allowedIps` (strict comparison), a
`Swift_Webhook_SignatureVerificationException` is thrown before any signature
work happens. Omit either argument (or leave both `null`) to skip the check.

### Replay protection

When `$maxAge > 0` and the converter implements
`Swift_Webhook_TimestampExtractorInterface`, the handler extracts the webhook
timestamp and rejects it when `abs(time() - timestamp) > $maxAge`, throwing
`Swift_Webhook_SignatureVerificationException`. `Swift_Webhook_AbstractPayloadConverter`
provides a default extractor that returns `null` (check skipped), which each
converter overrides when the provider supplies a timestamp.

Converters that currently extract a timestamp: **SendGrid, Mailgun, Amazon SES,
Resend, Sweego, AhaSend, Mailomat**. The rest (Postmark, Brevo, Mailjet,
Mandrill, Mailtrap, MailerSend, MailPace) rely on signature verification alone
and are not subject to the replay window.

### Errors

`handle()` throws -- it never returns partial results:

| Exception | Cause |
|-|-|
| `InvalidArgumentException` | `$secret` is an empty string, or the body is not valid JSON |
| `Swift_Webhook_SignatureVerificationException` | Signature invalid, timestamp outside the replay window, or `$remoteIp` not in `$allowedIps` |

`Swift_Webhook_SignatureVerificationException` extends `RuntimeException`; its
message has the form `Webhook signature verification failed for provider "<name>".`

## Supported Providers

14 providers have built-in webhook converters:

| Provider | Converter Class |
|-|-|
| SendGrid | `Swift_Webhook_Converter_SendgridConverter` |
| Mailgun | `Swift_Webhook_Converter_MailgunConverter` |
| Postmark | `Swift_Webhook_Converter_PostmarkConverter` |
| Amazon SES | `Swift_Webhook_Converter_AmazonSesConverter` |
| Brevo | `Swift_Webhook_Converter_BrevoConverter` |
| Resend | `Swift_Webhook_Converter_ResendConverter` |
| MailerSend | `Swift_Webhook_Converter_MailerSendConverter` |
| Mailjet | `Swift_Webhook_Converter_MailjetConverter` |
| Mandrill | `Swift_Webhook_Converter_MandrillConverter` |
| AhaSend | `Swift_Webhook_Converter_AhaSendConverter` |
| Mailomat | `Swift_Webhook_Converter_MailomatConverter` |
| Mailtrap | `Swift_Webhook_Converter_MailtrapConverter` |
| Sweego | `Swift_Webhook_Converter_SweegoConverter` |
| MailPace | `Swift_Webhook_Converter_MailPaceConverter` |

## Secret reference

The `$secret` argument means something different per provider. Pass the value
described here (never `null` or an empty string):

| Provider | What `$secret` must be | Verification method |
|-|-|-|
| SendGrid | PEM-encoded ECDSA public verification key | ECDSA (`openssl_verify`, SHA-256) over `timestamp + body` |
| Mailgun | HTTP webhook signing key | HMAC-SHA256 of `timestamp + token` (from the body) |
| Postmark | Shared webhook token | Timing-safe compare with `X-Postmark-Webhook-Token` |
| Amazon SES | Expected SNS **Topic ARN** | Full SNS signature validation (fetches cert, RSA verify) |
| Brevo | Webhook token | Timing-safe compare with `X-Brevo-Webhook-Token` |
| Resend | Svix signing secret (`whsec_...`) | HMAC-SHA256 over `svix-id.svix-timestamp.body` |
| MailerSend | HMAC signing secret | HMAC-SHA256 of the raw body (hex `Signature` header) |
| Mailjet | Basic-Auth password portion | Timing-safe compare against the `Authorization: Basic` password |
| Mandrill | `"webhook_key\|webhook_url"` (pipe-delimited) | HMAC-SHA1 of URL + sorted POST vars, base64 |
| AhaSend | Base64 Standard-Webhooks secret | HMAC-SHA256 over `webhook-id.webhook-timestamp.body` |
| Mailomat | Webhook secret | HMAC-SHA256 of `id.event.timestamp` (from headers) |
| Mailtrap | HMAC signing secret | HMAC-SHA256 of the raw body (hex `Mailtrap-Signature` header) |
| Sweego | Base64 webhook secret | HMAC-SHA256 over `webhook-id.webhook-timestamp.body` |
| MailPace | Base64 Ed25519 public key | Ed25519 (`sodium_crypto_sign_verify_detached`) |

## Provider-Specific Setup

### SendGrid

**Converter:** `Swift_Webhook_Converter_SendgridConverter`

**Signature verification:** Uses ECDSA with SendGrid's public verification key. Headers: `X-Twilio-Email-Event-Webhook-Signature` and `X-Twilio-Email-Event-Webhook-Timestamp`.

**Secret:** Your SendGrid Event Webhook verification key as a **PEM-encoded public key** -- the value is passed straight to `openssl_pkey_get_public()`. SendGrid shows the key as base64 DER (begins with `MFkw...`); wrap it in `-----BEGIN PUBLIC KEY-----` / `-----END PUBLIC KEY-----` armor before passing it.

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

**Signature verification:** HMAC-SHA256 of `timestamp + token` (from the `signature` field in the JSON payload) compared against the `signature` value. The signing data comes from the payload body, not the HTTP headers.

**Secret:** Your Mailgun webhook signing key (found in Mailgun dashboard under Webhooks).

**Event mapping:**

| Mailgun Event | Webhook Event |
|-|-|
| `delivered` | delivery / delivered |
| `failed` (permanent) | delivery / bounced |
| `failed` (temporary) | delivery / deferred |
| `opened` | engagement / opened |
| `clicked` | engagement / clicked |
| `unsubscribed` | engagement / unsubscribed |
| `complained` | engagement / complained |

```php
$converter = new Swift_Webhook_Converter_MailgunConverter();
$events = $handler->handle($converter, $rawBody, $headers, $signingKey);
```

### Postmark

**Converter:** `Swift_Webhook_Converter_PostmarkConverter`

**Signature verification:** Compares the `X-Postmark-Webhook-Token` header against your configured secret using timing-safe comparison. This is a shared-secret token, not HMAC.

**Secret:** The webhook token you configured in Postmark's webhook settings.

**Event mapping:**

| Postmark RecordType | Webhook Event |
|-|-|
| `Bounce` | delivery / bounced |
| `Delivery` | delivery / delivered |
| `Open` | engagement / opened |
| `Click` | engagement / clicked |
| `SpamComplaint` | engagement / complained |
| `SubscriptionChange` | engagement / unsubscribed |

```php
$converter = new Swift_Webhook_Converter_PostmarkConverter();
$events = $handler->handle($converter, $rawBody, $headers, $webhookToken);
```

### Amazon SES

**Converter:** `Swift_Webhook_Converter_AmazonSesConverter`

**Signature verification:** Full SNS signature validation. The converter (1) requires the `X-Amz-Sns-Message-Type` header, (2) checks the payload `TopicArn` against `$secret` with `hash_equals`, (3) requires the `SigningCertURL` to be HTTPS on `sns.<region>.amazonaws.com`, (4) fetches the signing certificate and RSA-verifies the SNS canonical string-to-sign, supporting `SignatureVersion` `"1"` (SHA-1) and `"2"` (SHA-256).

**Secret:** The **expected SNS Topic ARN** (e.g. `arn:aws:sns:us-east-1:123456789012:ses-events`), compared against the notification's `TopicArn`. Do **not** pass `null` -- an empty secret throws `InvalidArgumentException`.

**Note:** SES sends notifications through SNS. The converter automatically skips `SubscriptionConfirmation` and `UnsubscribeConfirmation` message types (returns no events) -- you must confirm the SNS subscription separately. The inner SES `Message` JSON is decoded and mapped by `notificationType`. For replay protection it extracts the SNS `Timestamp` field.

**Event mapping:**

| SES notificationType | Webhook Event |
|-|-|
| `Bounce` (Permanent) | delivery / bounced |
| `Bounce` (Transient) | delivery / deferred |
| `Delivery` | delivery / delivered |
| `Complaint` | engagement / complained |

SES bounces, deliveries, and complaints may contain multiple recipients; the converter emits one event per recipient.

```php
$converter = new Swift_Webhook_Converter_AmazonSesConverter();
$events = $handler->handle($converter, $rawBody, $headers, $expectedTopicArn);
```

### Brevo

**Converter:** `Swift_Webhook_Converter_BrevoConverter`

```php
$converter = new Swift_Webhook_Converter_BrevoConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Resend

**Converter:** `Swift_Webhook_Converter_ResendConverter`

```php
$converter = new Swift_Webhook_Converter_ResendConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### MailerSend

**Converter:** `Swift_Webhook_Converter_MailerSendConverter`

```php
$converter = new Swift_Webhook_Converter_MailerSendConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Mailjet

**Converter:** `Swift_Webhook_Converter_MailjetConverter`

```php
$converter = new Swift_Webhook_Converter_MailjetConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Mandrill

**Converter:** `Swift_Webhook_Converter_MandrillConverter`

**Signature verification:** HMAC-SHA1 of the webhook URL concatenated with the sorted form-POST keys/values, base64-encoded, compared against `X-Mandrill-Signature`. Mandrill POSTs form-encoded data (a `mandrill_events` JSON array).

**Secret:** Must be the **pipe-delimited** string `"<webhook_key>|<webhook_url>"` -- the exact URL you registered with Mandrill is part of the signed data, so it has to be supplied here.

```php
$converter = new Swift_Webhook_Converter_MandrillConverter();
$secret    = $webhookKey.'|'.'https://example.com/webhooks/mandrill';
$events    = $handler->handle($converter, $rawBody, $headers, $secret);
```

### AhaSend

**Converter:** `Swift_Webhook_Converter_AhaSendConverter`

```php
$converter = new Swift_Webhook_Converter_AhaSendConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Mailomat

**Converter:** `Swift_Webhook_Converter_MailomatConverter`

```php
$converter = new Swift_Webhook_Converter_MailomatConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Mailtrap

**Converter:** `Swift_Webhook_Converter_MailtrapConverter`

```php
$converter = new Swift_Webhook_Converter_MailtrapConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### Sweego

**Converter:** `Swift_Webhook_Converter_SweegoConverter`

```php
$converter = new Swift_Webhook_Converter_SweegoConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
```

### MailPace

**Converter:** `Swift_Webhook_Converter_MailPaceConverter`

```php
$converter = new Swift_Webhook_Converter_MailPaceConverter();
$events = $handler->handle($converter, $rawBody, $headers, $secret);
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

A complete endpoint -- signature verification, replay window, optional IP
allowlist, and both failure modes handled:

```php
<?php
require 'vendor/autoload.php';

$handler = new Swift_Webhook_RequestHandler();

try {
    $events = $handler->handle(
        new Swift_Webhook_Converter_SendgridConverter(),
        file_get_contents('php://input'),
        getallheaders(), // handler lowercases keys itself
        $_ENV['SENDGRID_WEBHOOK_SECRET'], // PEM public key
        maxAge: 300,                       // reject webhooks older than 5 minutes
        allowedIps: null,                  // e.g. ['1.2.3.4'] to enable the allowlist
        remoteIp: $_SERVER['REMOTE_ADDR'] ?? null,
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
    // Bad signature, stale timestamp, or IP not in the allowlist
    http_response_code(401);
    echo 'Invalid signature';
} catch (InvalidArgumentException $e) {
    // Empty secret or malformed JSON body
    http_response_code(400);
    echo 'Bad request';
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
- `verifyHmac(string $data, string $signature, string $secret, string $algo)` -- timing-safe HMAC comparison
- `createDeliveryEvent(...)` -- factory for delivery events (takes a `DateTimeImmutable` timestamp)
- `createEngagementEvent(...)` -- factory for engagement events (takes a `DateTimeImmutable` timestamp)
- `parseTimestamp(int|string $timestamp)` -- parses Unix timestamps, ISO 8601, and common formats into a `DateTimeImmutable`
- `extractTimestamp(string $rawBody, array $headers): ?int` -- override to expose a timestamp for [replay protection](#replay-protection); returns `null` by default (check skipped). `Swift_Webhook_AbstractPayloadConverter` already implements `Swift_Webhook_TimestampExtractorInterface`, so you only override this method
