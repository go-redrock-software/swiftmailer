# Swift_Envelope Object -- Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Introduce a `Swift_Envelope` value object that decouples the SMTP envelope (MAIL FROM sender + RCPT TO recipients) from message headers, enabling sender rewriting, recipient overriding, and clean BCC handling without mutating `Swift_Mime_SimpleMessage`.

**Architecture:** `Swift_Envelope` is an immutable value object holding a sender string and a flat array of recipient email addresses. A static factory method `Swift_Envelope::fromMessage()` extracts envelope data from a message using the same logic transports use today (Return-Path > Sender > From for sender; To + Cc + Bcc for recipients). An optional `?Swift_Envelope $envelope` parameter is threaded through `Swift_Mailer::send()`, `Swift_Transport::send()`, and all concrete transport implementations. When null, transports derive envelope from message headers exactly as they do now -- full backward compatibility.

**Tech Stack:** PHP 8.1+, existing SwiftMailer transport/mailer layer. No new dependencies.

---

## Task 1: Create `Swift_Envelope` value object

### 1a. Write tests

**File:** `tests/unit/Swift/EnvelopeTest.php`

```php
<?php

class Swift_EnvelopeTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructorSetsProperties()
    {
        $envelope = new Swift_Envelope('sender@example.com', ['to@example.com', 'cc@example.com']);

        $this->assertSame('sender@example.com', $envelope->getSender());
        $this->assertSame(['to@example.com', 'cc@example.com'], $envelope->getRecipients());
    }

    public function testConstructorRejectsEmptySender()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Swift_Envelope('', ['to@example.com']);
    }

    public function testConstructorRejectsEmptyRecipients()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Swift_Envelope('sender@example.com', []);
    }

    public function testConstructorRejectsNonStringRecipient()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Swift_Envelope('sender@example.com', [123]);
    }

    public function testFromMessageUsesReturnPathAsSender()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => 'bounce@example.com',
            'getSender'     => ['sender@example.com' => 'Sender'],
            'getFrom'       => ['from@example.com' => 'From'],
            'getTo'         => ['to@example.com' => 'To'],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame('bounce@example.com', $envelope->getSender());
        $this->assertSame(['to@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageUsesSenderHeaderWhenNoReturnPath()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => ['sender@example.com' => 'Sender'],
            'getFrom'       => ['from@example.com' => 'From'],
            'getTo'         => ['to@example.com' => 'To'],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame('sender@example.com', $envelope->getSender());
    }

    public function testFromMessageUsesFromWhenNoSenderOrReturnPath()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => ['from@example.com' => 'From'],
            'getTo'         => ['to@example.com' => 'To'],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame('from@example.com', $envelope->getSender());
    }

    public function testFromMessageMergesToCcBcc()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => ['from@example.com' => null],
            'getTo'         => ['to@example.com' => null],
            'getCc'         => ['cc@example.com' => null],
            'getBcc'        => ['bcc@example.com' => null],
        ]);

        $envelope = Swift_Envelope::fromMessage($message);

        $this->assertSame(['to@example.com', 'cc@example.com', 'bcc@example.com'], $envelope->getRecipients());
    }

    public function testFromMessageThrowsWhenNoSenderDeterminable()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => null,
            'getTo'         => ['to@example.com' => null],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $this->expectException(Swift_SwiftException::class);
        Swift_Envelope::fromMessage($message);
    }

    public function testFromMessageThrowsWhenNoRecipients()
    {
        $message = $this->createConfiguredMock(Swift_Mime_SimpleMessage::class, [
            'getReturnPath' => null,
            'getSender'     => null,
            'getFrom'       => ['from@example.com' => null],
            'getTo'         => [],
            'getCc'         => [],
            'getBcc'        => [],
        ]);

        $this->expectException(Swift_SwiftException::class);
        Swift_Envelope::fromMessage($message);
    }

    public function testIsImmutable()
    {
        $recipients = ['to@example.com'];
        $envelope   = new Swift_Envelope('sender@example.com', $recipients);

        // Modifying the original array must not affect the envelope
        $recipients[] = 'other@example.com';
        $this->assertCount(1, $envelope->getRecipients());
    }
}
```

