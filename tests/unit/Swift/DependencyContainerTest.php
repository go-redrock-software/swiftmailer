<?php

class One
{
    public $arg1;

    public $arg2;

    public function __construct($arg1 = null, $arg2 = null)
    {
        $this->arg1 = $arg1;
        $this->arg2 = $arg2;
    }
}

class Swift_DependencyContainerTest extends PHPUnit\Framework\TestCase
{
    private $container;

    protected function setUp(): void
    {
        $this->container = new Swift_DependencyContainer();
    }

    public function testListItemsReturnsAllRegistered()
    {
        $this->container->register('foo')->asValue('bar');
        $this->container->register('baz')->asValue(42);
        $items = $this->container->listItems();
        $this->assertContains('foo', $items);
        $this->assertContains('baz', $items);
    }

    public function testAsValueWithoutRegisterThrows()
    {
        $container = new Swift_DependencyContainer();
        $this->expectException(BadMethodCallException::class);
        $container->asValue('bar');
    }

    public function testRegisterAndLookupValue()
    {
        $this->container->register('foo')->asValue('bar');
        $this->assertEquals('bar', $this->container->lookup('foo'));
    }

    public function testHasReturnsTrueForRegisteredValue()
    {
        $this->container->register('foo')->asValue('bar');
        $this->assertTrue($this->container->has('foo'));
    }

    public function testHasReturnsFalseForUnregisteredValue()
    {
        $this->assertFalse($this->container->has('foo'));
    }

    public function testRegisterAndLookupNewInstance()
    {
        $this->container->register('one')->asNewInstanceOf('One');
        $this->assertInstanceOf('One', $this->container->lookup('one'));
    }

    public function testHasReturnsTrueForRegisteredInstance()
    {
        $this->container->register('one')->asNewInstanceOf('One');
        $this->assertTrue($this->container->has('one'));
    }

    public function testNewInstanceIsAlwaysNew()
    {
        $this->container->register('one')->asNewInstanceOf('One');
        $a = $this->container->lookup('one');
        $b = $this->container->lookup('one');
        $this->assertEquals($a, $b);
    }

    public function testRegisterAndLookupSharedInstance()
    {
        $this->container->register('one')->asSharedInstanceOf('One');
        $this->assertInstanceOf('One', $this->container->lookup('one'));
    }

    public function testHasReturnsTrueForSharedInstance()
    {
        $this->container->register('one')->asSharedInstanceOf('One');
        $this->assertTrue($this->container->has('one'));
    }

    public function testMultipleSharedInstancesAreSameInstance()
    {
        $this->container->register('one')->asSharedInstanceOf('One');
        $a = $this->container->lookup('one');
        $b = $this->container->lookup('one');
        $this->assertEquals($a, $b);
    }

    public function testRegisterAndLookupArray()
    {
        $this->container->register('One')->asArray();
        $this->assertSame([], $this->container->lookup('One'));
    }

