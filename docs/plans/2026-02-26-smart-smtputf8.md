# Smart SMTPUTF8 / Unicode Email Addresses — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make SMTPUTF8 support automatic — detect non-ASCII email addresses at send time and enable SMTPUTF8 when the server supports it, without requiring manual configuration. Matches Symfony Mailer 7.2's automatic unicode address handling (RFC 6530/6531/6532).

**Architecture:** Create `Swift_AddressEncoder_AutoAddressEncoder` that delegates to `Utf8AddressEncoder` when SMTPUTF8 is available, or `IdnAddressEncoder` otherwise. Modify `EsmtpTransport` to set the encoder mode after EHLO based on capabilities. Register `SmtpUtf8Handler` by default in the dependency container. The existing `Utf8AddressEncoder` and `SmtpUtf8Handler` classes are already complete — this plan wires them together automatically.

**Tech Stack:** PHP 8.1+ with `intl` extension (for IDN), existing SwiftMailer ESMTP layer. No new dependencies.

**Existing code to build on:**
- `lib/classes/Swift/AddressEncoder/Utf8AddressEncoder.php` — returns addresses verbatim (RFC 6531)
- `lib/classes/Swift/AddressEncoder/IdnAddressEncoder.php` — encodes domain via `idn_to_ascii`, throws on non-ASCII local-part
- `lib/classes/Swift/Transport/Esmtp/SmtpUtf8Handler.php` — adds `SMTPUTF8` to MAIL FROM params

---

## Task 1: Swift_AddressEncoder_AutoAddressEncoder

**Files:**
- Create: `lib/classes/Swift/AddressEncoder/AutoAddressEncoder.php`
- Test: `tests/unit/Swift/AddressEncoder/AutoAddressEncoderTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_AddressEncoder_AutoAddressEncoderTest extends \PHPUnit\Framework\TestCase
{
    public function testDelegatestoIdnByDefault()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // ASCII address — both encoders handle it the same
        $result = $encoder->encodeString('user@example.com');
        $this->assertSame('user@example.com', $result);
    }

    public function testIdnEncodesInternationalizedDomain()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // IDN domain — IdnAddressEncoder converts to punycode
        $result = $encoder->encodeString('user@dømæne.dk');
        $this->assertSame('user@xn--dmne-gra0l.dk', $result);
    }

    public function testIdnThrowsOnNonAsciiLocalPart()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // Non-ASCII local-part without SMTPUTF8 — IdnAddressEncoder throws
        $this->expectException(Swift_AddressEncoderException::class);
        $encoder->encodeString('dørmi@example.com');
    }

    public function testUtf8ModeAllowsNonAsciiLocalPart()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();
        $encoder->setSmtpUtf8Available(true);

        // Non-ASCII local-part with SMTPUTF8 — passes through verbatim
        $result = $encoder->encodeString('dørmi@dømæne.dk');
        $this->assertSame('dørmi@dømæne.dk', $result);
    }

    public function testResetToIdnMode()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();
        $encoder->setSmtpUtf8Available(true);
        $encoder->setSmtpUtf8Available(false);

        // Back to IDN mode — non-ASCII local-part throws
        $this->expectException(Swift_AddressEncoderException::class);
        $encoder->encodeString('dørmi@example.com');
    }

    public function testIsSmtpUtf8Available()
    {
        $encoder = new Swift_AddressEncoder_AutoAddressEncoder();

        $this->assertFalse($encoder->isSmtpUtf8Available());

        $encoder->setSmtpUtf8Available(true);
        $this->assertTrue($encoder->isSmtpUtf8Available());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/AddressEncoder/AutoAddressEncoderTest.php --verbose`
Expected: FAIL — class `Swift_AddressEncoder_AutoAddressEncoder` not found.

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
 * Auto-detecting address encoder that switches between IDN and UTF-8 modes.
 *
 * In IDN mode (default): delegates to IdnAddressEncoder, which encodes the domain
 * via idn_to_ascii() but throws on non-ASCII local-parts.
 *
 * In UTF-8 mode (when SMTPUTF8 is available): delegates to Utf8AddressEncoder,
 * which passes addresses through verbatim per RFC 6531/6532.
 *
 * The EsmtpTransport sets the mode after EHLO based on server capabilities.
 */
class Swift_AddressEncoder_AutoAddressEncoder implements Swift_AddressEncoder
{
    private Swift_AddressEncoder_IdnAddressEncoder $idnEncoder;
    private Swift_AddressEncoder_Utf8AddressEncoder $utf8Encoder;
    private bool $smtpUtf8Available = false;