### 1b. Implement the class

**File:** `lib/classes/Swift/Envelope.php`

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Represents the SMTP envelope (MAIL FROM + RCPT TO) independently of message headers.
 *
 * This allows sender rewriting, recipient overriding, and BCC handling
 * without modifying the Swift_Mime_SimpleMessage headers.
 */
class Swift_Envelope
{
    private string $sender;

    /** @var string[] */
    private array $recipients;

    /**
     * @param string   $sender     The envelope sender (MAIL FROM address)
     * @param string[] $recipients The envelope recipients (RCPT TO addresses)
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $sender, array $recipients)
    {
        if ('' === $sender) {
            throw new \InvalidArgumentException('The envelope sender address must not be empty.');
        }

        if (empty($recipients)) {
            throw new \InvalidArgumentException('The envelope must have at least one recipient.');
        }

        foreach ($recipients as $recipient) {
            if (!\is_string($recipient)) {
                throw new \InvalidArgumentException(
                    \sprintf('Each envelope recipient must be a string, got "%s".', \get_debug_type($recipient)),
                );
            }
        }

        $this->sender     = $sender;
        $this->recipients = \array_values($recipients);
    }

    /**
     * Create an Envelope from a message, using the same sender/recipient
     * derivation logic the transports use today.
     *
     * Sender priority: Return-Path > Sender header > From header.
     * Recipients: To + Cc + Bcc merged.
     *
     * @throws Swift_SwiftException If sender or recipients cannot be determined
     */
    public static function fromMessage(Swift_Mime_SimpleMessage $message): self
    {
        $sender = self::resolveSender($message);
        if (null === $sender) {
            throw new Swift_SwiftException('Cannot determine envelope sender from message headers.');
        }

        $recipients = self::resolveRecipients($message);
        if (empty($recipients)) {
            throw new Swift_SwiftException('Cannot determine envelope recipients from message headers.');
        }

        return new self($sender, $recipients);
    }

    /**
     * Get the envelope sender address (used as MAIL FROM).
     */
    public function getSender(): string
    {
        return $this->sender;
    }

    /**
     * Get the envelope recipient addresses (used as RCPT TO).
     *
     * @return string[]
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * Resolve the sender address from message headers.
     *
     * Uses the same priority as AbstractSmtpTransport::getReversePath():
     * Return-Path > Sender > From.
     */
    private static function resolveSender(Swift_Mime_SimpleMessage $message): ?string
    {
        $return = $message->getReturnPath();
        if (!empty($return)) {
            return $return;
        }

        $sender = $message->getSender();
        if (!empty($sender)) {
            \reset($sender);

            return \key($sender);
        }

        $from = $message->getFrom();
        if (!empty($from)) {
            \reset($from);

            return \key($from);
        }

        return null;
    }

    /**
     * Resolve all recipient addresses from message headers: To + Cc + Bcc.
     *
     * @return string[]
     */
    private static function resolveRecipients(Swift_Mime_SimpleMessage $message): array
    {
        $to  = (array) $message->getTo();
        $cc  = (array) $message->getCc();
        $bcc = (array) $message->getBcc();

        return \array_keys(\array_merge($to, $cc, $bcc));
    }
}
```

### 1c. Run and verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/EnvelopeTest.php --verbose
```

### 1d. Fix code style and commit

```bash
composer php-cs-fixer
git add lib/classes/Swift/Envelope.php tests/unit/Swift/EnvelopeTest.php
git commit -m "feat: add Swift_Envelope value object for SMTP envelope separation"
```

---

## Task 2: Add optional Envelope parameter to `Swift_Mailer::send()`

### 2a. Write tests

**File:** `tests/unit/Swift/MailerTest.php` -- add new test methods to the existing test class.

Find the existing test class and add:

