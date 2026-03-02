<?php

class Swift_CharacterReader_Utf8ReaderExtendedTest extends PHPUnit\Framework\TestCase
{
    private Swift_CharacterReader_Utf8Reader $reader;

    protected function setUp(): void
    {
        $this->reader = new Swift_CharacterReader_Utf8Reader();
    }

    public function testGetMapTypeReturnsPositions()
    {
        $this->assertEquals(Swift_CharacterReader::MAP_TYPE_POSITIONS, $this->reader->getMapType());
    }

    public function testGetInitialByteSizeReturns1()
    {
        $this->assertEquals(1, $this->reader->getInitialByteSize());
    }

    public function testValidateSingleAsciiByteReturnsZero()
    {
        $this->assertEquals(0, $this->reader->validateByteSequence([0x41], 1));
    }

    public function testValidateAllAsciiCharsReturnZero()
    {
        for ($i = 0x00; $i <= 0x7F; ++$i) {
            $this->assertEquals(0, $this->reader->validateByteSequence([$i], 1));
        }
    }

    public function testValidateTwoByteSequenceStartNeeds1More()
    {
        // 0xC0-0xDF = 2-byte sequence start
        $this->assertEquals(1, $this->reader->validateByteSequence([0xC2], 1));
    }

    public function testValidateThreeByteSequenceStartNeeds2More()
    {
        // 0xE0-0xEF = 3-byte sequence start
        $this->assertEquals(2, $this->reader->validateByteSequence([0xE0], 1));
    }

    public function testValidateFourByteSequenceStartNeeds3More()
    {
        // 0xF0-0xF7 = 4-byte sequence start
        $this->assertEquals(3, $this->reader->validateByteSequence([0xF0], 1));
    }

    public function testValidateCompleteTwoByteSequenceReturnsZero()
    {
        // e-acute: 0xC3 0xA9
        $this->assertEquals(0, $this->reader->validateByteSequence([0xC3, 0xA9], 2));
    }

    public function testValidateCompleteThreeByteSequenceReturnsZero()
    {
        // Euro sign: 0xE2 0x82 0xAC
        $this->assertEquals(0, $this->reader->validateByteSequence([0xE2, 0x82, 0xAC], 3));
    }

    public function testValidateInvalidContinuationByte()
    {
        // Invalid: continuation byte without a start byte
        $this->assertEquals(-1, $this->reader->validateByteSequence([0x80], 1));
    }

    public function testValidateInvalidByteFE()
    {
        // 0xFE is never valid in UTF-8
        $this->assertEquals(-1, $this->reader->validateByteSequence([0xFE], 1));
    }

    public function testValidateInvalidByteFF()
    {
        // 0xFF is never valid in UTF-8
        $this->assertEquals(-1, $this->reader->validateByteSequence([0xFF], 1));
    }

    public function testGetCharPositionsWithAsciiOnly()
    {
        $map     = [];
        $ignored = '';
        $count   = $this->reader->getCharPositions('abc', 0, $map, $ignored);
        $this->assertEquals(3, $count);
    }

    public function testGetCharPositionsWithMultiByteChars()
    {
        $map     = [];
        $ignored = '';
        // "Ã©" = e-acute = 2 bytes, 1 character
        $count = $this->reader->getCharPositions("\xC3\xA9", 0, $map, $ignored);
        $this->assertEquals(1, $count);
    }

    public function testGetCharPositionsWithMixedContent()
    {
        $map     = [];
        $ignored = '';
        // "aÃ©b" = a (1 byte) + e-acute (2 bytes) + b (1 byte) = 3 chars
        $count = $this->reader->getCharPositions("a\xC3\xA9b", 0, $map, $ignored);
        $this->assertEquals(3, $count);
    }

    public function testGetCharPositionsEmptyString()
    {
        $map     = [];
        $ignored = '';
        $count   = $this->reader->getCharPositions('', 0, $map, $ignored);
        $this->assertEquals(0, $count);
    }
}
