<?php

use PHPUnit\Framework\TestCase;

class SwiftDependencyContainerTest extends TestCase
{
    private $swiftDependencyContainer;

    protected function setUp(): void
    {
        $this->swiftDependencyContainer = Swift_DependencyContainer::getInstance();
    }

    public function testRegister(): void
    {
        $this->swiftDependencyContainer->register('cache')->asNewInstanceOf('Swift_KeyCache_NullKeyCache');
        $this->assertTrue($this->swiftDependencyContainer->has('cache'));
    }

    public function testAliasOf(): void
    {
        $this->swiftDependencyContainer->register('cache.array')->asNewInstanceOf('Swift_KeyCache_NullKeyCache');
        $this->swiftDependencyContainer->register('cache')->asAliasOf('cache.array');
        $this->assertInstanceOf('Swift_KeyCache_NullKeyCache', $this->swiftDependencyContainer->lookup('cache'));
    }

    public function testValue(): void
    {
        $this->swiftDependencyContainer->register('tempdir')->asValue('/tmp');
        $this->assertEquals('/tmp', $this->swiftDependencyContainer->lookup('tempdir'));
    }

    public function testSharedInstanceOf(): void
    {
        $this->swiftDependencyContainer->register('cache.null')->asSharedInstanceOf('Swift_KeyCache_NullKeyCache');
        $this->assertInstanceOf('Swift_KeyCache_NullKeyCache', $this->swiftDependencyContainer->lookup('cache.null'));
    }

    public function testWithDependencies(): void
    {
        $this->swiftDependencyContainer->register('cache.array')->asSharedInstanceOf('Swift_KeyCache_ArrayKeyCache')->withDependencies(['cache.inputstream']);
        $this->assertInstanceOf('Swift_KeyCache_ArrayKeyCache', $this->swiftDependencyContainer->lookup('cache.array'));
        // Add assert to check if dependencies have been resolved properly
    }
}