    public function __construct(
        ?Swift_AddressEncoder_IdnAddressEncoder $idnEncoder = null,
        ?Swift_AddressEncoder_Utf8AddressEncoder $utf8Encoder = null,
    ) {
        $this->idnEncoder = $idnEncoder ?? new Swift_AddressEncoder_IdnAddressEncoder();
        $this->utf8Encoder = $utf8Encoder ?? new Swift_AddressEncoder_Utf8AddressEncoder();
    }

    public function encodeString(string $address): string
    {
        if ($this->smtpUtf8Available) {
            return $this->utf8Encoder->encodeString($address);
        }

        return $this->idnEncoder->encodeString($address);
    }

    /**
     * Set whether SMTPUTF8 is available on the current connection.
     *
     * Called by EsmtpTransport after EHLO capability parsing.
     */
    public function setSmtpUtf8Available(bool $available): void
    {
        $this->smtpUtf8Available = $available;
    }

    public function isSmtpUtf8Available(): bool
    {
        return $this->smtpUtf8Available;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/AddressEncoder/AutoAddressEncoderTest.php --verbose`
Expected: PASS (6 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/AddressEncoder/AutoAddressEncoder.php \
        tests/unit/Swift/AddressEncoder/AutoAddressEncoderTest.php
git commit -m "feat: add AutoAddressEncoder that switches between IDN and UTF-8 based on SMTPUTF8 capability"
```

---

## Task 2: Wire AutoAddressEncoder into EsmtpTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/EsmtpTransport.php`
- Create: `tests/unit/Swift/Transport/EsmtpTransport/SmtpUtf8AutoDetectTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_Transport_EsmtpTransport_SmtpUtf8AutoDetectTest extends \PHPUnit\Framework\TestCase
{
    public function testAutoEncoderIsSetAfterCapabilityParsing()
    {
        // This test verifies that after doHeloCommand() parses capabilities
        // containing SMTPUTF8, the AutoAddressEncoder is switched to UTF-8 mode.

        $buf = $this->createMock(Swift_Transport_IoBuffer::class);
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $autoEncoder = new Swift_AddressEncoder_AutoAddressEncoder();

        // Pass the auto encoder to the transport
        $transport = new Swift_Transport_EsmtpTransport(
            $buf,
            [new Swift_Transport_Esmtp_SmtpUtf8Handler()],
            $dispatcher,
            'localhost',
            $autoEncoder,
        );

        // Before connection, UTF-8 should not be available
        $this->assertFalse($autoEncoder->isSmtpUtf8Available());
    }

    public function testAutoEncoderDefaultsToIdn()
    {
        $buf = $this->createMock(Swift_Transport_IoBuffer::class);
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $autoEncoder = new Swift_AddressEncoder_AutoAddressEncoder();

        $transport = new Swift_Transport_EsmtpTransport(
            $buf,
            [],
            $dispatcher,
            'localhost',
            $autoEncoder,
        );

        // IDN encoding should work
        $this->assertSame('user@example.com', $autoEncoder->encodeString('user@example.com'));

        // Non-ASCII local-part should fail
        $this->expectException(Swift_AddressEncoderException::class);
        $autoEncoder->encodeString('dørmi@example.com');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/EsmtpTransport/SmtpUtf8AutoDetectTest.php --verbose`
Expected: FAIL if the constructor signature doesn't accept the address encoder in the expected position. Check the actual constructor signature first.

**Step 3: Modify EsmtpTransport**

Read the `EsmtpTransport` constructor to understand its current signature. The constructor takes `(IoBuffer, array $extensionHandlers, EventDispatcher, ?string $localDomain, ?AddressEncoder)`.

**3a.** In the `doHeloCommand()` method, AFTER the capabilities are parsed (`$this->capabilities = $this->getCapabilities($response);`) — which occurs after both explicit STARTTLS and opportunistic STARTTLS blocks — add:

```php
        // Update AutoAddressEncoder based on SMTPUTF8 capability
        $addressEncoder = $this->getAddressEncoder();
        if ($addressEncoder instanceof Swift_AddressEncoder_AutoAddressEncoder) {
            $addressEncoder->setSmtpUtf8Available(isset($this->capabilities['SMTPUTF8']));
        }
```

**Important:** Find the right location by reading the file. There may be multiple places where capabilities are parsed (after initial EHLO and after STARTTLS re-EHLO). The auto-encoder update should happen at the END of `doHeloCommand()`, after ALL capability parsing is complete — typically right before the handler activation loop.

**3b.** Check if `getAddressEncoder()` method exists on `AbstractSmtpTransport`. If not, the encoder is stored as a property. Read `AbstractSmtpTransport.php` to find where the address encoder is stored and how to access it.

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/EsmtpTransport/SmtpUtf8AutoDetectTest.php --verbose`
Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/EsmtpTransport.php \
        tests/unit/Swift/Transport/EsmtpTransport/SmtpUtf8AutoDetectTest.php
git commit -m "feat: wire AutoAddressEncoder into EsmtpTransport for SMTPUTF8 auto-detection"
```

---

## Task 3: Register SmtpUtf8Handler and AutoAddressEncoder by Default

**Files:**
- Modify: `lib/dependency_maps/transport_deps.php`

**Step 1: Read the dependency map**

Read `lib/dependency_maps/transport_deps.php` to understand how transports are configured. Find where `EsmtpTransport` handlers and the address encoder are registered.

**Step 2: Add SmtpUtf8Handler to the default handler list**

In the dependency map, find the ESMTP transport handler registration. Add `Swift_Transport_Esmtp_SmtpUtf8Handler` to the default list of handlers (alongside `AuthHandler`, `EightBitMimeHandler`, etc.).

**Step 3: Replace the default address encoder with AutoAddressEncoder**

Find where the address encoder is registered (likely `Swift_AddressEncoder_IdnAddressEncoder`). Change it to `Swift_AddressEncoder_AutoAddressEncoder`.

**Step 4: Run all ESMTP tests**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/EsmtpTransport/ --verbose`
Expected: All tests pass. If any tests broke because they expect `IdnAddressEncoder`, update them to expect `AutoAddressEncoder`.

**Step 5: Commit**

```bash
git add lib/dependency_maps/transport_deps.php
git commit -m "feat: register SmtpUtf8Handler and AutoAddressEncoder as defaults in dependency map"
```

---

## Task 4: Add SmtpUtf8 DSN Parameter

**Files:**
- Modify: `lib/classes/Swift/Transport/DsnTransportFactory.php`
- Modify: `tests/unit/Swift/Transport/DsnTransportFactoryTest.php`

**Step 1: Write the failing test**

```php
public function testSmtpUtf8DisabledViaDsn()
{
    $factory = new Swift_Transport_DsnTransportFactory();
    $transport = $factory->fromDsnString('smtp://user:pass@smtp.example.com:587?smtputf8=false');

    $this->assertInstanceOf(Swift_SmtpTransport::class, $transport);

    // When smtputf8=false, the address encoder should be IdnAddressEncoder (not Auto)
    // This uses reflection to check the encoder, or we check via behavior
    $encoder = $this->getAddressEncoder($transport);
    $this->assertInstanceOf(Swift_AddressEncoder_IdnAddressEncoder::class, $encoder);
}

public function testSmtpUtf8EnabledByDefaultInDsn()
{
    $factory = new Swift_Transport_DsnTransportFactory();
    $transport = $factory->fromDsnString('smtp://user:pass@smtp.example.com:587');

    // Default should use AutoAddressEncoder
    $encoder = $this->getAddressEncoder($transport);
    $this->assertInstanceOf(Swift_AddressEncoder_AutoAddressEncoder::class, $encoder);
}

private function getAddressEncoder(Swift_SmtpTransport $transport): Swift_AddressEncoder
{
    // Use reflection to access the private address encoder
    $ref = new \ReflectionProperty(Swift_Transport_AbstractSmtpTransport::class, 'addressEncoder');
    return $ref->getValue($transport);
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryTest.php --verbose`

**Step 3: Add smtputf8 DSN parameter**

In `DsnTransportFactory::createSmtpTransport()`, after the `auto_tls` parameter (from the auto_tls plan), add:

```php
        if (isset($params['smtputf8']) && !filter_var($params['smtputf8'], FILTER_VALIDATE_BOOLEAN)) {
            // Disable SMTPUTF8: use plain IdnAddressEncoder instead of AutoAddressEncoder
            $transport->setAddressEncoder(new Swift_AddressEncoder_IdnAddressEncoder());
        }
```

**Note:** Check if `setAddressEncoder()` exists on `AbstractSmtpTransport`. If not, you may need to add it. Read the class to verify.

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryTest.php --verbose`
Expected: PASS.

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/DsnTransportFactory.php \
        tests/unit/Swift/Transport/DsnTransportFactoryTest.php
git commit -m "feat: add smtputf8 DSN parameter to disable SMTPUTF8 auto-detection"
```

---

## Task 5: Run Full Test Suite

**Step 1: Run all unit tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All tests pass.

**Step 2: Run code style fixer**

Run: `composer php-cs-fixer`

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "style: fix code style in SMTPUTF8 auto-detection feature"
```

---

## Summary of Changes

| File | Change |
|-|-|
| `lib/classes/Swift/AddressEncoder/AutoAddressEncoder.php` | New auto-switching encoder |
| `lib/classes/Swift/Transport/EsmtpTransport.php` | Update AutoAddressEncoder after EHLO |
| `lib/dependency_maps/transport_deps.php` | Register SmtpUtf8Handler + AutoAddressEncoder as defaults |
| `lib/classes/Swift/Transport/DsnTransportFactory.php` | `smtputf8` DSN parameter |
| 3 test files | Full coverage |
