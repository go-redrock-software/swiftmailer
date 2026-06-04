<?php

use PHPUnit\Framework\TestCase;

class Swift_PreferencesTest extends TestCase
{
    protected function tearDown(): void
    {
        // testSetQPDotEscapeReturnsSelf mutates the global Swift_Preferences
        // singleton (and the DI container's mime.qpcontentencoder value). Reset
        // it so dot-escaping does not leak into later test classes and break
        // SimpleMessageAcceptanceTest::testComplexEmbeddingOfContent.
        Swift_Preferences::getInstance()->setQPDotEscape(false);
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $prefs1 = Swift_Preferences::getInstance();
        $prefs2 = Swift_Preferences::getInstance();

        $this->assertSame($prefs1, $prefs2);
    }

    public function testSetCharsetReturnsSelf(): void
    {
        $prefs  = Swift_Preferences::getInstance();
        $result = $prefs->setCharset('utf-8');

        $this->assertSame($prefs, $result);
    }

    public function testSetTempDirReturnsSelf(): void
    {
        $prefs  = Swift_Preferences::getInstance();
        $result = $prefs->setTempDir(\sys_get_temp_dir());

        $this->assertSame($prefs, $result);
    }

    public function testSetCacheTypeReturnsSelf(): void
    {
        $prefs  = Swift_Preferences::getInstance();
        $result = $prefs->setCacheType('array');

        $this->assertSame($prefs, $result);
    }

    public function testSetCacheTypeRejectsUnknownType(): void
    {
        $prefs = Swift_Preferences::getInstance();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache type "evil"');

        $prefs->setCacheType('evil');
    }

    public function testSetQPDotEscapeReturnsSelf(): void
    {
        $prefs  = Swift_Preferences::getInstance();
        $result = $prefs->setQPDotEscape(true);

        $this->assertSame($prefs, $result);
    }
}