```php
public function testSendPassesEnvelopeToTransport()
{
    $envelope  = new Swift_Envelope('override@example.com', ['recipient@example.com']);
    $message   = $this->createMock(Swift_Mime_SimpleMessage::class);
    $message->method('getTo')->willReturn(['to@example.com' => 'To']);

    $transport = $this->createMock(Swift_Transport::class);
    $transport->method('isStarted')->willReturn(true);
    $transport->expects($this->once())
        ->method('send')
        ->with($message, $this->anything(), $envelope)
        ->willReturn(1);

    $mailer = new Swift_Mailer($transport);
    $result = $mailer->send($message, $failures, $envelope);

    $this->assertSame(1, $result);
}

public function testSendWithoutEnvelopePassesNull()
{
    $message   = $this->createMock(Swift_Mime_SimpleMessage::class);
    $message->method('getTo')->willReturn(['to@example.com' => 'To']);

    $transport = $this->createMock(Swift_Transport::class);
    $transport->method('isStarted')->willReturn(true);
    $transport->expects($this->once())
        ->method('send')
        ->with($message, $this->anything(), null)
        ->willReturn(1);

    $mailer = new Swift_Mailer($transport);
    $result = $mailer->send($message);

    $this->assertSame(1, $result);
}
```

### 2b. Update `Swift_Transport` interface

**File:** `lib/classes/Swift/Transport.php`

Change the `send()` signature to accept an optional envelope:

```php
/**
 * Send the given Message.
 *
 * Recipient/sender data will be retrieved from the Message API unless
 * an explicit Envelope is provided.
 *
 * @param string[]            $failedRecipients An array of failures by-reference
 * @param Swift_Envelope|null $envelope         Optional explicit SMTP envelope
 *
 * @return int
 */
public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null);
```

### 2c. Update `Swift_Mailer::send()`

**File:** `lib/classes/Swift/Mailer.php`

```php
public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null)
{
    $failedRecipients = (array) $failedRecipients;

    if (!$this->transport->isStarted()) {
        $this->transport->start();
    }

    $sent = 0;

    try {
        $sent = $this->transport->send($message, $failedRecipients, $envelope);
    } catch (Swift_RfcComplianceException $e) {
        foreach ($message->getTo() as $address => $name) {
            $failedRecipients[] = $address;
        }
    }

    return $sent;
}
```

### 2d. Run and verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/MailerTest.php --verbose
```

### 2e. Fix code style and commit

```bash
composer php-cs-fixer
git add lib/classes/Swift/Transport.php lib/classes/Swift/Mailer.php tests/unit/Swift/MailerTest.php
git commit -m "feat: add optional Swift_Envelope parameter to Mailer and Transport interface"
```

---

## Task 3: Update `AbstractSmtpTransport` to use Envelope

### 3a. Write tests

**File:** `tests/unit/Swift/Transport/AbstractSmtpTransportTest.php` -- add to existing test class (or create a focused test file if one does not exist for this class).

Test that when an envelope is provided, its sender is used as MAIL FROM and its recipients as RCPT TO, bypassing message headers. Also test the BCC-stripping behavior is skipped when envelope is explicit.

```php
public function testSendUsesEnvelopeSenderWhenProvided()
{
    // Setup: message has From: original@example.com
    // Envelope overrides sender to override@example.com
    // Assert: MAIL FROM uses override@example.com
    // Assert: RCPT TO uses envelope recipients, not message To/Cc/Bcc
}

public function testSendDoesNotStripBccWhenEnvelopeProvided()
{
    // Setup: message has Bcc: secret@example.com, envelope has recipients
    // Assert: message->setBcc([]) is NOT called when envelope provided
}

