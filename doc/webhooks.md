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
| `Swift_Webhook_Event` | Normalized event value object (readonly) |
| `Swift_Webhook_SignatureVerificationException` | Thrown when signature verification fails |

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

**Signature verification:** Basic validation only -- checks for the `X-Amz-Sns-Message-Type` header. For production use, validate SNS signatures using the AWS SDK or a dedicated SNS verification library.

**Note:** SES sends notifications through SNS. The converter automatically skips `SubscriptionConfirmation` and `UnsubscribeConfirmation` message types -- you must handle SNS subscription confirmation separately. The inner SES `Message` JSON is decoded and mapped by `notificationType`.

**Event mapping:**

| SES notificationType | Webhook Event |
|-|-|
| `Bounce` (Permanent) | delivery / bounced |
| `Bounce` (Transient) | delivery / deferred |
| `Delivery` | delivery / delivered |
| `Complaint` | engagement / complained |

SES bounces and deliveries may contain multiple recipients; the converter emits one event per recipient.

```php
$converter = new Swift_Webhook_Converter_AmazonSesConverter();
$events = $handler->handle($converter, $rawBody, $headers, null);
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

```php
$converter = new Swift_Webhook_Converter_MandrillConverter();
$events = $handler->handle($converter, $rawBody, $headers, $webhookKey);
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
- `verifyHmac(string $data, string $signature, string $secret, string $algo)` -- timing-safe HMAC comparison
- `createDeliveryEvent(...)` -- factory for delivery events
- `createEngagementEvent(...)` -- factory for engagement events
- `parseTimestamp(int|string $timestamp)` -- parses Unix timestamps, ISO 8601, and common formats
