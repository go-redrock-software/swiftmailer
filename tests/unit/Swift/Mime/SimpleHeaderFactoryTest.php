<?php

use Egulias\EmailValidator\EmailValidator;

class Swift_Mime_SimpleHeaderFactoryTest extends PHPUnit\Framework\TestCase
{
    private $factory;

    protected function setUp(): void
    {
        $this->factory = $this->createFactory();
    }

    public function testMailboxHeaderIsCorrectType()
    {
        $header = $this->factory->createMailboxHeader('X-Foo');
        $this->assertInstanceOf('Swift_Mime_Headers_MailboxHeader', $header);
    }

    public function testMailboxHeaderHasCorrectName()
    {
        $header = $this->factory->createMailboxHeader('X-Foo');
        $this->assertEquals('X-Foo', $header->getFieldName());
    }

    public function testMailboxHeaderHasCorrectModel()
    {
        $header = $this->factory->createMailboxHeader(
            'X-Foo',
            ['foo@bar' => 'FooBar'],
        );
        $this->assertEquals(['foo@bar' => 'FooBar'], $header->getFieldBodyModel());
    }

    public function testDateHeaderHasCorrectType()
    {
        $header = $this->factory->createDateHeader('X-Date');
        $this->assertInstanceOf('Swift_Mime_Headers_DateHeader', $header);
    }

    public function testDateHeaderHasCorrectName()
    {
        $header = $this->factory->createDateHeader('X-Date');
        $this->assertEquals('X-Date', $header->getFieldName());
    }

    public function testDateHeaderHasCorrectModel()
    {
        $dateTime = new DateTimeImmutable();
        $header   = $this->factory->createDateHeader('X-Date', $dateTime);
        $this->assertEquals($dateTime, $header->getFieldBodyModel());
    }

    public function testTextHeaderHasCorrectType()
    {
        $header = $this->factory->createTextHeader('X-Foo');
        $this->assertInstanceOf('Swift_Mime_Headers_UnstructuredHeader', $header);
    }

    public function testTextHeaderHasCorrectName()
    {
        $header = $this->factory->createTextHeader('X-Foo');
        $this->assertEquals('X-Foo', $header->getFieldName());
    }

    public function testTextHeaderHasCorrectModel()
    {
        $header = $this->factory->createTextHeader('X-Foo', 'bar');
        $this->assertEquals('bar', $header->getFieldBodyModel());
    }

    public function testParameterizedHeaderHasCorrectType()
    {
        $header = $this->factory->createParameterizedHeader('X-Foo');
        $this->assertInstanceOf('Swift_Mime_Headers_ParameterizedHeader', $header);
    }

    public function testParameterizedHeaderHasCorrectName()
    {
        $header = $this->factory->createParameterizedHeader('X-Foo');
        $this->assertEquals('X-Foo', $header->getFieldName());
    }

    public function testParameterizedHeaderHasCorrectModel()
    {
        $header = $this->factory->createParameterizedHeader('X-Foo', 'bar');
        $this->assertEquals('bar', $header->getFieldBodyModel());
    }

    public function testParameterizedHeaderHasCorrectParams()
    {
        $header = $this->factory->createParameterizedHeader(
            'X-Foo',
            'bar',
            ['zip' => 'button'],
        );
        $this->assertEquals(['zip' => 'button'], $header->getParameters());
    }

    public function testIdHeaderHasCorrectType()
    {
        $header = $this->factory->createIdHeader('X-ID');
        $this->assertInstanceOf('Swift_Mime_Headers_IdentificationHeader', $header);
    }

    public function testIdHeaderHasCorrectName()
    {
        $header = $this->factory->createIdHeader('X-ID');
        $this->assertEquals('X-ID', $header->getFieldName());
    }

    public function testIdHeaderHasCorrectModel()
    {
        $header = $this->factory->createIdHeader('X-ID', 'xyz@abc');
        $this->assertEquals(['xyz@abc'], $header->getFieldBodyModel());
    }

    public function testPathHeaderHasCorrectType()
    {
        $header = $this->factory->createPathHeader('X-Path');
        $this->assertInstanceOf('Swift_Mime_Headers_PathHeader', $header);
    }

    public function testPathHeaderHasCorrectName()
    {
        $header = $this->factory->createPathHeader('X-Path');
        $this->assertEquals('X-Path', $header->getFieldName());
    }

    public function testPathHeaderHasCorrectModel()
    {
        $header = $this->factory->createPathHeader('X-Path', 'foo@bar');
        $this->assertEquals('foo@bar', $header->getFieldBodyModel());
    }

    public function testCharsetChangeNotificationNotifiesEncoders()
    {
        $encoder = $this->createHeaderEncoder();
        $encoder->expects($this->once())
            ->method('charsetChanged')
            ->with('utf-8');
        $paramEncoder = $this->createParamEncoder();
        $paramEncoder->expects($this->once())
            ->method('charsetChanged')
            ->with('utf-8');

        $factory = $this->createFactory($encoder, $paramEncoder);

        $factory->charsetChanged('utf-8');
    }

