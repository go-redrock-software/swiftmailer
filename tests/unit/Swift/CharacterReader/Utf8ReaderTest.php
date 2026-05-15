<?php

class Swift_CharacterReader_Utf8ReaderTest extends PHPUnit\Framework\TestCase
{
    private $reader;

    protected function setUp(): void
    {
        $this->reader = new Swift_CharacterReader_Utf8Reader();
    }

    public function testLeading7BitOctetCausesReturnZero()
    {
        for ($ordinal = 0x00; $ordinal <= 0x7F; ++$ordinal) {
            $this->assertSame(
                0,
                $this->reader->validateByteSequence([$ordinal], 1),
            );
        }
    }

    public function testLeadingByteOf2OctetCharCausesReturn1()
    {
        for ($octet = 0xC0; $octet <= 0xDF; ++$octet) {
            $this->assertSame(
                1,
                $this->reader->validateByteSequence([$octet], 1),
            );
        }
    }

    public function testLeadingByteOf3OctetCharCausesReturn2()
    {
        for ($octet = 0xE0; $octet <= 0xEF; ++$octet) {
            $this->assertSame(
                2,
                $this->reader->validateByteSequence([$octet], 1),
            );
        }
    }

    public function testLeadingByteOf4OctetCharCausesReturn3()
    {
        for ($octet = 0xF0; $octet <= 0xF7; ++$octet) {
            $this->assertSame(
                3,
                $this->reader->validateByteSequence([$octet], 1),
            );
        }
    }

    public function testLeadingByteOf5OctetCharCausesReturn4()
    {
        for ($octet = 0xF8; $octet <= 0xFB; ++$octet) {
            $this->assertSame(
                4,
                $this->reader->validateByteSequence([$octet], 1),
            );
        }
    }

    public function testLeadingByteOf6OctetCharCausesReturn5()
    {
        for ($octet = 0xFC; $octet <= 0xFD; ++$octet) {
            $this->assertSame(
                5,
                $this->reader->validateByteSequence([$octet], 1),
            );
        }
    }

    public function testGetMapTypeReturnsPositions()
    {
        $this->assertSame(
            Swift_CharacterReader::MAP_TYPE_POSITIONS,
            $this->reader->getMapType(),
        );
    }

    public function testValidateByteSequenceWithSizeZeroReturnsMinus1()
    {
        $this->assertSame(-1, $this->reader->validateByteSequence([0x41], 0));
    }

    public function testValidateByteSequenceReturnsMinus1ForContinuationByte()
    {
        // 0x80 is a continuation byte, length_map[0x80] = 0, so 0 - 1 = -1
        $this->assertSame(-1, $this->reader->validateByteSequence([0x80], 1));
    }

    public function testGetCharPositionsWithInvalidBytes()
    {
        $reader = new Swift_CharacterReader_Utf8Reader();
        $map = ['p' => [], 'i' => []];
        $ignored = '';

        // A continuation byte (0x80) alone is invalid
        $count = $reader->getCharPositions("\x80A", 0, $map, $ignored);

        // The invalid byte produces a char, and 'A' produces another
        $this->assertSame(2, $count);
        $this->assertTrue(isset($map['i'][0]), 'Invalid char should be marked');
    }

    public function testGetCharPositionsWithIncompleteMultibyte()
    {
        $reader = new Swift_CharacterReader_Utf8Reader();
        $map = ['p' => [], 'i' => []];
        $ignored = '';

        // 0xC3 starts a 2-byte sequence but is at end of string
        $count = $reader->getCharPositions("\xC3", 0, $map, $ignored);

        // Incomplete char should be returned as ignoredChars
        $this->assertSame(0, $count);
        $this->assertEquals("\xC3", $ignored);
    }

    public function testGetCharPositionsWithInvalidContinuationByte()
    {
        $reader = new Swift_CharacterReader_Utf8Reader();
        $map = ['p' => [], 'i' => []];
        $ignored = '';

        // 0xC3 starts a 2-byte sequence, 0x41 is not a valid continuation byte
        $count = $reader->getCharPositions("\xC3\x41", 0, $map, $ignored);

        // The invalid sequence triggers resync: invalid char + valid 'A'
        $this->assertGreaterThanOrEqual(1, $count);
    }
}
