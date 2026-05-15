<?php

class Swift_Plugins_Loggers_EchoLoggerTest extends PHPUnit\Framework\TestCase
{
    public function testAddingEntryDumpsSingleLineWithoutHtml()
    {
        $logger = new Swift_Plugins_Loggers_EchoLogger(false);
        \ob_start();
        $logger->add('>> Foo');
        $data = \ob_get_clean();

        $this->assertEquals('&gt;&gt; Foo'.PHP_EOL, $data);
    }

    public function testAddingEntryDumpsEscapedLineWithHtml()
    {
        $logger = new Swift_Plugins_Loggers_EchoLogger(true);
        \ob_start();
        $logger->add('>> Foo');
        $data = \ob_get_clean();

        $this->assertEquals('&gt;&gt; Foo<br />'.PHP_EOL, $data);
    }

    public function testEchoLoggerEscapesHtmlInNonHtmlMode()
    {
        $logger = new Swift_Plugins_Loggers_EchoLogger(false);
        \ob_start();
        $logger->add('<script>alert("xss")</script>');
        $data = \ob_get_clean();

        $this->assertStringNotContainsString('<script>', $data);
        $this->assertStringContainsString('&lt;script&gt;', $data);
    }

    public function testClearIsNoOp()
    {
        $logger = new Swift_Plugins_Loggers_EchoLogger(false);
        // clear() should not throw or produce output
        \ob_start();
        $logger->clear();
        $data = \ob_get_clean();
        $this->assertSame('', $data);
    }

    public function testDumpIsNoOp()
    {
        $logger = new Swift_Plugins_Loggers_EchoLogger(false);
        // dump() should not throw or produce output
        \ob_start();
        $logger->dump();
        $data = \ob_get_clean();
        $this->assertSame('', $data);
    }
}
