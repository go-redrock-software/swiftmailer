<?php

class Swift_Mime_SimpleHeaderSetExtendedTest extends PHPUnit\Framework\TestCase
{
    private function createHeaderSet(): Swift_Mime_SimpleHeaderSet
    {
        $factory = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
        $encoder = new Swift_Mime_HeaderEncoder_QpHeaderEncoder(
            new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'),
        );
        $paramEncoder   = new Swift_Encoder_Rfc2231Encoder(new Swift_CharacterStream_ArrayCharacterStream($factory, 'utf-8'));
        $emailValidator = new Egulias\EmailValidator\EmailValidator();

        return new Swift_Mime_SimpleHeaderSet(
            new Swift_Mime_SimpleHeaderFactory($encoder, $paramEncoder, $emailValidator),
        );
    }

    public function testAddTextHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Test');
        $this->assertTrue($headers->has('Subject'));
    }

    public function testGetReturnsHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Test');
        $header = $headers->get('Subject');
        $this->assertInstanceOf(Swift_Mime_Header::class, $header);
    }

    public function testRemoveHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Test');
        $headers->remove('Subject');
        $this->assertFalse($headers->has('Subject'));
    }

    public function testAddMailboxHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addMailboxHeader('From', ['test@test.com' => 'Test']);
        $this->assertTrue($headers->has('From'));
    }

    public function testAddDateHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addDateHeader('Date', new DateTimeImmutable());
        $this->assertTrue($headers->has('Date'));
    }

    public function testAddIdHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addIdHeader('Message-ID', 'some-id@example.com');
        $this->assertTrue($headers->has('Message-ID'));
    }

    public function testAddPathHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addPathHeader('Return-Path', 'bounce@example.com');
        $this->assertTrue($headers->has('Return-Path'));
    }

    public function testAddParameterizedHeader()
    {
        $headers = $this->createHeaderSet();
        $headers->addParameterizedHeader('Content-Type', 'text/plain', ['charset' => 'utf-8']);
        $this->assertTrue($headers->has('Content-Type'));
    }

    public function testListAll()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Test');
        $headers->addMailboxHeader('From', 'test@test.com');
        $list = $headers->listAll();
        $this->assertContains('subject', $list);
        $this->assertContains('from', $list);
    }

    public function testGetAll()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('X-Custom', 'Value1');
        $all = $headers->getAll('X-Custom');
        $this->assertCount(1, $all);
    }

    public function testHasReturnsFalseForNonExistent()
    {
        $headers = $this->createHeaderSet();
        $this->assertFalse($headers->has('X-Nonexistent'));
    }

    public function testGetReturnsNullForNonExistent()
    {
        $headers = $this->createHeaderSet();
        $this->assertNull($headers->get('X-Nonexistent'));
    }

    public function testRemoveAllInstances()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('X-Custom', 'A');
        $headers->addTextHeader('X-Custom', 'B');
        $headers->removeAll('X-Custom');
        $this->assertFalse($headers->has('X-Custom'));
    }

    public function testToStringIncludesAllHeaders()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Test');
        $string = $headers->toString();
        $this->assertStringContainsString('Subject', $string);
    }

    public function testSetCharset()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Test');
        $headers->setCharset('iso-8859-1');
        $this->assertTrue($headers->has('Subject'));
    }

    public function testDefineOrdering()
    {
        $headers = $this->createHeaderSet();
        $headers->defineOrdering(['Subject', 'From', 'To']);
        $headers->addTextHeader('Subject', 'Test');
        $this->assertTrue($headers->has('Subject'));
    }

    public function testGetTextHeaderValue()
    {
        $headers = $this->createHeaderSet();
        $headers->addTextHeader('Subject', 'Hello World');
        $header = $headers->get('Subject');
        $this->assertEquals('Hello World', $header->getFieldBodyModel());
    }

    public function testNewInstance()
    {
        $headers  = $this->createHeaderSet();
        $headers2 = $headers->newInstance();
        $this->assertInstanceOf(Swift_Mime_SimpleHeaderSet::class, $headers2);
        $this->assertNotSame($headers, $headers2);
    }
}
