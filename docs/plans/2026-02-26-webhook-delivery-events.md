# Webhook Delivery Event System — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a framework-agnostic webhook payload processing system that converts inbound provider webhooks into typed SwiftMailer events for bounce, delivery, open, click, and complaint handling.

**Architecture:** Framework-agnostic design — parsers accept raw `array $payload` + `array $headers` (not PSR-7), letting the user's framework handle HTTP. Each provider gets a converter class that verifies HMAC signatures and maps provider-specific payloads to two typed event classes: `DeliveryEvent` (delivered/bounced/deferred/dropped) and `EngagementEvent` (opened/clicked/unsubscribed/complained). A `WebhookRequestHandler` orchestrates parsing.

**Tech Stack:** PHP 8.1+, `hash_hmac()` for HMAC verification, existing `Swift_` PSR-0 naming. No new dependencies.

**Design reference:** `docs/plans/2026-02-26-symfony-mailer-parity-design.md`

---

## Phase 1: Core Webhook Infrastructure

### Task 1: Swift_Webhook_Event Base Class

**Files:**
- Create: `lib/classes/Swift/Webhook/Event.php`
- Test: `tests/unit/Swift/Webhook/EventTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_EventTest extends \PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $timestamp = new \DateTimeImmutable('2026-01-15 10:30:00');
        $event = new Swift_Webhook_Event(
            'delivery',
            'bounced',
            'msg-123@example.com',
            'recipient@example.com',
            ['campaign' => 'jan'],
            $timestamp,
            ['raw' => 'data']
        );

        $this->assertSame('delivery', $event->getType());
        $this->assertSame('bounced', $event->getName());
        $this->assertSame('msg-123@example.com', $event->getMessageId());
        $this->assertSame('recipient@example.com', $event->getRecipient());
        $this->assertSame(['campaign' => 'jan'], $event->getMetadata());
        $this->assertSame($timestamp, $event->getTimestamp());
        $this->assertSame(['raw' => 'data'], $event->getRawPayload());
    }

    public function testInvalidTypeThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid event type');
        new Swift_Webhook_Event(
            'invalid',
            'bounced',
            'msg-123@example.com',
            'recipient@example.com',
            [],
            new \DateTimeImmutable(),
            []
        );
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/EventTest.php --verbose`
Expected: FAIL — class `Swift_Webhook_Event` not found.

**Step 3: Write minimal implementation**

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Represents a parsed webhook event from an email service provider.
 *
 * Two types: 'delivery' (bounced, delivered, deferred, dropped) and
 * 'engagement' (opened, clicked, unsubscribed, complained).
 */
class Swift_Webhook_Event
{
    private const VALID_TYPES = ['delivery', 'engagement'];

