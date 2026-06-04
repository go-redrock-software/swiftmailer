# AllowlistPlugin + reject() Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Upgrade `Swift_Plugins_AllowlistPlugin` to use `SendEvent::reject()` instead of `cancelBubble()` when all recipients are filtered out, so the rejection reason is logged and auditable.

**Architecture:** The AllowlistPlugin currently calls `$evt->cancelBubble(true)` when no recipients remain after filtering. The pre-send rejection feature added `reject(?string $reason)` to `SendEvent`, which sets a reason string, marks the event as rejected, and also cancels bubble. This plan replaces the single `cancelBubble(true)` call with `reject()`, passing a descriptive reason that includes the filtered addresses. The existing `LoggerPlugin::sendPerformed()` already logs rejection reasons, so this integration gives operators visibility into *why* a message was blocked and *which* recipients triggered it. Existing tests that assert `bubbleCancelled()` remain valid since `reject()` calls `cancelBubble(true)` internally.

**Tech Stack:** PHP 8.1+, existing SwiftMailer event/plugin system. No new dependencies.

---

## Task 1: Update AllowlistPlugin to Use reject() Instead of cancelBubble()

**Files:**
- Modify: `lib/classes/Swift/Plugins/AllowlistPlugin.php`
- Modify: `tests/unit/Swift/Plugins/AllowlistPluginTest.php`

**Step 1: Write the failing tests**

Add to the existing test file `tests/unit/Swift/Plugins/AllowlistPluginTest.php`:

```php
public function testAllRecipientsFilteredUsesRejectWithReason()
{
    $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

    $message = (new Swift_Message())
        ->setFrom(['sender@example.com'])
        ->setTo(['external@other.com' => 'External User'])
        ->setSubject('Test');

    $event = $this->createSendEvent($message);
    $plugin->beforeSendPerformed($event);

    $this->assertTrue($event->isRejected());
    $reason = $event->getRejectionReason();
    $this->assertNotNull($reason);
    $this->assertStringContainsString('external@other.com', $reason);
    $this->assertStringContainsString('allowlist', strtolower($reason));
}

public function testAllRecipientsFilteredIncludesAllRemovedAddressesInReason()
{
    $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

    $message = (new Swift_Message())
        ->setFrom(['sender@example.com'])
        ->setTo([
            'blocked1@other.com' => 'Blocked One',
            'blocked2@other.com' => 'Blocked Two',
        ])
        ->setCc(['blocked3@other.com' => 'Blocked Three'])
        ->setSubject('Test');

    $event = $this->createSendEvent($message);
    $plugin->beforeSendPerformed($event);

    $this->assertTrue($event->isRejected());
    $reason = $event->getRejectionReason();
    $this->assertStringContainsString('blocked1@other.com', $reason);
    $this->assertStringContainsString('blocked2@other.com', $reason);
    $this->assertStringContainsString('blocked3@other.com', $reason);
}

public function testPartialFilterDoesNotReject()
{
    $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

    $message = (new Swift_Message())
        ->setFrom(['sender@example.com'])
        ->setTo([
            'dev@example.com'   => 'Dev',
            'real@external.com' => 'Real',
        ])
        ->setSubject('Test');

    $event = $this->createSendEvent($message);
    $plugin->beforeSendPerformed($event);

    $this->assertFalse($event->isRejected());
    $this->assertNull($event->getRejectionReason());
}
```

**Step 2: Run tests to verify they fail**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/AllowlistPluginTest.php --verbose`
Expected: `testAllRecipientsFilteredUsesRejectWithReason` and `testAllRecipientsFilteredIncludesAllRemovedAddressesInReason` FAIL because `isRejected()` returns `false` — the plugin currently uses `cancelBubble()`, not `reject()`. `testPartialFilterDoesNotReject` should PASS already.

**Step 3: Update AllowlistPlugin implementation**

In `lib/classes/Swift/Plugins/AllowlistPlugin.php`, find lines 69-74 in `beforeSendPerformed()`:

```php
        // If no recipients left at all, cancel the send
        if (empty($filteredTo) && empty($filteredCc) && empty($filteredBcc)) {
            $evt->cancelBubble(true);

            return;
        }