public function testSendFallsBackToMessageWhenNoEnvelope()
{
    // Same behavior as today: reads From/To/Cc/Bcc, strips Bcc
}
```

### 3b. Implement changes

**File:** `lib/classes/Swift/Transport/AbstractSmtpTransport.php`

Update `send()` signature and body:

```php
public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null)
{
    if (!$this->isStarted()) {
        $this->start();
    }

    $sent             = 0;
    $failedRecipients = (array) $failedRecipients;

    if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
        $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
        if ($evt->bubbleCancelled()) {
            $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            $evt->cancelBubble(false);
            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');

            return 0;
        }
    }

    if (null !== $envelope) {
        // Use explicit envelope -- no BCC stripping, no header inspection
        $reversePath  = $envelope->getSender();
        $recipientArr = $envelope->getRecipients();
        $totalExpected = \count($recipientArr);

        try {
            $sent += $this->doMailTransaction($message, $reversePath, $recipientArr, $failedRecipients);
        } catch (Exception $e) {
            // Let it propagate after setting event
        }
    } else {
        // Legacy behavior: derive from message headers
        if (!$reversePath = $this->getReversePath($message)) {
            $this->throwException(new Swift_TransportException('Cannot send message without a sender address'));
        }

        $to  = (array) $message->getTo();
        $cc  = (array) $message->getCc();
        $bcc = (array) $message->getBcc();
        $tos = \array_merge($to, $cc, $bcc);
        $totalExpected = \count($to) + \count($cc) + \count($bcc);

        $message->setBcc([]);

        try {
            $sent += $this->sendTo($message, $reversePath, $tos, $failedRecipients);
        } finally {
            $message->setBcc($bcc);
        }
    }

    if ($evt) {
        if ($sent == $totalExpected) {
            $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
        } elseif ($sent > 0) {
            $evt->setResult(Swift_Events_SendEvent::RESULT_TENTATIVE);
        } else {
            $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
        }
        $evt->setFailedRecipients($failedRecipients);
        $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
    }

    $message->generateId();

    return $sent;
}
```

Note: `doMailTransaction()` already accepts a flat array of recipient strings in the `$recipients` parameter and iterates with `doRcptToCommand()`, so `$envelope->getRecipients()` (which returns `string[]`) slots in directly.

### 3c. Run and verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Transport/ --verbose
```

### 3d. Fix code style and commit

```bash
composer php-cs-fixer
git add lib/classes/Swift/Transport/AbstractSmtpTransport.php tests/unit/Swift/Transport/AbstractSmtpTransportTest.php
git commit -m "feat: AbstractSmtpTransport uses Swift_Envelope for MAIL FROM/RCPT TO when provided"
```

---

## Task 4: Update `AbstractApiTransport` and `AbstractHttpApiTransport`

### 4a. Write tests

**File:** `tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php`

Test that when an envelope is provided:
- `doSend()` receives the envelope (or envelope data is accessible)
- `countRecipients()` and `collectRecipients()` use envelope when available
- Without envelope, behavior is identical to current

### 4b. Implement changes

**File:** `lib/classes/Swift/Transport/AbstractApiTransport.php`

Update the abstract `send()` signature:

```php
abstract public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int;
```

**File:** `lib/classes/Swift/Transport/AbstractHttpApiTransport.php`

Update `send()` to accept and store envelope for use by `doSend()`, `countRecipients()`, and `collectRecipients()`:

```php
/** @var Swift_Envelope|null Active envelope during send */
protected ?Swift_Envelope $activeEnvelope = null;

public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
{
    // Store envelope so doSend()/countRecipients()/collectRecipients() can use it
    $this->activeEnvelope = $envelope;

    try {
        // ... existing send() body unchanged ...
        // (The existing body already calls doSend(), countRecipients(), collectRecipients()
        //  which will now check $this->activeEnvelope)
    } finally {
        $this->activeEnvelope = null;
    }
}
```

Update helper methods to prefer envelope when set:

```php
protected function countRecipients(Swift_Mime_SimpleMessage $message): int
{
    if (null !== $this->activeEnvelope) {
        return \count($this->activeEnvelope->getRecipients());
    }

    return \count($message->getTo() ?? [])
        + \count($message->getCc() ?? [])
        + \count($message->getBcc() ?? []);
}

protected function collectRecipients(Swift_Mime_SimpleMessage $message): array
{
    if (null !== $this->activeEnvelope) {
        return $this->activeEnvelope->getRecipients();
    }

    $recipients = [];
    foreach (['getTo', 'getCc', 'getBcc'] as $method) {
        foreach ($message->$method() ?? [] as $address => $name) {
            $recipients[] = $address;
        }
    }

    return $recipients;
}

/**
 * Get the envelope sender, preferring the active envelope over message headers.
 */
protected function getEnvelopeSender(Swift_Mime_SimpleMessage $message): ?string
{
    if (null !== $this->activeEnvelope) {
        return $this->activeEnvelope->getSender();
    }

    $from = $message->getFrom();
    if (!empty($from)) {
        return \array_key_first($from);
    }

    return null;
}
```

