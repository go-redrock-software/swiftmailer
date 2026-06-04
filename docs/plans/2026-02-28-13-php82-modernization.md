# PHP 8.5 Modernization — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Modernize the codebase with PHP 8.2–8.5 features (readonly classes, enums, typed class constants, `#[\Override]`, asymmetric visibility, `clone with`, pipe operator) where they improve clarity and safety, without breaking public APIs.

**Architecture:** This is a purely internal modernization targeting PHP 8.5 as minimum. Value objects (`Swift_SentMessage`, `Swift_Webhook_Event`, `Swift_Dsn`) become `readonly` classes (8.2). The `RESULT_*` constants on `Swift_Events_SendEvent` become a backed `int` enum (`Swift_SendResult`) with old constants kept for backward compatibility. All class constants get explicit types (8.3). `#[\Override]` attributes are added to all overriding methods (8.3). Asymmetric visibility (`public private(set)`) replaces `private` + getter patterns on mutable objects (8.4). The `clone with` syntax enables immutable-style modifications on readonly value objects (8.5). The pipe operator (`|>`) is adopted in DSN parsing and message processing chains where it improves readability (8.5).

**Tech Stack:** PHP 8.5+, existing codebase. No new dependencies.

---

## Task 1: Update composer.json PHP Requirement to 8.5+

**Files:**
- Modify: `composer.json`

**Step 1: No tests needed — infrastructure change**

**Step 2: Update the PHP version constraint**

In `composer.json`, change the `php` requirement from the current value to:

```json
"require": {
    "php": ">=8.5.0",
```

**Step 3: Update lock file**

```bash
composer update --lock
```

**Step 4: Verify**

```bash
php -r "echo PHP_VERSION;" && composer validate --strict
```

**Commit:**

```bash
git add composer.json composer.lock
git commit -m "build: bump minimum PHP version to 8.5+"
```

---

## Task 2: Create Swift_SendResult Backed Enum

**Files:**
- Create: `lib/classes/Swift/SendResult.php`
- Create: `tests/unit/Swift/SendResultTest.php`

**Step 1: Write the failing test**

Create `tests/unit/Swift/SendResultTest.php`:

```php
<?php

class Swift_SendResultTest extends PHPUnit\Framework\TestCase
{
    public function testEnumCasesHaveExpectedValues()
    {
        $this->assertSame(0x0001, Swift_SendResult::PENDING->value);
        $this->assertSame(0x0011, Swift_SendResult::SPOOLED->value);
        $this->assertSame(0x0010, Swift_SendResult::SUCCESS->value);
        $this->assertSame(0x0100, Swift_SendResult::TENTATIVE->value);
        $this->assertSame(0x1000, Swift_SendResult::FAILED->value);
    }

    public function testEnumIsBackedInt()
    {
        $this->assertInstanceOf(\BackedEnum::class, Swift_SendResult::PENDING);
    }

    public function testFromInt()
    {
        $result = Swift_SendResult::from(0x0010);
        $this->assertSame(Swift_SendResult::SUCCESS, $result);
    }

    public function testTryFromInvalidReturnsNull()
    {
        $this->assertNull(Swift_SendResult::tryFrom(0x9999));
    }

    public function testBackwardCompatibilityWithSendEventConstants()
    {
        $this->assertSame(Swift_Events_SendEvent::RESULT_PENDING, Swift_SendResult::PENDING->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, Swift_SendResult::SUCCESS->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_TENTATIVE, Swift_SendResult::TENTATIVE->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, Swift_SendResult::FAILED->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SPOOLED, Swift_SendResult::SPOOLED->value);
    }

    public function testBitmaskOperationsStillWork()
    {
        $combined = Swift_SendResult::SUCCESS->value | Swift_SendResult::TENTATIVE->value;
        $this->assertTrue((bool) ($combined & Swift_SendResult::SUCCESS->value));
        $this->assertTrue((bool) ($combined & Swift_SendResult::TENTATIVE->value));
        $this->assertFalse((bool) ($combined & Swift_SendResult::FAILED->value));
    }
}
```

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SendResultTest.php`
Expected: FAIL — enum class doesn't exist yet.

**Step 2: Create the enum**

Create `lib/classes/Swift/SendResult.php`:

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Enum representing the result of a send operation.
 *
 * Replaces the RESULT_* integer constants previously defined on
 * Swift_Events_SendEvent. The values are identical to the old constants,
 * so bitmask operations continue to work with ->value.
 */
enum Swift_SendResult: int
{
    /** Sending has yet to occur */
    case PENDING   = 0x0001;

    /** Email is spooled, ready to be sent */
    case SPOOLED   = 0x0011;

    /** Sending was successful */
    case SUCCESS   = 0x0010;

    /** Sending worked, but there were some failures */
    case TENTATIVE = 0x0100;

    /** Sending failed */
    case FAILED    = 0x1000;
}
```

