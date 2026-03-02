<?php

use Egulias\EmailValidator\EmailValidator;

class Swift_Signers_DKIMSignerTest extends SwiftMailerTestCase
{
    public function testBasicSigningHeaderManipulation()
    {
        $headers        = $this->createHeaders();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(\file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'), 'dummy.nxdomain.be', 'dummySelector');
        /* @var $signer Swift_Signers_HeaderSigner */
        $altered = $signer->getAlteredHeaders();
        $signer->reset();
        // Headers
        $signer->setHeaders($headers);
        // Body
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        // Signing
        $signer->addSignature($headers);
    }

    // SHA1 Signing
    public function testSigningSHA1()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(\file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'), 'dummy.nxdomain.be', 'dummySelector');
        $signer->setHashAlgorithm('rsa-sha1');
        $signer->setSignatureTimestamp('1299879181');
        $altered = $signer->getAlteredHeaders();
        $this->assertEquals(['DKIM-Signature'], $altered);
        $signer->reset();
        $signer->setHeaders($headerSet);
        $this->assertFalse($headerSet->has('DKIM-Signature'));
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DKIM-Signature'));
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertEquals($sig->getValue(), 'v=1; q=dns/txt; a=rsa-sha1; bh=wlbYcY9O9OPInGJ4D0E/rGsvMLE=; d=dummy.nxdomain.be; h=; i=@dummy.nxdomain.be; s=dummySelector; c=simple/simple; t=1299879181; b=mXaWZGkmLsUyQzoOQLBHFULU9bK3JpckZ99AGt7E/CGOTNgUkPmi69Kj1pCeLYtj3wKve48dI hqmmaeVWVYHAGASm2WbFc27idM6hPB/iqV1BqeeBaO+PnRecGQ9GmWvfhaUzxEMvDrbiiR35J plhRhbisw4icOKdBPWSPKLKDE=');
    }

    // SHA256 Signing
    public function testSigning256()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(\file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'), 'dummy.nxdomain.be', 'dummySelector');
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $altered = $signer->getAlteredHeaders();
        $this->assertEquals(['DKIM-Signature'], $altered);
        $signer->reset();
        $signer->setHeaders($headerSet);
        $this->assertFalse($headerSet->has('DKIM-Signature'));
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DKIM-Signature'));
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertEquals($sig->getValue(), 'v=1; q=dns/txt; a=rsa-sha256; bh=f+W+hu8dIhf2VAni89o8lF6WKTXi7nViA4RrMdpD5/U=; d=dummy.nxdomain.be; h=; i=@dummy.nxdomain.be; s=dummySelector; c=simple/simple; t=1299879181; b=rTJ+UhdTPaQl7qAVytvMUehTPCMQ5rkllj4DagUta0stO+Du6C3gw+TeZc6AJRpHa0AdQekW5 Ca7vm7dU3HFIpmlBsqRJlPTBwMH9UNHnivA85rdl9S1FpoG0JvOdFww5PLD43Jn5olcOfcU1R +MrZ20ukFy9MOw7tJhU3YvwZk=');
    }

    // Relaxed/Relaxed Hash Signing
    public function testSigningRelaxedRelaxed256()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(\file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'), 'dummy.nxdomain.be', 'dummySelector');
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->setBodyCanon('relaxed');
        $signer->setHeaderCanon('relaxed');
        $altered = $signer->getAlteredHeaders();
        $this->assertEquals(['DKIM-Signature'], $altered);
        $signer->reset();
        $signer->setHeaders($headerSet);
        $this->assertFalse($headerSet->has('DKIM-Signature'));
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DKIM-Signature'));
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertEquals($sig->getValue(), 'v=1; q=dns/txt; a=rsa-sha256; bh=f+W+hu8dIhf2VAni89o8lF6WKTXi7nViA4RrMdpD5/U=; d=dummy.nxdomain.be; h=; i=@dummy.nxdomain.be; s=dummySelector; c=relaxed/relaxed; t=1299879181; b=w5OGnqxz+68TQFhfOv/6bmMQCZbLUI2yKCSax+RZw6inOToRBcXNej/JqbXJg8cPqK2n94e9E NhRxHdcPQKswuILsFJoMQvXc6Zm1jCgW4JZKy8ghV58O5P5PXzstHnf05s7zTlArBmZuURgks 8v5txRVl8hYXO5E4vpzeLJnak=');
    }

    // Relaxed/Simple Hash Signing
    public function testSigningRelaxedSimple256()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(\file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'), 'dummy.nxdomain.be', 'dummySelector');
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->setHeaderCanon('relaxed');
        $altered = $signer->getAlteredHeaders();
        $this->assertEquals(['DKIM-Signature'], $altered);
        $signer->reset();
        $signer->setHeaders($headerSet);
        $this->assertFalse($headerSet->has('DKIM-Signature'));
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DKIM-Signature'));
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertEquals($sig->getValue(), 'v=1; q=dns/txt; a=rsa-sha256; bh=f+W+hu8dIhf2VAni89o8lF6WKTXi7nViA4RrMdpD5/U=; d=dummy.nxdomain.be; h=; i=@dummy.nxdomain.be; s=dummySelector; c=relaxed/simple; t=1299879181; b=iotFIBu8nAK30NBzpc5rLRnErDiUSbdbgjA9ChC4spuuLrOOH0s3H0xpisyB/ZM87gpn8yEef 3Ti4bALS2qlbLUea4dtFSR94viBR8laB4A+VtkqnpNXn98xgUSNwNtqmFmt9QiBOR1lgkM5kv 074s+Qk5R7iYzNLebZB8GmgjI=');
    }

    // Simple/Relaxed Hash Signing
    public function testSigningSimpleRelaxed256()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(\file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'), 'dummy.nxdomain.be', 'dummySelector');
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->setBodyCanon('relaxed');
        $altered = $signer->getAlteredHeaders();
        $this->assertEquals(['DKIM-Signature'], $altered);
        $signer->reset();
        $signer->setHeaders($headerSet);
        $this->assertFalse($headerSet->has('DKIM-Signature'));
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DKIM-Signature'));
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertEquals($sig->getValue(), 'v=1; q=dns/txt; a=rsa-sha256; bh=f+W+hu8dIhf2VAni89o8lF6WKTXi7nViA4RrMdpD5/U=; d=dummy.nxdomain.be; h=; i=@dummy.nxdomain.be; s=dummySelector; c=simple/relaxed; t=1299879181; b=k/y8Cyt5YylUbo2Ey0iXMeOO/KBV5lMClErTPeKRQ1Q5Y3X4UsbBldbta8ZxxIj/cpAVjheDk v/t0OMZLrbCxCVXnB+d2/aiz7w5Lnru2E2EFaVM2DmXVEIb6KjCGmpAJFZn+AKZtSpramk4zm Z80Df07CsmItnJE/A+J5m1nnw=');
    }

    public function testRsaSha1TriggersDeprecation()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );

