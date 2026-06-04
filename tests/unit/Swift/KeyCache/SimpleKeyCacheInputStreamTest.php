<?php

class Swift_KeyCache_SimpleKeyCacheInputStreamTest extends PHPUnit\Framework\TestCase
{
    private $nsKey = 'ns1';

    public function testStreamWritesToCacheInAppendMode()
    {
        $cache = $this->getMockBuilder('Swift_KeyCache')->getMock();
        $cache->expects($this->exactly(3))
            ->method('setString')
            ->withConsecutive(
                [$this->nsKey, 'foo', 'a', Swift_KeyCache::MODE_APPEND],
                [$this->nsKey, 'foo', 'b', Swift_KeyCache::MODE_APPEND],
                [$this->nsKey, 'foo', 'c', Swift_KeyCache::MODE_APPEND],
            );

        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->setKeyCache($cache);
        $stream->setNsKey($this->nsKey);
        $stream->setItemKey('foo');

        $stream->write('a');
        $stream->write('b');
        $stream->write('c');
    }

    public function testFlushContentClearsKey()
    {
        $cache = $this->getMockBuilder('Swift_KeyCache')->getMock();
        $cache->expects($this->once())
            ->method('clearKey')
            ->with($this->nsKey, 'foo');

        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->setKeyCache($cache);
        $stream->setNsKey($this->nsKey);
        $stream->setItemKey('foo');

        $stream->flushBuffers();
    }

    public function testClonedStreamStillReferencesSameCache()
    {
        $cache = $this->getMockBuilder('Swift_KeyCache')->getMock();
        $cache->expects($this->exactly(3))
            ->method('setString')
            ->withConsecutive(
                [$this->nsKey, 'foo', 'a', Swift_KeyCache::MODE_APPEND],
                [$this->nsKey, 'foo', 'b', Swift_KeyCache::MODE_APPEND],
                ['test', 'bar', 'x', Swift_KeyCache::MODE_APPEND],
            );

        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->setKeyCache($cache);
        $stream->setNsKey($this->nsKey);
        $stream->setItemKey('foo');

        $stream->write('a');
        $stream->write('b');

        $newStream = clone $stream;
        $newStream->setKeyCache($cache);
        $newStream->setNsKey('test');
        $newStream->setItemKey('bar');

        $newStream->write('x');
    }

    public function testWriteConvertsArrayToString()
    {
        $cache = $this->getMockBuilder('Swift_KeyCache')->getMock();
        $cache->expects($this->once())
            ->method('setString')
            ->with($this->nsKey, 'foo', 'abc', Swift_KeyCache::MODE_APPEND);

        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->setKeyCache($cache);
        $stream->setNsKey($this->nsKey);
        $stream->setItemKey('foo');

        $stream->write(['a', 'b', 'c']);
    }

    public function testWriteForwardsToIsParameter()
    {
        $cache = $this->getMockBuilder('Swift_KeyCache')->getMock();
        $cache->expects($this->once())
            ->method('setString');

        $is = $this->getMockBuilder('Swift_InputByteStream')->getMock();
        $is->expects($this->once())
            ->method('write')
            ->with('data');

        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->setKeyCache($cache);
        $stream->setNsKey($this->nsKey);
        $stream->setItemKey('foo');

        $stream->write('data', $is);
    }

    public function testWriteForwardsToWriteThroughStream()
    {
        $cache = $this->getMockBuilder('Swift_KeyCache')->getMock();
        $cache->expects($this->once())
            ->method('setString');

        $writeThrough = $this->getMockBuilder('Swift_InputByteStream')->getMock();
        $writeThrough->expects($this->once())
            ->method('write')
            ->with('data');

        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->setKeyCache($cache);
        $stream->setNsKey($this->nsKey);
        $stream->setItemKey('foo');
        $stream->setWriteThroughStream($writeThrough);

        $stream->write('data');
    }

    public function testCommitDoesNothing()
    {
        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->commit();
        $this->addToAssertionCount(1);
    }

    public function testBindDoesNothing()
    {
        $is     = $this->getMockBuilder('Swift_InputByteStream')->getMock();
        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->bind($is);
        $this->addToAssertionCount(1);
    }

    public function testUnbindDoesNothing()
    {
        $is     = $this->getMockBuilder('Swift_InputByteStream')->getMock();
        $stream = new Swift_KeyCache_SimpleKeyCacheInputStream();
        $stream->unbind($is);
        $this->addToAssertionCount(1);
    }
}
