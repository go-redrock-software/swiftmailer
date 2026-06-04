# Pre-send Message Rejection — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an explicit `reject()` mechanism to `SendEvent` so plugins can prevent message delivery with a reason string, matching Symfony Mailer's `MessageEvent::reject()` behavior.

**Architecture:** Add `reject(?string $reason)`, `isRejected()`, and `getRejectionReason()` to `Swift_Events_SendEvent`. Update all transport `send()` methods to check `isRejected()` after `beforeSendPerformed` dispatch. The existing `cancelBubble()` mechanism stops event propagation; `reject()` explicitly prevents sending with an auditable reason. `LoggerPlugin` logs rejections.

**Tech Stack:** PHP 8.1+, existing SwiftMailer event system. No new dependencies.

---

## Task 1: Add reject() to SendEvent

**Files:**
- Modify: `lib/classes/Swift/Events/SendEvent.php`
- Test: `tests/unit/Swift/Events/SendEventTest.php` (create)

**Step 1: Write the failing test**

```php
<?php

class Swift_Events_SendEventTest extends \PHPUnit\Framework\TestCase
{
    private function createEvent(): Swift_Events_SendEvent
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        return new Swift_Events_SendEvent($transport, $message);
    }

    public function testNotRejectedByDefault()
    {
        $event = $this->createEvent();

        $this->assertFalse($event->isRejected());
        $this->assertNull($event->getRejectionReason());
    }

    public function testRejectWithReason()
    {
        $event = $this->createEvent();
        $event->reject('Recipient is on suppression list');

        $this->assertTrue($event->isRejected());
        $this->assertSame('Recipient is on suppression list', $event->getRejectionReason());
    }

    public function testRejectWithoutReason()
    {
        $event = $this->createEvent();
        $event->reject();

        $this->assertTrue($event->isRejected());
        $this->assertNull($event->getRejectionReason());
    }

    public function testRejectAlsoCancelsBubble()
    {
        $event = $this->createEvent();
        $event->reject('Blocked');

        $this->assertTrue($event->bubbleCancelled());
    }

    public function testExistingGettersStillWork()
    {
        $event = $this->createEvent();

        $this->assertSame(Swift_Events_SendEvent::RESULT_PENDING, $event->getResult());
        $this->assertInstanceOf(Swift_Mime_SimpleMessage::class, $event->getMessage());
        $this->assertInstanceOf(Swift_Transport::class, $event->getTransport());
        $this->assertSame([], $event->getFailedRecipients());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Events/SendEventTest.php --verbose`
Expected: FAIL — `isRejected()` method does not exist.

**Step 3: Add reject methods to SendEvent**

Add the following properties and methods to `lib/classes/Swift/Events/SendEvent.php`:

After the existing `private $result;` property (line 52), add:

```php
    /** Whether this message has been rejected by a listener. */
    private bool $rejected = false;

    /** Optional reason for rejection. */
    private ?string $rejectionReason = null;
```

After the `getResult()` method (after line 125), add:

```php
    /**
     * Reject this message, preventing it from being sent.
     *
     * Calling this in a beforeSendPerformed listener will prevent
     * the transport from sending the message. Also cancels bubble
     * to stop further listener processing.
     *
     * @param string|null $reason Optional human-readable reason for rejection
     */
    public function reject(?string $reason = null): void
    {
        $this->rejected = true;
        $this->rejectionReason = $reason;
        $this->cancelBubble(true);
    }

    /**
     * Whether the message has been rejected.
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * Get the rejection reason, if any.
     */
    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Events/SendEventTest.php --verbose`
Expected: PASS (5 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Events/SendEvent.php tests/unit/Swift/Events/SendEventTest.php
git commit -m "feat: add reject() method to SendEvent for pre-send message suppression"
```

---

## Task 2: Update Transports to Check isRejected()

**Files:**
- Modify: `lib/classes/Swift/Transport/AbstractHttpApiTransport.php` (line ~85)
- Modify: `lib/classes/Swift/Transport/AbstractSmtpTransport.php` (line ~193)
- Modify: `lib/classes/Swift/Transport/AbstractApiTransport.php` (line ~83)
- Modify: `lib/classes/Swift/Transport/SpoolTransport.php` (line ~95)
- Modify: `lib/classes/Swift/Transport/NullTransport.php` (line ~69)
- Modify: `lib/classes/Swift/Transport/SendmailTransport.php` (line ~112)

The `bubbleCancelled()` check already returns 0 in each transport. The `reject()` method calls `cancelBubble(true)`, so rejected messages are already prevented from sending by the existing check. The transports don't need behavioral changes — the existing `if ($evt->bubbleCancelled()) { return 0; }` already handles this.

**However**, we should explicitly set `RESULT_FAILED` when a message is rejected, to distinguish rejection from bubble cancellation. Check each transport to ensure the result is set properly.

**Step 1: Write a test that verifies rejection returns 0 and sets RESULT_FAILED**

Create test: `tests/unit/Swift/Transport/RejectionBehaviorTest.php`

```php
<?php

class Swift_Transport_RejectionBehaviorTest extends \PHPUnit\Framework\TestCase
{
    public function testHttpApiTransportReturnsZeroOnRejection()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();

        // Create a concrete anonymous subclass of AbstractHttpApiTransport
        $transport = new class ('test-key', new \GuzzleHttp\Client(), $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return ['Authorization' => 'Bearer test-key'];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register a plugin that rejects all messages
        $rejecter = new class () implements Swift_Events_SendListener {
            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                $evt->reject('Test rejection');
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
            }
        };
        $transport->registerPlugin($rejecter);

        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        $this->assertSame(0, $result);
    }

    public function testRejectionReasonIsAccessibleInSendPerformed()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();

        $transport = new class ('test-key', new \GuzzleHttp\Client(), $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register rejecting plugin
        $transport->registerPlugin(new class () implements Swift_Events_SendListener {
            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                $evt->reject('Suppression list match');
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
            }
        });

        // Register observer plugin that captures the sendPerformed event
        $capturedReason = null;
        $capturedRejected = null;
        $transport->registerPlugin(new class ($capturedReason, $capturedRejected) implements Swift_Events_SendListener {
            private mixed $reasonRef;
            private mixed $rejectedRef;

            public function __construct(&$reason, &$rejected)
            {
                $this->reasonRef = &$reason;
                $this->rejectedRef = &$rejected;
            }

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                $this->rejectedRef = $evt->isRejected();
                $this->reasonRef = $evt->getRejectionReason();
            }
        });

        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test')
            ->setBody('Hello');

        $transport->send($message);

        $this->assertTrue($capturedRejected);
        $this->assertSame('Suppression list match', $capturedReason);
    }
}
```

**Step 2: Run test to verify behavior**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/RejectionBehaviorTest.php --verbose`
Expected: PASS — the existing `bubbleCancelled()` check already prevents sending. If the `sendPerformed` event isn't dispatched after rejection, the second test will fail and we'll need to ensure `sendPerformed` fires even on rejection.

