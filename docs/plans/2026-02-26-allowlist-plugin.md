# Recipients Allowlist Plugin — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a plugin that restricts email delivery to a configured allowlist of email addresses and/or domain patterns. Emails to non-allowed recipients are either filtered (recipients removed) or redirected to a catch-all address. Essential for dev/staging environments to prevent accidental sends to real users. Matches Symfony Mailer 7.1's `EnvelopeListener` recipients allowlist.

**Architecture:** `Swift_Plugins_AllowlistPlugin` implements `Swift_Events_SendListener`. In `beforeSendPerformed()`, it filters To/Cc/Bcc recipients against the allowlist. Non-matching recipients are removed. If no recipients remain, the send is cancelled via `cancelBubble()`. Original recipients are stored and restored in `sendPerformed()` to avoid permanently modifying the message. An optional `$redirectTo` address allows redirecting all mail to a catch-all while preserving original headers as `X-Original-To`.

**Tech Stack:** PHP 8.1+, existing SwiftMailer plugin/event system. No new dependencies.

---

## Task 1: Swift_Plugins_AllowlistPlugin — Core Filtering

**Files:**
- Create: `lib/classes/Swift/Plugins/AllowlistPlugin.php`
- Test: `tests/unit/Swift/Plugins/AllowlistPluginTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Plugins_AllowlistPluginTest extends \PHPUnit\Framework\TestCase
{
    private function createSendEvent(Swift_Message $message): Swift_Events_SendEvent
    {
        $transport = $this->createMock(Swift_Transport::class);

        return new Swift_Events_SendEvent($transport, $message);
    }

    public function testAllowedExactEmailPassesThrough()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['dev@example.com' => 'Dev User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertArrayHasKey('dev@example.com', $message->getTo());
        $this->assertFalse($event->bubbleCancelled());
    }

    public function testNonAllowedRecipientIsRemoved()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com' => 'Dev User',
                'real-user@external.com' => 'Real User',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertArrayHasKey('dev@example.com', $to);
        $this->assertArrayNotHasKey('real-user@external.com', $to);
    }

    public function testDomainWildcardAllowsEntireDomain()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'anyone@example.com' => 'Internal',
                'user@external.com' => 'External',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertArrayHasKey('anyone@example.com', $to);
        $this->assertArrayNotHasKey('user@external.com', $to);
    }

    public function testAllRecipientRemovedCancelsSend()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['external@other.com' => 'External User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertTrue($event->bubbleCancelled());
    }

    public function testCcAndBccAreAlsoFiltered()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@safe.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['to@safe.com' => 'Safe To'])
            ->setCc([
                'cc-safe@safe.com' => 'Safe CC',
                'cc-unsafe@other.com' => 'Unsafe CC',
            ])
            ->setBcc([
                'bcc-safe@safe.com' => 'Safe BCC',
                'bcc-unsafe@other.com' => 'Unsafe BCC',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $cc = $message->getCc();
        $this->assertArrayHasKey('cc-safe@safe.com', $cc);
        $this->assertArrayNotHasKey('cc-unsafe@other.com', $cc);

        $bcc = $message->getBcc();
        $this->assertArrayHasKey('bcc-safe@safe.com', $bcc);
        $this->assertArrayNotHasKey('bcc-unsafe@other.com', $bcc);
    }

    public function testOriginalRecipientsRestoredAfterSend()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com' => 'Dev',
                'real@external.com' => 'Real',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        // During send: only dev@example.com
        $this->assertCount(1, $message->getTo());

        // After send: originals restored
        $plugin->sendPerformed($event);
        $to = $message->getTo();
        $this->assertCount(2, $to);
        $this->assertArrayHasKey('dev@example.com', $to);
        $this->assertArrayHasKey('real@external.com', $to);
    }

    public function testMultiplePatternsWork()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin([
            'specific@allowed.com',
            '*@internal.corp',
        ]);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'specific@allowed.com' => 'Specific',
                'anyone@internal.corp' => 'Internal',
                'blocked@external.com' => 'Blocked',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertCount(2, $to);
        $this->assertArrayHasKey('specific@allowed.com', $to);
        $this->assertArrayHasKey('anyone@internal.corp', $to);
    }

    public function testCaseInsensitiveMatching()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['Dev@Example.COM']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['dev@example.com' => 'Dev'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertFalse($event->bubbleCancelled());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/AllowlistPluginTest.php --verbose`