    public function testNewInstanceWithDependencies()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('one')->asNewInstanceOf('One')
            ->withDependencies(['foo']);
        $obj = $this->container->lookup('one');
        $this->assertSame('FOO', $obj->arg1);
    }

    public function testNewInstanceWithMultipleDependencies()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('bar')->asValue(42);
        $this->container->register('one')->asNewInstanceOf('One')
            ->withDependencies(['foo', 'bar']);
        $obj = $this->container->lookup('one');
        $this->assertSame('FOO', $obj->arg1);
        $this->assertSame(42, $obj->arg2);
    }

    public function testNewInstanceWithInjectedObjects()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('one')->asNewInstanceOf('One');
        $this->container->register('two')->asNewInstanceOf('One')
            ->withDependencies(['one', 'foo']);
        $obj = $this->container->lookup('two');
        $this->assertEquals($this->container->lookup('one'), $obj->arg1);
        $this->assertSame('FOO', $obj->arg2);
    }

    public function testNewInstanceWithAddConstructorValue()
    {
        $this->container->register('one')->asNewInstanceOf('One')
            ->addConstructorValue('x')
            ->addConstructorValue(99);
        $obj = $this->container->lookup('one');
        $this->assertSame('x', $obj->arg1);
        $this->assertSame(99, $obj->arg2);
    }

    public function testNewInstanceWithAddConstructorLookup()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('bar')->asValue(42);
        $this->container->register('one')->asNewInstanceOf('One')
            ->addConstructorLookup('foo')
            ->addConstructorLookup('bar');

        $obj = $this->container->lookup('one');
        $this->assertSame('FOO', $obj->arg1);
        $this->assertSame(42, $obj->arg2);
    }

    public function testResolvedDependenciesCanBeLookedUp()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('one')->asNewInstanceOf('One');
        $this->container->register('two')->asNewInstanceOf('One')
            ->withDependencies(['one', 'foo']);
        $deps = $this->container->createDependenciesFor('two');
        $this->assertEquals(
            [$this->container->lookup('one'), 'FOO'],
            $deps,
        );
    }

    public function testArrayOfDependenciesCanBeSpecified()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('one')->asNewInstanceOf('One');
        $this->container->register('two')->asNewInstanceOf('One')
            ->withDependencies([['one', 'foo'], 'foo']);

        $obj = $this->container->lookup('two');
        $this->assertEquals([$this->container->lookup('one'), 'FOO'], $obj->arg1);
        $this->assertSame('FOO', $obj->arg2);
    }

    public function testArrayWithDependencies()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('bar')->asValue(42);
        $this->container->register('one')->asArray('One')
            ->withDependencies(['foo', 'bar']);
        $this->assertSame(['FOO', 42], $this->container->lookup('one'));
    }

    public function testAliasCanBeSet()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('bar')->asAliasOf('foo');

        $this->assertSame('FOO', $this->container->lookup('bar'));
    }

    public function testAliasOfAliasCanBeSet()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('bar')->asAliasOf('foo');
        $this->container->register('zip')->asAliasOf('bar');
        $this->container->register('button')->asAliasOf('zip');

        $this->assertSame('FOO', $this->container->lookup('button'));
    }

    public function testLookupThrowsExceptionForUnregisteredItem()
    {
        $this->expectException(Swift_DependencyException::class);
        $this->expectExceptionMessage('Cannot lookup dependency "nonexistent"');
        $this->container->lookup('nonexistent');
    }

    public function testHasReturnsFalseForPartiallyRegisteredItem()
    {
        $this->container->register('incomplete');
        $this->assertFalse($this->container->has('incomplete'));
    }

    public function testRegisterOverwritesPreviousRegistration()
    {
        $this->container->register('foo')->asValue('first');
        $this->assertSame('first', $this->container->lookup('foo'));

        $this->container->register('foo')->asValue('second');
        $this->assertSame('second', $this->container->lookup('foo'));
    }

    public function testListItemsReturnsRegisteredKeys()
    {
        $this->container->register('alpha')->asValue(1);
        $this->container->register('beta')->asValue(2);
        $items = $this->container->listItems();
        $this->assertContains('alpha', $items);
        $this->assertContains('beta', $items);
    }

    public function testListItemsReturnsEmptyArrayInitially()
    {
        $this->assertSame([], $this->container->listItems());
    }

    public function testValueCanBeNull()
    {
        $this->container->register('nullable')->asValue(null);
        $this->assertTrue($this->container->has('nullable'));
        $this->assertNull($this->container->lookup('nullable'));
    }

    public function testValueCanBeArray()
    {
        $arr = [1, 2, 3];
        $this->container->register('arr')->asValue($arr);
        $this->assertSame($arr, $this->container->lookup('arr'));
    }

    public function testValueCanBeObject()
    {
        $obj       = new stdClass();
        $obj->name = 'test';
        $this->container->register('obj')->asValue($obj);
        $this->assertSame($obj, $this->container->lookup('obj'));
    }

    public function testValueCanBeBoolean()
    {
        $this->container->register('truthy')->asValue(true);
        $this->container->register('falsy')->asValue(false);
        $this->assertTrue($this->container->lookup('truthy'));
        $this->assertFalse($this->container->lookup('falsy'));
    }

    public function testValueCanBeEmptyString()
    {
        $this->container->register('empty')->asValue('');
        $this->assertSame('', $this->container->lookup('empty'));
    }

    public function testValueCanBeZero()
    {
        $this->container->register('zero')->asValue(0);
        $this->assertSame(0, $this->container->lookup('zero'));
    }

    public function testNewInstanceReturnsDistinctObjects()
    {
        $this->container->register('one')->asNewInstanceOf('One');
        $a = $this->container->lookup('one');
        $b = $this->container->lookup('one');
        $this->assertNotSame($a, $b);
    }

    public function testSharedInstanceReturnsSameObject()
    {
        $this->container->register('one')->asSharedInstanceOf('One');
        $a = $this->container->lookup('one');
        $b = $this->container->lookup('one');
        $this->assertSame($a, $b);
    }

    public function testNewInstanceWithNoConstructor()
    {
        $this->container->register('obj')->asNewInstanceOf('stdClass');
        $obj = $this->container->lookup('obj');
        $this->assertInstanceOf('stdClass', $obj);
    }

    public function testCreateDependenciesForReturnsEmptyArrayWhenNoArgs()
    {
        $this->container->register('one')->asNewInstanceOf('One');
        $deps = $this->container->createDependenciesFor('one');
        $this->assertSame([], $deps);
    }

    public function testGetInstanceReturnsSingleton()
    {
        $a = Swift_DependencyContainer::getInstance();
        $b = Swift_DependencyContainer::getInstance();
        $this->assertSame($a, $b);
    }

    public function testFluidInterfaceOnRegister()
    {
        $result = $this->container->register('test');
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnAsValue()
    {
        $result = $this->container->register('test')->asValue('x');
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnAsNewInstanceOf()
    {
        $result = $this->container->register('test')->asNewInstanceOf('One');
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnAsSharedInstanceOf()
    {
        $result = $this->container->register('test')->asSharedInstanceOf('One');
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnAsArray()
    {
        $result = $this->container->register('test')->asArray();
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnWithDependencies()
    {
        $this->container->register('foo')->asValue('FOO');
        $result = $this->container->register('test')->asNewInstanceOf('One')->withDependencies(['foo']);
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnAddConstructorValue()
    {
        $result = $this->container->register('test')->asNewInstanceOf('One')->addConstructorValue('x');
        $this->assertSame($this->container, $result);
    }

    public function testFluidInterfaceOnAddConstructorLookup()
    {
        $this->container->register('foo')->asValue('FOO');
        $result = $this->container->register('test')->asNewInstanceOf('One')->addConstructorLookup('foo');
        $this->assertSame($this->container, $result);
    }

    public function testAliasReflectsUpdatedValue()
    {
        $this->container->register('foo')->asValue('original');
        $this->container->register('bar')->asAliasOf('foo');
        $this->assertSame('original', $this->container->lookup('bar'));

        $this->container->register('foo')->asValue('updated');
        $this->assertSame('updated', $this->container->lookup('bar'));
    }

    public function testLookupNonExistentAliasTargetThrows()
    {
        $this->container->register('alias')->asAliasOf('missing');
        $this->expectException(Swift_DependencyException::class);
        $this->container->lookup('alias');
    }

    public function testEmptyArrayLookup()
    {
        $this->container->register('emptyArr')->asArray();
        $result = $this->container->lookup('emptyArr');
        $this->assertSame([], $result);
    }

    public function testNewInstanceWithMixedConstructorValuesAndLookups()
    {
        $this->container->register('foo')->asValue('FOO');
        $this->container->register('one')->asNewInstanceOf('One')
            ->addConstructorValue('direct')
            ->addConstructorLookup('foo');
        $obj = $this->container->lookup('one');
        $this->assertSame('direct', $obj->arg1);
        $this->assertSame('FOO', $obj->arg2);
    }

    public function testValueCanBeFloat()
    {
        $this->container->register('pi')->asValue(3.14);
        $this->assertSame(3.14, $this->container->lookup('pi'));
    }

    public function testValueCanBeNegativeInt()
    {
        $this->container->register('neg')->asValue(-42);
        $this->assertSame(-42, $this->container->lookup('neg'));
    }

    public function testRegisterOverwritesSharedWithValue()
    {
        $this->container->register('item')->asSharedInstanceOf('One');
        $this->assertInstanceOf('One', $this->container->lookup('item'));

        $this->container->register('item')->asValue('replaced');
        $this->assertSame('replaced', $this->container->lookup('item'));
    }

    public function testRegisterOverwritesValueWithNew()
    {
        $this->container->register('item')->asValue('old');
        $this->assertSame('old', $this->container->lookup('item'));

        $this->container->register('item')->asNewInstanceOf('One');
        $this->assertInstanceOf('One', $this->container->lookup('item'));
    }

    public function testWithDependenciesOverwritesPreviousArgs()
    {
        $this->container->register('a')->asValue('A');
        $this->container->register('b')->asValue('B');
        $this->container->register('one')->asNewInstanceOf('One')
            ->withDependencies(['a'])
            ->withDependencies(['b']);

        $obj = $this->container->lookup('one');
        $this->assertSame('B', $obj->arg1);
    }

    public function testListItemsIncludesAllTypes()
    {
        $this->container->register('val')->asValue(1);
        $this->container->register('inst')->asNewInstanceOf('One');
        $this->container->register('shared')->asSharedInstanceOf('One');
        $this->container->register('arr')->asArray();
        $this->container->register('alias')->asAliasOf('val');

        $items = $this->container->listItems();
        $this->assertContains('val', $items);
        $this->assertContains('inst', $items);
        $this->assertContains('shared', $items);
        $this->assertContains('arr', $items);
        $this->assertContains('alias', $items);
        $this->assertCount(5, $items);
    }

    public function testNestedArrayDependencies()
    {
        $this->container->register('a')->asValue('A');
        $this->container->register('b')->asValue('B');
        $this->container->register('c')->asValue('C');
        $this->container->register('one')->asNewInstanceOf('One')
            ->withDependencies([['a', 'b'], 'c']);

        $obj = $this->container->lookup('one');
        $this->assertEquals(['A', 'B'], $obj->arg1);
        $this->assertSame('C', $obj->arg2);
    }

    public function testAddConstructorValueBeforeArgs()
    {
        $this->container->register('one')->asNewInstanceOf('One')
            ->addConstructorValue('first');
        $obj = $this->container->lookup('one');
        $this->assertSame('first', $obj->arg1);
        $this->assertNull($obj->arg2);
    }

    public function testCyclicAliasDependencyThrows()
    {
        $this->container->register('a')->asAliasOf('b');
        $this->container->register('b')->asAliasOf('a');

        $this->expectException(Swift_DependencyException::class);
        $this->expectExceptionMessage('Circular dependency detected');
        $this->container->lookup('a');
    }

    public function testDeeplyNestedAliasChainWithinLimitSucceeds()
    {
        $this->container->register('final')->asValue('FOUND');
        $prev = 'final';
        for ($i = 1; $i <= 15; ++$i) {
            $name = 'alias'.$i;
            $this->container->register($name)->asAliasOf($prev);
            $prev = $name;
        }
        $this->assertSame('FOUND', $this->container->lookup($prev));
    }

    public function testSharedInstancePreservedAcrossLookups()
    {
        $this->container->register('shared')->asSharedInstanceOf('One')
            ->addConstructorValue('initial');

        $first       = $this->container->lookup('shared');
        $first->arg2 = 'modified';

        $second = $this->container->lookup('shared');
        $this->assertSame('modified', $second->arg2);
        $this->assertSame($first, $second);
    }
}
