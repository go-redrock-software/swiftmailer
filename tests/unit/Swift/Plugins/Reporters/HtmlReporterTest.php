<?php

class Swift_Plugins_Reporters_HtmlReporterTest extends PHPUnit\Framework\TestCase
{
    private $html;

    private $message;

    protected function setUp(): void
    {
        $this->html    = new Swift_Plugins_Reporters_HtmlReporter();
        $this->message = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
    }

    public function testReportingPass()
    {
        \ob_start();
        $this->html->notify(
            $this->message,
            'foo@bar.tld',
            Swift_Plugins_Reporter::RESULT_PASS,
        );
        $html = \ob_get_clean();

        $this->assertMatchesRegularExpression('~ok|pass~i', $html, '%s: Reporter should indicate pass');
        $this->assertMatchesRegularExpression('~foo@bar\.tld~', $html, '%s: Reporter should show address');
    }

    public function testReportingFail()
    {
        \ob_start();
        $this->html->notify(
            $this->message,
            'zip@button',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $html = \ob_get_clean();

        $this->assertMatchesRegularExpression('~fail~i', $html, '%s: Reporter should indicate fail');
        $this->assertMatchesRegularExpression('~zip@button~', $html, '%s: Reporter should show address');
    }

    public function testMultipleReports()
    {
        \ob_start();
        $this->html->notify(
            $this->message,
            'foo@bar.tld',
            Swift_Plugins_Reporter::RESULT_PASS,
        );
        $this->html->notify(
            $this->message,
            'zip@button',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $html = \ob_get_clean();

        $this->assertMatchesRegularExpression('~ok|pass~i', $html, '%s: Reporter should indicate pass');
        $this->assertMatchesRegularExpression('~foo@bar\.tld~', $html, '%s: Reporter should show address');
        $this->assertMatchesRegularExpression('~fail~i', $html, '%s: Reporter should indicate fail');
        $this->assertMatchesRegularExpression('~zip@button~', $html, '%s: Reporter should show address');
    }

    public function testImplementsReporterInterface()
    {
        $this->assertInstanceOf(Swift_Plugins_Reporter::class, $this->html);
    }

    public function testPassOutputContainsGreenBackground()
    {
        \ob_start();
        $this->html->notify($this->message, 'pass@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $html = \ob_get_clean();

        $this->assertStringContainsString('#006600', $html);
        $this->assertStringContainsString('PASS', $html);
    }

    public function testFailOutputContainsRedBackground()
    {
        \ob_start();
        $this->html->notify($this->message, 'fail@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $html = \ob_get_clean();

        $this->assertStringContainsString('#880000', $html);
        $this->assertStringContainsString('FAIL', $html);
    }

    public function testOutputContainsDivElements()
    {
        \ob_start();
        $this->html->notify($this->message, 'test@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $html = \ob_get_clean();

        $this->assertStringContainsString('<div', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    public function testAddressAppearsInOutput()
    {
        \ob_start();
        $this->html->notify($this->message, 'unique-address@example.com', Swift_Plugins_Reporter::RESULT_PASS);
        $html = \ob_get_clean();

        $this->assertStringContainsString('unique-address@example.com', $html);
    }

    /**
     * @dataProvider provideRecipientText
     */
    public function testRecipientIsRenderedAsUtf8Text(string $address, string $escapedAddress, int $result, string $status, string $color): void
    {
        \ob_start();
        try {
            $this->html->notify($this->message, $address, $result);
            $html = \ob_get_contents();
        } finally {
            \ob_end_clean();
        }

        $this->assertSame(
            '<div style="color: #fff; background: '.$color.'; padding: 2px; margin: 2px;">'.PHP_EOL
            .$status.' '.$escapedAddress.PHP_EOL
            .'</div>'.PHP_EOL,
            $html,
        );
    }

    public static function provideRecipientText(): iterable
    {
        $addresses = [
            'markup'       => ['"<img src=x onerror=alert(1)>"@example.org', '&quot;&lt;img src=x onerror=alert(1)&gt;&quot;@example.org'],
            'quotes'       => ['"O\'Reilly & partners"@example.org', '&quot;O&#039;Reilly &amp; partners&quot;@example.org'],
            'entity'       => ['&lt;script&gt;@example.org', '&amp;lt;script&amp;gt;@example.org'],
            'unicode'      => ['用户@例子.测试', '用户@例子.测试'],
            'invalid utf8' => ["bad\xFF<user>@example.org", "bad\u{FFFD}&lt;user&gt;@example.org"],
        ];

        foreach ($addresses as $name => [$address, $escapedAddress]) {
            yield $name.' pass' => [$address, $escapedAddress, Swift_Plugins_Reporter::RESULT_PASS, 'PASS', '#006600'];
            yield $name.' fail' => [$address, $escapedAddress, Swift_Plugins_Reporter::RESULT_FAIL, 'FAIL', '#880000'];
        }
    }

    /**
     * @dataProvider provideRecipientFieldsAndResults
     */
    public function testReporterPluginEscapesValidatedRecipients(string $field, bool $failed): void
    {
        $address = '"<img src=x onerror=alert(1)>"@example.org';
        $message = new Swift_Message('HTML reporting regression');
        $message->{'set'.$field}($address);
        $event = new Swift_Events_SendEvent($this->createMock(Swift_Transport::class), $message);
        $event->setFailedRecipients($failed ? [$address] : []);
        $plugin = new Swift_Plugins_ReporterPlugin($this->html);

        \ob_start();
        try {
            $plugin->sendPerformed($event);
            $html = \ob_get_contents();
        } finally {
            \ob_end_clean();
        }

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(
            ($failed ? 'FAIL' : 'PASS').' &quot;&lt;img src=x onerror=alert(1)&gt;&quot;@example.org',
            $html,
        );
        $this->assertSame([$address => null], $message->{'get'.$field}());
    }

    public static function provideRecipientFieldsAndResults(): iterable
    {
        foreach (['To', 'Cc', 'Bcc'] as $field) {
            yield $field.' pass' => [$field, false];
            yield $field.' fail' => [$field, true];
        }
    }
}