**Step 3: Fix any transport that doesn't dispatch sendPerformed on rejection**

Check `AbstractHttpApiTransport::send()` around line 85. After the `bubbleCancelled()` return, `sendPerformed` is NOT dispatched. Fix this by moving the `sendPerformed` dispatch to a `finally` block or dispatching before the early return.

In `lib/classes/Swift/Transport/AbstractHttpApiTransport.php`, find the bubble check (approx line 83-88):

```php
        if ($evt = $this->eventDispatcher?->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }
```

Change to:

```php
        if ($evt = $this->eventDispatcher?->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');

                return 0;
            }
        }
```

Apply the same pattern to every transport that has this early-return pattern:
- `AbstractSmtpTransport.php` (~line 193)
- `AbstractApiTransport.php` (~line 83)
- `SpoolTransport.php` (~line 95)
- `NullTransport.php` (~line 69)
- `SendmailTransport.php` (~line 112)
- `Api/GoogleTransport.php` (~line 60)
- `Api/MicrosoftGraphTransport.php` (~line 67 and ~line 97)

**Important:** Read each file first to find the exact location of the `bubbleCancelled()` check and adapt accordingly. Some transports already dispatch `sendPerformed` in a `finally` block — don't double-dispatch.

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/RejectionBehaviorTest.php --verbose`
Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/AbstractHttpApiTransport.php \
        lib/classes/Swift/Transport/AbstractSmtpTransport.php \
        lib/classes/Swift/Transport/AbstractApiTransport.php \
        lib/classes/Swift/Transport/SpoolTransport.php \
        lib/classes/Swift/Transport/NullTransport.php \
        lib/classes/Swift/Transport/SendmailTransport.php \
        lib/classes/Swift/Transport/Api/GoogleTransport.php \
        lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php \
        tests/unit/Swift/Transport/RejectionBehaviorTest.php
git commit -m "feat: dispatch sendPerformed with RESULT_FAILED on rejected messages in all transports"
```

---

## Task 3: Update LoggerPlugin to Log Rejections

**Files:**
- Modify: `lib/classes/Swift/Plugins/LoggerPlugin.php`
- Modify: `tests/unit/Swift/Plugins/LoggerPluginTest.php`

**Step 1: Read LoggerPlugin to find the sendPerformed handler**

Read `lib/classes/Swift/Plugins/LoggerPlugin.php` to understand the current logging format. Find the `sendPerformed()` method.

**Step 2: Write a failing test for rejection logging**

Add to the existing `tests/unit/Swift/Plugins/LoggerPluginTest.php`:

```php
public function testRejectionIsLogged()
{
    $logger = $this->createMock(Swift_Plugins_Logger::class);
    $plugin = new Swift_Plugins_LoggerPlugin($logger);

    $transport = $this->createMock(Swift_Transport::class);
    $message = (new Swift_Message())
        ->setFrom(['from@example.com'])
        ->setTo(['to@example.com'])
        ->setSubject('Test');

    $event = new Swift_Events_SendEvent($transport, $message);
    $event->reject('Recipient on suppression list');

    $logger->expects($this->once())
        ->method('add')
        ->with($this->stringContains('Rejected'));

    $plugin->sendPerformed($event);
}
```

**Step 3: Implement rejection logging**

In `LoggerPlugin::sendPerformed()`, add a check before the existing result logging:

```php
if ($evt->isRejected()) {
    $reason = $evt->getRejectionReason() ?? 'no reason given';
    $this->logger->add(\sprintf(
        ">> Message rejected before sending: %s\n",
        $reason,
    ));

    return;
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/LoggerPluginTest.php --verbose`
Expected: PASS (all existing + new test).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Plugins/LoggerPlugin.php tests/unit/Swift/Plugins/LoggerPluginTest.php
git commit -m "feat: log pre-send rejections with reason in LoggerPlugin"
```

---

## Task 4: Run Full Test Suite

**Step 1: Run all unit tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All tests pass.

**Step 2: Run code style fixer**

Run: `composer php-cs-fixer`

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "style: fix code style in rejection feature"
```

---

## Summary of Changes

| File | Change |
|-|-|
| `lib/classes/Swift/Events/SendEvent.php` | Add `reject()`, `isRejected()`, `getRejectionReason()` |
| 8 transport files | Dispatch `sendPerformed` with `RESULT_FAILED` on rejection |
| `lib/classes/Swift/Plugins/LoggerPlugin.php` | Log rejection reason |
| 3 new test files | Full coverage |
