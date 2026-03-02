<?php

class Swift_KeyCache_NullKeyCacheTest extends PHPUnit\Framework\TestCase
{
    public function testImplementsKeyCacheInterface()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $this->assertInstanceOf(Swift_KeyCache::class, $cache);
    }

    public function testHasKeyAlwaysReturnsFalse()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $this->assertFalse($cache->hasKey('ns', 'item'));
    }

    public function testHasKeyReturnsFalseAfterSetString()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->setString('ns', 'item', 'data', Swift_KeyCache::MODE_WRITE);
        $this->assertFalse($cache->hasKey('ns', 'item'));
    }

    public function testGetStringReturnsNull()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $this->assertNull($cache->getString('ns', 'item'));
    }

    public function testGetStringReturnsNullAfterSetString()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->setString('ns', 'item', 'data', Swift_KeyCache::MODE_WRITE);
        $this->assertNull($cache->getString('ns', 'item'));
    }

    public function testSetStringDoesNotThrow()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->setString('ns', 'item', 'data', Swift_KeyCache::MODE_WRITE);
        $this->addToAssertionCount(1);
    }

    public function testSetStringAppendModeDoesNotThrow()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->setString('ns', 'item', 'data', Swift_KeyCache::MODE_APPEND);
        $this->addToAssertionCount(1);
    }

    public function testImportFromByteStreamDoesNotThrow()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $os    = $this->createMock(Swift_OutputByteStream::class);
        $cache->importFromByteStream('ns', 'item', $os, Swift_KeyCache::MODE_WRITE);
        $this->addToAssertionCount(1);
    }

    public function testExportToByteStreamDoesNotWriteAnything()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $is    = $this->createMock(Swift_InputByteStream::class);
        $is->expects($this->never())->method('write');
        $cache->exportToByteStream('ns', 'item', $is);
    }

    public function testGetInputByteStreamReturnsNull()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $this->assertNull($cache->getInputByteStream('ns', 'item'));
    }

    public function testGetInputByteStreamWithWriteThroughReturnsNull()
    {
        $cache        = new Swift_KeyCache_NullKeyCache();
        $writeThrough = $this->createMock(Swift_InputByteStream::class);
        $this->assertNull($cache->getInputByteStream('ns', 'item', $writeThrough));
    }

    public function testClearKeyDoesNotThrow()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->clearKey('ns', 'item');
        $this->addToAssertionCount(1);
    }

    public function testClearAllDoesNotThrow()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->clearAll('ns');
        $this->addToAssertionCount(1);
    }

    public function testHasKeyReturnsFalseForVariousKeys()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $this->assertFalse($cache->hasKey('ns1', 'key1'));
        $this->assertFalse($cache->hasKey('ns2', 'key2'));
        $this->assertFalse($cache->hasKey('', ''));
    }

    public function testMultipleSetStringCallsDoNotAccumulate()
    {
        $cache = new Swift_KeyCache_NullKeyCache();
        $cache->setString('ns', 'item', 'data1', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns', 'item', 'data2', Swift_KeyCache::MODE_APPEND);
        $this->assertNull($cache->getString('ns', 'item'));
    }
}
