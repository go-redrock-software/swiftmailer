<?php

class Swift_CharacterReaderFactory_SimpleCharacterReaderFactoryTest extends PHPUnit\Framework\TestCase
{
    private Swift_CharacterReaderFactory_SimpleCharacterReaderFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new Swift_CharacterReaderFactory_SimpleCharacterReaderFactory();
    }

    public function testImplementsCharacterReaderFactoryInterface()
    {
        $this->assertInstanceOf(Swift_CharacterReaderFactory::class, $this->factory);
    }

    public function testReturnsUtf8ReaderForUtf8()
    {
        $reader = $this->factory->getReaderFor('utf-8');
        $this->assertInstanceOf(Swift_CharacterReader_Utf8Reader::class, $reader);
    }

    public function testReturnsUtf8ReaderForUtf8CaseInsensitive()
    {
        $reader = $this->factory->getReaderFor('UTF-8');
        $this->assertInstanceOf(Swift_CharacterReader_Utf8Reader::class, $reader);
    }

    public function testReturnsUtf8ReaderForUtf8WithoutHyphen()
    {
        $reader = $this->factory->getReaderFor('utf8');
        $this->assertInstanceOf(Swift_CharacterReader_Utf8Reader::class, $reader);
    }

    public function testReturnsFixedWidthReaderForUsAscii()
    {
        $reader = $this->factory->getReaderFor('us-ascii');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFixedWidthReaderForAscii()
    {
        $reader = $this->factory->getReaderFor('ascii');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFixedWidthReaderForIso88591()
    {
        $reader = $this->factory->getReaderFor('iso-8859-1');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFixedWidthReaderForIso88592()
    {
        $reader = $this->factory->getReaderFor('iso-8859-2');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFixedWidthReaderForWindows1252()
    {
        $reader = $this->factory->getReaderFor('windows-1252');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFixedWidthReaderForWindows1251()
    {
        $reader = $this->factory->getReaderFor('windows-1251');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsDoubleByteReaderForUcs2()
    {
        $reader = $this->factory->getReaderFor('ucs-2');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsDoubleByteReaderForUtf16()
    {
        $reader = $this->factory->getReaderFor('utf-16');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFourByteReaderForUcs4()
    {
        $reader = $this->factory->getReaderFor('ucs-4');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsFourByteReaderForUtf32()
    {
        $reader = $this->factory->getReaderFor('utf-32');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsReaderForKoi8r()
    {
        $reader = $this->factory->getReaderFor('koi-8-r');
        $this->assertInstanceOf(Swift_CharacterReader::class, $reader);
    }

    public function testReturnsSameInstanceForSameCharset()
    {
        $reader1 = $this->factory->getReaderFor('utf-8');
        $reader2 = $this->factory->getReaderFor('utf-8');
        $this->assertSame($reader1, $reader2);
    }

    public function testReturnsFallbackForUnknownCharset()
    {
        $reader = $this->factory->getReaderFor('unknown-charset-xyz');
        $this->assertInstanceOf(Swift_CharacterReader::class, $reader);
    }

    public function testHandlesNullCharset()
    {
        $reader = $this->factory->getReaderFor(null);
        $this->assertInstanceOf(Swift_CharacterReader::class, $reader);
    }

    public function testHandlesEmptyCharset()
    {
        $reader = $this->factory->getReaderFor('');
        $this->assertInstanceOf(Swift_CharacterReader::class, $reader);
    }

    public function testHandlesWhitespaceInCharset()
    {
        $reader = $this->factory->getReaderFor(' utf-8 ');
        $this->assertInstanceOf(Swift_CharacterReader_Utf8Reader::class, $reader);
    }

    public function testReturnsReaderForMacintosh()
    {
        $reader = $this->factory->getReaderFor('macintosh');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsReaderForViscii()
    {
        $reader = $this->factory->getReaderFor('viscii');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsReaderForAnsi()
    {
        $reader = $this->factory->getReaderFor('ansi');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testReturnsReaderForCp1252()
    {
        $reader = $this->factory->getReaderFor('cp1252');
        $this->assertInstanceOf(Swift_CharacterReader_GenericFixedWidthReader::class, $reader);
    }

    public function testInitCanBeCalledMultipleTimes()
    {
        $this->factory->init();
        $this->factory->init();
        $reader = $this->factory->getReaderFor('utf-8');
        $this->assertInstanceOf(Swift_CharacterReader_Utf8Reader::class, $reader);
    }
}