**Step 3: The existing RESULT_* constants on SendEvent stay as-is for backward compatibility.**

**Step 4: Run tests**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SendResultTest.php`
Expected: PASS (6 tests).

**Commit:**

```bash
git add lib/classes/Swift/SendResult.php tests/unit/Swift/SendResultTest.php
git commit -m "feat: add Swift_SendResult backed int enum for send results"
```

---

## Task 3: Create Swift_ReportResult Backed Enum

**Files:**
- Create: `lib/classes/Swift/ReportResult.php`
- Create: `tests/unit/Swift/ReportResultTest.php`

**Step 1: Write the failing test**

Create `tests/unit/Swift/ReportResultTest.php`:

```php
<?php

class Swift_ReportResultTest extends PHPUnit\Framework\TestCase
{
    public function testEnumCasesHaveExpectedValues()
    {
        $this->assertSame(0x01, Swift_ReportResult::PASS->value);
        $this->assertSame(0x10, Swift_ReportResult::FAIL->value);
    }

    public function testBackwardCompatibilityWithReporterConstants()
    {
        $this->assertSame(Swift_Plugins_Reporter::RESULT_PASS, Swift_ReportResult::PASS->value);
        $this->assertSame(Swift_Plugins_Reporter::RESULT_FAIL, Swift_ReportResult::FAIL->value);
    }
}
```

**Step 2: Create the enum**

Create `lib/classes/Swift/ReportResult.php`:

```php
<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Enum representing the result of a per-recipient report.
 *
 * Mirrors the RESULT_PASS / RESULT_FAIL constants on Swift_Plugins_Reporter.
 */
enum Swift_ReportResult: int
{
    case PASS = 0x01;
    case FAIL = 0x10;
}
```

**Step 3: Run tests**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/ReportResultTest.php`
Expected: PASS (2 tests).

**Commit:**

```bash
git add lib/classes/Swift/ReportResult.php tests/unit/Swift/ReportResultTest.php
git commit -m "feat: add Swift_ReportResult backed int enum for reporter results"
```

---

## Task 4: Add Typed Class Constants (PHP 8.3)

**Files:**
- Modify: `lib/classes/Swift/Events/SendEvent.php`
- Modify: `lib/classes/Swift/Plugins/Reporter.php`
- Modify: `lib/classes/Swift/Mime/Header.php`
- Modify: `lib/classes/Swift/Transport/IoBuffer.php`
- Modify: `lib/classes/Swift/DependencyContainer.php`

**Step 1: Read each file to find the exact constant declarations**

**Step 2: Add explicit types to all public constants**

For `SendEvent.php`:
```php
    public const int RESULT_PENDING   = 0x0001;
    public const int RESULT_SPOOLED   = 0x0011;
    public const int RESULT_SUCCESS   = 0x0010;
    public const int RESULT_TENTATIVE = 0x0100;
    public const int RESULT_FAILED    = 0x1000;
```

For `Reporter.php`:
```php
    public const int RESULT_PASS = 0x01;
    public const int RESULT_FAIL = 0x10;
```

For `Header.php`:
```php
    public const int TYPE_TEXT          = 2;
    public const int TYPE_PARAMETERIZED = 6;
    public const int TYPE_MAILBOX       = 8;
    public const int TYPE_DATE          = 16;
    public const int TYPE_ID            = 32;
    public const int TYPE_PATH          = 64;
```

For `IoBuffer.php`:
```php
    public const int TYPE_SOCKET  = 0x0001;
    public const int TYPE_PROCESS = 0x0010;
```

