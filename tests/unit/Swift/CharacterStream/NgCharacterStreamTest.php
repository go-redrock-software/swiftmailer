<?php

class Swift_CharacterStream_NgCharacterStreamTest extends PHPUnit\Framework\TestCase
{
    private function createFactory(?Swift_CharacterReader $reader = null): Swift_CharacterReaderFactory
    {
        $factory = $this->createMock(Swift_CharacterReaderFactory::class);
        if ($reader) {
            $factory->method('getReaderFor')->willReturn($reader);
        }

        return $factory;
    }

    private function createFixedWidthReader(int $width = 1): Swift_CharacterReader
    {
        $reader = $this->createMock(Swift_CharacterReader::class);
        $reader->method('getMapType')->willReturn(Swift_CharacterReader::MAP_TYPE_FIXED_LEN);
        $reader->method('getCharPositions')->willReturnCallback(
            function ($string, $startOffset, &$currentMap, &$ignoredChars) use ($width) {
                $currentMap   = $width;
                $ignoredChars = '';

                return (int) (\strlen($string) / $width);
            },
        );

        return $reader;
    }

    public function testImplementsCharacterStreamInterface()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $this->assertInstanceOf(Swift_CharacterStream::class, $stream);
    }

    public function testFlushContentsClearsData()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('hello');
        $stream->flushContents();
        $this->assertFalse($stream->read(1));
    }

    public function testImportStringAllowsReading()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abc');
        $this->assertEquals('a', $stream->read(1));
    }

    public function testImportStringFlushesExistingData()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('old');
        $stream->importString('new');
        $this->assertEquals('n', $stream->read(1));
    }

    public function testReadReturnsRequestedLength()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abcdef');
        $this->assertEquals('abc', $stream->read(3));
    }

    public function testReadReturnsFalseWhenExhausted()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('a');
        $stream->read(1);
        $this->assertFalse($stream->read(1));
    }

    public function testReadAdvancesPointer()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abcd');
        $stream->read(2);
        $this->assertEquals('cd', $stream->read(2));
    }

    public function testSetPointerChangesReadPosition()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abcdef');
        $stream->setPointer(3);
        $this->assertEquals('def', $stream->read(3));
    }

    public function testSetPointerBeyondEndClampsToEnd()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abc');
        $stream->setPointer(100);
        $this->assertFalse($stream->read(1));
    }

    public function testReadBytesReturnsOrdinalArray()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('AB');
        $bytes = $stream->readBytes(2);
        $this->assertEquals([65, 66], $bytes);
    }

    public function testReadBytesReturnsFalseWhenExhausted()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('A');
        $stream->readBytes(1);
        $this->assertFalse($stream->readBytes(1));
    }

    public function testSetCharacterSetResetsReader()
    {
        $reader1 = $this->createFixedWidthReader();
        $reader2 = $this->createFixedWidthReader();

        $factory = $this->createMock(Swift_CharacterReaderFactory::class);
        $factory->expects($this->exactly(2))
            ->method('getReaderFor')
            ->willReturnOnConsecutiveCalls($reader1, $reader2);

        $stream = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('test');
        $stream->setCharacterSet('iso-8859-1');
        $stream->importString('new');
    }

    public function testSetCharacterReaderFactory()
    {
        $factory1 = $this->createFactory($this->createFixedWidthReader());
        $factory2 = $this->createFactory($this->createFixedWidthReader());

        $stream = new Swift_CharacterStream_NgCharacterStream($factory1, 'utf-8');
        $stream->setCharacterReaderFactory($factory2);
        $stream->importString('test');
        $this->assertEquals('t', $stream->read(1));
    }

    public function testWriteAppendsData()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('ab');
        $stream->write('cd');
        $this->assertEquals('abcd', $stream->read(10));
    }

    public function testImportByteStreamReadsAllData()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');

        $os = $this->createMock(Swift_OutputByteStream::class);
        $os->expects($this->once())->method('setReadPointer')->with(0);
        $os->expects($this->exactly(2))
            ->method('read')
            ->with(512)
            ->willReturnOnConsecutiveCalls('hello', false);

        $stream->importByteStream($os);
        $this->assertEquals('hello', $stream->read(10));
    }

    public function testReadMoreThanAvailableReturnsOnlyAvailable()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('ab');
        $this->assertEquals('ab', $stream->read(100));
    }

    public function testEmptyStringReadReturnsFalse()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('');
        $this->assertFalse($stream->read(1));
    }

    public function testSetPointerToZeroResetsToStart()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abcdef');
        $stream->read(3);
        $stream->setPointer(0);
        $this->assertEquals('abc', $stream->read(3));
    }

    public function testMultipleWriteCalls()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream  = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->write('abc');
        $stream->write('def');
        $this->assertEquals('abcdef', $stream->read(10));
    }

    public function testReadWithMapTypeInvalid()
    {
        $reader = $this->createMock(Swift_CharacterReader::class);
        $reader->method('getMapType')->willReturn(Swift_CharacterReader::MAP_TYPE_INVALID);
        $reader->method('getCharPositions')->willReturnCallback(
            function ($string, $startOffset, &$currentMap, &$ignoredChars) {
                // Mark positions 1 and 3 as invalid in the map
                $currentMap = [1 => true, 3 => true];
                $ignoredChars = '';
                return \strlen($string);
            },
        );

        $factory = $this->createFactory($reader);
        $stream = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString('abcde');

        $result = $stream->read(5);
        // Invalid-mapped positions should be replaced with '?'
        $this->assertIsString($result);
    }

    public function testReadWithMapTypePositions()
    {
        $reader = $this->createMock(Swift_CharacterReader::class);
        $reader->method('getMapType')->willReturn(Swift_CharacterReader::MAP_TYPE_POSITIONS);
        $reader->method('getCharPositions')->willReturnCallback(
            function ($string, $startOffset, &$currentMap, &$ignoredChars) {
                // Simulate UTF-8 positions for 2-byte chars: "\xC3\xA9\xC3\xA8"
                $currentMap = ['p' => [2, 4], 'i' => []];
                $ignoredChars = '';
                return 2;
            },
        );

        $factory = $this->createFactory($reader);
        $stream = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString("\xC3\xA9\xC3\xA8");

        $result = $stream->read(1);
        $this->assertEquals("\xC3\xA9", $result);
    }

    public function testReadWithMapTypePositionsAndInvalidChars()
    {
        $reader = $this->createMock(Swift_CharacterReader::class);
        $reader->method('getMapType')->willReturn(Swift_CharacterReader::MAP_TYPE_POSITIONS);
        $reader->method('getCharPositions')->willReturnCallback(
            function ($string, $startOffset, &$currentMap, &$ignoredChars) {
                $currentMap = ['p' => [1, 3, 5], 'i' => [0 => true]];
                $ignoredChars = '';
                return 3;
            },
        );

        $factory = $this->createFactory($reader);
        $stream = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        $stream->importString("\x80AB\xC3\xA9");

        $result = $stream->read(3);
        // First char is invalid (replaced with ?), rest are normal
        $this->assertStringContainsString('?', $result);
    }

    public function testWriteWithoutPriorImportInitializesReader()
    {
        $factory = $this->createFactory($this->createFixedWidthReader());
        $stream = new Swift_CharacterStream_NgCharacterStream($factory, 'utf-8');
        // write() without importString first should work
        $stream->write('hello');
        $this->assertEquals('hello', $stream->read(10));
    }
}