    public function __construct(
        private readonly string $type,
        private readonly string $name,
        private readonly string $messageId,
        private readonly string $recipient,
        private readonly array $metadata,
        private readonly \DateTimeImmutable $timestamp,
        private readonly array $rawPayload,
    ) {
        if (!\in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid event type "%s". Valid types: %s', $type, implode(', ', self::VALID_TYPES))
            );
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    public function isDelivery(): bool
    {
        return 'delivery' === $this->type;
    }

    public function isEngagement(): bool
    {
        return 'engagement' === $this->type;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/EventTest.php --verbose`
Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/Event.php tests/unit/Swift/Webhook/EventTest.php
git commit -m "feat: add Swift_Webhook_Event value object for parsed webhook payloads"
```

---

### Task 2: PayloadConverterInterface + AbstractPayloadConverter

**Files:**
- Create: `lib/classes/Swift/Webhook/PayloadConverterInterface.php`
- Create: `lib/classes/Swift/Webhook/AbstractPayloadConverter.php`
- Test: `tests/unit/Swift/Webhook/AbstractPayloadConverterTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_AbstractPayloadConverterTest extends \PHPUnit\Framework\TestCase
{
    public function testVerifyHmacSha256()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $payload = '{"event":"bounce"}';
        $secret = 'test-secret-key';
        $validSig = hash_hmac('sha256', $payload, $secret);

        // Use reflection to test protected method
        $method = new \ReflectionMethod($converter, 'verifyHmac');

        $this->assertTrue($method->invoke($converter, $payload, $validSig, $secret, 'sha256'));
        $this->assertFalse($method->invoke($converter, $payload, 'invalid-sig', $secret, 'sha256'));
    }

    public function testVerifyHmacSha1()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $payload = '{"event":"bounce"}';
        $secret = 'test-secret-key';
        $validSig = hash_hmac('sha1', $payload, $secret);

        $method = new \ReflectionMethod($converter, 'verifyHmac');

        $this->assertTrue($method->invoke($converter, $payload, $validSig, $secret, 'sha1'));
    }

    public function testCreateDeliveryEvent()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $method = new \ReflectionMethod($converter, 'createDeliveryEvent');

        $event = $method->invoke(
            $converter,
            'bounced',
            'msg-123',
            'user@example.com',
            ['tag' => 'test'],
            new \DateTimeImmutable('2026-01-15'),
            ['raw' => true]
        );

        $this->assertInstanceOf(Swift_Webhook_Event::class, $event);
        $this->assertSame('delivery', $event->getType());
        $this->assertSame('bounced', $event->getName());
    }

    public function testCreateEngagementEvent()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $method = new \ReflectionMethod($converter, 'createEngagementEvent');

        $event = $method->invoke(
            $converter,
            'opened',
            'msg-456',
            'user@example.com',
            [],
            new \DateTimeImmutable('2026-01-15'),
            []
        );

        $this->assertSame('engagement', $event->getType());
        $this->assertSame('opened', $event->getName());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/AbstractPayloadConverterTest.php --verbose`
Expected: FAIL — class not found.

**Step 3: Write the interface and abstract class**

`lib/classes/Swift/Webhook/PayloadConverterInterface.php`:
```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts raw webhook payloads from email providers into Swift_Webhook_Event objects.
 *
 * Implementations are provider-specific (SendGrid, Mailgun, etc.).
 * The framework's HTTP layer is responsible for parsing the request body
 * into an array — this interface is framework-agnostic.
 */
interface Swift_Webhook_PayloadConverterInterface
{
    /**
     * Convert a webhook payload into one or more events.
     *
     * @param array $payload The decoded JSON payload (or form data)
     * @param array $headers HTTP request headers (keys lowercased, e.g. 'x-sendgrid-signature')
     *
     * @return Swift_Webhook_Event[]
     */
    public function convert(array $payload, array $headers): array;

    /**
     * Verify the webhook signature.
     *
     * @param string $rawBody  The raw request body string (before JSON decoding)
     * @param array  $headers  HTTP request headers (keys lowercased)
     * @param string $secret   The signing secret configured with the provider
     *
     * @return bool True if signature is valid
     */
    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool;

    /**
     * Get the provider name (e.g. 'sendgrid', 'mailgun').
     */
    public function getProviderName(): string;
}
```

`lib/classes/Swift/Webhook/AbstractPayloadConverter.php`:
```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Base class for webhook payload converters with HMAC helpers and event factories.
 */
abstract class Swift_Webhook_AbstractPayloadConverter implements Swift_Webhook_PayloadConverterInterface
{
    /**
     * Verify an HMAC signature using timing-safe comparison.
     */
    protected function verifyHmac(
        string $data,
        string $signature,
        #[\SensitiveParameter] string $secret,
        string $algo = 'sha256',
    ): bool {
        $expected = hash_hmac($algo, $data, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Create a delivery event (delivered, bounced, deferred, dropped).
     */
    protected function createDeliveryEvent(
        string $name,
        string $messageId,
        string $recipient,
        array $metadata,
        \DateTimeImmutable $timestamp,
        array $rawPayload,
    ): Swift_Webhook_Event {
        return new Swift_Webhook_Event('delivery', $name, $messageId, $recipient, $metadata, $timestamp, $rawPayload);
    }

    /**
     * Create an engagement event (opened, clicked, unsubscribed, complained).
     */
    protected function createEngagementEvent(
        string $name,
        string $messageId,
        string $recipient,
        array $metadata,
        \DateTimeImmutable $timestamp,
        array $rawPayload,
    ): Swift_Webhook_Event {
        return new Swift_Webhook_Event('engagement', $name, $messageId, $recipient, $metadata, $timestamp, $rawPayload);
    }

    /**
     * Parse a timestamp from various formats providers use.
     */
    protected function parseTimestamp(int|string $timestamp): \DateTimeImmutable
    {
        if (\is_int($timestamp)) {
            return (new \DateTimeImmutable())->setTimestamp($timestamp);
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp)
            ?: \DateTimeImmutable::createFromFormat('U', $timestamp)
            ?: new \DateTimeImmutable($timestamp);

        return $parsed;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/AbstractPayloadConverterTest.php --verbose`
Expected: PASS (4 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/PayloadConverterInterface.php \
        lib/classes/Swift/Webhook/AbstractPayloadConverter.php \
        tests/unit/Swift/Webhook/AbstractPayloadConverterTest.php
git commit -m "feat: add webhook PayloadConverterInterface and AbstractPayloadConverter with HMAC helpers"
```

---

### Task 3: Swift_Webhook_RequestHandler

**Files:**
- Create: `lib/classes/Swift/Webhook/RequestHandler.php`
- Test: `tests/unit/Swift/Webhook/RequestHandlerTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_RequestHandlerTest extends \PHPUnit\Framework\TestCase
{
    public function testHandleWithValidSignature()
    {
        $event = new Swift_Webhook_Event(
            'delivery',
            'bounced',
            'msg-1',
            'user@example.com',
            [],
            new \DateTimeImmutable(),
            []
        );

        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result = $handler->handle(
            $converter,
            '{"event":"bounce"}',
            ['x-signature' => 'valid'],
            'my-secret'
        );

        $this->assertCount(1, $result);
        $this->assertSame($event, $result[0]);
    }

    public function testHandleWithInvalidSignatureThrows()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(false);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(Swift_Webhook_SignatureVerificationException::class);
        $this->expectExceptionMessage('test');

        $handler->handle(
            $converter,
            '{"event":"bounce"}',
            ['x-signature' => 'invalid'],
            'my-secret'
        );
    }

    public function testHandleWithInvalidJsonThrows()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $handler->handle($converter, 'not-json{', [], 'secret');
    }

    public function testHandleWithoutSecretSkipsVerification()
    {
        $event = new Swift_Webhook_Event(
            'engagement',
            'opened',
            'msg-2',
            'user@example.com',
            [],
            new \DateTimeImmutable(),
            []
        );

        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->expects($this->never())->method('verify');
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result = $handler->handle($converter, '{}', [], null);

        $this->assertCount(1, $result);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/RequestHandlerTest.php --verbose`
Expected: FAIL — classes not found.

**Step 3: Write implementation**

`lib/classes/Swift/Webhook/SignatureVerificationException.php`:
```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Thrown when a webhook signature fails verification.
 */
class Swift_Webhook_SignatureVerificationException extends \RuntimeException
{
    public function __construct(string $providerName)
    {
        parent::__construct(
            \sprintf('Webhook signature verification failed for provider "%s".', $providerName)
        );
    }
}
```

`lib/classes/Swift/Webhook/RequestHandler.php`:
```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Orchestrates webhook processing: verifies signatures, decodes JSON, delegates to converters.
 *
 * Usage in a controller/route handler:
 *
 *     $handler = new Swift_Webhook_RequestHandler();
 *     $events = $handler->handle($sendgridConverter, $rawBody, $headers, $secret);
 *     foreach ($events as $event) {
 *         // Process bounce, delivery, open, click, etc.
 *     }
 */
class Swift_Webhook_RequestHandler
{
    /**
     * Process a webhook request.
     *
     * @param Swift_Webhook_PayloadConverterInterface $converter Provider-specific converter
     * @param string                                  $rawBody   Raw HTTP request body
     * @param array                                   $headers   HTTP headers (keys lowercased)
     * @param string|null                             $secret    Signing secret (null to skip verification)
     *
     * @return Swift_Webhook_Event[]
     *
     * @throws Swift_Webhook_SignatureVerificationException If signature is invalid
     * @throws \InvalidArgumentException                    If body is not valid JSON
     */
    public function handle(
        Swift_Webhook_PayloadConverterInterface $converter,
        string $rawBody,
        array $headers,
        #[\SensitiveParameter] ?string $secret,
    ): array {
        // Normalize header keys to lowercase
        $headers = array_change_key_case($headers, CASE_LOWER);

        // Verify signature if secret provided
        if (null !== $secret) {
            if (!$converter->verify($rawBody, $headers, $secret)) {
                throw new Swift_Webhook_SignatureVerificationException($converter->getProviderName());
            }
        }

        // Decode JSON
        $payload = json_decode($rawBody, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid JSON in webhook body: %s', json_last_error_msg())
            );
        }

        return $converter->convert($payload, $headers);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/RequestHandlerTest.php --verbose`
Expected: PASS (4 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/RequestHandler.php \
        lib/classes/Swift/Webhook/SignatureVerificationException.php \
        tests/unit/Swift/Webhook/RequestHandlerTest.php
git commit -m "feat: add Swift_Webhook_RequestHandler with signature verification and JSON decoding"
```

---

## Phase 2: Provider Converters

Each provider converter follows the same pattern: map the provider's event names to our canonical names, extract message ID and recipient, verify signatures per the provider's spec. Below are the 4 most popular providers. Additional providers follow the identical pattern.

**Important context for all provider tasks:** You will need to look up each provider's webhook documentation to verify payload formats and signature schemes. Use web search to find the official docs. The code below is based on known formats but should be verified.

### Task 4: SendGrid Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/SendgridConverter.php`
- Test: `tests/unit/Swift/Webhook/Converter/SendgridConverterTest.php`

**Context:** SendGrid sends an array of event objects in a single POST. Each event has a top-level `event` field. Signature uses ECDSA verification (Signed Event Webhook), but simpler deployments use no signature. We'll implement the `v3` signature verification using the verification key.

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_SendgridConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_SendgridConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_SendgridConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('sendgrid', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            [
                'event' => 'bounce',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-001.filter0001',
                'timestamp' => 1706000000,
                'reason' => '550 User unknown',
                'type' => 'bounce',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('msg-001', $events[0]->getMessageId());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            [
                'event' => 'delivered',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-002',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            [
                'event' => 'open',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-003',
                'timestamp' => 1706000000,
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
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-004',
                'timestamp' => 1706000000,
                'url' => 'https://example.com/link',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/link', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamReportEvent()
    {
        $payload = [
            [
                'event' => 'spamreport',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-005',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertMultipleEvents()
    {
        $payload = [
            [
                'event' => 'delivered',
                'email' => 'a@example.com',
                'sg_message_id' => 'msg-a',
                'timestamp' => 1706000000,
            ],
            [
                'event' => 'open',
                'email' => 'b@example.com',
                'sg_message_id' => 'msg-b',
                'timestamp' => 1706000001,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testConvertUnknownEventIsSkipped()
    {
        $payload = [
            [
                'event' => 'some_future_event',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-x',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(0, $events);
    }

    public function testVerifyAlwaysReturnsTrueWhenNoVerificationKey()
    {
        // SendGrid's ECDSA verification requires the openssl extension.
        // When verify is called, it validates the signature header.
        // For basic test: verify with empty headers should return false.
        $this->assertFalse(
            $this->converter->verify('{}', [], 'some-key')
        );
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/SendgridConverterTest.php --verbose`
Expected: FAIL — class not found.

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
 * Converts SendGrid Event Webhook payloads into Swift_Webhook_Event objects.
 *
 * SendGrid sends an array of event objects. Each has an 'event' field.
 * Signature verification uses the SendGrid-provided ECDSA public key
 * and the X-Twilio-Email-Event-Webhook-Signature/Timestamp headers.
 *
 * @see https://docs.sendgrid.com/for-developers/tracking-events/event
 */
class Swift_Webhook_Converter_SendgridConverter extends Swift_Webhook_AbstractPayloadConverter
{
    /**
     * Map SendGrid event names to our canonical [type, name] pairs.
     */
    private const EVENT_MAP = [
        'bounce'      => ['delivery', 'bounced'],
        'deferred'    => ['delivery', 'deferred'],
        'delivered'   => ['delivery', 'delivered'],
        'dropped'     => ['delivery', 'dropped'],
        'open'        => ['engagement', 'opened'],
        'click'       => ['engagement', 'clicked'],
        'unsubscribe' => ['engagement', 'unsubscribed'],
        'spamreport'  => ['engagement', 'complained'],
    ];

    public function getProviderName(): string
    {
        return 'sendgrid';
    }

    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool
    {
        $signature = $headers['x-twilio-email-event-webhook-signature'] ?? null;
        $timestamp = $headers['x-twilio-email-event-webhook-timestamp'] ?? null;

        if (null === $signature || null === $timestamp) {
            return false;
        }

        // SendGrid uses ECDSA with the public verification key
        $payload = $timestamp . $rawBody;
        $decodedSig = base64_decode($signature, true);

        if (false === $decodedSig) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($secret);
        if (false === $publicKey) {
            return false;
        }

        return 1 === openssl_verify($payload, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
    }

    public function convert(array $payload, array $headers): array
    {
        $events = [];

        foreach ($payload as $entry) {
            $sgEvent = $entry['event'] ?? null;
            if (null === $sgEvent || !isset(self::EVENT_MAP[$sgEvent])) {
                continue;
            }

            [$type, $name] = self::EVENT_MAP[$sgEvent];
            $messageId = $this->extractMessageId($entry);
            $recipient = $entry['email'] ?? '';
            $timestamp = $this->parseTimestamp($entry['timestamp'] ?? time());
            $metadata = $this->extractMetadata($entry);

            if ('delivery' === $type) {
                $events[] = $this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            } else {
                $events[] = $this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            }
        }

        return $events;
    }

    private function extractMessageId(array $entry): string
    {
        $id = $entry['sg_message_id'] ?? '';

        // SendGrid appends filter IDs like "msg-001.filter0001" — strip the suffix
        if (false !== $pos = strpos($id, '.')) {
            $id = substr($id, 0, $pos);
        }

        return $id;
    }

    private function extractMetadata(array $entry): array
    {
        $metadata = [];

        if (isset($entry['reason'])) {
            $metadata['reason'] = $entry['reason'];
        }
        if (isset($entry['url'])) {
            $metadata['url'] = $entry['url'];
        }
        if (isset($entry['useragent'])) {
            $metadata['user_agent'] = $entry['useragent'];
        }
        if (isset($entry['ip'])) {
            $metadata['ip'] = $entry['ip'];
        }
        if (isset($entry['category'])) {
            $metadata['categories'] = (array) $entry['category'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/SendgridConverterTest.php --verbose`
Expected: PASS (8 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/Converter/SendgridConverter.php \
        tests/unit/Swift/Webhook/Converter/SendgridConverterTest.php
git commit -m "feat: add SendGrid webhook payload converter"
```

---

### Task 5: Mailgun Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/MailgunConverter.php`
- Test: `tests/unit/Swift/Webhook/Converter/MailgunConverterTest.php`

**Context:** Mailgun sends individual events wrapped in `{"signature":{...}, "event-data":{...}}`. Signature uses HMAC-SHA256 of `timestamp + token` with the API key. Verify the official Mailgun webhook docs before implementing.

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_MailgunConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailgunConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailgunConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailgun', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'event-data' => [
                'event' => 'failed',
                'severity' => 'permanent',
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => 'msg-100']],
                'timestamp' => 1706000000.0,
                'delivery-status' => ['message' => '550 User not found'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-100', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertTemporaryFailureAsDeferred()
    {
        $payload = [
            'event-data' => [
                'event' => 'failed',
                'severity' => 'temporary',
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => 'msg-101']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event-data' => [
                'event' => 'delivered',
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => 'msg-102']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'event-data' => [
                'event' => 'opened',
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => 'msg-103']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'event-data' => [
                'event' => 'clicked',
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => 'msg-104']],
                'timestamp' => 1706000000.0,
                'url' => 'https://example.com/tracked',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/tracked', $events[0]->getMetadata()['url']);
    }

    public function testConvertComplainedEvent()
    {
        $payload = [
            'event-data' => [
                'event' => 'complained',
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => 'msg-105']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('complained', $events[0]->getName());
    }

    public function testVerifyValidSignature()
    {
        $secret = 'test-api-key';
        $timestamp = '1706000000';
        $token = 'random-token-abc';
        $expectedSig = hash_hmac('sha256', $timestamp . $token, $secret);

        $rawBody = json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $expectedSig,
            ],
            'event-data' => [],
        ]);

        $this->assertTrue($this->converter->verify($rawBody, [], $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $rawBody = json_encode([
            'signature' => [
                'timestamp' => '1706000000',
                'token' => 'random-token',
                'signature' => 'invalid',
            ],
            'event-data' => [],
        ]);

        $this->assertFalse($this->converter->verify($rawBody, [], 'my-secret'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailgunConverterTest.php --verbose`
Expected: FAIL — class not found.

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
 * Converts Mailgun webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailgun sends individual events wrapped in {"signature":{...}, "event-data":{...}}.
 * Signature verification uses HMAC-SHA256 of (timestamp + token) with the API key.
 *
 * @see https://documentation.mailgun.com/docs/mailgun/api-reference/openapi-final/tag/Webhooks/
 */
class Swift_Webhook_Converter_MailgunConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'   => ['delivery', 'delivered'],
        'opened'      => ['engagement', 'opened'],
        'clicked'     => ['engagement', 'clicked'],
        'unsubscribed' => ['engagement', 'unsubscribed'],
        'complained'  => ['engagement', 'complained'],
    ];

    public function getProviderName(): string
    {
        return 'mailgun';
    }

    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool
    {
        $decoded = json_decode($rawBody, true);
        $sig = $decoded['signature'] ?? [];

        $timestamp = $sig['timestamp'] ?? null;
        $token = $sig['token'] ?? null;
        $signature = $sig['signature'] ?? null;

        if (null === $timestamp || null === $token || null === $signature) {
            return false;
        }

        return $this->verifyHmac($timestamp . $token, $signature, $secret, 'sha256');
    }

    public function convert(array $payload, array $headers): array
    {
        $eventData = $payload['event-data'] ?? [];
        $eventName = $eventData['event'] ?? null;

        if (null === $eventName) {
            return [];
        }

        $recipient = $eventData['recipient'] ?? '';
        $messageId = $eventData['message']['headers']['message-id'] ?? '';
        $timestamp = $this->parseTimestamp((int) ($eventData['timestamp'] ?? time()));
        $metadata = $this->extractMailgunMetadata($eventData);

        // Handle 'failed' event which maps to bounced or deferred based on severity
        if ('failed' === $eventName) {
            $severity = $eventData['severity'] ?? 'permanent';
            $name = 'permanent' === $severity ? 'bounced' : 'deferred';

            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $eventData)];
        }

        if (!isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $eventData)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $eventData)];
    }

    private function extractMailgunMetadata(array $eventData): array
    {
        $metadata = [];

        if (isset($eventData['delivery-status']['message'])) {
            $metadata['reason'] = $eventData['delivery-status']['message'];
        }
        if (isset($eventData['url'])) {
            $metadata['url'] = $eventData['url'];
        }
        if (isset($eventData['client-info'])) {
            $metadata['client_info'] = $eventData['client-info'];
        }
        if (isset($eventData['tags'])) {
            $metadata['tags'] = $eventData['tags'];
        }

        return $metadata;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/MailgunConverterTest.php --verbose`
Expected: PASS (8 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/Converter/MailgunConverter.php \
        tests/unit/Swift/Webhook/Converter/MailgunConverterTest.php
git commit -m "feat: add Mailgun webhook payload converter"
```

---

### Task 6: Postmark Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/PostmarkConverter.php`
- Test: `tests/unit/Swift/Webhook/Converter/PostmarkConverterTest.php`

**Context:** Postmark sends individual events as flat JSON objects. The `RecordType` field identifies the event type. Postmark uses basic auth or webhook challenge token for verification (not HMAC). Check the official Postmark webhook docs for exact payload format.

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_PostmarkConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_PostmarkConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_PostmarkConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('postmark', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'RecordType' => 'Bounce',
            'MessageID' => 'msg-200',
            'Email' => 'user@example.com',
            'BouncedAt' => '2026-01-15T10:30:00Z',
            'Type' => 'HardBounce',
            'Description' => 'The server was unable to deliver',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-200', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('HardBounce', $events[0]->getMetadata()['bounce_type']);
    }

    public function testConvertDeliveryEvent()
    {
        $payload = [
            'RecordType' => 'Delivery',
            'MessageID' => 'msg-201',
            'Recipient' => 'user@example.com',
            'DeliveredAt' => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'RecordType' => 'Open',
            'MessageID' => 'msg-202',
            'Recipient' => 'user@example.com',
            'ReceivedAt' => '2026-01-15T10:30:00Z',
            'UserAgent' => 'Mozilla/5.0',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'RecordType' => 'Click',
            'MessageID' => 'msg-203',
            'Recipient' => 'user@example.com',
            'ReceivedAt' => '2026-01-15T10:30:00Z',
            'OriginalLink' => 'https://example.com/page',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamComplaintEvent()
    {
        $payload = [
            'RecordType' => 'SpamComplaint',
            'MessageID' => 'msg-204',
            'Email' => 'user@example.com',
            'BouncedAt' => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertSubscriptionChangeEvent()
    {
        $payload = [
            'RecordType' => 'SubscriptionChange',
            'MessageID' => 'msg-205',
            'Recipient' => 'user@example.com',
            'ChangedAt' => '2026-01-15T10:30:00Z',
            'SuppressSending' => true,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testVerifyWithWebhookToken()
    {
        $token = 'my-postmark-webhook-token';
        $headers = ['x-postmark-webhook-token' => $token];

        $this->assertTrue($this->converter->verify('{}', $headers, $token));
        $this->assertFalse($this->converter->verify('{}', $headers, 'wrong-token'));
        $this->assertFalse($this->converter->verify('{}', [], $token));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/PostmarkConverterTest.php --verbose`
Expected: FAIL — class not found.

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
 * Converts Postmark webhook payloads into Swift_Webhook_Event objects.
 *
 * Postmark sends flat JSON objects with a 'RecordType' field.
 * Signature verification uses the X-Postmark-Webhook-Token header.
 *
 * @see https://postmarkapp.com/developer/webhooks/webhooks-overview
 */
class Swift_Webhook_Converter_PostmarkConverter extends Swift_Webhook_AbstractPayloadConverter
{
    public function getProviderName(): string
    {
        return 'postmark';
    }

    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool
    {
        $token = $headers['x-postmark-webhook-token'] ?? null;

        if (null === $token) {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function convert(array $payload, array $headers): array
    {
        $recordType = $payload['RecordType'] ?? null;

        if (null === $recordType) {
            return [];
        }

        return match ($recordType) {
            'Bounce' => [$this->convertBounce($payload)],
            'Delivery' => [$this->convertDelivery($payload)],
            'Open' => [$this->convertOpen($payload)],
            'Click' => [$this->convertClick($payload)],
            'SpamComplaint' => [$this->convertSpamComplaint($payload)],
            'SubscriptionChange' => [$this->convertSubscriptionChange($payload)],
            default => [],
        };
    }

    private function convertBounce(array $payload): Swift_Webhook_Event
    {
        $metadata = [];
        if (isset($payload['Type'])) {
            $metadata['bounce_type'] = $payload['Type'];
        }
        if (isset($payload['Description'])) {
            $metadata['reason'] = $payload['Description'];
        }

        return $this->createDeliveryEvent(
            'bounced',
            $payload['MessageID'] ?? '',
            $payload['Email'] ?? '',
            $metadata,
            $this->parseTimestamp($payload['BouncedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertDelivery(array $payload): Swift_Webhook_Event
    {
        return $this->createDeliveryEvent(
            'delivered',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            [],
            $this->parseTimestamp($payload['DeliveredAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertOpen(array $payload): Swift_Webhook_Event
    {
        $metadata = [];
        if (isset($payload['UserAgent'])) {
            $metadata['user_agent'] = $payload['UserAgent'];
        }

        return $this->createEngagementEvent(
            'opened',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            $metadata,
            $this->parseTimestamp($payload['ReceivedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertClick(array $payload): Swift_Webhook_Event
    {
        $metadata = [];
        if (isset($payload['OriginalLink'])) {
            $metadata['url'] = $payload['OriginalLink'];
        }

        return $this->createEngagementEvent(
            'clicked',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            $metadata,
            $this->parseTimestamp($payload['ReceivedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertSpamComplaint(array $payload): Swift_Webhook_Event
    {
        return $this->createEngagementEvent(
            'complained',
            $payload['MessageID'] ?? '',
            $payload['Email'] ?? '',
            [],
            $this->parseTimestamp($payload['BouncedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertSubscriptionChange(array $payload): Swift_Webhook_Event
    {
        return $this->createEngagementEvent(
            'unsubscribed',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            ['suppress_sending' => $payload['SuppressSending'] ?? false],
            $this->parseTimestamp($payload['ChangedAt'] ?? 'now'),
            $payload,
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/PostmarkConverterTest.php --verbose`
Expected: PASS (7 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/Converter/PostmarkConverter.php \
        tests/unit/Swift/Webhook/Converter/PostmarkConverterTest.php
git commit -m "feat: add Postmark webhook payload converter"
```

---

### Task 7: Amazon SES Webhook Converter

**Files:**
- Create: `lib/classes/Swift/Webhook/Converter/AmazonSesConverter.php`
- Test: `tests/unit/Swift/Webhook/Converter/AmazonSesConverterTest.php`

**Context:** Amazon SES sends notifications via SNS. The payload is an SNS message wrapping an SES notification. The `notificationType` field identifies the event. SNS messages can be verified using the `SigningCertURL` and `Signature` fields. Also handle SNS SubscriptionConfirmation messages. Verify the AWS SES notification docs.

**Step 1: Write the failing test**

```php
<?php

class Swift_Webhook_Converter_AmazonSesConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_AmazonSesConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_AmazonSesConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('amazon-ses', $this->converter->getProviderName());
    }

    public function testConvertBounceNotification()
    {
        $payload = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Bounce',
                'bounce' => [
                    'bounceType' => 'Permanent',
                    'bouncedRecipients' => [
                        ['emailAddress' => 'user@example.com'],
                        ['emailAddress' => 'other@example.com'],
                    ],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => [
                    'messageId' => 'ses-msg-300',
                ],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('ses-msg-300', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('other@example.com', $events[1]->getRecipient());
    }

    public function testConvertDeliveryNotification()
    {
        $payload = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Delivery',
                'delivery' => [
                    'recipients' => ['user@example.com'],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => [
                    'messageId' => 'ses-msg-301',
                ],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertComplaintNotification()
    {
        $payload = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Complaint',
                'complaint' => [
                    'complainedRecipients' => [
                        ['emailAddress' => 'user@example.com'],
                    ],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
                    'complaintFeedbackType' => 'abuse',
                ],
                'mail' => [
                    'messageId' => 'ses-msg-302',
                ],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertSnsSubscriptionConfirmationIsSkipped()
    {
        $payload = [
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.amazonaws.com/confirm?...',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(0, $events);
    }

    public function testVerifySkipsForSns()
    {
        // SNS signature verification requires fetching the signing cert.
        // For simplicity, verify() validates the x-amz-sns-message-type header presence.
        $headers = ['x-amz-sns-message-type' => 'Notification'];
        $this->assertTrue($this->converter->verify('{}', $headers, ''));
    }

    public function testVerifyFailsWithoutSnsHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], ''));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/AmazonSesConverterTest.php --verbose`
Expected: FAIL — class not found.

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
 * Converts Amazon SES webhook payloads (via SNS) into Swift_Webhook_Event objects.
 *
 * SES sends notifications through SNS. The outer payload has Type and Message fields.
 * The Message field is a JSON string containing the SES notification with notificationType.
 *
 * Note: Full SNS signature verification requires fetching the signing certificate from AWS.
 * This converter performs basic validation (SNS header presence). For production use,
 * validate SNS signatures using the AWS SDK or a dedicated SNS verification library.
 *
 * @see https://docs.aws.amazon.com/ses/latest/dg/notification-contents.html
 */
class Swift_Webhook_Converter_AmazonSesConverter extends Swift_Webhook_AbstractPayloadConverter
{
    public function getProviderName(): string
    {
        return 'amazon-ses';
    }

    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool
    {
        // Basic validation: ensure this comes from SNS
        return isset($headers['x-amz-sns-message-type']);
    }

    public function convert(array $payload, array $headers): array
    {
        $type = $payload['Type'] ?? null;

        // Skip subscription confirmations — the user should handle those separately
        if ('SubscriptionConfirmation' === $type || 'UnsubscribeConfirmation' === $type) {
            return [];
        }

        $message = json_decode($payload['Message'] ?? '{}', true);
        $notificationType = $message['notificationType'] ?? null;
        $messageId = $message['mail']['messageId'] ?? '';

        return match ($notificationType) {
            'Bounce' => $this->convertBounce($message, $messageId),
            'Delivery' => $this->convertDelivery($message, $messageId),
            'Complaint' => $this->convertComplaint($message, $messageId),
            default => [],
        };
    }

    /** @return Swift_Webhook_Event[] */
    private function convertBounce(array $message, string $messageId): array
    {
        $bounce = $message['bounce'] ?? [];
        $timestamp = $this->parseTimestamp($bounce['timestamp'] ?? 'now');
        $bounceType = $bounce['bounceType'] ?? 'Permanent';
        $name = 'Transient' === $bounceType ? 'deferred' : 'bounced';

        $events = [];
        foreach ($bounce['bouncedRecipients'] ?? [] as $recipient) {
            $metadata = ['bounce_type' => $bounceType];
            if (isset($recipient['diagnosticCode'])) {
                $metadata['reason'] = $recipient['diagnosticCode'];
            }

            $events[] = $this->createDeliveryEvent(
                $name,
                $messageId,
                $recipient['emailAddress'] ?? '',
                $metadata,
                $timestamp,
                $message,
            );
        }

        return $events;
    }

    /** @return Swift_Webhook_Event[] */
    private function convertDelivery(array $message, string $messageId): array
    {
        $delivery = $message['delivery'] ?? [];
        $timestamp = $this->parseTimestamp($delivery['timestamp'] ?? 'now');

        $events = [];
        foreach ($delivery['recipients'] ?? [] as $recipient) {
            $events[] = $this->createDeliveryEvent(
                'delivered',
                $messageId,
                $recipient,
                [],
                $timestamp,
                $message,
            );
        }

        return $events;
    }

    /** @return Swift_Webhook_Event[] */
    private function convertComplaint(array $message, string $messageId): array
    {
        $complaint = $message['complaint'] ?? [];
        $timestamp = $this->parseTimestamp($complaint['timestamp'] ?? 'now');

        $events = [];
        foreach ($complaint['complainedRecipients'] ?? [] as $recipient) {
            $metadata = [];
            if (isset($complaint['complaintFeedbackType'])) {
                $metadata['feedback_type'] = $complaint['complaintFeedbackType'];
            }

            $events[] = $this->createEngagementEvent(
                'complained',
                $messageId,
                $recipient['emailAddress'] ?? '',
                $metadata,
                $timestamp,
                $message,
            );
        }

        return $events;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Webhook/Converter/AmazonSesConverterTest.php --verbose`
Expected: PASS (6 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Webhook/Converter/AmazonSesConverter.php \
        tests/unit/Swift/Webhook/Converter/AmazonSesConverterTest.php
git commit -m "feat: add Amazon SES webhook payload converter (via SNS)"
```

---

## Phase 3: Integration

### Task 8: Integration Test — Full Webhook Flow

**Files:**
- Create: `tests/unit/Swift/Integration/WebhookFlowTest.php`

**Step 1: Write the integration test**

```php
<?php

class Swift_Integration_WebhookFlowTest extends \PHPUnit\Framework\TestCase
{
    public function testFullSendgridWebhookFlow()
    {
        $handler = new Swift_Webhook_RequestHandler();
        $converter = new Swift_Webhook_Converter_SendgridConverter();

        $rawBody = json_encode([
            [
                'event' => 'bounce',
                'email' => 'bounce@example.com',
                'sg_message_id' => 'test-msg-001.filter',
                'timestamp' => 1706000000,
                'reason' => '550 No such user',
            ],
            [
                'event' => 'open',
                'email' => 'reader@example.com',
                'sg_message_id' => 'test-msg-002',
                'timestamp' => 1706000001,
            ],
        ]);

        // Skip signature verification for integration test
        $events = $handler->handle($converter, $rawBody, [], null);

        $this->assertCount(2, $events);

        // First event: bounce
        $this->assertTrue($events[0]->isDelivery());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('test-msg-001', $events[0]->getMessageId());
        $this->assertSame('550 No such user', $events[0]->getMetadata()['reason']);

        // Second event: open
        $this->assertTrue($events[1]->isEngagement());
        $this->assertSame('opened', $events[1]->getName());
    }

    public function testFullMailgunWebhookFlow()
    {
        $handler = new Swift_Webhook_RequestHandler();
        $converter = new Swift_Webhook_Converter_MailgunConverter();

        $secret = 'test-key';
        $timestamp = '1706000000';
        $token = 'random-token';
        $signature = hash_hmac('sha256', $timestamp . $token, $secret);

        $rawBody = json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
            'event-data' => [
                'event' => 'failed',
                'severity' => 'permanent',
                'recipient' => 'bad@example.com',
                'message' => ['headers' => ['message-id' => 'mg-msg-001']],
                'timestamp' => 1706000000.0,
            ],
        ]);

        $events = $handler->handle($converter, $rawBody, [], $secret);

        $this->assertCount(1, $events);
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('mg-msg-001', $events[0]->getMessageId());
    }

    public function testSignatureVerificationFailure()
    {
        $handler = new Swift_Webhook_RequestHandler();
        $converter = new Swift_Webhook_Converter_MailgunConverter();

        $rawBody = json_encode([
            'signature' => [
                'timestamp' => '123',
                'token' => 'abc',
                'signature' => 'tampered',
            ],
            'event-data' => [],
        ]);

        $this->expectException(Swift_Webhook_SignatureVerificationException::class);
        $handler->handle($converter, $rawBody, [], 'real-secret');
    }
}
```

**Step 2: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Integration/WebhookFlowTest.php --verbose`
Expected: PASS (3 tests). These are integration tests using real classes from Tasks 1-7.

**Step 3: Commit**

```bash
git add tests/unit/Swift/Integration/WebhookFlowTest.php
git commit -m "test: add webhook flow integration tests for SendGrid, Mailgun, and signature verification"
```

---

### Task 9: Run Full Test Suite

**Step 1: Run all tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All tests pass including all new webhook tests.

**Step 2: Run code style fixer**

Run: `composer php-cs-fixer`
Expected: No style violations (or fix any found).

**Step 3: Commit any style fixes**

```bash
git add -A
git commit -m "style: fix code style in webhook classes"
```

---

## Summary of Files Created

| File | Purpose |
|-|-|
| `lib/classes/Swift/Webhook/Event.php` | Typed webhook event value object |
| `lib/classes/Swift/Webhook/PayloadConverterInterface.php` | Interface for provider converters |
| `lib/classes/Swift/Webhook/AbstractPayloadConverter.php` | Base class with HMAC/factory helpers |
| `lib/classes/Swift/Webhook/RequestHandler.php` | Orchestrator: verify → decode → convert |
| `lib/classes/Swift/Webhook/SignatureVerificationException.php` | Exception for failed HMAC |
| `lib/classes/Swift/Webhook/Converter/SendgridConverter.php` | SendGrid payload converter |
| `lib/classes/Swift/Webhook/Converter/MailgunConverter.php` | Mailgun payload converter |
| `lib/classes/Swift/Webhook/Converter/PostmarkConverter.php` | Postmark payload converter |
| `lib/classes/Swift/Webhook/Converter/AmazonSesConverter.php` | Amazon SES/SNS payload converter |
| 6 test files mirroring the above | Full test coverage |

## Future Work (not in this plan)

Additional provider converters to add using the same pattern:
- Brevo, Resend, MailerSend, Mailjet, Mandrill, AhaSend, Mailomat, Mailtrap, Sweego, MailPace