For `DependencyContainer.php`:
```php
    public const int TYPE_VALUE    = 0x00001;
    public const int TYPE_INSTANCE = 0x00010;
    public const int TYPE_SHARED   = 0x00100;
    public const int TYPE_ALIAS    = 0x01000;
    public const int TYPE_ARRAY    = 0x10000;
```

**Step 3: Run full test suite**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: PASS.

**Commit:**

```bash
git add lib/classes/Swift/Events/SendEvent.php \
        lib/classes/Swift/Plugins/Reporter.php \
        lib/classes/Swift/Mime/Header.php \
        lib/classes/Swift/Transport/IoBuffer.php \
        lib/classes/Swift/DependencyContainer.php
git commit -m "refactor: add typed class constants (PHP 8.3)

All public int constants now have explicit type declarations,
preventing accidental type coercion in subclasses or implementations."
```

---

## Task 5: Make Value Objects Readonly Classes (PHP 8.2)

**Files:**
- Modify: `lib/classes/Swift/SentMessage.php`
- Modify: `lib/classes/Swift/Webhook/Event.php` (if it exists from webhook plan)
- Modify: `lib/classes/Swift/Dsn.php`
- Modify corresponding test files

**Step 1: Read each file to understand current property layout**

**Step 2: For each file:**

Add `readonly` class modifier. Remove per-property `readonly` keywords (redundant in a readonly class). For `Swift_Dsn`, convert the `private static array $transport_class_map` to a `private const array TRANSPORT_CLASS_MAP` (static properties are not allowed in readonly classes) and update all references from `static::$transport_class_map` to `self::TRANSPORT_CLASS_MAP`.

**Step 3: Add immutability tests to each test file**

```php
public function testIsReadonlyClass()
{
    $ref = new ReflectionClass(Swift_SentMessage::class); // or the relevant class
    $this->assertTrue($ref->isReadOnly());
}
```

**Step 4: Run tests**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SentMessageTest.php tests/unit/Swift/DsnTest.php --verbose`
Expected: PASS.

**Commit:**

```bash
git add lib/classes/Swift/SentMessage.php lib/classes/Swift/Dsn.php \
        tests/unit/Swift/SentMessageTest.php tests/unit/Swift/DsnTest.php
git commit -m "refactor: make value objects readonly classes (PHP 8.2)

Swift_SentMessage and Swift_Dsn are now readonly classes.
Properties are set once in the constructor and never mutated."
```

If `Swift_Webhook_Event` exists, include it in the same commit. If it doesn't exist yet (webhook plan not yet executed), skip it — it will be created as readonly when the webhook plan runs.

---

## Task 6: Add #[\Override] Attributes (PHP 8.3)

**Files:**
- All classes that override methods from parent classes or implement interface methods

**Step 1: Find all override candidates**

Search for methods that override parent methods. Key locations:
- All transport classes that extend `AbstractHttpApiTransport` (override `doSend`, `getEndpoint`, `getAuthHeaders`, `parseResponse`, `getPingEndpoint`)
- `EsmtpTransport` extends `AbstractSmtpTransport` (override `doHeloCommand`, `getParams`)
- `SimpleEventDispatcher` implements `EventDispatcher`
- All plugins implementing listener interfaces
- `SendmailTransport` extends `AbstractSmtpTransport`

**Step 2: Add `#[\Override]` before each overriding method**

Example for a transport:
```php
    #[\Override]
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        // ...
    }
```

**Important:** Only add `#[\Override]` to methods that genuinely override a parent/interface method. If PHP cannot verify the override at compile time, it will throw a fatal error. Test after each batch of files.

**Step 3: Apply in batches — run tests after each batch**

Batch 1: All API transports in `lib/classes/Swift/Transport/Api/`
Batch 2: Abstract transports and SMTP transports
Batch 3: Event dispatcher, plugins, signers
Batch 4: MIME classes

Run after each batch: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`

**Step 4: Commit**

```bash
git add lib/classes/Swift/
git commit -m "refactor: add #[\\Override] attributes to all overriding methods (PHP 8.3)