Expected: FAIL — class `Swift_Plugins_AllowlistPlugin` not found.

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
 * Restricts email delivery to a configured allowlist of recipients.
 *
 * Intended for dev/staging environments to prevent accidental sends to real users.
 * Supports exact email addresses and domain wildcards (e.g., '*@example.com').
 *
 * Usage:
 *     $plugin = new Swift_Plugins_AllowlistPlugin(['*@mycompany.com', 'tester@gmail.com']);
 *     $mailer->registerPlugin($plugin);
 *
 * Behavior:
 * - Recipients not matching any pattern are removed from To/Cc/Bcc
 * - If no recipients remain, the send is cancelled
 * - Original recipients are restored after sending (message is not permanently modified)
 */
class Swift_Plugins_AllowlistPlugin implements Swift_Events_SendListener, Swift_Plugins_Sleeper
{
    /** @var string[] Lowercased allowlist patterns */
    private array $patterns;

    /** @var array|null Stored original recipients for restoration */
    private ?array $originalRecipients = null;

    /**
     * @param string[] $allowedPatterns Exact addresses or '*@domain' wildcards
     */
    public function __construct(array $allowedPatterns)
    {
        $this->patterns = array_map('strtolower', $allowedPatterns);
    }

    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        $message = $evt->getMessage();

        // Store original recipients
        $this->originalRecipients = [
            'to' => $message->getTo(),
            'cc' => $message->getCc(),
            'bcc' => $message->getBcc(),
        ];

        // Filter each recipient field
        $filteredTo = $this->filterRecipients($this->originalRecipients['to'] ?? []);
        $filteredCc = $this->filterRecipients($this->originalRecipients['cc'] ?? []);
        $filteredBcc = $this->filterRecipients($this->originalRecipients['bcc'] ?? []);

        // If no recipients left at all, cancel the send
        if (empty($filteredTo) && empty($filteredCc) && empty($filteredBcc)) {
            $evt->cancelBubble(true);

            return;
        }

        // Apply filtered recipients
        $message->setTo($filteredTo);

        if (null !== $this->originalRecipients['cc']) {
            $message->setCc($filteredCc);
        }
        if (null !== $this->originalRecipients['bcc']) {
            $message->setBcc($filteredBcc);
        }
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        if (null === $this->originalRecipients) {
            return;
        }

        $message = $evt->getMessage();

        // Restore original recipients
        $message->setTo($this->originalRecipients['to'] ?? []);

        if (null !== $this->originalRecipients['cc']) {
            $message->setCc($this->originalRecipients['cc']);
        }
        if (null !== $this->originalRecipients['bcc']) {
            $message->setBcc($this->originalRecipients['bcc']);
        }

