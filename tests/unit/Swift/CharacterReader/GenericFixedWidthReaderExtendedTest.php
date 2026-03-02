<?php

class Swift_CharacterReader_GenericFixedWidthReaderExtendedTest extends PHPUnit\Framework\TestCase
{
    public function testSingleByteReaderGetInitialByteSize()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $this->assertEquals(1, $reader->getInitialByteSize());
    }

    public function testDoubleByteReaderGetInitialByteSize()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(2);
        $this->assertEquals(2, $reader->getInitialByteSize());
    }

    public function testFourByteReaderGetInitialByteSize()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(4);
        $this->assertEquals(4, $reader->getInitialByteSize());
    }

    public function testSingleByteValidation()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $this->assertEquals(0, $reader->validateByteSequence([0x41], 1));
    }

    public function testSingleByteRejectsMultipleBytes()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $this->assertEquals(-1, $reader->validateByteSequence([0x41, 0x42], 2));
    }

    public function testDoubleByteNeedsMoreBytes()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(2);
        $this->assertEquals(1, $reader->validateByteSequence([0x41], 1));
    }

    public function testDoubleByteAcceptsTwoBytes()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(2);
        $this->assertEquals(0, $reader->validateByteSequence([0x41, 0x42], 2));
    }

    public function testDoubleByteRejectsTooManyBytes()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(2);
        $this->assertEquals(-1, $reader->validateByteSequence([0x41, 0x42, 0x43], 3));
    }

    public function testFourByteNeedsThreeMore()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(4);
        $this->assertEquals(3, $reader->validateByteSequence([0x41], 1));
    }

    public function testFourByteNeedsTwoMore()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(4);
        $this->assertEquals(2, $reader->validateByteSequence([0x41, 0x42], 2));
    }

    public function testFourByteNeedsOneMore()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(4);
        $this->assertEquals(1, $reader->validateByteSequence([0x41, 0x42, 0x43], 3));
    }

    public function testFourByteAcceptsFourBytes()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(4);
        $this->assertEquals(0, $reader->validateByteSequence([0x41, 0x42, 0x43, 0x44], 4));
    }

    public function testGetMapTypeReturnsFixedLen()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $this->assertEquals(Swift_CharacterReader::MAP_TYPE_FIXED_LEN, $reader->getMapType());
    }

    public function testGetCharPositionsSingleByte()
    {
        $reader = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $map    = null;
        $ignored = null;
        $count  = $reader->getCharPositions('abcde', 0, $map, $ignored);
        $this->assertEquals(5, $count);
        $this->assertEquals(1, $map);
    }

    public function testGetCharPositionsDoubleByte()
    {
        $reader  = new Swift_CharacterReader_GenericFixedWidthReader(2);
        $map     = null;
        $ignored = null;
        $count   = $reader->getCharPositions('abcd', 0, $map, $ignored);
        $this->assertEquals(2, $count);
        $this->assertEquals(2, $map);
    }

    public function testGetCharPositionsWithOffset()
    {
        $reader  = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $map     = null;
        $ignored = null;
        $count   = $reader->getCharPositions('abc', 5, $map, $ignored);
        $this->assertEquals(3, $count);
    }

    public function testGetCharPositionsEmptyString()
    {
        $reader  = new Swift_CharacterReader_GenericFixedWidthReader(1);
        $map     = null;
        $ignored = null;
        $count   = $reader->getCharPositions('', 0, $map, $ignored);
        $this->assertEquals(0, $count);
    }
}
