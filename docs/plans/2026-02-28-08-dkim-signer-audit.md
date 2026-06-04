# DKIM Signer Audit & Modernization -- Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Audit and modernize `Swift_Signers_DKIMSigner` to close feature gaps with Symfony Mailer's `DkimSigner`, add Ed25519-SHA256 support (RFC 8463), implement oversigning protection, and modernize the class with PHP 8.1+ features.

**Architecture:** The signer implements `Swift_Signers_HeaderSigner` (which extends `Swift_Signer` and `Swift_InputByteStream`). It processes headers and body through canonicalization, hashes the body separately, then signs the concatenation of canonicalized headers + the DKIM-Signature stub. The key change is adding Ed25519-SHA256 as a second algorithm path alongside RSA-SHA256, introducing oversigning for critical headers, and fixing canonicalization parity with Symfony's implementation. The `q=dns/txt` tag and `x-transport` ignored header from Symfony are also missing.

**Tech Stack:** PHP 8.1+, OpenSSL extension. No new dependencies.

---

## Audit Summary: SwiftMailer DKIMSigner vs Symfony DkimSigner

| Feature | SwiftMailer (current) | Symfony DkimSigner | Gap |
|-|-|-|-|
| rsa-sha256 | Yes | Yes | None |
| rsa-sha1 | Yes (deprecated) | No | SwiftMailer keeps deprecated algo |
| ed25519-sha256 (RFC 8463) | No | Declared but throws RuntimeException | Missing entirely |
| `q=dns/txt` tag | Missing | Present | Missing |
| `x-transport` ignored | No | Yes | Missing |
| `return-path` ignored | Yes | Yes | None |
| `from` forced signed | No | Yes (`unset($headersToIgnore['from'])`) | Missing |
| Oversigning protection | No | No | Both lack it |
| Default canonicalization | simple/simple | relaxed/relaxed | Different defaults |
| `c=` tag emission | Omitted when both simple | Always emitted | Inconsistent |
| Body canon empty body (simple) | No CRLF added for zero-length | Adds CRLF per RFC 6376 3.4.3 | Bug |
| Typed properties | No | Yes (PHP 8.1+) | Modernization needed |
| Constructor key validation | Deferred to signing time | Validated in constructor | Missing early validation |
| Passphrase as SensitiveParameter | No | N/A (constructor param) | Missing |

---

## Task 1: Add missing `q=dns/txt` tag, `x-transport` ignore, and force-sign `From`

**Why:** Symfony emits `q=dns/txt` in every DKIM-Signature (required by some verifiers). The `x-transport` header is a Symfony internal that should never be signed. The `From` header must never be excluded from signing per RFC 6376 Section 5.4.

### 1a. Write failing tests

**File:** `tests/unit/Swift/Signers/DKIMSignerTest.php`

Add after the last test method (before `createHeaderSet`):

```php
public function testSignatureContainsQueryMethodTag()
{
    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    $this->assertStringContainsString('q=dns/txt', $sig->getValue());
}

public function testXTransportHeaderIsIgnoredByDefault()
{
    $headerSet = $this->createHeaderSetWithXTransport();
    $messageContent = 'Hello World';
    $signer = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    $this->assertStringNotContainsString('X-Transport', $sig->getValue());
}

public function testFromHeaderCannotBeIgnored()
{
    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->ignoreHeader('From');
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    // h= must contain From even though ignoreHeader was called
    $this->assertMatchesRegularExpression('/h=.*From/', $sig->getValue());
}
```

Also add the helper method alongside the existing `createHeaderSet`:

```php
private function createHeaderSetWithXTransport()
{
    $cache          = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
    $factory        = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
    $contentEncoder = new Swift_Mime_ContentEncoder_Base64ContentEncoder();

    $headerEncoder  = new Swift_Mime_HeaderEncoder_QpHeaderEncoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
    $paramEncoder   = new Swift_Encoder_Rfc2231Encoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
    $emailValidator = new EmailValidator();
    $headers        = new Swift_Mime_SimpleHeaderSet(new Swift_Mime_SimpleHeaderFactory($headerEncoder, $paramEncoder, $emailValidator));
    $headers->addTextHeader('X-Transport', 'smtp://internal');

    return $headers;
}
```

