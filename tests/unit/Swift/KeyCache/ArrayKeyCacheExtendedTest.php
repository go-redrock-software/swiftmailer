<?php

class Swift_KeyCache_ArrayKeyCacheExtendedTest extends PHPUnit\Framework\TestCase
{
    private function createCache(): Swift_KeyCache_ArrayKeyCache
    {
        return new Swift_KeyCache_ArrayKeyCache(new Swift_KeyCache_SimpleKeyCacheInputStream());
    }

    public function testSetStringAndGetString()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'value', Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('value', $cache->getString('ns', 'key'));
    }

    public function testSetStringOverwrite()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'first', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns', 'key', 'second', Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('second', $cache->getString('ns', 'key'));
    }

    public function testSetStringAppend()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'hello', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns', 'key', ' world', Swift_KeyCache::MODE_APPEND);
        $this->assertEquals('hello world', $cache->getString('ns', 'key'));
    }

    public function testHasKeyAfterSet()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'data', Swift_KeyCache::MODE_WRITE);
        $this->assertTrue($cache->hasKey('ns', 'key'));
    }

    public function testHasKeyFalseForMissing()
    {
        $cache = $this->createCache();
        $this->assertFalse($cache->hasKey('ns', 'missing'));
    }

    public function testClearKeyRemovesItem()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'data', Swift_KeyCache::MODE_WRITE);
        $cache->clearKey('ns', 'key');
        $this->assertFalse($cache->hasKey('ns', 'key'));
    }

    public function testClearAllRemovesNamespace()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key1', 'a', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns', 'key2', 'b', Swift_KeyCache::MODE_WRITE);
        $cache->clearAll('ns');
        $this->assertFalse($cache->hasKey('ns', 'key1'));
        $this->assertFalse($cache->hasKey('ns', 'key2'));
    }

    public function testNamespaceIsolation()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key', 'ns1', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns2', 'key', 'ns2', Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('ns1', $cache->getString('ns1', 'key'));
        $this->assertEquals('ns2', $cache->getString('ns2', 'key'));
    }

    public function testClearAllDoesNotAffectOtherNamespace()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key', 'data1', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns2', 'key', 'data2', Swift_KeyCache::MODE_WRITE);
        $cache->clearAll('ns1');
        $this->assertTrue($cache->hasKey('ns2', 'key'));
    }

    public function testGetStringReturnsNullForMissing()
    {
        $cache = $this->createCache();
        $this->assertNull($cache->getString('ns', 'nonexistent'));
    }

    public function testImportFromByteStream()
    {
        $cache = $this->createCache();
        $os    = $this->createMock(Swift_OutputByteStream::class);
        $os->expects($this->exactly(2))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('streamed', false);

        $cache->importFromByteStream('ns', 'key', $os, Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('streamed', $cache->getString('ns', 'key'));
    }

    public function testExportToByteStream()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'exported', Swift_KeyCache::MODE_WRITE);

        $is = $this->createMock(Swift_InputByteStream::class);
        $is->expects($this->once())->method('write')->with('exported');

        $cache->exportToByteStream('ns', 'key', $is);
    }

    public function testGetInputByteStream()
    {
        $cache = $this->createCache();
        $is    = $cache->getInputByteStream('ns', 'key');
        $this->assertInstanceOf(Swift_InputByteStream::class, $is);
    }

    public function testSetEmptyString()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', '', Swift_KeyCache::MODE_WRITE);
        $this->assertTrue($cache->hasKey('ns', 'key'));
    }

    public function testMultipleAppends()
    {
        $cache = $this->createCache();
        $cache->setString('ns', 'key', 'a', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns', 'key', 'b', Swift_KeyCache::MODE_APPEND);
        $cache->setString('ns', 'key', 'c', Swift_KeyCache::MODE_APPEND);
        $this->assertEquals('abc', $cache->getString('ns', 'key'));
    }

    public function testClearKeyForNonExistentKeyDoesNotThrow()
    {
        $cache = $this->createCache();
        $cache->clearKey('ns', 'nonexistent');
        $this->addToAssertionCount(1);
    }

    public function testLargeData()
    {
        $cache = $this->createCache();
        $data  = str_repeat('x', 50000);
        $cache->setString('ns', 'key', $data, Swift_KeyCache::MODE_WRITE);
        $this->assertEquals($data, $cache->getString('ns', 'key'));
    }
}
