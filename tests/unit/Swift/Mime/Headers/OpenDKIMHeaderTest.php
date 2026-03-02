<?php

class Swift_Mime_Headers_OpenDKIMHeaderTest extends PHPUnit\Framework\TestCase
{
    public function testFieldNameIsSetInConstructor()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('DKIM-Signature');
        $this->assertEquals('DKIM-Signature', $header->getFieldName());
    }

    public function testFieldTypeIsText()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('DKIM-Signature');
        $this->assertEquals(Swift_Mime_Header::TYPE_TEXT, $header->getFieldType());
    }

    public function testValueCanBeSetAndRetrieved()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('DKIM-Signature');
        $header->setValue('v=1; a=rsa-sha256; d=example.com');
        $this->assertEquals('v=1; a=rsa-sha256; d=example.com', $header->getValue());
    }

    public function testGetValueReturnsNullByDefault()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('DKIM-Signature');
        $this->assertNull($header->getValue());
    }

    public function testFieldBodyModelSetsValue()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setFieldBodyModel('test value');
        $this->assertEquals('test value', $header->getValue());
    }

    public function testGetFieldBodyModelReturnsValue()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setValue('my value');
        $this->assertEquals('my value', $header->getFieldBodyModel());
    }

    public function testGetFieldBodyReturnsRawValue()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setValue('raw body');
        $this->assertEquals('raw body', $header->getFieldBody());
    }

    public function testToStringFormatsAsRfc2822Header()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('DKIM-Signature');
        $header->setValue('v=1; d=example.com');
        $this->assertEquals("DKIM-Signature: v=1; d=example.com\r\n", $header->toString());
    }

    public function testToStringWithEmptyValueHasColon()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Empty');
        $header->setValue('');
        $this->assertEquals("X-Empty: \r\n", $header->toString());
    }

    public function testSetCharsetIsIgnored()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setValue('test');
        $header->setCharset('iso-8859-1');
        // Value should be unchanged
        $this->assertEquals('test', $header->getValue());
    }

    public function testImplementsHeaderInterface()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $this->assertInstanceOf(Swift_Mime_Header::class, $header);
    }

    public function testValueIsNotEncoded()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setValue('special chars: =?utf-8?Q?test?=');
        $this->assertEquals('special chars: =?utf-8?Q?test?=', $header->getFieldBody());
    }

    public function testCustomFieldName()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Custom-Header');
        $this->assertEquals('X-Custom-Header', $header->getFieldName());
    }

    public function testSetValueOverwritesPrevious()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setValue('first');
        $header->setValue('second');
        $this->assertEquals('second', $header->getValue());
    }

    public function testSetFieldBodyModelOverwritesPrevious()
    {
        $header = new Swift_Mime_Headers_OpenDKIMHeader('X-Test');
        $header->setFieldBodyModel('first');
        $header->setFieldBodyModel('second');
        $this->assertEquals('second', $header->getFieldBodyModel());
    }
}