### 1b. Implement changes

**File:** `lib/classes/Swift/Signers/DKIMSigner.php`

1. Change the `$ignoredHeaders` default to include `x-transport`:

```php
protected $ignoredHeaders = ['return-path' => true, 'x-transport' => true];
```

2. In `ignoreHeader()`, prevent ignoring `from`:

```php
public function ignoreHeader($header_name)
{
    $lower = \strtolower($header_name ?? '');
    if ('from' === $lower) {
        return $this;
    }
    $this->ignoredHeaders[$lower] = true;

    return $this;
}
```

3. In `addSignature()`, add `q=dns/txt` to the params array right after `'v' => '1'`:

```php
$params = [
    'v' => '1',
    'q' => 'dns/txt',
    'a' => $this->hashAlgorithm,
    // ... rest unchanged
];
```

### 1c. Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php
```

Note: Existing signature-comparison tests (`testSigningSHA1`, `testSigning256`, etc.) will break because the `q=dns/txt` tag changes the signature output. These expected values must be regenerated by running the signer with the new code, capturing the output, and updating the assertions. The approach:

```bash
# Run the tests, capture actual values from failure output, update assertions
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php 2>&1 | head -100
```

Then update each `assertEquals` with the new actual signature values.

### 1d. Commit

```bash
git add lib/classes/Swift/Signers/DKIMSigner.php tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "feat(dkim): add q=dns/txt tag, ignore x-transport, prevent ignoring From header

Aligns DKIMSigner with Symfony's DkimSigner behavior and RFC 6376 Section 5.4
requirements. The From header is mandatory for DKIM signatures and can no longer
be excluded via ignoreHeader().

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Fix empty-body canonicalization bug (RFC 6376 Section 3.4.3/3.4.4)

**Why:** RFC 6376 Section 3.4.3 (simple body canon) states: "If the body is null (not even one CRLF), a CRLF is added." The current implementation does not handle a zero-length body correctly under simple canonicalization. Symfony's implementation explicitly adds `\r\n` when `!$relaxed && 0 === $length`.

### 2a. Write failing tests

**File:** `tests/unit/Swift/Signers/DKIMSignerTest.php`

```php
public function testEmptyBodySimpleCanon()
{
    $headerSet = $this->createHeaderSet();
    $signer    = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->setBodyCanon('simple');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    // Write nothing -- empty body
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    // RFC 6376 3.4.3: empty body gets CRLF appended, SHA-256 of "\r\n"
    $expectedBh = \base64_encode(\hash('sha256', "\r\n", true));
    $this->assertStringContainsString('bh='.$expectedBh, $sig->getValue());
}

public function testEmptyBodyRelaxedCanon()
{
    $headerSet = $this->createHeaderSet();
    $signer    = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->setBodyCanon('relaxed');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    // Write nothing -- empty body
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    // RFC 6376 3.4.4: empty body in relaxed = hash of empty string
    $expectedBh = \base64_encode(\hash('sha256', '', true));
    $this->assertStringContainsString('bh='.$expectedBh, $sig->getValue());
}
```

### 2b. Implement the fix

**File:** `lib/classes/Swift/Signers/DKIMSigner.php`

Modify `endOfBody()`:

```php
protected function endOfBody()
{
    // Add trailing line return if last line is non-empty
    if (\strlen($this->bodyCanonLine) > 0) {
        $this->addToBodyHash("\r\n");
    }

    // RFC 6376 Section 3.4.3: If the body is null (not even one CRLF),
    // a CRLF is added for simple canonicalization.
    if ('simple' === $this->bodyCanon && 0 === $this->bodyLen) {
        $this->addToBodyHash("\r\n");
    }

    $this->bodyHash = \hash_final($this->bodyHashHandler, true);
}
```