```

Replace with:

```php
        // If no recipients left at all, reject with reason
        if (empty($filteredTo) && empty($filteredCc) && empty($filteredBcc)) {
            $allOriginalAddresses = array_keys(array_merge(
                $this->originalRecipients['to']  ?? [],
                $this->originalRecipients['cc']  ?? [],
                $this->originalRecipients['bcc'] ?? [],
            ));

            $evt->reject(sprintf(
                'AllowlistPlugin: all recipients removed by allowlist filter (%s)',
                implode(', ', $allOriginalAddresses),
            ));

            return;
        }
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/AllowlistPluginTest.php --verbose`
Expected: PASS (all 14 tests). The existing `testAllRecipientRemovedCancelsSend` test still passes because `reject()` calls `cancelBubble(true)` internally.

**Step 5: Run code style fixer**

Run: `composer php-cs-fixer`

**Step 6: Commit**

```bash
git add lib/classes/Swift/Plugins/AllowlistPlugin.php \
        tests/unit/Swift/Plugins/AllowlistPluginTest.php
git commit -m "feat: use reject() with descriptive reason in AllowlistPlugin instead of cancelBubble()"
```

---

## Task 2: Integration Test — LoggerPlugin Captures AllowlistPlugin Rejection Reason

**Files:**
- Modify: `tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php`

**Step 1: Write the integration test**

Add to the existing test file `tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php`:

```php
public function testLoggerCapturesAllowlistRejectionReason(): void
{
    $dispatcher = new \Swift_Events_SimpleEventDispatcher();
    $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

    $transport = new class('test-key', $httpClient, $dispatcher) extends \Swift_Transport_AbstractHttpApiTransport {
        protected function doSend(\Swift_Mime_SimpleMessage $message): array
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

    // Register AllowlistPlugin
    $allowlist = new \Swift_Plugins_AllowlistPlugin(['*@safe.com']);
    $transport->registerPlugin($allowlist);

    // Register LoggerPlugin with an ArrayLogger to capture output
    $logger = new \Swift_Plugins_Loggers_ArrayLogger();
    $loggerPlugin = new \Swift_Plugins_LoggerPlugin($logger);
    $transport->registerPlugin($loggerPlugin);

    // Send to a non-allowed recipient
    $message = (new \Swift_Message())
        ->setFrom(['sender@safe.com'])
        ->setTo(['blocked@external.com' => 'Blocked User'])
        ->setSubject('Test')
        ->setBody('Hello');

    $result = $transport->send($message);

    $this->assertSame(0, $result);

    // LoggerPlugin should have captured the rejection reason from AllowlistPlugin
    $log = $logger->dump();
    $this->assertStringContainsString('rejected', strtolower($log));
    $this->assertStringContainsString('blocked@external.com', $log);
    $this->assertStringContainsString('allowlist', strtolower($log));
}

public function testLoggerNotTriggeredWhenRecipientsAllowed(): void
{
    $dispatcher = new \Swift_Events_SimpleEventDispatcher();
    $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

    $transport = new class('test-key', $httpClient, $dispatcher) extends \Swift_Transport_AbstractHttpApiTransport {
        protected function doSend(\Swift_Mime_SimpleMessage $message): array
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

    $allowlist = new \Swift_Plugins_AllowlistPlugin(['*@safe.com']);
    $transport->registerPlugin($allowlist);

    $logger = new \Swift_Plugins_Loggers_ArrayLogger();
    $loggerPlugin = new \Swift_Plugins_LoggerPlugin($logger);
    $transport->registerPlugin($loggerPlugin);

    $message = (new \Swift_Message())
        ->setFrom(['sender@safe.com'])
        ->setTo(['allowed@safe.com' => 'Safe User'])
        ->setSubject('Test')
        ->setBody('Hello');

    $result = $transport->send($message);

    $this->assertGreaterThan(0, $result);

    // Log should NOT contain rejection messages
    $log = $logger->dump();
    $this->assertStringNotContainsString('rejected', strtolower($log));
}
```

**Step 2: Run tests to verify they pass**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php --verbose`
Expected: PASS (all 4 tests — 2 existing + 2 new). These should pass immediately since Task 1 already wired the `reject()` call and the `LoggerPlugin` already handles `isRejected()` from the pre-send rejection plan.

**Step 3: Run code style fixer**

Run: `composer php-cs-fixer`

**Step 4: Commit**

```bash
git add tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php
git commit -m "test: add integration tests for AllowlistPlugin rejection with LoggerPlugin"
```

---

## Task 3: Run Full Test Suite

**Step 1: Run all unit tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All tests pass.

**Step 2: Run code style fixer**

Run: `composer php-cs-fixer`

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "style: fix code style in allowlist-reject integration"
```

---

## Summary of Changes

| File | Change |
|-|-|
| `lib/classes/Swift/Plugins/AllowlistPlugin.php` | Replace `cancelBubble(true)` with `reject()` + descriptive reason listing filtered addresses |
| `tests/unit/Swift/Plugins/AllowlistPluginTest.php` | Add 3 tests: rejection reason content, multi-recipient reason, partial filter no-reject |
| `tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php` | Add 2 tests: logger captures rejection reason, logger silent on allowed sends |
