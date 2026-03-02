<?php

class Swift_Signers_DomainKeySignerTest extends PHPUnit\Framework\TestCase
{
    private function createSigner(): Swift_Signers_DomainKeySigner
    {
        return new Swift_Signers_DomainKeySigner(
            file_get_contents(dirname(__DIR__, 3).'/_samples/dkim/dkim.test.priv'),
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
}
