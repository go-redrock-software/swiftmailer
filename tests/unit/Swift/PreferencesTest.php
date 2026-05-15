<?php

use PHPUnit\Framework\TestCase;

class Swift_PreferencesTest extends TestCase
{
    public function testGetInstanceReturnsSingleton(): void
    {
        $prefs1 = Swift_Preferences::getInstance();
        $prefs2 = Swift_Preferences::getInstance();

        $this->assertSame($prefs1, $prefs2);
    }

    public function testSetCharsetReturnsSelf(): void
    {
        $prefs = Swift_Preferences::getInstance();
        $result = $prefs->setCharset('utf-8');

        $this->assertSame($prefs, $result);
    }

    public function testSetTempDirReturnsSelf(): void
    {
        $prefs = Swift_Preferences::getInstance();
        $result = $prefs->setTempDir(\sys_get_temp_dir());

        $this->assertSame($prefs, $result);
    }

    public function testSetCacheTypeReturnsSelf(): void
    {
        $prefs = Swift_Preferences::getInstance();
        $result = $prefs->setCacheType('array');

        $this->assertSame($prefs, $result);
    }

    public function testSetCacheTypeRejectsUnknownType(): void
    {
        $prefs = Swift_Preferences::getInstance();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache type "evil"');

        $prefs->setCacheType('evil');
    }

    public function testSetQPDotEscapeReturnsSelf(): void
    {
        $prefs = Swift_Preferences::getInstance();
        $result = $prefs->setQPDotEscape(true);

        $this->assertSame($prefs, $result);
    }
}