### 2c. Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php --filter="testEmptyBody"
```

### 2d. Commit

```bash
git add lib/classes/Swift/Signers/DKIMSigner.php tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "fix(dkim): add CRLF for empty body in simple canonicalization per RFC 6376

Section 3.4.3 requires that a null body (no content at all) has a single
CRLF appended before hashing. Relaxed canon correctly hashes the empty
string. This fixes DKIM verification failures for empty-body messages.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Add Ed25519-SHA256 support (RFC 8463)

**Why:** RFC 8463 defines `ed25519-sha256` as a new DKIM signing algorithm. Ed25519 keys are much smaller (256-bit vs 2048-bit RSA) and cryptographically stronger. Symfony declares the constant but throws at runtime. We will implement actual signing.

**Prerequisite:** PHP 8.1+ with OpenSSL 1.1.1+ compiled with Ed25519 support. The `sodium` extension (bundled since PHP 7.2) provides `sodium_crypto_sign_detached()` as a fallback.

### 3a. Write failing tests

**File:** `tests/unit/Swift/Signers/DKIMSignerTest.php`

```php
public function testSetHashAlgorithmAcceptsEd25519()
{
    $signer = new Swift_Signers_DKIMSigner(
        'dummy-key-not-used-here',
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $result = $signer->setHashAlgorithm('ed25519-sha256');
    $this->assertSame($signer, $result);
}

public function testSetHashAlgorithmRejectsUnknown()
{
    $this->expectException(Swift_SwiftException::class);
    $signer = new Swift_Signers_DKIMSigner(
        'dummy-key-not-used-here',
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-md5');
}

public function testEd25519SigningProducesValidSignature()
{
    if (!\function_exists('sodium_crypto_sign_keypair')) {
        $this->markTestSkipped('sodium extension required for Ed25519 tests');
    }

    // Generate an Ed25519 keypair for testing
    $keypair    = \sodium_crypto_sign_keypair();
    $secretKey  = \sodium_crypto_sign_secretkey($keypair);
    $publicKey  = \sodium_crypto_sign_publickey($keypair);

    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        $secretKey,
        'dummy.nxdomain.be',
        'ed25519selector'
    );
    $signer->setHashAlgorithm('ed25519-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);

    $this->assertTrue($headerSet->has('DKIM-Signature'));
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    $this->assertStringContainsString('a=ed25519-sha256', $sig->getValue());
    // Ed25519 signatures are always 64 bytes = 88 base64 chars (with padding)
    $this->assertMatchesRegularExpression('/b=.{10,}/', $sig->getValue());
}

public function testEd25519AlwaysUsesSha256ForBody()
{
    if (!\function_exists('sodium_crypto_sign_keypair')) {
        $this->markTestSkipped('sodium extension required for Ed25519 tests');
    }

    $keypair   = \sodium_crypto_sign_keypair();
    $secretKey = \sodium_crypto_sign_secretkey($keypair);

    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        $secretKey,
        'dummy.nxdomain.be',
        'ed25519selector'
    );
    $signer->setHashAlgorithm('ed25519-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);

    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    // Body hash should be SHA-256 of "Hello World\r\n" under simple canon
    $expectedBh = \base64_encode(\hash('sha256', "Hello World\r\n", true));
    $this->assertStringContainsString('bh='.$expectedBh, $sig->getValue());
}
```

### 3b. Implement Ed25519-SHA256 signing

**File:** `lib/classes/Swift/Signers/DKIMSigner.php`

1. Update `setHashAlgorithm()` to accept `ed25519-sha256`:

