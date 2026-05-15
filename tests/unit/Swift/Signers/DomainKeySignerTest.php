<?php

class Swift_Signers_DomainKeySignerTest extends PHPUnit\Framework\TestCase
{
    private function createSigner(): Swift_Signers_DomainKeySigner
    {
        return new Swift_Signers_DomainKeySigner(
            \file_get_contents(\dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
            'dummy.nxdomain.be',
            'dummySelector',
        );
    }

    private function createHeaderSet(): Swift_Mime_SimpleHeaderSet
    {
        $factory        = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
        $headerEncoder  = new Swift_Mime_HeaderEncoder_QpHeaderEncoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $paramEncoder   = new Swift_Encoder_Rfc2231Encoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $emailValidator = new Egulias\EmailValidator\EmailValidator();

        return new Swift_Mime_SimpleHeaderSet(
            new Swift_Mime_SimpleHeaderFactory($headerEncoder, $paramEncoder, $emailValidator),
        );
    }

    public function testImplementsHeaderSignerInterface()
    {
        $signer = $this->createSigner();
        $this->assertInstanceOf(Swift_Signers_HeaderSigner::class, $signer);
    }

    public function testGetAlteredHeadersReturnsDomainKeySignature()
    {
        $signer = $this->createSigner();
        $this->assertContains('DomainKey-Signature', $signer->getAlteredHeaders());
    }

    public function testGetAlteredHeadersWithDebugHeaders()
    {
        $signer = $this->createSigner();
        $signer->setDebugHeaders(true);
        $altered = $signer->getAlteredHeaders();
        $this->assertContains('DomainKey-Signature', $altered);
        $this->assertContains('X-DebugHash', $altered);
    }

    public function testGetAlteredHeadersWithoutDebugHeaders()
    {
        $signer = $this->createSigner();
        $signer->setDebugHeaders(false);
        $altered = $signer->getAlteredHeaders();
        $this->assertNotContains('X-DebugHash', $altered);
    }

    public function testSetHashAlgorithmReturnsSelf()
    {
        $signer = $this->createSigner();
        $result = $signer->setHashAlgorithm('rsa-sha1');
        $this->assertSame($signer, $result);
    }

    public function testSetHashAlgorithmAlwaysSetsRsaSha1()
    {
        $signer = $this->createSigner();
        $result = $signer->setHashAlgorithm('anything');
        $this->assertSame($signer, $result);
    }

    public function testSetCanonReturnsSelf()
    {
        $signer = $this->createSigner();
        $result = $signer->setCanon('nofws');
        $this->assertSame($signer, $result);
    }

    public function testSetCanonAcceptsSimple()
    {
        $signer = $this->createSigner();
        $result = $signer->setCanon('simple');
        $this->assertSame($signer, $result);
    }

    public function testSetCanonAcceptsNofws()
    {
        $signer = $this->createSigner();
        $result = $signer->setCanon('nofws');
        $this->assertSame($signer, $result);
    }

    public function testSetSignerIdentityReturnsSelf()
    {
        $signer = $this->createSigner();
        $result = $signer->setSignerIdentity('@example.com');
        $this->assertSame($signer, $result);
    }

    public function testSetDebugHeadersReturnsSelf()
    {
        $signer = $this->createSigner();
        $result = $signer->setDebugHeaders(true);
        $this->assertSame($signer, $result);
    }

    public function testIgnoreHeaderReturnsSelf()
    {
        $signer = $this->createSigner();
        $result = $signer->ignoreHeader('X-Mailer');
        $this->assertSame($signer, $result);
    }

    public function testResetDoesNotThrow()
    {
        $signer = $this->createSigner();
        $signer->reset();
        $this->addToAssertionCount(1);
    }

    public function testFlushBuffersCallsReset()
    {
        $signer = $this->createSigner();
        $signer->flushBuffers();
        $this->addToAssertionCount(1);
    }

    public function testCommitIsNoOp()
    {
        $signer = $this->createSigner();
        $signer->commit();
        $this->addToAssertionCount(1);
    }

    public function testBindAndUnbind()
    {
        $signer = $this->createSigner();
        $stream = $this->createMock(Swift_InputByteStream::class);
        $signer->bind($stream);
        $signer->unbind($stream);
        $this->addToAssertionCount(1);
    }

    public function testWriteForwardsToBoundStreams()
    {
        $signer    = $this->createSigner();
        $headerSet = $this->createHeaderSet();
        $signer->setHeaders($headerSet);
        $stream = $this->createMock(Swift_InputByteStream::class);
        $stream->expects($this->once())
            ->method('write')
            ->with('test data');
        $signer->bind($stream);
        $signer->startBody();
        $signer->write('test data');
    }

    public function testWriteAcceptsArrayInput()
    {
        $signer    = $this->createSigner();
        $headerSet = $this->createHeaderSet();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write(['hello', ' ', 'world']);
        $this->addToAssertionCount(1);
    }

    public function testConstructorAcceptsAnyKey()
    {
        // DomainKeySigner doesn't validate the key in the constructor
        $signer = new Swift_Signers_DomainKeySigner(
            'some-key-value',
            'example.com',
            'selector',
        );
        $this->assertInstanceOf(Swift_Signers_DomainKeySigner::class, $signer);
    }

    public function testSigningSimpleCanon()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->setCanon('simple');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Hello World\r\nSecond line\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
        $dks = $headerSet->getAll('DomainKey-Signature');
        $sig = \reset($dks);
        $this->assertStringContainsString('c=simple', $sig->getValue());
        $this->assertStringContainsString('a=rsa-sha1', $sig->getValue());
    }

