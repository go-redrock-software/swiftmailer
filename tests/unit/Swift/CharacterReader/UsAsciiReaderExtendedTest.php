<?php

class Swift_CharacterReader_UsAsciiReaderExtendedTest extends PHPUnit\Framework\TestCase
{
    private Swift_CharacterReader_UsAsciiReader $reader;

    protected function setUp(): void
    {
        $this->reader = new Swift_CharacterReader_UsAsciiReader();
    }

    public function testGetMapTypeReturnsInvalid()
    {
        $this->assertEquals(Swift_CharacterReader::MAP_TYPE_INVALID, $this->reader->getMapType());
    }

    public function testGetInitialByteSizeReturns1()
    {
        $this->assertEquals(1, $this->reader->getInitialByteSize());
    }

    public function testNullByteIsValid()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x00], 1));
    }

    public function testMaxAsciiByteIsValid()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x7F], 1));
    }

    public function testFirstNonAsciiByteIsInvalid()
    {
        $this->assertEquals(-1, $this->reader->validateByteSequence([0x80], 1));
    }

    public function testMaxByteIsInvalid()
    {
        $this->assertEquals(-1, $this->reader->validateByteSequence([0xFF], 1));
    }

    public function testGetCharPositionsAsciiOnly()
    {
        $map     = [];
        $ignored = '';
        $count   = $this->reader->getCharPositions('Hello', 0, $map, $ignored);
        $this->assertEquals(5, $count);
        $this->assertEquals('', $ignored);
    }

    public function testGetCharPositionsEmptyString()
    {
        $map     = [];
        $ignored = '';
        $count   = $this->reader->getCharPositions('', 0, $map, $ignored);
        $this->assertEquals(0, $count);
    }

    public function testGetCharPositionsMarksHighBytesInMap()
    {
        $map     = [];
        $ignored = '';
        $this->reader->getCharPositions("a\x80b", 0, $map, $ignored);
        $this->assertArrayHasKey(1, $map);
    }

    public function testGetCharPositionsReturnsCorrectLength()
    {
        $map     = [];
        $ignored = '';
        $count   = $this->reader->getCharPositions('abc', 0, $map, $ignored);
        $this->assertEquals(3, $count);
        $this->assertEquals('', $ignored);
    }

    public function testGetCharPositionsWithOffset()
    {
        $map     = [];
        $ignored = '';
        $this->reader->getCharPositions("a\x80", 5, $map, $ignored);
        // Invalid byte at position 1 + offset 5 = 6
        $this->assertArrayHasKey(6, $map);
    }

    public function testGetCharPositionsAllHighBytes()
    {
        $map     = [];
        $ignored = '';
        $count   = $this->reader->getCharPositions("\x80\x81\x82", 0, $map, $ignored);
        $this->assertEquals(3, $count);
        $this->assertCount(3, $map);
    }

    public function testSpaceIsValid()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x20], 1));
    }

    public function testTabIsValid()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x09], 1));
    }

    public function testNewlineIsValid()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x0A], 1));
    }

    public function testCarriageReturnIsValid()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x0D], 1));
    }
}