        $this->originalRecipients = null;
    }

    /**
     * Not used, but required by the Sleeper interface for consistent plugin lifecycle.
     */
    public function sleep(): void
    {
    }

    /**
     * Filter recipients, keeping only those matching the allowlist.
     *
     * @param array $recipients ['email' => 'name'] or ['email'] format
     *
     * @return array Filtered recipients
     */
    private function filterRecipients(array $recipients): array
    {
        $filtered = [];

        foreach ($recipients as $email => $name) {
            if ($this->isAllowed((string) $email)) {
                $filtered[$email] = $name;
            }
        }

        return $filtered;
    }

    private function isAllowed(string $email): bool
    {
        $email = strtolower($email);

        foreach ($this->patterns as $pattern) {
            // Exact match
            if ($pattern === $email) {
                return true;
            }

            // Domain wildcard: *@domain
            if (str_starts_with($pattern, '*@')) {
                $domain = substr($pattern, 2);
                $emailDomain = substr($email, strrpos($email, '@') + 1);

                if ($domain === $emailDomain) {
                    return true;
                }
            }
        }

        return false;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/AllowlistPluginTest.php --verbose`
Expected: PASS (8 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Plugins/AllowlistPlugin.php \
        tests/unit/Swift/Plugins/AllowlistPluginTest.php
git commit -m "feat: add AllowlistPlugin for restricting delivery to approved recipients"
```

---

## Task 2: Redirect Mode (Optional Catch-All)

**Files:**
- Modify: `lib/classes/Swift/Plugins/AllowlistPlugin.php`
- Modify: `tests/unit/Swift/Plugins/AllowlistPluginTest.php`

**Step 1: Write the failing test**

Add to the existing test file:

```php
public function testRedirectModeReplacesAllRecipients()
{
    $plugin = new Swift_Plugins_AllowlistPlugin([], 'catchall@dev.example.com');

    $message = (new Swift_Message())
        ->setFrom(['sender@example.com'])
        ->setTo([
            'real-user@external.com' => 'Real User',
            'another@external.com' => 'Another',
        ])
        ->setCc(['cc@external.com' => 'CC'])
        ->setSubject('Test');

    $event = $this->createSendEvent($message);
    $plugin->beforeSendPerformed($event);

    // All recipients should be replaced with the catch-all
    $to = $message->getTo();
    $this->assertCount(1, $to);
    $this->assertArrayHasKey('catchall@dev.example.com', $to);

    // Cc and Bcc should be cleared
    $this->assertEmpty($message->getCc());
    $this->assertEmpty($message->getBcc());

    // Original To should be preserved as X-Original-To header
    $header = $message->getHeaders()->get('X-Original-To');
    $this->assertNotNull($header);
    $this->assertStringContainsString('real-user@external.com', $header->getFieldBody());

    // Send should NOT be cancelled
    $this->assertFalse($event->bubbleCancelled());
}

public function testRedirectModeRestoresOriginalRecipients()
{
    $plugin = new Swift_Plugins_AllowlistPlugin([], 'catchall@dev.example.com');

    $message = (new Swift_Message())
        ->setFrom(['sender@example.com'])
        ->setTo(['real@external.com' => 'Real'])
        ->setSubject('Test');

    $event = $this->createSendEvent($message);
    $plugin->beforeSendPerformed($event);

    // After send, originals should be restored
    $plugin->sendPerformed($event);

    $to = $message->getTo();
    $this->assertArrayHasKey('real@external.com', $to);
    $this->assertArrayNotHasKey('catchall@dev.example.com', $to);

    // X-Original-To header should be removed
    $this->assertFalse($message->getHeaders()->has('X-Original-To'));
}

public function testRedirectWithAllowlistCombined()
{
    // Allowed recipients go through normally, non-allowed get redirected
    $plugin = new Swift_Plugins_AllowlistPlugin(
        ['dev@example.com'],
        'catchall@dev.example.com'
    );

    $message = (new Swift_Message())
        ->setFrom(['sender@example.com'])
        ->setTo([
            'dev@example.com' => 'Dev',
            'real@external.com' => 'Real',
        ])
        ->setSubject('Test');

    $event = $this->createSendEvent($message);
    $plugin->beforeSendPerformed($event);

    $to = $message->getTo();
    // dev@example.com passes through, real@external.com gets redirected to catch-all
    $this->assertArrayHasKey('dev@example.com', $to);
    $this->assertArrayHasKey('catchall@dev.example.com', $to);
    $this->assertArrayNotHasKey('real@external.com', $to);
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/AllowlistPluginTest.php --verbose`
Expected: 3 new tests FAIL.

**Step 3: Add redirect mode to AllowlistPlugin**

Modify the constructor to accept an optional `?string $redirectTo` parameter:

```php
    private ?string $redirectTo;

    public function __construct(array $allowedPatterns, ?string $redirectTo = null)
    {
        $this->patterns = array_map('strtolower', $allowedPatterns);
        $this->redirectTo = $redirectTo;
    }
```

Modify `beforeSendPerformed()` to handle redirect mode. After filtering recipients, if `$this->redirectTo` is set and there were non-allowed recipients:

```php
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        $message = $evt->getMessage();

        $this->originalRecipients = [
            'to' => $message->getTo(),
            'cc' => $message->getCc(),
            'bcc' => $message->getBcc(),
        ];

        if (null !== $this->redirectTo) {
            $this->applyRedirect($message);

            return;
        }

        // ... existing filtering logic
    }
```

Add the `applyRedirect()` method:

```php
    private function applyRedirect(Swift_Mime_SimpleMessage $message): void
    {
        $allOriginal = array_merge(
            $this->originalRecipients['to'] ?? [],
            $this->originalRecipients['cc'] ?? [],
            $this->originalRecipients['bcc'] ?? [],
        );

        $filteredTo = $this->filterRecipients($this->originalRecipients['to'] ?? []);
        $needsRedirect = \count($filteredTo) < \count($allOriginal);

        if ($needsRedirect) {
            // Store original recipients in X-Original-To header
            $originalAddresses = implode(', ', array_keys($allOriginal));
            $message->getHeaders()->addTextHeader('X-Original-To', $originalAddresses);
        }

        // Allowed recipients go through, add catch-all for any non-allowed
        $newTo = $filteredTo;
        if (\count($filteredTo) < \count($this->originalRecipients['to'] ?? [])) {
            $newTo[$this->redirectTo] = $this->redirectTo;
        }

        $message->setTo($newTo);
        $message->setCc([]);
        $message->setBcc([]);
    }
```

In `sendPerformed()`, also remove the `X-Original-To` header when restoring:

```php
    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        if (null === $this->originalRecipients) {
            return;
        }

        $message = $evt->getMessage();

        // Remove temporary header
        if ($message->getHeaders()->has('X-Original-To')) {
            $message->getHeaders()->removeAll('X-Original-To');
        }

        // Restore original recipients
        $message->setTo($this->originalRecipients['to'] ?? []);

        if (null !== $this->originalRecipients['cc']) {
            $message->setCc($this->originalRecipients['cc']);
        }
        if (null !== $this->originalRecipients['bcc']) {
            $message->setBcc($this->originalRecipients['bcc']);
        }

        $this->originalRecipients = null;
    }
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/AllowlistPluginTest.php --verbose`
Expected: PASS (all 11 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Plugins/AllowlistPlugin.php \
        tests/unit/Swift/Plugins/AllowlistPluginTest.php
git commit -m "feat: add redirect mode to AllowlistPlugin with X-Original-To header"
```

---

## Task 3: Integration Test — AllowlistPlugin with Real EventDispatcher

**Files:**
- Create: `tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php`

**Step 1: Write the integration test**

```php
<?php

class Swift_Integration_AllowlistPluginIntegrationTest extends \PHPUnit\Framework\TestCase
{
    public function testAllowlistWithRealTransport()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $sent = false;

        // Create anonymous transport that records whether doSend was called
        $transport = new class ($dispatcher, $sent) extends Swift_Transport_AbstractHttpApiTransport {
            private bool $sentRef;

            public function __construct(Swift_Events_EventDispatcher $d, bool &$sent)
            {
                parent::__construct('test-key', new \GuzzleHttp\Client(), $d);
                $this->sentRef = &$sent;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                $this->sentRef = true;

                return ['message_id' => 'test', 'recipients' => \count($message->getTo())];
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

        // Register allowlist plugin
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@safe.com']);
        $transport->registerPlugin($plugin);

        // Send to non-allowed recipient
        $message = (new Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['blocked@external.com'])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        $this->assertSame(0, $result);
        $this->assertFalse($sent);

        // Original recipients should be restored
        $this->assertArrayHasKey('blocked@external.com', $message->getTo());
    }

    public function testAllowlistAllowsMatchingRecipients()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $sentTo = null;

        $transport = new class ($dispatcher, $sentTo) extends Swift_Transport_AbstractHttpApiTransport {
            private mixed $sentToRef;

            public function __construct(Swift_Events_EventDispatcher $d, &$sentTo)
            {
                parent::__construct('test-key', new \GuzzleHttp\Client(), $d);
                $this->sentToRef = &$sentTo;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                $this->sentToRef = $message->getTo();

                return ['message_id' => 'test', 'recipients' => \count($message->getTo())];
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

        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@safe.com', '*@internal.corp']);
        $transport->registerPlugin($plugin);

        $message = (new Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo([
                'dev@safe.com' => 'Dev',
                'anyone@internal.corp' => 'Internal',
                'blocked@external.com' => 'Blocked',
            ])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        // Only 2 allowed recipients
        $this->assertCount(2, $sentTo);
        $this->assertArrayHasKey('dev@safe.com', $sentTo);
        $this->assertArrayHasKey('anyone@internal.corp', $sentTo);

        // After send, all 3 original recipients should be restored
        $this->assertCount(3, $message->getTo());
    }
}
```

**Step 2: Run test**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php --verbose`
Expected: PASS (2 tests).

**Step 3: Commit**

```bash
git add tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php
git commit -m "test: add AllowlistPlugin integration tests with real event dispatcher"
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
git commit -m "style: fix code style in AllowlistPlugin"
```

---

## Summary of Changes

| File | Change |
|-|-|
| `lib/classes/Swift/Plugins/AllowlistPlugin.php` | New plugin with filtering and redirect modes |
| `tests/unit/Swift/Plugins/AllowlistPluginTest.php` | 11 unit tests |
| `tests/unit/Swift/Integration/AllowlistPluginIntegrationTest.php` | 2 integration tests |