```php
public function setHashAlgorithm($hash)
{
    switch ($hash) {
        case 'rsa-sha1':
            $this->hashAlgorithm = 'rsa-sha1';
            break;
        case 'rsa-sha256':
            $this->hashAlgorithm = 'rsa-sha256';
            if (!\defined('OPENSSL_ALGO_SHA256')) {
                throw new Swift_SwiftException('Unable to set sha256 as it is not supported by OpenSSL.');
            }
            break;
        case 'ed25519-sha256':
            if (!\function_exists('sodium_crypto_sign_detached')) {
                throw new Swift_SwiftException('The sodium extension is required for ed25519-sha256 DKIM signing.');
            }
            $this->hashAlgorithm = 'ed25519-sha256';
            break;
        default:
            throw new Swift_SwiftException(
                \sprintf('Unable to set the hash algorithm, must be one of rsa-sha1, rsa-sha256, or ed25519-sha256 (%s given).', $hash)
            );
    }

    return $this;
}
```

2. Update `startBody()` to use sha256 for Ed25519:

```php
public function startBody()
{
    switch ($this->hashAlgorithm) {
        case 'rsa-sha256':
        case 'ed25519-sha256':
            $this->bodyHashHandler = \hash_init('sha256');
            break;
        case 'rsa-sha1':
            $this->bodyHashHandler = \hash_init('sha1');
            break;
    }
    $this->bodyCanonLine = '';
}
```

3. Update `getEncryptedHash()` to handle Ed25519:

```php
private function getEncryptedHash()
{
    $signature = '';

    if ('ed25519-sha256' === $this->hashAlgorithm) {
        // Ed25519 uses sodium_crypto_sign_detached with the raw secret key.
        // The header canon data is first hashed with SHA-256, then signed.
        $hash = \hash('sha256', $this->headerCanonData, true);
        $signature = \sodium_crypto_sign_detached($hash, $this->privateKey);

        return $signature;
    }

    switch ($this->hashAlgorithm) {
        case 'rsa-sha1':
            $algorithm = OPENSSL_ALGO_SHA1;
            break;
        case 'rsa-sha256':
        default:
            $algorithm = OPENSSL_ALGO_SHA256;
            break;
    }
    $pkeyId = \openssl_pkey_get_private($this->privateKey, $this->passphrase);
    if (!$pkeyId) {
        throw new Swift_SwiftException('Unable to load DKIM Private Key ['.\openssl_error_string().']');
    }
    if (\openssl_sign($this->headerCanonData, $signature, $pkeyId, $algorithm)) {
        return $signature;
    }
    throw new Swift_SwiftException('Unable to sign DKIM Hash ['.\openssl_error_string().']');
}
```

### 3c. Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php --filter="Ed25519"
```

### 3d. Commit

```bash
git add lib/classes/Swift/Signers/DKIMSigner.php tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "feat(dkim): add Ed25519-SHA256 signing support per RFC 8463

Uses the sodium extension (bundled since PHP 7.2) for Ed25519 signing
via sodium_crypto_sign_detached(). The header data is SHA-256 hashed
before signing per the RFC 8463 PureEdDSA procedure. Body hashing
always uses SHA-256 for ed25519-sha256 as required by the spec.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Add oversigning protection for critical headers

**Why:** DKIM oversigning prevents replay attacks by signing critical headers an extra time (for a non-existent instance), so that any header added post-signing breaks verification. This is a best practice recommended by major email security vendors and addresses a real-world attack vector (e.g., the 2024 Gmail DKIM replay attack).

### 4a. Write failing tests

**File:** `tests/unit/Swift/Signers/DKIMSignerTest.php`

```php
public function testOversigningDisabledByDefault()
{
    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    $value = $sig->getValue();
    // Extract h= value
    \preg_match('/h=([^;]+)/', $value, $matches);
    $signedHeaders = \array_map('trim', \explode(':', $matches[1]));
    // From should appear exactly once (not oversigned)
    $fromCount = \array_count_values($signedHeaders)['From'] ?? 0;
    $this->assertEquals(1, $fromCount);
}

public function testOversigningAddsExtraHeaderInstances()
{
    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    $signer->setOversigning(true);
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    $value = $sig->getValue();
    // Extract h= value
    \preg_match('/h=([^;]+)/', $value, $matches);
    $signedHeaders = \array_map('trim', \explode(':', $matches[1]));
    $headerCounts  = \array_count_values($signedHeaders);
    // From, Subject, To should each appear twice (once real + once oversigned)
    $this->assertEquals(2, $headerCounts['From'] ?? 0, 'From should be oversigned');
    $this->assertEquals(2, $headerCounts['Subject'] ?? 0, 'Subject should be oversigned');
}
```