        $triggered       = false;
        $previousHandler = \set_error_handler(static function (int $errno, string $errstr) use (&$triggered) {
            if (\E_USER_DEPRECATED === $errno && \str_contains($errstr, 'rsa-sha1 is deprecated')) {
                $triggered = true;

                return true;
            }

            return false;
        });
        try {
            $signer->setHashAlgorithm('rsa-sha1');
        } finally {
            \restore_error_handler();
        }
        $this->assertTrue($triggered, 'Expected E_USER_DEPRECATED to be triggered for rsa-sha1');
    }

    public function testConstructorValidatesRsaPrivateKey()
    {
        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Unable to load DKIM Private Key');
        $signer = new Swift_Signers_DKIMSigner(
            'not-a-valid-key',
            'dummy.nxdomain.be',
            'dummySelector',
        );
        // RSA keys are validated at construction time
    }

    public function testConstructorAcceptsValidRsaKey()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
            'dummySelector',
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

    public function testOversigningDisabledByDefault()
    {
        $headerSet      = $this->createHeaderSetWithFrom();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $dkim  = $headerSet->getAll('DKIM-Signature');
        $sig   = \reset($dkim);
        $value = $sig->getValue();
        // Extract h= value (use \b to avoid matching bh=)
        \preg_match('/\bh=([^;]+)/', $value, $matches);
        $signedHeaders = \array_map('trim', \explode(':', $matches[1]));
        // From should appear exactly once (not oversigned)
        $fromCount = \array_count_values($signedHeaders)['From'] ?? 0;
        $this->assertEquals(1, $fromCount);
    }

    public function testOversigningAddsExtraHeaderInstances()
    {
        $headerSet      = $this->createHeaderSetWithFrom();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $dkim  = $headerSet->getAll('DKIM-Signature');
        $sig   = \reset($dkim);
        $value = $sig->getValue();
        // Extract h= value (use \b to avoid matching bh=)
        \preg_match('/\bh=([^;]+)/', $value, $matches);
        $signedHeaders = \array_map('trim', \explode(':', $matches[1]));
        $headerCounts  = \array_count_values($signedHeaders);
        // From, Subject, To should each appear twice (once real + once oversigned)
        $this->assertEquals(2, $headerCounts['From'] ?? 0, 'From should be oversigned');
        $this->assertEquals(2, $headerCounts['Subject'] ?? 0, 'Subject should be oversigned');
    }

    public function testSetHashAlgorithmAcceptsEd25519()
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 tests');
        }
        $keypair   = \sodium_crypto_sign_keypair();
        $secretKey = \sodium_crypto_sign_secretkey($keypair);
        $signer    = new Swift_Signers_DKIMSigner(
            $secretKey,
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->setHashAlgorithm('ed25519-sha256');
        $this->assertSame($signer, $result);
    }

    public function testSetHashAlgorithmRejectsUnknown()
    {
        $this->expectException(Swift_SwiftException::class);
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-md5');
    }

    public function testEd25519SigningProducesValidSignature()
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 tests');
        }

        // Generate an Ed25519 keypair for testing
        $keypair   = \sodium_crypto_sign_keypair();
        $secretKey = \sodium_crypto_sign_secretkey($keypair);
        $publicKey = \sodium_crypto_sign_publickey($keypair);

        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            $secretKey,
            'dummy.nxdomain.be',
            'ed25519selector',
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

        // Extract the b= value and verify it is a valid Ed25519 signature (64 bytes)
        \preg_match('/\bb=([A-Za-z0-9+\/= ]+)$/', $sig->getValue(), $bMatch);
        $this->assertNotEmpty($bMatch, 'b= tag must be present in DKIM-Signature');
        $rawSignature = \base64_decode(\str_replace(' ', '', $bMatch[1]), true);
        $this->assertNotFalse($rawSignature, 'b= value must be valid base64');
        $this->assertSame(64, \strlen($rawSignature), 'Ed25519 signature must be exactly 64 bytes');
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
            'ed25519selector',
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
        // Body hash must match the SHA-256 hash (same bh= as rsa-sha256 tests)
        $this->assertStringContainsString('bh=f+W+hu8dIhf2VAni89o8lF6WKTXi7nViA4RrMdpD5/U=', $sig->getValue());
    }

    public function testEmptyBodySimpleCanon()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
            'dummySelector',
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

    public function testSignatureContainsQueryMethodTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $headerSet      = $this->createHeaderSetWithXTransport();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $headerSet      = $this->createHeaderSetWithFrom();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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

    public function testGetAlteredHeadersReturnsDKIMSignature()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $this->assertEquals(['DKIM-Signature'], $signer->getAlteredHeaders());
    }

    public function testResetAllowsReuse()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');

        // First signature
        $headerSet1 = $this->createHeaderSet();
        $signer->reset();
        $signer->setHeaders($headerSet1);
        $signer->startBody();
        $signer->write('Body 1');
        $signer->endBody();
        $signer->addSignature($headerSet1);
        $this->assertTrue($headerSet1->has('DKIM-Signature'));

        // Second signature after reset
        $headerSet2 = $this->createHeaderSet();
        $signer->reset();
        $signer->setHeaders($headerSet2);
        $signer->startBody();
        $signer->write('Body 2');
        $signer->endBody();
        $signer->addSignature($headerSet2);
        $this->assertTrue($headerSet2->has('DKIM-Signature'));
    }

    public function testSignatureContainsDomainTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'example.com',
            'selector1',
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
        $this->assertStringContainsString('d=example.com', $sig->getValue());
    }

    public function testSignatureContainsSelectorTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'example.com',
            'mySelector',
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
        $this->assertStringContainsString('s=mySelector', $sig->getValue());
    }

    public function testSignatureContainsTimestamp()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1234567890');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertStringContainsString('t=1234567890', $sig->getValue());
    }

    public function testSignatureContainsVersionTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('v=1', $sig->getValue());
    }

    public function testSignatureContainsIdentityTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('i=@dummy.nxdomain.be', $sig->getValue());
    }

    public function testSignatureContainsBodyHashTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('bh=', $sig->getValue());
    }

    public function testSignatureContainsSignatureTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertMatchesRegularExpression('/b=[A-Za-z0-9+\/= ]+/', $sig->getValue());
    }

    public function testMultipleWriteChunks()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write('Hello ');
        $signer->write('World');
        $signer->endBody();
        $signer->addSignature($headerSet);

        $this->assertTrue($headerSet->has('DKIM-Signature'));
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        // Body hash should be the same as single-write "Hello World"
        $this->assertStringContainsString('bh=f+W+hu8dIhf2VAni89o8lF6WKTXi7nViA4RrMdpD5/U=', $sig->getValue());
    }

    public function testSetHashAlgorithmReturnsSelf()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->setHashAlgorithm('rsa-sha256');
        $this->assertSame($signer, $result);
    }

    public function testSetBodyCanonReturnsSelf()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->setBodyCanon('relaxed');
        $this->assertSame($signer, $result);
    }

    public function testSetHeaderCanonReturnsSelf()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->setHeaderCanon('relaxed');
        $this->assertSame($signer, $result);
    }

    public function testIgnoreHeaderReturnsSelf()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->ignoreHeader('X-Mailer');
        $this->assertSame($signer, $result);
    }

    public function testCustomHeaderIsIgnored()
    {
        $headerSet = $this->createHeaderSetWithXTransport();
        $signer    = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->ignoreHeader('X-Transport');
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write('Test');
        $signer->endBody();
        $signer->addSignature($headerSet);
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertStringNotContainsString('X-Transport', $sig->getValue());
    }

    public function testSignatureDomainTagMatchesDomain()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('d=dummy.nxdomain.be', $sig->getValue());
    }

    public function testSignatureSelectorTagMatchesSelector()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('s=dummySelector', $sig->getValue());
    }

    public function testSignatureContainsAlgorithmTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('a=rsa-sha256', $sig->getValue());
    }

    public function testSignatureContainsCanonTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setBodyCanon('relaxed');
        $signer->setHeaderCanon('relaxed');
        $signer->setSignatureTimestamp('1299879181');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write($messageContent);
        $signer->endBody();
        $signer->addSignature($headerSet);
        $dkim = $headerSet->getAll('DKIM-Signature');
        $sig  = \reset($dkim);
        $this->assertStringContainsString('c=relaxed/relaxed', $sig->getValue());
    }

    public function testSignatureContainsTimestampTag()
    {
        $headerSet      = $this->createHeaderSet();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        $this->assertStringContainsString('t=1299879181', $sig->getValue());
    }

    public function testSetSignatureTimestampReturnsSelf()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->setSignatureTimestamp('1299879181');
        $this->assertSame($signer, $result);
    }

    public function testSetSignatureExpirationReturnsSelf()
    {
        $signer = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $result = $signer->setSignatureExpiration('1299999999');
        $this->assertSame($signer, $result);
    }

    public function testSignatureWithFromHeader()
    {
        $headerSet      = $this->createHeaderSetWithFrom();
        $messageContent = 'Hello World';
        $signer         = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
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
        // h= tag should include signed headers
        $this->assertStringContainsString('h=', $sig->getValue());
    }

    public function testEmptyBodyProducesValidSignature()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = new Swift_Signers_DKIMSigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->setHashAlgorithm('rsa-sha256');
        $signer->setSignatureTimestamp('1299879181');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write('');
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DKIM-Signature'));
    }

    private function createHeaderSet()
    {
        $cache          = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
        $factory        = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
        $contentEncoder = new Swift_Mime_ContentEncoder_Base64ContentEncoder();

        $headerEncoder  = new Swift_Mime_HeaderEncoder_QpHeaderEncoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $paramEncoder   = new Swift_Encoder_Rfc2231Encoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $emailValidator = new EmailValidator();
        $headers        = new Swift_Mime_SimpleHeaderSet(new Swift_Mime_SimpleHeaderFactory($headerEncoder, $paramEncoder, $emailValidator));

        return $headers;
    }

    private function createHeaderSetWithFrom()
    {
        $cache          = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
        $factory        = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
        $contentEncoder = new Swift_Mime_ContentEncoder_Base64ContentEncoder();

        $headerEncoder  = new Swift_Mime_HeaderEncoder_QpHeaderEncoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $paramEncoder   = new Swift_Encoder_Rfc2231Encoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $emailValidator = new EmailValidator();
        $headerFactory  = new Swift_Mime_SimpleHeaderFactory($headerEncoder, $paramEncoder, $emailValidator);
        $headers        = new Swift_Mime_SimpleHeaderSet($headerFactory);
        $headers->addMailboxHeader('From', 'test@test.test');
        $headers->addMailboxHeader('To', 'recipient@test.test');
        $headers->addTextHeader('Subject', 'Test Subject');

        return $headers;
    }

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

    /**
     * @return Swift_Mime_Headers
     */
    private function createHeaders()
    {
        $x              = 0;
        $cache          = new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
        $factory        = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
        $contentEncoder = new Swift_Mime_ContentEncoder_Base64ContentEncoder();

        $headerEncoder  = new Swift_Mime_HeaderEncoder_QpHeaderEncoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $paramEncoder   = new Swift_Encoder_Rfc2231Encoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $emailValidator = new EmailValidator();
        $headerFactory  = new Swift_Mime_SimpleHeaderFactory($headerEncoder, $paramEncoder, $emailValidator);
        $headers        = $this->getMockery('Swift_Mime_SimpleHeaderSet');

        $headers->shouldReceive('listAll')
            ->zeroOrMoreTimes()
            ->andReturn(['From', 'To', 'Date', 'Subject']);
        $headers->shouldReceive('has')
            ->zeroOrMoreTimes()
            ->with('From')
            ->andReturn(true);
        $headers->shouldReceive('getAll')
            ->zeroOrMoreTimes()
            ->with('From')
            ->andReturn([$headerFactory->createMailboxHeader('From', 'test@test.test')]);
        $headers->shouldReceive('has')
            ->zeroOrMoreTimes()
            ->with('To')
            ->andReturn(true);
        $headers->shouldReceive('getAll')
            ->zeroOrMoreTimes()
            ->with('To')
            ->andReturn([$headerFactory->createMailboxHeader('To', 'test@test.test')]);
        $headers->shouldReceive('has')
            ->zeroOrMoreTimes()
            ->with('Date')
            ->andReturn(true);
        $headers->shouldReceive('getAll')
            ->zeroOrMoreTimes()
            ->with('Date')
            ->andReturn([$headerFactory->createTextHeader('Date', 'Fri, 11 Mar 2011 20:56:12 +0000 (GMT)')]);
        $headers->shouldReceive('has')
            ->zeroOrMoreTimes()
            ->with('Subject')
            ->andReturn(true);
        $headers->shouldReceive('getAll')
            ->zeroOrMoreTimes()
            ->with('Subject')
            ->andReturn([$headerFactory->createTextHeader('Subject', 'Foo Bar Text Message')]);
        $headers->shouldReceive('addTextHeader')
            ->zeroOrMoreTimes()
            ->with('DKIM-Signature', Mockery::any())
            ->andReturn(true);
        $headers->shouldReceive('getAll')
            ->zeroOrMoreTimes()
            ->with('DKIM-Signature')
            ->andReturn([$headerFactory->createTextHeader('DKIM-Signature', 'Foo Bar Text Message')]);

        return $headers;
    }
}