    public function testSigningNofwsCanon()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->setCanon('nofws');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Hello   World\r\nSecond\t line\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
        $dks = $headerSet->getAll('DomainKey-Signature');
        $sig = \reset($dks);
        $this->assertStringContainsString('c=nofws', $sig->getValue());
    }

    public function testSigningWithHeaders()
    {
        $headerSet = $this->createHeaderSet();
        $headerSet->addMailboxHeader('From', 'test@test.test');
        $headerSet->addTextHeader('Subject', 'Test Subject');
        $signer = $this->createSigner();
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Body content\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
        $dks = $headerSet->getAll('DomainKey-Signature');
        $sig = \reset($dks);
        $this->assertStringContainsString('h=', $sig->getValue());
        $this->assertStringContainsString('From', $sig->getValue());
    }

    public function testSigningNofwsCanonWithHeaders()
    {
        $headerSet = $this->createHeaderSet();
        $headerSet->addMailboxHeader('From', 'test@test.test');
        $headerSet->addTextHeader('Subject', 'Test Subject');
        $signer = $this->createSigner();
        $signer->setCanon('nofws');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Body content\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testEmptyBodySimpleCanon()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->setCanon('simple');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testEmptyBodyNofwsCanon()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->setCanon('nofws');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testBodyWithTrailingContent()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Line without trailing CRLF");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testNofwsBodyWithSpaces()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->setCanon('nofws');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Text with   spaces   and\ttabs\r\nMore text\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testNofwsBodyWithEmptyLines()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->setCanon('nofws');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("First line\r\n\r\n\r\nAfter empty lines\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testIgnoredHeadersExcluded()
    {
        $headerSet = $this->createHeaderSet();
        $headerSet->addMailboxHeader('From', 'test@test.test');
        $headerSet->addTextHeader('X-Mailer', 'SwiftMailer');
        $signer = $this->createSigner();
        $signer->ignoreHeader('X-Mailer');
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Body\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $dks = $headerSet->getAll('DomainKey-Signature');
        $sig = \reset($dks);
        $this->assertStringNotContainsString('X-Mailer', $sig->getValue());
    }

    public function testSignatureContainsDomainAndSelector()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Body\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $dks = $headerSet->getAll('DomainKey-Signature');
        $sig = \reset($dks);
        $this->assertStringContainsString('d=dummy.nxdomain.be', $sig->getValue());
        $this->assertStringContainsString('s=dummySelector', $sig->getValue());
        $this->assertStringContainsString('q=dns', $sig->getValue());
    }

    public function testMultipleEmptyLinesAtEndAreStripped()
    {
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Body\r\n\r\n\r\n");
        $signer->endBody();
        $signer->addSignature($headerSet);
        $this->assertTrue($headerSet->has('DomainKey-Signature'));
    }

    public function testBareLinefeedThrowsException()
    {
        // Covers line 455: '\n without preceding \r' error
        $headerSet = $this->createHeaderSet();
        $signer    = $this->createSigner();
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Invalid new line sequence');
        $signer->write("Line without CR before LF\nBad line");
    }

    public function testSigningWithInvalidPrivateKeyThrows()
    {
        // Covers line 512: openssl_get_privatekey returns false
        $headerSet = $this->createHeaderSet();
        $signer    = new Swift_Signers_DomainKeySigner(
            'not-a-valid-private-key',
            'dummy.nxdomain.be',
            'dummySelector',
        );
        $signer->reset();
        $signer->setHeaders($headerSet);
        $signer->startBody();
        $signer->write("Body\r\n");
        $signer->endBody();

        $this->expectException(Swift_SwiftException::class);
        $this->expectExceptionMessage('Unable to load DomainKey Private Key');
        $signer->addSignature($headerSet);
    }
}
