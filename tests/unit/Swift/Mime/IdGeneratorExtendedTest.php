<?php

class Swift_Mime_IdGeneratorExtendedTest extends PHPUnit\Framework\TestCase
{
    public function testGetIdRightReturnsConstructorValue()
    {
        $gen = new Swift_Mime_IdGenerator('example.com');
        $this->assertEquals('example.com', $gen->getIdRight());
    }

    public function testSetIdRightChangesValue()
    {
        $gen = new Swift_Mime_IdGenerator('old.com');
        $gen->setIdRight('new.com');
        $this->assertEquals('new.com', $gen->getIdRight());
    }

    public function testGenerateIdContainsAtSign()
    {
        $gen = new Swift_Mime_IdGenerator('example.com');
        $this->assertStringContainsString('@', $gen->generateId());
    }

    public function testGenerateIdContainsIdRight()
    {
        $gen = new Swift_Mime_IdGenerator('my-domain.org');
        $id  = $gen->generateId();
        $this->assertStringEndsWith('@my-domain.org', $id);
    }

    public function testGenerateIdHas32CharLeftPart()
    {
        $gen   = new Swift_Mime_IdGenerator('example.com');
        $id    = $gen->generateId();
        $parts = explode('@', $id);
        $this->assertEquals(32, strlen($parts[0]));
    }

    public function testGenerateIdLeftPartIsHex()
    {
        $gen   = new Swift_Mime_IdGenerator('example.com');
        $id    = $gen->generateId();
        $parts = explode('@', $id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $parts[0]);
    }

    public function testGenerateIdIsUnique()
    {
        $gen = new Swift_Mime_IdGenerator('example.com');
        $ids = [];
        for ($i = 0; $i < 100; ++$i) {
            $ids[] = $gen->generateId();
        }
        $this->assertCount(100, array_unique($ids));
    }

    public function testImplementsIdGeneratorInterface()
    {
        $gen = new Swift_Mime_IdGenerator('example.com');
        $this->assertInstanceOf(Swift_IdGenerator::class, $gen);
    }

    public function testSetIdRightReflectsInGeneratedId()
    {
        $gen = new Swift_Mime_IdGenerator('first.com');
        $gen->setIdRight('second.com');
        $this->assertStringEndsWith('@second.com', $gen->generateId());
    }

    public function testEmptyDomainAllowed()
    {
        $gen = new Swift_Mime_IdGenerator('');
        $id  = $gen->generateId();
        $this->assertStringEndsWith('@', $id);
    }

    public function testIdWithSubdomain()
    {
        $gen = new Swift_Mime_IdGenerator('sub.example.com');
        $id  = $gen->generateId();
        $this->assertStringEndsWith('@sub.example.com', $id);
    }

    public function testIdWithHyphenatedDomain()
    {
        $gen = new Swift_Mime_IdGenerator('my-domain.example.com');
        $id  = $gen->generateId();
        $this->assertStringEndsWith('@my-domain.example.com', $id);
    }
}
