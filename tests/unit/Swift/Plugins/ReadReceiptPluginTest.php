<?php

class Swift_Plugins_ReadReceiptPluginTest extends PHPUnit\Framework\TestCase
{
    public function testModeCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL);
        $this->assertEquals(Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL, $plugin->getMode());

        $plugin->setMode(Swift_Plugins_ReadReceiptPlugin::MODE_BOTH);
        $this->assertEquals(Swift_Plugins_ReadReceiptPlugin::MODE_BOTH, $plugin->getMode());
    }

    public function testInvalidModeThrows()
    {
        $this->expectException(Swift_SwiftException::class);
        new Swift_Plugins_ReadReceiptPlugin(0);
    }

    public function testInvalidModeAboveRangeThrows()
    {
        $this->expectException(Swift_SwiftException::class);
        new Swift_Plugins_ReadReceiptPlugin(4);
    }

    public function testAddressCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_MDN,
            'receipts@example.com'
        );
        $this->assertEquals('receipts@example.com', $plugin->getAddress());

        $plugin->setAddress('other@example.com');
        $this->assertEquals('other@example.com', $plugin->getAddress());
    }

    public function testPixelUrlGeneratorCanBeSetAndFetched()
    {
        $generator = fn () => 'http://example.com/pixel.gif';
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            $generator
        );
        $this->assertSame($generator, $plugin->getPixelUrlGenerator());
    }

    public function testPluginImplementsSendListener()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin();
        $this->assertInstanceOf(Swift_Events_SendListener::class, $plugin);
    }

    public function testDefaultsToMdnMode()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin();
        $this->assertEquals(Swift_Plugins_ReadReceiptPlugin::MODE_MDN, $plugin->getMode());
    }

    public function testMdnModeAddsDispositionNotificationHeader()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_MDN,
            'receipts@example.com'
        );

        $message = $this->createRealMessage();
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertTrue($message->getHeaders()->has('Disposition-Notification-To'));
        $readReceipt = $message->getReadReceiptTo();
        $this->assertArrayHasKey('receipts@example.com', $readReceipt);
    }

    public function testMdnModeDefaultsToFromAddress()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(Swift_Plugins_ReadReceiptPlugin::MODE_MDN);

        $message = $this->createRealMessage();
        $message->setFrom('sender@example.com');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $readReceipt = $message->getReadReceiptTo();
        $this->assertArrayHasKey('sender@example.com', $readReceipt);
    }

    public function testMdnModeSkipsWhenNoAddress()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(Swift_Plugins_ReadReceiptPlugin::MODE_MDN);

        $message = $this->createRealMessage();
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertFalse($message->getHeaders()->has('Disposition-Notification-To'));
    }

    public function testMdnHeaderRemovedAfterSend()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_MDN,
            'receipts@example.com'
        );

        $message = $this->createRealMessage();
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);
        $this->assertTrue($message->getHeaders()->has('Disposition-Notification-To'));

        $plugin->sendPerformed($evt);
        $this->assertFalse($message->getHeaders()->has('Disposition-Notification-To'));
    }

    public function testMdnPreservesExistingReadReceiptHeader()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_MDN,
            'new@example.com'
        );

        $message = $this->createRealMessage();
        $message->setReadReceiptTo('original@example.com');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);
        $readReceipt = $message->getReadReceiptTo();
        $this->assertArrayHasKey('new@example.com', $readReceipt);

        $plugin->sendPerformed($evt);
        $readReceipt = $message->getReadReceiptTo();
        $this->assertArrayHasKey('original@example.com', $readReceipt);
    }

    public function testPixelModeInjectsTrackingPixelInHtmlBody()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => 'http://track.example.com/open/abc123'
        );

        $message = $this->createRealMessage();
        $message->setBody('<html><body><p>Hello</p></body></html>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $body = $message->getBody();
        $this->assertStringContainsString('track.example.com/open/abc123', $body);
        $this->assertStringContainsString('width="1" height="1"', $body);
        $this->assertStringContainsString('<img ', $body);
    }

    public function testPixelInjectedBeforeClosingBodyTag()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => 'http://track.example.com/pixel'
        );

        $message = $this->createRealMessage();
        $message->setBody('<html><body><p>Content</p></body></html>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $body = $message->getBody();
        $imgPos = strpos($body, '<img ');
        $bodyClosePos = strpos($body, '</body>');
        $this->assertLessThan($bodyClosePos, $imgPos);
    }

    public function testPixelAppendedWhenNoBodyTag()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => 'http://track.example.com/pixel'
        );

        $message = $this->createRealMessage();
        $message->setBody('<p>Hello World</p>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $body = $message->getBody();
        $this->assertStringEndsWith('" />', $body);
        $this->assertStringContainsString('<img ', $body);
    }

    public function testPixelUrlIsHtmlEncoded()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => 'http://track.example.com/open?id=1&token=abc'
        );

        $message = $this->createRealMessage();
        $message->setBody('<html><body>Hi</body></html>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $body = $message->getBody();
        $this->assertStringContainsString('id=1&amp;token=abc', $body);
        $this->assertStringNotContainsString('id=1&token', $body);
    }

    public function testPixelBodyRestoredAfterSend()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => 'http://track.example.com/pixel'
        );

        $originalBody = '<html><body><p>Hello</p></body></html>';
        $message = $this->createRealMessage();
        $message->setBody($originalBody, 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);
        $this->assertNotEquals($originalBody, $message->getBody());

        $plugin->sendPerformed($evt);
        $this->assertEquals($originalBody, $message->getBody());
    }

    public function testPixelSkippedWhenNoGenerator()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL);

        $originalBody = '<html><body><p>Hello</p></body></html>';
        $message = $this->createRealMessage();
        $message->setBody($originalBody, 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($originalBody, $message->getBody());
    }

    public function testPixelSkippedWhenGeneratorReturnsNull()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => null
        );

        $originalBody = '<html><body><p>Hello</p></body></html>';
        $message = $this->createRealMessage();
        $message->setBody($originalBody, 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($originalBody, $message->getBody());
    }

    public function testPixelSkippedWhenGeneratorReturnsEmptyString()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => ''
        );

        $originalBody = '<html><body><p>Hello</p></body></html>';
        $message = $this->createRealMessage();
        $message->setBody($originalBody, 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($originalBody, $message->getBody());
    }

    public function testPixelNotInjectedInPlainTextBody()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            fn () => 'http://track.example.com/pixel'
        );

        $message = $this->createRealMessage();
        $message->setBody('Plain text only', 'text/plain');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals('Plain text only', $message->getBody());
    }

    public function testBothModeAddsMdnAndPixel()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_BOTH,
            'receipts@example.com',
            fn () => 'http://track.example.com/pixel'
        );

        $message = $this->createRealMessage();
        $message->setBody('<html><body>Hi</body></html>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertTrue($message->getHeaders()->has('Disposition-Notification-To'));
        $this->assertStringContainsString('<img ', $message->getBody());
    }

    public function testBothModeRestoresEverythingAfterSend()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_BOTH,
            'receipts@example.com',
            fn () => 'http://track.example.com/pixel'
        );

        $originalBody = '<html><body>Hi</body></html>';
        $message = $this->createRealMessage();
        $message->setBody($originalBody, 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);
        $plugin->sendPerformed($evt);

        $this->assertFalse($message->getHeaders()->has('Disposition-Notification-To'));
        $this->assertEquals($originalBody, $message->getBody());
    }

    public function testGeneratorReceivesMessage()
    {
        $receivedMessage = null;
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            null,
            function (Swift_Mime_SimpleMessage $msg) use (&$receivedMessage) {
                $receivedMessage = $msg;
                return 'http://track.example.com/pixel/' . $msg->getId();
            }
        );

        $message = $this->createRealMessage();
        $message->setBody('<p>Hello</p>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertSame($message, $receivedMessage);
        $this->assertStringContainsString($message->getId(), $message->getBody());
    }

    public function testPixelModeOnlyDoesNotAddMdnHeader()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_PIXEL,
            'receipts@example.com',
            fn () => 'http://track.example.com/pixel'
        );

        $message = $this->createRealMessage();
        $message->setBody('<p>Hello</p>', 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertFalse($message->getHeaders()->has('Disposition-Notification-To'));
    }

    public function testMdnModeOnlyDoesNotInjectPixel()
    {
        $plugin = new Swift_Plugins_ReadReceiptPlugin(
            Swift_Plugins_ReadReceiptPlugin::MODE_MDN,
            'receipts@example.com',
            fn () => 'http://track.example.com/pixel'
        );

        $originalBody = '<html><body>Hello</body></html>';
        $message = $this->createRealMessage();
        $message->setBody($originalBody, 'text/html');
        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($originalBody, $message->getBody());
    }

    private function createRealMessage(): Swift_Message
    {
        return new Swift_Message('Test Subject');
    }

    private function createSendEvent(Swift_Mime_SimpleMessage $message): Swift_Events_SendEvent
    {
        $transport = $this->getMockBuilder(Swift_Transport::class)->getMock();

        return new Swift_Events_SendEvent($transport, $message);
    }
}