Concrete API transports (`SendgridTransport`, etc.) do NOT need changes for basic envelope support because they call `countRecipients()` and `collectRecipients()` from the base class. However, transports that read `$message->getFrom()` for the From header in the API payload should keep doing so -- the envelope sender only affects the SMTP-level MAIL FROM, not the visible "From:" header. The `getEnvelopeSender()` helper is available for transports that need it.

### 4c. Update `doSend()` signature (optional, forward-looking)

Add an optional envelope parameter to the abstract `doSend()` for transports that want direct access:

```php
abstract protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array;
```

Update the call in `send()`:

```php
$result = $this->doSend($message, $envelope);
```

Concrete transports can ignore the parameter (PHP allows this since it has a default value) until they choose to use it.

### 4d. Run and verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Transport/ --verbose
```

### 4e. Fix code style and commit

```bash
composer php-cs-fixer
git add lib/classes/Swift/Transport/AbstractApiTransport.php lib/classes/Swift/Transport/AbstractHttpApiTransport.php tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php
git commit -m "feat: AbstractHttpApiTransport supports Swift_Envelope for sender/recipient override"
```

---

## Task 5: Update meta-transports (Failover + LoadBalanced) and SendmailTransport

### 5a. Update signatures

These transports implement `Swift_Transport::send()` and delegate to wrapped transports. Update their `send()` to accept and forward the envelope:

**File:** `lib/classes/Swift/Transport/FailoverTransport.php`

```php
public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null)
{
    // ... existing logic, but forward $envelope in the inner send() call:
    $sent = $transport->send($message, $failedRecipients, $envelope);
    // ...
}
```

**File:** `lib/classes/Swift/Transport/LoadBalancedTransport.php`

Same pattern -- add `?Swift_Envelope $envelope = null` and forward it.

**File:** `lib/classes/Swift/Transport/SendmailTransport.php` (extends `AbstractSmtpTransport`)

If it overrides `send()`, update its signature. If it inherits, no change needed.

### 5b. Run and verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Transport/ --verbose
```

### 5c. Fix code style and commit

```bash
composer php-cs-fixer
git add lib/classes/Swift/Transport/FailoverTransport.php lib/classes/Swift/Transport/LoadBalancedTransport.php
git commit -m "feat: forward Swift_Envelope through Failover and LoadBalanced meta-transports"
```

---

## Task 6: Add Envelope to `Swift_Events_SendEvent` for plugin access

### 6a. Write tests

**File:** `tests/unit/Swift/Events/SendEventTest.php` -- add to existing test class:

```php
public function testEnvelopeIsNullByDefault()
{
    $transport = $this->createMock(Swift_Transport::class);
    $message   = $this->createMock(Swift_Mime_SimpleMessage::class);
    $evt       = new Swift_Events_SendEvent($transport, $message);

    $this->assertNull($evt->getEnvelope());
}

public function testEnvelopeCanBeSetAndRetrieved()
{
    $transport = $this->createMock(Swift_Transport::class);
    $message   = $this->createMock(Swift_Mime_SimpleMessage::class);
    $envelope  = new Swift_Envelope('sender@example.com', ['to@example.com']);
    $evt       = new Swift_Events_SendEvent($transport, $message);

    $evt->setEnvelope($envelope);

    $this->assertSame($envelope, $evt->getEnvelope());
}
```

### 6b. Implement changes

**File:** `lib/classes/Swift/Events/SendEvent.php`

Add envelope property and accessors:

