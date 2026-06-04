<?php

class Swift_Mime_HeaderEncoder_Base64HeaderEncoderTest extends PHPUnit\Framework\TestCase
{
    // Most tests are already covered in Base64EncoderTest since this subclass only
    // adds a getName() method

    public function testNameIsB()
    {
        $encoder = new Swift_Mime_HeaderEncoder_Base64HeaderEncoder();
        $this->assertEquals('B', $encoder->getName());
    }

    public function testEncodeStringWithIso2022JpCharset()
    {
        $encoder = new Swift_Mime_HeaderEncoder_Base64HeaderEncoder();
        $result  = $encoder->encodeString('Test', 0, 0, 'iso-2022-jp');
        $this->assertIsString($result);
    }
}