Ensures compile-time verification that overriding methods match their
parent signatures. Catches signature mismatches immediately."
```

---

## Task 7: Apply Asymmetric Visibility (PHP 8.4)

**Files:**
- Modify: `lib/classes/Swift/Plugins/AllowlistPlugin.php` (if it exists)
- Modify: `lib/classes/Swift/Plugins/SentMessagePlugin.php`
- Modify: other plugin classes with private properties that have public getters

**Step 1: Read each plugin to find candidates**

Look for the pattern: private property + public getter + no setter. These can become `public private(set)` properties, eliminating the getter boilerplate.

**However:** Only apply this if removing the getter doesn't break the public API. If external code calls `$plugin->getLastSentMessage()`, changing to direct property access `$plugin->lastSentMessage` is a different API. **Keep the getters** for backward compatibility. Instead, use asymmetric visibility for internal-only properties that are currently `private` with a protected getter, or for new code.

**Step 2: Focus on internal properties only**

In mutable classes (non-readonly), convert `private` properties that should be readable by subclasses to:
```php
    protected(set) public bool $started = false;
```

This is most useful in `AbstractSmtpTransport` and `AbstractHttpApiTransport` where subclasses need to read parent state.

**Step 3: Read `AbstractSmtpTransport` and `AbstractHttpApiTransport` to find specific candidates**

Look for `private` properties with `protected` getter methods. Convert to `protected(set) public` with the getter kept for backward compatibility.

**Step 4: Run tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`

**Commit:**

```bash
git add lib/classes/Swift/Transport/
git commit -m "refactor: apply asymmetric visibility to transport properties (PHP 8.4)

Internal properties that subclasses need to read are now
public-read/protected-write, reducing getter boilerplate."
```

---

## Task 8: Add clone with Support to Readonly Value Objects (PHP 8.5)

**Files:**
- Modify: `lib/classes/Swift/SentMessage.php` (if readonly from Task 5)
- Create: `tests/unit/Swift/SentMessage/CloneWithTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_SentMessage_CloneWithTest extends \PHPUnit\Framework\TestCase
{
    public function testCloneWithModifiedMessageId()
    {
        $message = (new Swift_Message())
            ->setFrom(['from@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Test');
        $transport = $this->createMock(Swift_Transport::class);

        $original = new Swift_SentMessage($message, $transport, [
            'message_id' => 'original-id',
            'recipients' => 1,
        ]);

        // PHP 8.5 clone with syntax
        $cloned = clone $original with {messageId: 'new-id'};

        $this->assertSame('original-id', $original->getMessageId());
        $this->assertSame('new-id', $cloned->getMessageId());
        $this->assertSame($message, $cloned->getOriginalMessage());
    }
}
```

**Step 2: Run test to verify current behavior**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SentMessage/CloneWithTest.php --verbose`

PHP 8.5's `clone with` works automatically on readonly classes — you just need the properties to be named correctly. If `SentMessage` uses constructor promotion, clone with can modify promoted properties directly.

**Step 3: Verify the readonly class has correctly named properties**

Read `Swift_SentMessage` and ensure properties are named consistently with what clone with expects. If the class uses `private` readonly properties (set in constructor body, not promoted), `clone with` cannot modify them. In that case, convert to constructor promotion:

```php
readonly class Swift_SentMessage
{
    public function __construct(
        private Swift_Mime_SimpleMessage $originalMessage,
        private Swift_Transport $transport,
        private ?string $messageId = null,
        private int $recipientCount = 0,
        private array $debug = [],
        private array $failedRecipients = [],
    ) {
    }
    // ...
}
```

**Important:** This changes the constructor signature. If existing code constructs `SentMessage` with the `array $result` parameter, the constructor must keep backward compatibility. Consider adding a named-parameter-friendly static factory:

```php
    public static function fromResult(
        Swift_Mime_SimpleMessage $message,
        Swift_Transport $transport,
        array $result,
    ): self {
        return new self(
            $message,
            $transport,
            $result['message_id'] ?? null,
            $result['recipients'] ?? 0,
            $result['debug'] ?? [],
            $result['failed_recipients'] ?? [],
        );
    }
```

**Step 4: Run tests**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SentMessageTest.php tests/unit/Swift/SentMessage/CloneWithTest.php --verbose`
Expected: PASS.

