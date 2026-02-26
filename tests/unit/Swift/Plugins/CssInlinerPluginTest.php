<?php

class Swift_Plugins_CssInlinerPluginTest extends PHPUnit\Framework\TestCase
{
    public function testInlinesCssFromStyleBlock()
    {
        if (!\class_exists(TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            $this->markTestSkipped('tijsverkoyen/css-to-inline-styles not installed');
        }

        $plugin = new Swift_Plugins_CssInlinerPlugin();

        $html    = '<html><head><style>p { color: red; }</style></head><body><p>Hello</p></body></html>';
        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C'])
            ->setSubject('Test')
            ->setBody($html, 'text/html');

        $transport = $this->createMock(Swift_Transport::class);
        $event     = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $body = $message->getBody();
        $this->assertStringContainsString('style=', $body);
        $this->assertStringContainsString('color:', $body);
    }

    public function testSkipsPlainTextMessages()
    {
        $plugin = new Swift_Plugins_CssInlinerPlugin();

        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C'])
            ->setSubject('Test')
            ->setBody('Just plain text');

        $transport = $this->createMock(Swift_Transport::class);
        $event     = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $this->assertEquals('Just plain text', $message->getBody());
    }

    public function testInlinesHtmlMimePart()
    {
        if (!\class_exists(TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            $this->markTestSkipped('tijsverkoyen/css-to-inline-styles not installed');
        }

        $plugin = new Swift_Plugins_CssInlinerPlugin();
        $html   = '<html><head><style>h1 { font-size: 20px; }</style></head><body><h1>Hi</h1></body></html>';

        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C'])
            ->setSubject('Test')
            ->setBody('Plain text version')
            ->addPart($html, 'text/html');

        $transport = $this->createMock(Swift_Transport::class);
        $event     = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $found = false;
        foreach ($message->getChildren() as $child) {
            if ('text/html' === $child->getContentType()) {
                $this->assertStringContainsString('style=', $child->getBody());
                $found = true;
            }
        }
        $this->assertTrue($found, 'HTML part should have inlined styles');
    }
}
