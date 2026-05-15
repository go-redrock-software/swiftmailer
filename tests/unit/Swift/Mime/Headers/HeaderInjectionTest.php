<?php

class Swift_Mime_Headers_HeaderInjectionTest extends SwiftMailerTestCase
{
    private $charset = 'utf-8';

    public function testCrlfStrippedFromUnstructuredHeaderValue()
    {
        $header = $this->getUnstructuredHeader('Subject');
        $header->setValue("Test\r\nBcc: hidden@evil.com");
        $this->assertEquals('TestBcc: hidden@evil.com', $header->getValue());
    }

    public function testLfStrippedFromUnstructuredHeaderValue()
    {
        $header = $this->getUnstructuredHeader('Subject');
        $header->setValue("Test\nBcc: hidden@evil.com");
        $this->assertStringNotContainsString("\n", $header->getValue());
    }

    public function testCrStrippedFromUnstructuredHeaderValue()
    {
        $header = $this->getUnstructuredHeader('Subject');
        $header->setValue("Test\rInjected");
        $this->assertStringNotContainsString("\r", $header->getValue());
    }

    public function testCrlfStrippedFromHeaderFieldName()
    {
        $header = $this->getUnstructuredHeader("X-Bad\r\nInjected");
        $this->assertEquals('X-BadInjected', $header->getFieldName());
    }

    public function testNullByteStrippedFromHeaderFieldName()
    {
        $header = $this->getUnstructuredHeader("X-Bad\0Name");
        $this->assertEquals('X-BadName', $header->getFieldName());
    }

    public function testCleanValuePassesThroughUnchanged()
    {
        $header = $this->getUnstructuredHeader('Subject');
        $header->setValue('Perfectly normal subject line');
        $this->assertEquals('Perfectly normal subject line', $header->getValue());
    }

    private function getUnstructuredHeader($name)
    {
        $encoder = $this->getMockery('Swift_Mime_HeaderEncoder')->shouldIgnoreMissing();
        $encoder->shouldReceive('getName')->zeroOrMoreTimes()->andReturn('Q');
        $header = new Swift_Mime_Headers_UnstructuredHeader($name, $encoder);
        $header->setCharset($this->charset);

        return $header;
    }
}