**Commit:**

```bash
git add lib/classes/Swift/SentMessage.php tests/unit/Swift/SentMessage/
git commit -m "refactor: enable clone with on Swift_SentMessage (PHP 8.5)

Readonly value objects can now be cloned with modified properties
using PHP 8.5's clone with syntax."
```

---

## Task 9: Adopt Pipe Operator in DSN Parsing (PHP 8.5)

**Files:**
- Modify: `lib/classes/Swift/Transport/DsnTransportFactory.php`

**Step 1: Read the current DsnTransportFactory to find processing chains**

Look for nested function calls or sequential variable assignments that could be expressed as pipes.

**Step 2: Apply pipe operator where it improves readability**

Example — if the factory has a chain like:
```php
$nyholmDsn = DsnParser::parseUrl($dsnString);
$dsn = new Swift_Dsn($nyholmDsn);
$class = $dsn->getTransportClass();
```

This could become:
```php
$class = $dsnString
    |> DsnParser::parseUrl(...)
    |> fn($parsed) => new Swift_Dsn($parsed)
    |> fn($dsn) => $dsn->getTransportClass();
```

**However:** Only use pipe if it genuinely improves readability. If the original is already clear with named variables, keep it. Don't force pipe operator usage.

**Step 3: Run tests**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryTest.php --verbose`
Expected: PASS — behavior unchanged.

**Commit (only if changes were made):**

```bash
git add lib/classes/Swift/Transport/DsnTransportFactory.php
git commit -m "refactor: adopt pipe operator in DSN parsing (PHP 8.5)

Uses |> for sequential transformations where it improves readability."
```

---

## Task 10: Use array_first() / array_last() (PHP 8.5)

**Files:**
- Search for patterns like `reset($arr)`, `end($arr)`, `$arr[0]`, `$arr[array_key_first($arr)]` across the codebase

**Step 1: Find candidates**

```bash
grep -rn 'reset(\$\|end(\$\|array_key_first\|array_key_last' lib/classes/Swift/ | head -20
```

**Step 2: Replace with `array_first()` / `array_last()` where appropriate**

```php
// Before
$first = reset($items);
$last = end($items);

// After
$first = array_first($items);
$last = array_last($items);
```

**Step 3: Run tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`

**Commit:**

```bash
git add lib/classes/Swift/
git commit -m "refactor: use array_first()/array_last() instead of reset()/end() (PHP 8.5)"
```

---

## Task 11: Run Full Test Suite and Code Style Fixer

**Step 1: Run every test suite**

```bash
vendor/bin/simple-phpunit --verbose
```

**Step 2: Fix any failures from earlier tasks**

Common issues:
- Readonly property mutation attempts in tests (mocks/stubs that try to set properties)
- Type errors from typed constants if a subclass overrides with wrong type
- `static::$transport_class_map` references that weren't caught in Task 5

**Step 3: Run code style fixer**

```bash
composer php-cs-fixer
```

**Step 4: Run tests again**

```bash
vendor/bin/simple-phpunit --verbose
```

**Commit:**

```bash
git add -A
git commit -m "style: apply php-cs-fixer after PHP 8.5 modernization"
```

---

## Summary of Changes

| Task | What | PHP Feature | BC Impact |
|-|-|-|-|
| 1 | Bump PHP requirement | 8.5 minimum | Requires PHP 8.5+ |
| 2 | `Swift_SendResult` enum | Backed enum (8.1) | Additive only |
| 3 | `Swift_ReportResult` enum | Backed enum (8.1) | Additive only |
| 4 | Typed class constants | `const int` (8.3) | None |
| 5 | Readonly value objects | `readonly class` (8.2) | `static::` to `self::` on Dsn |
| 6 | `#[\Override]` attributes | Override (8.3) | None |
| 7 | Asymmetric visibility | `private(set)` (8.4) | Internal only |
| 8 | `clone with` on SentMessage | clone with (8.5) | Constructor may change |
| 9 | Pipe operator in DSN | `\|>` (8.5) | None (readability only) |
| 10 | `array_first()`/`array_last()` | Built-in (8.5) | None |
| 11 | Test suite + code style | N/A | N/A |