    public function testMailboxHeaderWithNullAddresses()
    {
        $header = $this->factory->createMailboxHeader('To');
        $this->assertInstanceOf('Swift_Mime_Headers_MailboxHeader', $header);
        $this->assertEquals('To', $header->getFieldName());
    }

    public function testMailboxHeaderWithStringAddress()
    {
        $header = $this->factory->createMailboxHeader('From', 'test@example.com');
        $this->assertEquals(['test@example.com'], $header->getAddresses());
    }

    public function testMailboxHeaderWithMultipleAddresses()
    {
        $header = $this->factory->createMailboxHeader(
            'To',
            ['a@b.com' => 'Alpha', 'c@d.com' => 'Charlie'],
        );
        $this->assertCount(2, $header->getAddresses());
    }

    public function testDateHeaderWithNullDate()
    {
        $header = $this->factory->createDateHeader('Date');
        $this->assertInstanceOf('Swift_Mime_Headers_DateHeader', $header);
        $this->assertNull($header->getFieldBodyModel());
    }

    public function testTextHeaderWithNullValue()
    {
        $header = $this->factory->createTextHeader('Subject');
        $this->assertInstanceOf('Swift_Mime_Headers_UnstructuredHeader', $header);
    }

    public function testTextHeaderWithEmptyString()
    {
        $header = $this->factory->createTextHeader('Subject', '');
        $this->assertEquals('', $header->getFieldBodyModel());
    }

    public function testParameterizedHeaderWithNullValue()
    {
        $header = $this->factory->createParameterizedHeader('Content-Type');
        $this->assertInstanceOf('Swift_Mime_Headers_ParameterizedHeader', $header);
    }

    public function testParameterizedHeaderWithEmptyParams()
    {
        $header = $this->factory->createParameterizedHeader('Content-Type', 'text/plain', []);
        $this->assertEquals('text/plain', $header->getFieldBodyModel());
    }

    public function testParameterizedHeaderWithMultipleParams()
    {
        $header = $this->factory->createParameterizedHeader(
            'Content-Type',
            'text/plain',
            ['charset' => 'utf-8', 'format' => 'flowed'],
        );
        $params = $header->getParameters();
        $this->assertEquals('utf-8', $params['charset']);
        $this->assertEquals('flowed', $params['format']);
    }

    public function testIdHeaderWithNullId()
    {
        $header = $this->factory->createIdHeader('Message-ID');
        $this->assertInstanceOf('Swift_Mime_Headers_IdentificationHeader', $header);
    }

    public function testIdHeaderWithArrayOfIds()
    {
        $header = $this->factory->createIdHeader('References', ['a@b', 'c@d']);
        $this->assertEquals(['a@b', 'c@d'], $header->getFieldBodyModel());
    }

    public function testPathHeaderWithNullPath()
    {
        $header = $this->factory->createPathHeader('Return-Path');
        $this->assertInstanceOf('Swift_Mime_Headers_PathHeader', $header);
    }

    public function testFactoryWithCharset()
    {
        $factory = new Swift_Mime_SimpleHeaderFactory(
            $this->createHeaderEncoder(),
            $this->createParamEncoder(),
            new EmailValidator(),
            'iso-8859-1',
        );
        $header = $factory->createTextHeader('Subject', 'test');
        $this->assertInstanceOf('Swift_Mime_Headers_UnstructuredHeader', $header);
    }

    public function testCloneProducesIndependentCopy()
    {
        $clone = clone $this->factory;
        $header1 = $this->factory->createTextHeader('X-Foo', 'bar');
        $header2 = $clone->createTextHeader('X-Foo', 'baz');
        $this->assertEquals('bar', $header1->getFieldBodyModel());
        $this->assertEquals('baz', $header2->getFieldBodyModel());
    }

    public function testContentDispositionGetsParamEncoder()
    {
        $header = $this->factory->createParameterizedHeader(
            'Content-Disposition',
            'attachment',
            ['filename' => 'test.txt'],
        );
        $this->assertInstanceOf('Swift_Mime_Headers_ParameterizedHeader', $header);
        $this->assertEquals('attachment', $header->getFieldBodyModel());
    }

    private function createFactory($encoder = null, $paramEncoder = null)
    {
        return new Swift_Mime_SimpleHeaderFactory(
            $encoder
                ?: $this->createHeaderEncoder(),
            $paramEncoder
                ?: $this->createParamEncoder(),
            new EmailValidator(),
        );
    }

    private function createHeaderEncoder()
    {
        return $this->getMockBuilder('Swift_Mime_HeaderEncoder')->getMock();
    }

    private function createParamEncoder()
    {
        return $this->getMockBuilder('Swift_Encoder')->getMock();
    }
}