```php
/** The explicit envelope, if any. */
private ?Swift_Envelope $envelope = null;

/**
 * Set an explicit SMTP envelope for this send operation.
 *
 * Plugins can read this in beforeSendPerformed to inspect or modify
 * the envelope before the transport uses it.
 */
public function setEnvelope(?Swift_Envelope $envelope): void
{
    $this->envelope = $envelope;
}

/**
 * Get the explicit SMTP envelope, if one was provided.
 */
public function getEnvelope(): ?Swift_Envelope
{
    return $this->envelope;
}
```

### 6c. Wire envelope into SendEvent from transports

In `AbstractSmtpTransport::send()` and `AbstractHttpApiTransport::send()`, after creating the send event and before dispatching `beforeSendPerformed`, set the envelope on the event:

```php
if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
    $evt->setEnvelope($envelope);  // <-- add this line
    $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
    // ...
}
```

This allows `beforeSendPerformed` listeners to:
- Inspect the envelope via `$evt->getEnvelope()`
- Replace it via `$evt->setEnvelope(new Swift_Envelope(...))`
- The transport should re-read `$evt->getEnvelope()` after dispatching `beforeSendPerformed` to honor listener modifications

### 6d. Run and verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Events/SendEventTest.php --verbose
```

### 6e. Fix code style and commit

```bash
composer php-cs-fixer
git add lib/classes/Swift/Events/SendEvent.php lib/classes/Swift/Transport/AbstractSmtpTransport.php lib/classes/Swift/Transport/AbstractHttpApiTransport.php tests/unit/Swift/Events/SendEventTest.php
git commit -m "feat: expose Swift_Envelope on SendEvent for plugin access"
```

---

## Task 7: Full test suite + final validation

### 7a. Run the full test suite

```bash
vendor/bin/simple-phpunit --verbose
```

### 7b. Fix any failures

Address any regressions. The most likely issues:
- Existing tests that mock `Swift_Transport::send()` with only 2 parameters may need updating if PHPUnit strict mode rejects the new optional parameter
- `FailoverTransport` / `LoadBalancedTransport` tests that assert `send()` call counts

### 7c. Run code style fixer

```bash
composer php-cs-fixer
```

### 7d. Final commit

```bash
git add -A
git commit -m "chore: fix code style and test adjustments for Swift_Envelope integration"
```

---

## Files changed summary

| File | Change |
|-|-|
| `lib/classes/Swift/Envelope.php` | NEW -- value object |
| `lib/classes/Swift/Transport.php` | Add `?Swift_Envelope` param to `send()` |
| `lib/classes/Swift/Mailer.php` | Add `?Swift_Envelope` param, forward to transport |
| `lib/classes/Swift/Transport/AbstractSmtpTransport.php` | Use envelope for MAIL FROM/RCPT TO when provided |
| `lib/classes/Swift/Transport/AbstractApiTransport.php` | Add `?Swift_Envelope` to abstract `send()` |
| `lib/classes/Swift/Transport/AbstractHttpApiTransport.php` | Store active envelope, update helpers |
| `lib/classes/Swift/Transport/FailoverTransport.php` | Forward envelope |
| `lib/classes/Swift/Transport/LoadBalancedTransport.php` | Forward envelope |
| `lib/classes/Swift/Events/SendEvent.php` | Add envelope getter/setter |
| `tests/unit/Swift/EnvelopeTest.php` | NEW -- unit tests for value object |
| `tests/unit/Swift/MailerTest.php` | Add envelope-forwarding tests |
| `tests/unit/Swift/Transport/AbstractSmtpTransportTest.php` | Add envelope-based send tests |
| `tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php` | Add envelope-based send tests |
| `tests/unit/Swift/Events/SendEventTest.php` | Add envelope accessor tests |

## Backward compatibility guarantees

- `Swift_Envelope` parameter is `null` by default everywhere
- When `null`, all transports behave exactly as before (derive from message headers)
- No existing public method signatures are broken (only optional param added)
- No existing event listener interfaces change
- `Swift_Envelope::fromMessage()` uses identical logic to `AbstractSmtpTransport::getReversePath()` for sender resolution
