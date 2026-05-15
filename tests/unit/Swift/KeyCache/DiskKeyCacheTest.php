<?php

class Swift_KeyCache_DiskKeyCacheTest extends PHPUnit\Framework\TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = \sys_get_temp_dir().'/swift_test_disk_cache_'.\uniqid();
        \mkdir($this->cachePath);
    }

    protected function tearDown(): void
    {
        // Clean up
        $this->removeDir($this->cachePath);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (\is_dir($path)) {
                $this->removeDir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    private function createCache(): Swift_KeyCache_DiskKeyCache
    {
        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();

        return new Swift_KeyCache_DiskKeyCache($stream, $this->cachePath);
    }

    public function testImplementsKeyCacheInterface()
    {
        $cache = $this->createCache();
        $this->assertInstanceOf(Swift_KeyCache::class, $cache);
    }

    public function testSetStringAndGetString()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'hello', Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('hello', $cache->getString('ns1', 'key1'));
    }

    public function testSetStringOverwrite()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'first', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns1', 'key1', 'second', Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('second', $cache->getString('ns1', 'key1'));
    }

    public function testSetStringAppend()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'hello', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns1', 'key1', ' world', Swift_KeyCache::MODE_APPEND);
        $this->assertEquals('hello world', $cache->getString('ns1', 'key1'));
    }

    public function testHasKeyReturnsTrueAfterSet()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'data', Swift_KeyCache::MODE_WRITE);
        $this->assertTrue($cache->hasKey('ns1', 'key1'));
    }

    public function testHasKeyReturnsFalseForMissing()
    {
        $cache = $this->createCache();
        $this->assertFalse($cache->hasKey('ns1', 'nonexistent'));
    }

    public function testClearKeyRemovesItem()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'data', Swift_KeyCache::MODE_WRITE);
        $cache->clearKey('ns1', 'key1');
        $this->assertFalse($cache->hasKey('ns1', 'key1'));
    }

    public function testClearAllRemovesAllItems()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'data1', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns1', 'key2', 'data2', Swift_KeyCache::MODE_WRITE);
        $cache->clearAll('ns1');
        $this->assertFalse($cache->hasKey('ns1', 'key1'));
        $this->assertFalse($cache->hasKey('ns1', 'key2'));
    }

    public function testDifferentNamespacesAreIsolated()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'ns1data', Swift_KeyCache::MODE_WRITE);
        $cache->setString('ns2', 'key1', 'ns2data', Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('ns1data', $cache->getString('ns1', 'key1'));
        $this->assertEquals('ns2data', $cache->getString('ns2', 'key1'));
    }

    public function testGetStringReturnsNullForMissingKey()
    {
        $cache = $this->createCache();
        $this->assertNull($cache->getString('ns1', 'missing'));
    }

    public function testExportToByteStream()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'export data', Swift_KeyCache::MODE_WRITE);

        $is = $this->createMock(Swift_InputByteStream::class);
        $is->expects($this->atLeastOnce())
            ->method('write')
            ->with($this->stringContains('export data'));

        $cache->exportToByteStream('ns1', 'key1', $is);
    }

    public function testExportToByteStreamSkipsNonExistentKey()
    {
        $cache = $this->createCache();
        $is    = $this->createMock(Swift_InputByteStream::class);
        $is->expects($this->never())->method('write');
        $cache->exportToByteStream('ns1', 'missing', $is);
    }

    public function testImportFromByteStream()
    {
        $cache = $this->createCache();
        $os    = $this->createMock(Swift_OutputByteStream::class);
        $os->expects($this->exactly(2))
            ->method('read')
            ->with(8192)
            ->willReturnOnConsecutiveCalls('imported data', false);

        $cache->importFromByteStream('ns1', 'key1', $os, Swift_KeyCache::MODE_WRITE);
        $this->assertEquals('imported data', $cache->getString('ns1', 'key1'));
    }

    public function testClearKeyForNonExistentKeyDoesNotThrow()
    {
        $cache = $this->createCache();
        $cache->clearKey('ns1', 'nonexistent');
        $this->addToAssertionCount(1);
    }

    public function testClearAllForNonExistentNamespaceDoesNotThrow()
    {
        $cache = $this->createCache();
        $cache->clearAll('nonexistent');
        $this->addToAssertionCount(1);
    }

    public function testSetStringWithInvalidModeThrows()
    {
        $cache = $this->createCache();
        $this->expectException(Swift_SwiftException::class);
        $cache->setString('ns1', 'key1', 'data', 999);
    }

    public function testGetInputByteStreamReturnsStream()
    {
        $cache = $this->createCache();
        $is    = $cache->getInputByteStream('ns1', 'key1');
        $this->assertInstanceOf(Swift_InputByteStream::class, $is);
    }

    public function testGetInputByteStreamWithWriteThrough()
    {
        $cache        = $this->createCache();
        $writeThrough = $this->createMock(Swift_InputByteStream::class);
        $is           = $cache->getInputByteStream('ns1', 'key1', $writeThrough);
        $this->assertInstanceOf(Swift_InputByteStream::class, $is);
    }

    public function testSetStringEmptyContent()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', '', Swift_KeyCache::MODE_WRITE);
        $this->assertTrue($cache->hasKey('ns1', 'key1'));
        $this->assertEquals('', $cache->getString('ns1', 'key1'));
    }

    public function testSetStringLargeContent()
    {
        $cache   = $this->createCache();
        $content = \str_repeat('x', 100000);
        $cache->setString('ns1', 'key1', $content, Swift_KeyCache::MODE_WRITE);
        $this->assertEquals($content, $cache->getString('ns1', 'key1'));
    }

    public function testImportFromByteStreamWithInvalidModeThrows()
    {
        $cache = $this->createCache();
        $os = $this->createMock(Swift_OutputByteStream::class);

        $this->expectException(Swift_SwiftException::class);
        $cache->importFromByteStream('ns1', 'key1', $os, 999);
    }

    public function testGetStringReturnsNullForNonExistentKey()
    {
        $cache = $this->createCache();
        // Prepare the namespace first
        $cache->setString('ns1', 'dummy', 'x', Swift_KeyCache::MODE_WRITE);
        $result = $cache->getString('ns1', 'nonexistent');
        $this->assertNull($result);
    }

    public function testWakeupResetsKeys()
    {
        $cache = $this->createCache();
        $cache->setString('ns1', 'key1', 'data', Swift_KeyCache::MODE_WRITE);
        $cache->__wakeup();
        // After wakeup, keys should be empty so clearAll on the namespace does nothing
        $cache->clearAll('ns1');
        $this->addToAssertionCount(1);
    }

    public function testPathTraversalInNsKeyIsRejected()
    {
        $cache = $this->createCache();
        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('invalid characters');
        $cache->setString('../../etc', 'key1', 'data', Swift_KeyCache::MODE_WRITE);
    }

    public function testPathTraversalInItemKeyIsRejected()
    {
        $cache = $this->createCache();
        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('invalid characters');
        $cache->setString('ns1', '../passwd', 'data', Swift_KeyCache::MODE_WRITE);
    }

    public function testNullByteInKeyIsRejected()
    {
        $cache = $this->createCache();
        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('invalid characters');
        $cache->setString("test\0evil", 'key1', 'data', Swift_KeyCache::MODE_WRITE);
    }

    public function testValidKeysAreAccepted()
    {
        $cache = $this->createCache();
        $cache->setString('valid-ns.1', 'item_key-2.txt', 'data', Swift_KeyCache::MODE_WRITE);
        $this->assertTrue($cache->hasKey('valid-ns.1', 'item_key-2.txt'));
        $this->assertEquals('data', $cache->getString('valid-ns.1', 'item_key-2.txt'));
    }

    public function testEmptyKeyIsRejected()
    {
        $cache = $this->createCache();
        $this->expectException(Swift_IoException::class);
        $this->expectExceptionMessage('must not be empty');
        $cache->setString('', 'key1', 'data', Swift_KeyCache::MODE_WRITE);
    }
}