### 4b. Implement oversigning

**File:** `lib/classes/Swift/Signers/DKIMSigner.php`

1. Add new property:

```php
/**
 * Whether to oversign critical headers to prevent replay attacks.
 *
 * When enabled, From, To, Subject, Date, Cc, Reply-To, and Message-ID
 * are signed an extra time (for a non-existent instance), so any header
 * added post-signing breaks DKIM verification.
 */
protected bool $oversigning = false;

/**
 * Headers to oversign when oversigning is enabled.
 */
private const OVERSIGN_HEADERS = [
    'from', 'to', 'subject', 'date', 'cc', 'reply-to', 'message-id',
];
```

2. Add setter method:

```php
/**
 * Enable or disable oversigning of critical headers.
 *
 * Oversigning adds an extra instance of critical headers (From, To,
 * Subject, Date, Cc, Reply-To, Message-ID) to the h= tag, preventing
 * replay attacks where an attacker adds a second instance of these
 * headers after signing.
 *
 * @param bool $oversign
 *
 * @return $this
 */
public function setOversigning(bool $oversign)
{
    $this->oversigning = $oversign;

    return $this;
}
```

3. Modify `addSignature()` to include oversigned headers in the `h=` tag. After computing `$params['h']`, if oversigning is enabled, append extra instances:

```php
// In addSignature(), after building $this->signedHeaders via setHeaders():
$headerList = $this->signedHeaders;
if ($this->oversigning) {
    $lowerSigned = \array_map('strtolower', $headerList);
    foreach (self::OVERSIGN_HEADERS as $oh) {
        if (\in_array($oh, $lowerSigned, true)) {
            // Find the original-case version to keep formatting consistent
            $idx = \array_search($oh, $lowerSigned, true);
            $headerList[] = $this->signedHeaders[$idx];
        }
    }
}
$params = [
    // ...
    'h' => \implode(': ', $headerList),
    // ...
];
```

### 4c. Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php --filter="versign"
```

### 4d. Commit

```bash
git add lib/classes/Swift/Signers/DKIMSigner.php tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "feat(dkim): add oversigning protection for critical headers

Adds setOversigning(true) which signs From, To, Subject, Date, Cc,
Reply-To, and Message-ID headers an extra time. This prevents replay
attacks where an attacker adds a duplicate header instance after the
message has been signed, since the extra h= entry would fail
verification if a new header is present.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Modernize with PHP 8.1+ features

**Why:** The codebase requires PHP 8.1+. The DKIMSigner uses untyped properties, no readonly where appropriate, and the `$passphrase` constructor param should use `#[\SensitiveParameter]`. The `c=` tag handling should always emit header/body canon explicitly per Symfony's behavior.

### 5a. Write tests for constructor validation

**File:** `tests/unit/Swift/Signers/DKIMSignerTest.php`

```php
public function testConstructorValidatesRsaPrivateKey()
{
    $this->expectException(Swift_SwiftException::class);
    $this->expectExceptionMessage('Unable to load DKIM Private Key');
    $signer = new Swift_Signers_DKIMSigner(
        'not-a-valid-key',
        'dummy.nxdomain.be',
        'dummySelector'
    );
    // RSA keys are validated at construction time
}

public function testConstructorAcceptsValidRsaKey()
{
    $signer = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $this->assertInstanceOf(Swift_Signers_DKIMSigner::class, $signer);
}

public function testCTagAlwaysEmitted()
{
    $headerSet      = $this->createHeaderSet();
    $messageContent = 'Hello World';
    $signer         = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );
    $signer->setHashAlgorithm('rsa-sha256');
    $signer->setSignatureTimestamp('1299879181');
    // Both simple (the default) -- previously c= was omitted
    $signer->reset();
    $signer->setHeaders($headerSet);
    $signer->startBody();
    $signer->write($messageContent);
    $signer->endBody();
    $signer->addSignature($headerSet);
    $dkim = $headerSet->getAll('DKIM-Signature');
    $sig  = \reset($dkim);
    $this->assertStringContainsString('c=simple/simple', $sig->getValue());
}
```

