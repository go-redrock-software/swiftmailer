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
}
