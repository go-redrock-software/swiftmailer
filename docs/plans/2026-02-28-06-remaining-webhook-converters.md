# Remaining Webhook Provider Converters — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add webhook payload converters for the 10 remaining email providers (Brevo, Resend, MailerSend, Mailjet, Mandrill, AhaSend, Mailomat, Mailtrap, Sweego, MailPace) so inbound webhooks from every supported transport can be converted into typed `Swift_Webhook_Event` objects.

**Architecture:** Each provider gets a converter class extending `Swift_Webhook_AbstractPayloadConverter` that implements `getProviderName()`, `verify()`, and `convert()`. Converters map provider-specific event names to canonical names (bounced, delivered, deferred, dropped, opened, clicked, unsubscribed, complained) and produce `Swift_Webhook_Event` objects via the inherited `createDeliveryEvent()` / `createEngagementEvent()` factories. Signature verification uses each provider's documented scheme (HMAC-SHA256, HMAC-SHA1, token comparison, Ed25519, or ECDSA).

**Tech Stack:** PHP 8.1+, existing Swift_Webhook infrastructure. No new dependencies.

---

## Task 1: Brevo Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/BrevoConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/BrevoConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_BrevoConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_BrevoConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_BrevoConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('brevo', $this->converter->getProviderName());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            'event'      => 'hardBounce',
            'email'      => 'user@example.com',
            'message-id' => '<msg-300@example.com>',
            'ts_epoch'   => 1706000000000,
            'reason'     => '550 User unknown',
            'tag'        => 'campaign-1',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('<msg-300@example.com>', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('550 User unknown', $events[0]->getMetadata()['reason']);
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'event'      => 'softBounce',
            'email'      => 'user@example.com',
            'message-id' => '<msg-301@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event'      => 'delivered',
            'email'      => 'user@example.com',
            'message-id' => '<msg-302@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'event'      => 'opened',
            'email'      => 'user@example.com',
            'message-id' => '<msg-303@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'event'      => 'click',
            'email'      => 'user@example.com',
            'message-id' => '<msg-304@example.com>',
            'ts_epoch'   => 1706000000000,
            'link'       => 'https://example.com/page',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'event'      => 'spam',
            'email'      => 'user@example.com',
            'message-id' => '<msg-305@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubscribedEvent()
    {
        $payload = [
            'event'      => 'unsubscribed',
            'email'      => 'user@example.com',
            'message-id' => '<msg-306@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            'event'      => 'deferred',
            'email'      => 'user@example.com',
            'message-id' => '<msg-307@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertBlockedAsDropped()
    {
        $payload = [
            'event'      => 'blocked',
            'email'      => 'user@example.com',
            'message-id' => '<msg-308@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'event'      => 'request',
            'email'      => 'user@example.com',
            'message-id' => '<msg-309@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyWithTokenHeader()
    {
        // Brevo uses a simple token comparison via a custom header set when creating the webhook
        $secret  = 'my-brevo-webhook-token';
        $headers = ['x-brevo-webhook-token' => $secret];

        $this->assertTrue($this->converter->verify('{}', $headers, $secret));
        $this->assertFalse($this->converter->verify('{}', $headers, 'wrong'));
        $this->assertFalse($this->converter->verify('{}', [], $secret));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/BrevoConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Brevo transactional webhook payloads into Swift_Webhook_Event objects.
 *
 * Brevo sends flat JSON objects with an 'event' field.
 * Signature verification uses a token header set when creating the webhook.
 *
 * @see https://developers.brevo.com/docs/transactional-webhooks
 */
class Swift_Webhook_Converter_BrevoConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'    => ['delivery', 'delivered'],
        'hardBounce'   => ['delivery', 'bounced'],
        'softBounce'   => ['delivery', 'deferred'],
        'deferred'     => ['delivery', 'deferred'],
        'blocked'      => ['delivery', 'dropped'],
        'invalid'      => ['delivery', 'dropped'],
        'error'        => ['delivery', 'dropped'],
        'opened'       => ['engagement', 'opened'],
        'uniqueOpened' => ['engagement', 'opened'],
        'click'        => ['engagement', 'clicked'],
        'spam'         => ['engagement', 'complained'],
        'unsubscribed' => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'brevo';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $token = $headers['x-brevo-webhook-token'] ?? null;

        if (null === $token) {
            return false;
        }

        return \hash_equals($secret, $token);
    }

    public function convert(array $payload, array $headers): array
    {
        $eventName = $payload['event'] ?? null;

        if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];
        $messageId     = $payload['message-id'] ?? '';
        $recipient     = $payload['email'] ?? '';
        $timestamp     = $this->parseTimestamp((int) (($payload['ts_epoch'] ?? \time() * 1000) / 1000));
        $metadata      = $this->extractMetadata($payload);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $payload): array
    {
        $metadata = [];

        if (isset($payload['reason'])) {
            $metadata['reason'] = $payload['reason'];
        }
        if (isset($payload['link'])) {
            $metadata['url'] = $payload['link'];
        }
        if (isset($payload['tag'])) {
            $metadata['tag'] = $payload['tag'];
        }
        if (isset($payload['tags'])) {
            $metadata['tags'] = (array) $payload['tags'];
        }
        if (isset($payload['sending_ip'])) {
            $metadata['sending_ip'] = $payload['sending_ip'];
        }
        if (isset($payload['subject'])) {
            $metadata['subject'] = $payload['subject'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/BrevoConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/BrevoConverter.php tests/unit/Swift/Webhook/Converter/BrevoConverterTest.php
git commit -m "feat: add Brevo webhook payload converter"
```

---

## Task 2: Resend Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/ResendConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/ResendConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_ResendConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_ResendConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_ResendConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('resend', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-400',
                'from'     => 'sender@example.com',
                'to'       => ['user@example.com'],
                'subject'  => 'Hello',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-400', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertBouncedEvent()
    {
        $payload = [
            'type'       => 'email.bounced',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-401',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertDeliveryDelayedEvent()
    {
        $payload = [
            'type'       => 'email.delivery_delayed',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-402',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'type'       => 'email.opened',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-403',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'type'       => 'email.clicked',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-404',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testConvertComplainedEvent()
    {
        $payload = [
            'type'       => 'email.complained',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-405',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'type'       => 'email.sent',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-406',
                'to'       => ['user@example.com'],
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSvixSignature()
    {
        // Resend uses Svix: HMAC-SHA256 of "{svix-id}.{svix-timestamp}.{body}" with base64-decoded secret
        $secretRaw = \random_bytes(32);
        $secret    = 'whsec_'.\base64_encode($secretRaw);
        $svixId    = 'msg_test123';
        $timestamp = '1706000000';
        $body      = '{"type":"email.delivered"}';

        $signedContent = $svixId.'.'.$timestamp.'.'.$body;
        $signature     = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'svix-id'        => $svixId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,'.$signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $secret  = 'whsec_'.\base64_encode(\random_bytes(32));
        $headers = [
            'svix-id'        => 'msg_test',
            'svix-timestamp' => '1706000000',
            'svix-signature' => 'v1,invalidsignature',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'whsec_dGVzdA=='));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/ResendConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Resend webhook payloads into Swift_Webhook_Event objects.
 *
 * Resend sends JSON with a 'type' field (e.g. "email.delivered").
 * Signature verification uses Svix: HMAC-SHA256 of "{svix-id}.{svix-timestamp}.{body}"
 * with the base64-decoded portion of the whsec_ prefixed secret.
 *
 * @see https://resend.com/docs/dashboard/webhooks/event-types
 */
class Swift_Webhook_Converter_ResendConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'email.delivered'        => ['delivery', 'delivered'],
        'email.bounced'          => ['delivery', 'bounced'],
        'email.delivery_delayed' => ['delivery', 'deferred'],
        'email.opened'           => ['engagement', 'opened'],
        'email.clicked'          => ['engagement', 'clicked'],
        'email.complained'       => ['engagement', 'complained'],
    ];

    public function getProviderName(): string
    {
        return 'resend';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $svixId    = $headers['svix-id']        ?? null;
        $timestamp = $headers['svix-timestamp'] ?? null;
        $signature = $headers['svix-signature'] ?? null;

        if (null === $svixId || null === $timestamp || null === $signature) {
            return false;
        }

        // Strip the "whsec_" prefix and base64-decode the secret
        $secretKey = \base64_decode(\substr($secret, 6), true);
        if (false === $secretKey) {
            return false;
        }

        $signedContent   = $svixId.'.'.$timestamp.'.'.$rawBody;
        $expectedSig     = \base64_encode(\hash_hmac('sha256', $signedContent, $secretKey, true));

        // Svix signature header may contain multiple signatures separated by spaces, each prefixed with "v1,"
        foreach (\explode(' ', $signature) as $candidate) {
            $parts = \explode(',', $candidate, 2);
            if (2 === \count($parts) && 'v1' === $parts[0] && \hash_equals($expectedSig, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $data          = $payload['data'] ?? [];
        $messageId     = $data['email_id'] ?? '';
        $recipients    = (array) ($data['to'] ?? []);
        $recipient     = $recipients[0] ?? '';
        $timestamp     = $this->parseTimestamp($payload['created_at'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $data): array
    {
        $metadata = [];

        if (isset($data['subject'])) {
            $metadata['subject'] = $data['subject'];
        }
        if (isset($data['from'])) {
            $metadata['from'] = $data['from'];
        }
        if (isset($data['tags'])) {
            $metadata['tags'] = (array) $data['tags'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/ResendConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/ResendConverter.php tests/unit/Swift/Webhook/Converter/ResendConverterTest.php
git commit -m "feat: add Resend webhook payload converter"
```

---

## Task 3: MailerSend Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MailerSendConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/MailerSendConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MailerSendConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailerSendConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailerSendConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailersend', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'type'       => 'activity.delivered',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-500',
                'email'      => 'user@example.com',
                'subject'    => 'Hello',
                'tags'       => ['welcome'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-500', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertHardBouncedEvent()
    {
        $payload = [
            'type'       => 'activity.hard_bounced',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-501',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertSoftBouncedEvent()
    {
        $payload = [
            'type'       => 'activity.soft_bounced',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-502',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            'type'       => 'activity.deferred',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-503',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'type'       => 'activity.opened',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-504',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'type'       => 'activity.clicked',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-505',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testConvertSpamComplaintEvent()
    {
        $payload = [
            'type'       => 'activity.spam_complaint',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-506',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubscribedEvent()
    {
        $payload = [
            'type'       => 'activity.unsubscribed',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-507',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'type'       => 'activity.sent',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => ['message_id' => 'msg-508', 'email' => 'user@example.com'],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        $secret  = 'test-signing-secret';
        $body    = '{"type":"activity.delivered"}';
        $sig     = \hash_hmac('sha256', $body, $secret);
        $headers = ['signature' => $sig];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = ['signature' => 'invalid'];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'secret'));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailerSendConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts MailerSend webhook payloads into Swift_Webhook_Event objects.
 *
 * MailerSend sends JSON with a 'type' field (e.g. "activity.delivered").
 * Signature verification uses HMAC-SHA256 of the raw body with the signing secret.
 * The signature is sent in the 'Signature' header as a hex string.
 *
 * @see https://developers.mailersend.com/api/v1/webhooks.html
 */
class Swift_Webhook_Converter_MailerSendConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'activity.delivered'      => ['delivery', 'delivered'],
        'activity.hard_bounced'   => ['delivery', 'bounced'],
        'activity.soft_bounced'   => ['delivery', 'deferred'],
        'activity.deferred'       => ['delivery', 'deferred'],
        'activity.opened'         => ['engagement', 'opened'],
        'activity.opened_unique'  => ['engagement', 'opened'],
        'activity.clicked'        => ['engagement', 'clicked'],
        'activity.clicked_unique' => ['engagement', 'clicked'],
        'activity.unsubscribed'   => ['engagement', 'unsubscribed'],
        'activity.spam_complaint' => ['engagement', 'complained'],
    ];

    public function getProviderName(): string
    {
        return 'mailersend';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        return $this->verifyHmac($rawBody, $signature, $secret, 'sha256');
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $data          = $payload['data'] ?? [];
        $messageId     = $data['message_id'] ?? '';
        $recipient     = $data['email'] ?? '';
        $timestamp     = $this->parseTimestamp($payload['created_at'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $data): array
    {
        $metadata = [];

        if (isset($data['subject'])) {
            $metadata['subject'] = $data['subject'];
        }
        if (isset($data['tags'])) {
            $metadata['tags'] = (array) $data['tags'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailerSendConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/MailerSendConverter.php tests/unit/Swift/Webhook/Converter/MailerSendConverterTest.php
git commit -m "feat: add MailerSend webhook payload converter"
```

---

## Task 4: Mailjet Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MailjetConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/MailjetConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MailjetConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailjetConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailjetConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailjet', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'event'         => 'bounce',
            'time'          => 1706000000,
            'email'         => 'user@example.com',
            'MessageID'     => 12345678901234,
            'Message_GUID'  => 'msg-600',
            'hard_bounce'   => true,
            'comment'       => '550 User unknown',
            'error_related_to' => 'recipient',
            'error'         => 'user unknown',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-600', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('550 User unknown', $events[0]->getMetadata()['reason']);
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'event'       => 'bounce',
            'time'        => 1706000000,
            'email'       => 'user@example.com',
            'Message_GUID' => 'msg-601',
            'hard_bounce' => false,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertSentEvent()
    {
        $payload = [
            'event'        => 'sent',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-602',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'event'        => 'open',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-603',
            'ip'           => '1.2.3.4',
            'agent'        => 'Mozilla/5.0',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['ip']);
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'event'        => 'click',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-604',
            'url'          => 'https://example.com/page',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'event'        => 'spam',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-605',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubEvent()
    {
        $payload = [
            'event'        => 'unsub',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-606',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertBlockedEvent()
    {
        $payload = [
            'event'        => 'blocked',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-607',
            'error'        => 'preblocked',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'event'        => 'unknown_event',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-608',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyWithBasicAuth()
    {
        // Mailjet uses basic HTTP auth on the webhook URL, not a signature header.
        // The converter always returns true since verification happens at the HTTP layer.
        $this->assertTrue($this->converter->verify('{}', [], 'any-secret'));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailjetConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailjet Event API webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailjet sends flat JSON objects with an 'event' field.
 * Mailjet does not use a signature header — security is handled via basic HTTP auth
 * on the webhook URL. The verify() method always returns true.
 *
 * @see https://dev.mailjet.com/email/guides/webhooks/
 */
class Swift_Webhook_Converter_MailjetConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'sent'    => ['delivery', 'delivered'],
        'blocked' => ['delivery', 'dropped'],
        'open'    => ['engagement', 'opened'],
        'click'   => ['engagement', 'clicked'],
        'spam'    => ['engagement', 'complained'],
        'unsub'   => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'mailjet';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        // Mailjet relies on basic HTTP authentication on the webhook URL.
        // Signature verification is not provided via headers.
        return true;
    }

    public function convert(array $payload, array $headers): array
    {
        $eventName = $payload['event'] ?? null;

        if (null === $eventName) {
            return [];
        }

        $messageId = (string) ($payload['Message_GUID'] ?? $payload['MessageID'] ?? '');
        $recipient = $payload['email'] ?? '';
        $timestamp = $this->parseTimestamp($payload['time'] ?? \time());
        $metadata  = $this->extractMetadata($payload);

        // Bounce has special handling: hard_bounce determines bounced vs deferred
        if ('bounce' === $eventName) {
            $isHard = $payload['hard_bounce'] ?? true;
            $name   = $isHard ? 'bounced' : 'deferred';

            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        if (!isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $payload): array
    {
        $metadata = [];

        if (isset($payload['comment'])) {
            $metadata['reason'] = $payload['comment'];
        }
        if (isset($payload['url'])) {
            $metadata['url'] = $payload['url'];
        }
        if (isset($payload['ip'])) {
            $metadata['ip'] = $payload['ip'];
        }
        if (isset($payload['agent'])) {
            $metadata['user_agent'] = $payload['agent'];
        }
        if (isset($payload['geo'])) {
            $metadata['geo'] = $payload['geo'];
        }
        if (isset($payload['error'])) {
            $metadata['error'] = $payload['error'];
        }
        if (isset($payload['error_related_to'])) {
            $metadata['error_related_to'] = $payload['error_related_to'];
        }
        if (isset($payload['CustomID'])) {
            $metadata['custom_id'] = $payload['CustomID'];
        }
        if (isset($payload['Payload'])) {
            $metadata['payload'] = $payload['Payload'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailjetConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/MailjetConverter.php tests/unit/Swift/Webhook/Converter/MailjetConverterTest.php
git commit -m "feat: add Mailjet webhook payload converter"
```

---

## Task 5: Mandrill (MailChimp Transactional) Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MandrillConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/MandrillConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MandrillConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MandrillConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MandrillConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mandrill', $this->converter->getProviderName());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            [
                'event' => 'hard_bounce',
                'ts'    => 1706000000,
                '_id'   => 'msg-700',
                'msg'   => [
                    'email'       => 'user@example.com',
                    'sender'      => 'sender@example.com',
                    'subject'     => 'Test',
                    'bounce_description' => '550 User unknown',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-700', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            [
                'event' => 'soft_bounce',
                'ts'    => 1706000000,
                '_id'   => 'msg-701',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            [
                'event' => 'delivered',
                'ts'    => 1706000000,
                '_id'   => 'msg-702',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertDeferralEvent()
    {
        $payload = [
            [
                'event' => 'deferral',
                'ts'    => 1706000000,
                '_id'   => 'msg-703',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            [
                'event' => 'open',
                'ts'    => 1706000000,
                '_id'   => 'msg-704',
                'msg'   => ['email' => 'user@example.com'],
                'ip'    => '1.2.3.4',
                'user_agent' => 'Mozilla/5.0',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            [
                'event' => 'click',
                'ts'    => 1706000000,
                '_id'   => 'msg-705',
                'msg'   => ['email' => 'user@example.com'],
                'url'   => 'https://example.com/page',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            [
                'event' => 'spam',
                'ts'    => 1706000000,
                '_id'   => 'msg-706',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubEvent()
    {
        $payload = [
            [
                'event' => 'unsub',
                'ts'    => 1706000000,
                '_id'   => 'msg-707',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertRejectEvent()
    {
        $payload = [
            [
                'event' => 'reject',
                'ts'    => 1706000000,
                '_id'   => 'msg-708',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertMultipleEvents()
    {
        $payload = [
            ['event' => 'delivered', 'ts' => 1706000000, '_id' => 'a', 'msg' => ['email' => 'a@example.com']],
            ['event' => 'open',      'ts' => 1706000001, '_id' => 'b', 'msg' => ['email' => 'b@example.com']],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            ['event' => 'send', 'ts' => 1706000000, '_id' => 'msg-709', 'msg' => ['email' => 'user@example.com']],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        // Mandrill uses HMAC-SHA1 of (url + sorted POST keys/values), base64-encoded.
        // The raw body for Mandrill is form-encoded: mandrill_events=[...]
        $webhookKey = 'test-webhook-key';
        $webhookUrl = 'https://example.com/webhook';
        $eventsJson = '[{"event":"delivered"}]';

        // Mandrill signs: url + "mandrill_events" + eventsJson
        $signedData = $webhookUrl.'mandrill_events'.$eventsJson;
        $signature  = \base64_encode(\hash_hmac('sha1', $signedData, $webhookKey, true));

        $rawBody = 'mandrill_events='.\urlencode($eventsJson);
        $headers = [
            'x-mandrill-signature' => $signature,
        ];

        // Secret format: "key|url" — the converter splits on pipe
        $secret = $webhookKey.'|'.$webhookUrl;

        $this->assertTrue($this->converter->verify($rawBody, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = ['x-mandrill-signature' => 'invalid'];
        $this->assertFalse($this->converter->verify('mandrill_events=[]', $headers, 'key|https://example.com'));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('mandrill_events=[]', [], 'key|https://example.com'));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MandrillConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mandrill (Mailchimp Transactional) webhook payloads into Swift_Webhook_Event objects.
 *
 * Mandrill POSTs form-encoded data with a 'mandrill_events' parameter containing
 * a JSON array of event objects. Each has an 'event' field and nested 'msg' object.
 *
 * Signature verification uses HMAC-SHA1 of (URL + sorted POST variable keys/values),
 * base64-encoded, compared to the X-Mandrill-Signature header.
 *
 * The $secret parameter must be formatted as "webhook_key|webhook_url".
 *
 * @see https://mailchimp.com/developer/transactional/docs/webhooks/
 */
class Swift_Webhook_Converter_MandrillConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'hard_bounce' => ['delivery', 'bounced'],
        'soft_bounce' => ['delivery', 'deferred'],
        'delivered'   => ['delivery', 'delivered'],
        'deferral'    => ['delivery', 'deferred'],
        'reject'      => ['delivery', 'dropped'],
        'open'        => ['engagement', 'opened'],
        'click'       => ['engagement', 'clicked'],
        'spam'        => ['engagement', 'complained'],
        'unsub'       => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'mandrill';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['x-mandrill-signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        // Secret is "webhook_key|webhook_url"
        $parts = \explode('|', $secret, 2);
        if (2 !== \count($parts)) {
            return false;
        }

        [$webhookKey, $webhookUrl] = $parts;

        // Parse the form-encoded body to get POST variables
        \parse_str($rawBody, $postVars);

        // Build signed data: URL + sorted keys and values
        $signedData = $webhookUrl;
        \ksort($postVars);
        foreach ($postVars as $key => $value) {
            $signedData .= $key.$value;
        }

        $expected = \base64_encode(\hash_hmac('sha1', $signedData, $webhookKey, true));

        return \hash_equals($expected, $signature);
    }

    public function convert(array $payload, array $headers): array
    {
        $events = [];

        foreach ($payload as $entry) {
            $eventName = $entry['event'] ?? null;

            if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
                continue;
            }

            [$type, $name] = self::EVENT_MAP[$eventName];
            $msg           = $entry['msg'] ?? [];
            $messageId     = (string) ($entry['_id'] ?? '');
            $recipient     = $msg['email'] ?? '';
            $timestamp     = $this->parseTimestamp($entry['ts'] ?? \time());
            $metadata      = $this->extractMetadata($entry, $msg);

            if ('delivery' === $type) {
                $events[] = $this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            } else {
                $events[] = $this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            }
        }

        return $events;
    }

    private function extractMetadata(array $entry, array $msg): array
    {
        $metadata = [];

        if (isset($msg['bounce_description'])) {
            $metadata['reason'] = $msg['bounce_description'];
        }
        if (isset($msg['diag'])) {
            $metadata['diagnostic'] = $msg['diag'];
        }
        if (isset($entry['url'])) {
            $metadata['url'] = $entry['url'];
        }
        if (isset($entry['ip'])) {
            $metadata['ip'] = $entry['ip'];
        }
        if (isset($entry['user_agent'])) {
            $metadata['user_agent'] = $entry['user_agent'];
        }
        if (isset($msg['subject'])) {
            $metadata['subject'] = $msg['subject'];
        }
        if (isset($msg['sender'])) {
            $metadata['sender'] = $msg['sender'];
        }
        if (isset($msg['tags'])) {
            $metadata['tags'] = (array) $msg['tags'];
        }
        if (isset($msg['metadata'])) {
            $metadata['custom_metadata'] = $msg['metadata'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MandrillConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/MandrillConverter.php tests/unit/Swift/Webhook/Converter/MandrillConverterTest.php
git commit -m "feat: add Mandrill webhook payload converter"
```

---

## Task 6: AhaSend Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/AhaSendConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/AhaSendConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_AhaSendConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_AhaSendConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_AhaSendConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('ahasend', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'type'      => 'message.delivered',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-800',
                'recipient'         => 'user@example.com',
                'from'              => 'sender@example.com',
                'subject'           => 'Hello',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-800', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            'type'      => 'message.hard_bounced',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-801',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'type'      => 'message.soft_bounced',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-802',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'type'      => 'message.opened',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-803',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'type'      => 'message.clicked',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-804',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testConvertComplainedEvent()
    {
        $payload = [
            'type'      => 'message.complained',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-805',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'type'      => 'suppression.created',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => ['recipient' => 'user@example.com'],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidStandardWebhookSignature()
    {
        // AhaSend follows Standard Webhooks: HMAC-SHA256 of "{id}.{timestamp}.{body}" with base64-decoded secret
        $secretRaw = \random_bytes(32);
        $secret    = \base64_encode($secretRaw);
        $id        = 'wh_test123';
        $timestamp = '1706000000';
        $body      = '{"type":"message.delivered"}';

        $signedContent = $id.'.'.$timestamp.'.'.$body;
        $signature     = 'v1,'.\base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'webhook-id'        => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => $signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = [
            'webhook-id'        => 'wh_test',
            'webhook-timestamp' => '1706000000',
            'webhook-signature' => 'v1,invalidsig',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, \base64_encode('secret')));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], \base64_encode('secret')));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/AhaSendConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts AhaSend webhook payloads into Swift_Webhook_Event objects.
 *
 * AhaSend follows the Standard Webhooks specification. Payloads have a 'type' field
 * (e.g. "message.delivered") and nested 'data' object.
 *
 * Signature verification uses Standard Webhooks: HMAC-SHA256 of
 * "{webhook-id}.{webhook-timestamp}.{body}" with the base64-decoded secret.
 *
 * @see https://ahasend.com/docs/integrations/webhooks
 */
class Swift_Webhook_Converter_AhaSendConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'message.delivered'    => ['delivery', 'delivered'],
        'message.hard_bounced' => ['delivery', 'bounced'],
        'message.soft_bounced' => ['delivery', 'deferred'],
        'message.opened'       => ['engagement', 'opened'],
        'message.clicked'      => ['engagement', 'clicked'],
        'message.complained'   => ['engagement', 'complained'],
        'message.unsubscribed' => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'ahasend';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $webhookId  = $headers['webhook-id']        ?? null;
        $timestamp  = $headers['webhook-timestamp'] ?? null;
        $signature  = $headers['webhook-signature'] ?? null;

        if (null === $webhookId || null === $timestamp || null === $signature) {
            return false;
        }

        $secretKey = \base64_decode($secret, true);
        if (false === $secretKey) {
            return false;
        }

        $signedContent = $webhookId.'.'.$timestamp.'.'.$rawBody;
        $expectedSig   = \base64_encode(\hash_hmac('sha256', $signedContent, $secretKey, true));

        // Standard Webhooks signature may contain multiple signatures separated by spaces
        foreach (\explode(' ', $signature) as $candidate) {
            $parts = \explode(',', $candidate, 2);
            if (2 === \count($parts) && 'v1' === $parts[0] && \hash_equals($expectedSig, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $data          = $payload['data'] ?? [];
        $messageId     = $data['message_id_header'] ?? $data['id'] ?? '';
        $recipient     = $data['recipient'] ?? '';
        $timestamp     = $this->parseTimestamp($payload['timestamp'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $data): array
    {
        $metadata = [];

        if (isset($data['from'])) {
            $metadata['from'] = $data['from'];
        }
        if (isset($data['subject'])) {
            $metadata['subject'] = $data['subject'];
        }
        if (isset($data['reason'])) {
            $metadata['reason'] = $data['reason'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/AhaSendConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/AhaSendConverter.php tests/unit/Swift/Webhook/Converter/AhaSendConverterTest.php
git commit -m "feat: add AhaSend webhook payload converter"
```

---

## Task 7: Mailomat Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MailomatConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/MailomatConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MailomatConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailomatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailomatConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailomat', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'id'         => '81a9813b-70e8-4d1f-8e8c-4c6885d849f7',
            'eventType'  => 'delivered',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-900@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-900@example.com', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertFailurePermanentEvent()
    {
        $payload = [
            'eventType'  => 'failure_perm',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-901@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertFailureTemporaryEvent()
    {
        $payload = [
            'eventType'  => 'failure_tmp',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-902@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'eventType'  => 'opened',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-903@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'eventType'  => 'clicked',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-904@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testSkipsAcceptedEvent()
    {
        $payload = [
            'eventType'  => 'accepted',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-905@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testSkipsNotAcceptedEvent()
    {
        $payload = [
            'eventType'  => 'not_accepted',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-906@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidHmacSignature()
    {
        // Mailomat signs: implode('.', [X-MOM-Webhook-Id, X-MOM-Webhook-Event, X-MOM-Webhook-Timestamp])
        $secret    = 'test-webhook-secret';
        $id        = '68f7add5-3470-4187-b02a-d7a795ed4345';
        $event     = 'delivered';
        $timestamp = '1712240232';

        $payload   = \implode('.', [$id, $event, $timestamp]);
        $signature = 'sha256='.\hash_hmac('sha256', $payload, $secret);

        $headers = [
            'x-mom-webhook-id'        => $id,
            'x-mom-webhook-event'     => $event,
            'x-mom-webhook-timestamp' => $timestamp,
            'x-mom-webhook-signature' => $signature,
        ];

        $this->assertTrue($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = [
            'x-mom-webhook-id'        => 'test-id',
            'x-mom-webhook-event'     => 'delivered',
            'x-mom-webhook-timestamp' => '1712240232',
            'x-mom-webhook-signature' => 'sha256=invalid',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'secret'));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailomatConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailomat webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailomat sends flat JSON objects with an 'eventType' field.
 * Event types: accepted, not_accepted, delivered, failure_tmp, failure_perm, opened, clicked.
 *
 * Signature verification uses HMAC-SHA256 of "{id}.{event}.{timestamp}" (from headers)
 * with the webhook secret. The signature header is prefixed with "sha256=".
 *
 * @see https://api.mailomat.swiss/docs
 */
class Swift_Webhook_Converter_MailomatConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'    => ['delivery', 'delivered'],
        'failure_perm' => ['delivery', 'bounced'],
        'failure_tmp'  => ['delivery', 'deferred'],
        'opened'       => ['engagement', 'opened'],
        'clicked'      => ['engagement', 'clicked'],
    ];

    public function getProviderName(): string
    {
        return 'mailomat';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $id        = $headers['x-mom-webhook-id']        ?? null;
        $event     = $headers['x-mom-webhook-event']     ?? null;
        $timestamp = $headers['x-mom-webhook-timestamp'] ?? null;
        $signature = $headers['x-mom-webhook-signature'] ?? null;

        if (null === $id || null === $event || null === $timestamp || null === $signature) {
            return false;
        }

        // Split "sha256=<hash>" to get the algorithm and hash
        $parts = \explode('=', $signature, 2);
        if (2 !== \count($parts)) {
            return false;
        }

        [$algo, $hash] = $parts;

        $payload  = \implode('.', [$id, $event, $timestamp]);
        $expected = \hash_hmac($algo, $payload, $secret);

        return \hash_equals($expected, $hash);
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['eventType'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $messageId     = $payload['messageId'] ?? '';
        $recipient     = $payload['recipient'] ?? '';
        $timestamp     = $this->parseTimestamp($payload['occurredAt'] ?? 'now');
        $metadata      = [];

        if (!empty($payload['payload']) && \is_array($payload['payload'])) {
            $metadata = $payload['payload'];
        }

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailomatConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/MailomatConverter.php tests/unit/Swift/Webhook/Converter/MailomatConverterTest.php
git commit -m "feat: add Mailomat webhook payload converter"
```

---

## Task 8: Mailtrap Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MailtrapConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/MailtrapConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MailtrapConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailtrapConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailtrapConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailtrap', $this->converter->getProviderName());
    }

    public function testConvertDeliveryEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'delivery',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1000',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-1',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-1000', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'bounce',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1001',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-2',
                    'response'   => '550 User not found',
                    'response_code' => 550,
                    'bounce_category' => 'spam',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('550 User not found', $events[0]->getMetadata()['reason']);
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'soft bounce',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1002',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-3',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'open',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1003',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-4',
                    'ip'         => '1.2.3.4',
                    'user_agent' => 'Mozilla/5.0',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['ip']);
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'click',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1004',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-5',
                    'url'        => 'https://example.com/page',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'spam',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1005',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-6',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubscribeEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'unsubscribe',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1006',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-7',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertSuspensionAsDropped()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'suspension',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1007',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-8',
                    'reason'     => 'Daily limit reached',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertRejectAsDropped()
    {
        $payload = [
            'events' => [
                [
                    'event'      => 'reject',
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-1008',
                    'email'      => 'user@example.com',
                    'event_id'   => 'evt-9',
                    'sending_stream'     => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertMultipleEvents()
    {
        $payload = [
            'events' => [
                ['event' => 'delivery',  'timestamp' => 1706000000, 'message_id' => 'a', 'email' => 'a@example.com', 'event_id' => 'e1', 'sending_stream' => 'transactional', 'sending_domain_name' => 'example.com'],
                ['event' => 'open',      'timestamp' => 1706000001, 'message_id' => 'b', 'email' => 'b@example.com', 'event_id' => 'e2', 'sending_stream' => 'transactional', 'sending_domain_name' => 'example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testSkipsActivityLogEvent()
    {
        $payload = [
            'events' => [
                ['event' => 'activity_log.user.login', 'timestamp' => 1706000000],
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        $secret = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        $body   = '{"events":[]}';
        $sig    = \hash_hmac('sha256', $body, $secret);

        $headers = ['mailtrap-signature' => $sig];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = ['mailtrap-signature' => 'invalid'];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'secret'));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailtrapConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailtrap webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailtrap sends a JSON object with an 'events' array. Each entry has an 'event' field.
 * Event types: delivery, bounce, soft bounce, open, click, spam, unsubscribe, suspension, reject.
 *
 * Signature verification uses HMAC-SHA256 of the raw body with the signing secret.
 * The signature is sent in the 'Mailtrap-Signature' header as a hex string.
 *
 * @see https://docs.mailtrap.io/email-api-smtp/advanced/webhooks
 */
class Swift_Webhook_Converter_MailtrapConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivery'    => ['delivery', 'delivered'],
        'bounce'      => ['delivery', 'bounced'],
        'soft bounce' => ['delivery', 'deferred'],
        'suspension'  => ['delivery', 'dropped'],
        'reject'      => ['delivery', 'dropped'],
        'open'        => ['engagement', 'opened'],
        'click'       => ['engagement', 'clicked'],
        'spam'        => ['engagement', 'complained'],
        'unsubscribe' => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'mailtrap';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['mailtrap-signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        return $this->verifyHmac($rawBody, $signature, $secret, 'sha256');
    }

    public function convert(array $payload, array $headers): array
    {
        $entries = $payload['events'] ?? [];
        $events  = [];

        foreach ($entries as $entry) {
            $eventName = $entry['event'] ?? null;

            if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
                continue;
            }

            [$type, $name] = self::EVENT_MAP[$eventName];
            $messageId     = $entry['message_id'] ?? '';
            $recipient     = $entry['email'] ?? '';
            $timestamp     = $this->parseTimestamp($entry['timestamp'] ?? \time());
            $metadata      = $this->extractMetadata($entry);

            if ('delivery' === $type) {
                $events[] = $this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            } else {
                $events[] = $this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            }
        }

        return $events;
    }

    private function extractMetadata(array $entry): array
    {
        $metadata = [];

        if (isset($entry['response'])) {
            $metadata['reason'] = $entry['response'];
        }
        if (isset($entry['reason'])) {
            $metadata['reason'] = $entry['reason'];
        }
        if (isset($entry['response_code'])) {
            $metadata['response_code'] = $entry['response_code'];
        }
        if (isset($entry['bounce_category'])) {
            $metadata['bounce_category'] = $entry['bounce_category'];
        }
        if (isset($entry['url'])) {
            $metadata['url'] = $entry['url'];
        }
        if (isset($entry['ip'])) {
            $metadata['ip'] = $entry['ip'];
        }
        if (isset($entry['user_agent'])) {
            $metadata['user_agent'] = $entry['user_agent'];
        }
        if (isset($entry['category'])) {
            $metadata['category'] = $entry['category'];
        }
        if (isset($entry['custom_variables'])) {
            $metadata['custom_variables'] = $entry['custom_variables'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailtrapConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/MailtrapConverter.php tests/unit/Swift/Webhook/Converter/MailtrapConverterTest.php
git commit -m "feat: add Mailtrap webhook payload converter"
```

---

## Task 9: Sweego Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/SweegoConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/SweegoConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_SweegoConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_SweegoConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_SweegoConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('sweego', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event_type'     => 'delivered',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1100',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-1',
            'domain_from'    => 'example.com',
            'details'        => 'ACCEPTED (250 OK)',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('tx-1100', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            'event_type'     => 'hard_bounce',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1101',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-2',
            'domain_from'    => 'example.com',
            'response_code'  => 550,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'event_type'     => 'soft-bounce',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1102',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-3',
            'domain_from'    => 'example.com',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'event_type'     => 'email_opened',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1103',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-4',
            'domain_from'    => 'example.com',
            'open'           => [
                'ip_address' => '1.2.3.4',
                'user_agent' => 'Mozilla/5.0',
                'proxy'      => false,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['ip']);
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'event_type'     => 'email_clicked',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1104',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-5',
            'domain_from'    => 'example.com',
            'click'          => [
                'url'        => 'https://example.com/page',
                'ip_address' => '1.2.3.4',
                'user_agent' => 'Mozilla/5.0',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertComplaintEvent()
    {
        $payload = [
            'event_type'     => 'complaint',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1105',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-6',
            'domain_from'    => 'example.com',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertListUnsubEvent()
    {
        $payload = [
            'event_type'     => 'list_unsub',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1106',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-7',
            'domain_from'    => 'example.com',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testSkipsEmailSentEvent()
    {
        $payload = [
            'event_type'     => 'email_sent',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1107',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-8',
            'domain_from'    => 'example.com',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        // Sweego: HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}" with base64-decoded secret
        $secretRaw = \random_bytes(32);
        $secret    = \base64_encode($secretRaw);
        $id        = 'wh_sweego_test';
        $timestamp = '1706000000';
        $body      = '{"event_type":"delivered"}';

        $signedContent = $id.'.'.$timestamp.'.'.$body;
        $signature     = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'webhook-id'        => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => $signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = [
            'webhook-id'        => 'test',
            'webhook-timestamp' => '1706000000',
            'webhook-signature' => 'invalidsig',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, \base64_encode('secret')));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], \base64_encode('secret')));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/SweegoConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Sweego webhook payloads into Swift_Webhook_Event objects.
 *
 * Sweego sends flat JSON objects with an 'event_type' field.
 * Event types: email_sent, delivered, soft-bounce, hard_bounce, list_unsub,
 * complaint, email_opened, email_clicked.
 *
 * Signature verification uses HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}"
 * with the base64-decoded webhook secret. The signature is base64-encoded.
 *
 * @see https://learn.sweego.io/docs/webhooks
 */
class Swift_Webhook_Converter_SweegoConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'     => ['delivery', 'delivered'],
        'hard_bounce'   => ['delivery', 'bounced'],
        'soft-bounce'   => ['delivery', 'deferred'],
        'complaint'     => ['engagement', 'complained'],
        'list_unsub'    => ['engagement', 'unsubscribed'],
        'email_opened'  => ['engagement', 'opened'],
        'email_clicked' => ['engagement', 'clicked'],
    ];

    public function getProviderName(): string
    {
        return 'sweego';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $webhookId  = $headers['webhook-id']        ?? null;
        $timestamp  = $headers['webhook-timestamp'] ?? null;
        $signature  = $headers['webhook-signature'] ?? null;

        if (null === $webhookId || null === $timestamp || null === $signature) {
            return false;
        }

        $secretKey = \base64_decode($secret, true);
        if (false === $secretKey) {
            return false;
        }

        $signedContent = $webhookId.'.'.$timestamp.'.'.$rawBody;
        $expectedSig   = \base64_encode(\hash_hmac('sha256', $signedContent, $secretKey, true));

        return \hash_equals($expectedSig, $signature);
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['event_type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $messageId     = $payload['transaction_id'] ?? '';
        $recipient     = $payload['recipient'] ?? '';
        $timestamp     = $this->parseTimestamp($payload['timestamp'] ?? 'now');
        $metadata      = $this->extractMetadata($payload);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $payload): array
    {
        $metadata = [];

        if (isset($payload['details'])) {
            $metadata['details'] = $payload['details'];
        }
        if (isset($payload['response_code'])) {
            $metadata['response_code'] = $payload['response_code'];
        }
        if (isset($payload['domain_from'])) {
            $metadata['domain_from'] = $payload['domain_from'];
        }
        if (isset($payload['campaign_id'])) {
            $metadata['campaign_id'] = $payload['campaign_id'];
        }
        if (isset($payload['campaign_tags'])) {
            $metadata['campaign_tags'] = $payload['campaign_tags'];
        }

        // Open tracking metadata
        if (isset($payload['open'])) {
            $open = $payload['open'];
            if (isset($open['ip_address'])) {
                $metadata['ip'] = $open['ip_address'];
            }
            if (isset($open['user_agent'])) {
                $metadata['user_agent'] = $open['user_agent'];
            }
            if (isset($open['proxy'])) {
                $metadata['proxy'] = $open['proxy'];
            }
        }

        // Click tracking metadata
        if (isset($payload['click'])) {
            $click = $payload['click'];
            if (isset($click['url'])) {
                $metadata['url'] = $click['url'];
            }
            if (isset($click['ip_address'])) {
                $metadata['ip'] = $click['ip_address'];
            }
            if (isset($click['user_agent'])) {
                $metadata['user_agent'] = $click['user_agent'];
            }
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/SweegoConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/SweegoConverter.php tests/unit/Swift/Webhook/Converter/SweegoConverterTest.php
git commit -m "feat: add Sweego webhook payload converter"
```

---

## Task 10: MailPace Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MailPaceConverter.php`
- Create: `tests/unit/Swift/Webhook/Converter/MailPaceConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MailPaceConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailPaceConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailPaceConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailpace', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event'   => 'email.delivered',
            'payload' => [
                'status'     => 'delivered',
                'id'         => 1,
                'message_id' => '<msg-1200@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'from'       => 'sender@example.com',
                'subject'    => 'Hello',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('<msg-1200@mailer.mailpace.com>', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertBouncedEvent()
    {
        $payload = [
            'event'   => 'email.bounced',
            'payload' => [
                'status'     => 'bounced',
                'message_id' => '<msg-1201@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            'event'   => 'email.deferred',
            'payload' => [
                'status'     => 'deferred',
                'message_id' => '<msg-1202@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'event'   => 'email.spam',
            'payload' => [
                'status'     => 'spam',
                'message_id' => '<msg-1203@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testSkipsQueuedEvent()
    {
        $payload = [
            'event'   => 'email.queued',
            'payload' => [
                'status'     => 'queued',
                'message_id' => '<msg-1204@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidEd25519Signature()
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 verification.');
        }

        // Generate an Ed25519 keypair for testing
        $keypair    = \sodium_crypto_sign_keypair();
        $privateKey = \sodium_crypto_sign_secretkey($keypair);
        $publicKey  = \sodium_crypto_sign_publickey($keypair);

        $body      = '{"event":"email.delivered"}';
        $signature = \base64_encode(\sodium_crypto_sign_detached($body, $privateKey));

        // Secret is the base64-encoded public key
        $secret  = \base64_encode($publicKey);
        $headers = ['x-mailpace-signature' => $signature];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 verification.');
        }

        $keypair   = \sodium_crypto_sign_keypair();
        $publicKey = \sodium_crypto_sign_publickey($keypair);
        $secret    = \base64_encode($publicKey);

        $headers = ['x-mailpace-signature' => \base64_encode(\str_repeat("\0", 64))];

        $this->assertFalse($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], \base64_encode(\str_repeat("\0", 32))));
    }
}
```

**Step 2: Run test to verify failure**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailPaceConverterTest.php
```

**Step 3: Write implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts MailPace webhook payloads into Swift_Webhook_Event objects.
 *
 * MailPace sends JSON with an 'event' field (e.g. "email.delivered") and a nested 'payload' object.
 * Event types: email.queued, email.delivered, email.deferred, email.bounced, email.spam.
 *
 * Signature verification uses Ed25519. The signature (base64-encoded) is sent in the
 * X-MailPace-Signature header. The $secret parameter is the base64-encoded public key.
 *
 * @see https://docs.mailpace.com/guide/webhooks/
 */
class Swift_Webhook_Converter_MailPaceConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'email.delivered' => ['delivery', 'delivered'],
        'email.bounced'   => ['delivery', 'bounced'],
        'email.deferred'  => ['delivery', 'deferred'],
        'email.spam'      => ['delivery', 'dropped'],
    ];

    public function getProviderName(): string
    {
        return 'mailpace';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['x-mailpace-signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        $publicKey    = \base64_decode($secret, true);
        $signatureRaw = \base64_decode($signature, true);

        if (false === $publicKey || false === $signatureRaw) {
            return false;
        }

        try {
            return \sodium_crypto_sign_verify_detached($signatureRaw, $rawBody, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    public function convert(array $payload, array $headers): array
    {
        $eventName = $payload['event'] ?? null;

        if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];
        $data          = $payload['payload'] ?? [];
        $messageId     = $data['message_id'] ?? '';
        $recipient     = $data['to'] ?? '';
        $timestamp     = $this->parseTimestamp($data['updated_at'] ?? $data['created_at'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $data): array
    {
        $metadata = [];

        if (isset($data['from'])) {
            $metadata['from'] = $data['from'];
        }
        if (isset($data['subject'])) {
            $metadata['subject'] = $data['subject'];
        }
        if (isset($data['tags'])) {
            $metadata['tags'] = (array) $data['tags'];
        }
        if (isset($data['status'])) {
            $metadata['status'] = $data['status'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify pass**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailPaceConverterTest.php
```

**Step 5: Commit**
```bash
git add lib/classes/Swift/Webhook/Converter/MailPaceConverter.php tests/unit/Swift/Webhook/Converter/MailPaceConverterTest.php
git commit -m "feat: add MailPace webhook payload converter"
```

---

## Task 11: Full Test Suite and Code Style

**Step 1: Run the complete webhook converter test suite**
```bash
vendor/bin/simple-phpunit tests/unit/Swift/Webhook/ --verbose
```

**Step 2: Run the full project test suite**
```bash
vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose
```

**Step 3: Run code style fixer**
```bash
composer php-cs-fixer
```

**Step 4: Commit any code style fixes**
```bash
git add -A
git diff --cached --quiet || git commit -m "style: apply code style fixes to webhook converters"
```