### 5b. Implement modernization

**File:** `lib/classes/Swift/Signers/DKIMSigner.php`

1. Add typed properties throughout:

```php
protected string $privateKey;
protected string $domainName;
protected string $selector;
private string $passphrase = '';
protected string $hashAlgorithm = 'rsa-sha256';
protected string $bodyCanon = 'simple';
protected string $headerCanon = 'simple';
protected array $ignoredHeaders = ['return-path' => true, 'x-transport' => true];
protected string $signerIdentity;
protected int $bodyLen = 0;
protected int $maxLen = PHP_INT_MAX;
protected bool $showLen = false;
protected int|bool $signatureTimestamp = true;
protected int|false $signatureExpiration = false;
protected bool $debugHeaders = false;
protected array $signedHeaders = [];
protected bool $oversigning = false;
```

2. Add `#[\SensitiveParameter]` to the passphrase constructor parameter:

```php
public function __construct(
    string $privateKey,
    string $domainName,
    string $selector,
    #[\SensitiveParameter]
    string $passphrase = '',
)
```

3. Validate RSA keys at construction time (skip for Ed25519 raw keys):

```php
public function __construct(
    string $privateKey,
    string $domainName,
    string $selector,
    #[\SensitiveParameter]
    string $passphrase = '',
) {
    $this->domainName     = $domainName;
    $this->signerIdentity = '@'.$domainName;
    $this->selector       = $selector;
    $this->passphrase     = $passphrase;

    // Try to load as RSA key; if it fails and it's a raw binary key
    // (64 bytes for Ed25519 secret key), store it for later Ed25519 use.
    if (\strlen($privateKey) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
        && !\str_contains($privateKey, '-----BEGIN')) {
        // Raw Ed25519 secret key
        $this->privateKey = $privateKey;
    } else {
        $pkeyId = \openssl_pkey_get_private($privateKey, $passphrase);
        if (!$pkeyId) {
            throw new Swift_SwiftException('Unable to load DKIM Private Key ['.\openssl_error_string().']');
        }
        $this->privateKey = $privateKey;
    }
}
```

4. Always emit `c=` tag in `addSignature()`. Replace the conditional `c=` logic:

```php
// Replace:
// if ('simple' != $this->bodyCanon) { ... } elseif ('simple' != $this->headerCanon) { ... }
// With:
$params['c'] = $this->headerCanon.'/'.$this->bodyCanon;
```

### 5c. Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php
```

Note: Changing `c=` to always emit will again alter signature outputs for tests that expected `c=` to be absent when both canons are `simple`. Update expected values in the existing tests (`testSigningSHA1`, `testSigning256`) by capturing the new actual output.

### 5d. Run code style fixer

```bash
composer php-cs-fixer
```

### 5e. Commit

```bash
git add lib/classes/Swift/Signers/DKIMSigner.php tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "refactor(dkim): modernize DKIMSigner with typed properties and constructor validation

- Add typed properties throughout (PHP 8.1+)
- Validate RSA private key at construction time (fail-fast)
- Add #[\SensitiveParameter] to passphrase parameter
- Always emit c= canonicalization tag in DKIM-Signature header
- Detect Ed25519 raw keys (64-byte sodium secret keys) vs RSA PEM keys

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Deprecate rsa-sha1 with runtime notice

**Why:** RFC 8301 (published 2018) explicitly states that signers MUST NOT sign with rsa-sha1. Symfony's DkimSigner removed it entirely. We keep it for backward compatibility but add a deprecation notice.

### 6a. Write test

**File:** `tests/unit/Swift/Signers/DKIMSignerTest.php`

```php
public function testRsaSha1TriggersDeprecation()
{
    $signer = new Swift_Signers_DKIMSigner(
        \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
        'dummy.nxdomain.be',
        'dummySelector'
    );

    $this->expectDeprecation();
    $this->expectDeprecationMessage('rsa-sha1 is deprecated');
    $signer->setHashAlgorithm('rsa-sha1');
}
```

### 6b. Implement deprecation

**File:** `lib/classes/Swift/Signers/DKIMSigner.php`

In `setHashAlgorithm()`, add a deprecation trigger for `rsa-sha1`:

```php
case 'rsa-sha1':
    @\trigger_error(
        'rsa-sha1 is deprecated per RFC 8301 and will be removed in a future version. Use rsa-sha256 or ed25519-sha256 instead.',
        \E_USER_DEPRECATED
    );
    $this->hashAlgorithm = 'rsa-sha1';
    break;
```

### 6c. Verify

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php --filter="Sha1Triggers"
```

### 6d. Commit

```bash
git add lib/classes/Swift/Signers/DKIMSigner.php tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "deprecate(dkim): emit E_USER_DEPRECATED for rsa-sha1 per RFC 8301

RFC 8301 states signers MUST NOT use rsa-sha1. The algorithm is kept
for backward compatibility but now triggers a deprecation notice
recommending rsa-sha256 or ed25519-sha256 instead.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Run full test suite and fix any regressions

**Why:** Tasks 1-6 changed signature output and constructor behavior. All existing tests must pass.

### 7a. Run full unit test suite

```bash
vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose
```

### 7b. Fix any broken assertions

Existing tests like `testSigningSHA1`, `testSigning256`, `testSigningRelaxedRelaxed256`, `testSigningRelaxedSimple256`, `testSigningSimpleRelaxed256` all assert exact signature values. These will break because of:

1. Added `q=dns/txt` tag (Task 1)
2. Always-emitted `c=` tag (Task 5)

For each failing test, run it individually, capture the actual signature from the failure output, and update the expected value:

```bash
vendor/bin/simple-phpunit tests/unit/Swift/Signers/DKIMSignerTest.php --filter="testSigning256" 2>&1
```

Update each `assertEquals` call with the new actual value. The signatures are deterministic given the same key, timestamp, and content, so the new values are stable.

### 7c. Run code style fixer

```bash
composer php-cs-fixer
```

### 7d. Final full run

```bash
vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose
```

### 7e. Commit

```bash
git add tests/unit/Swift/Signers/DKIMSignerTest.php
git commit -m "test(dkim): update expected signature values after DKIMSigner modernization

Updates expected signature assertions to account for the new q=dns/txt
tag and always-emitted c= canonicalization tag in DKIM-Signature headers.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## File Change Summary

| File | Action |
|-|-|
| `lib/classes/Swift/Signers/DKIMSigner.php` | Modified (all tasks) |
| `tests/unit/Swift/Signers/DKIMSignerTest.php` | Modified (all tasks) |

## References

- [RFC 6376 - DomainKeys Identified Mail (DKIM) Signatures](https://datatracker.ietf.org/doc/html/rfc6376)
- [RFC 8301 - Cryptographic Algorithm and Key Usage Update to DKIM](https://datatracker.ietf.org/doc/html/rfc8301)
- [RFC 8463 - Ed25519-SHA256 for DKIM](https://datatracker.ietf.org/doc/html/rfc8463)
- [Symfony DkimSigner source (symfony/mime 7.2)](https://github.com/symfony/mime/blob/7.2/Crypto/DkimSigner.php)
- [DKIM Oversigning Best Practices (Bird)](https://bird.com/blog/dkim-oversigning-to-help-avoid-replay-attacks)
- [DKIM Ed25519 Adoption (URIports)](https://www.uriports.com/blog/dkim-ed25519-adoption/)
