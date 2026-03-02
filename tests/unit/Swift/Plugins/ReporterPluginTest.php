<?php

class Swift_Plugins_ReporterPluginTest extends SwiftMailerTestCase
{
    public function testReportingPasses()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['foo@bar.tld' => 'Foo']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn([]);
        $reporter->shouldReceive('notify')->once()->with($message, 'foo@bar.tld', Swift_Plugins_Reporter::RESULT_PASS);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReportingFailedTo()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['foo@bar.tld' => 'Foo', 'zip@button' => 'Zip']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn(['zip@button']);
        $reporter->shouldReceive('notify')->once()->with($message, 'foo@bar.tld', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'zip@button', Swift_Plugins_Reporter::RESULT_FAIL);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReportingFailedCc()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['foo@bar.tld' => 'Foo']);
        $message->shouldReceive('getCc')->zeroOrMoreTimes()->andReturn(['zip@button' => 'Zip', 'test@test.com' => 'Test']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn(['zip@button']);
        $reporter->shouldReceive('notify')->once()->with($message, 'foo@bar.tld', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'zip@button', Swift_Plugins_Reporter::RESULT_FAIL);
        $reporter->shouldReceive('notify')->once()->with($message, 'test@test.com', Swift_Plugins_Reporter::RESULT_PASS);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReportingFailedBcc()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['foo@bar.tld' => 'Foo']);
        $message->shouldReceive('getBcc')->zeroOrMoreTimes()->andReturn(['zip@button' => 'Zip', 'test@test.com' => 'Test']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn(['zip@button']);
        $reporter->shouldReceive('notify')->once()->with($message, 'foo@bar.tld', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'zip@button', Swift_Plugins_Reporter::RESULT_FAIL);
        $reporter->shouldReceive('notify')->once()->with($message, 'test@test.com', Swift_Plugins_Reporter::RESULT_PASS);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    private function createMessage()
    {
        return $this->getMockery('Swift_Mime_SimpleMessage')->shouldIgnoreMissing();
    }

    private function createSendEvent()
    {
        return $this->getMockery('Swift_Events_SendEvent')->shouldIgnoreMissing();
    }

    private function createReporter()
    {
        return $this->getMockery('Swift_Plugins_Reporter')->shouldIgnoreMissing();
    }

    public function testPluginImplementsSendListener()
    {
        $reporter = $this->createReporter();
        $plugin   = new Swift_Plugins_ReporterPlugin($reporter);
        $this->assertInstanceOf(Swift_Events_SendListener::class, $plugin);
    }

    public function testBeforeSendPerformedIsNoop()
    {
        $reporter = $this->createReporter();
        $plugin   = new Swift_Plugins_ReporterPlugin($reporter);
        $evt      = $this->createSendEvent();

        // Should not throw or call reporter
        $reporter->shouldNotReceive('notify');
        $plugin->beforeSendPerformed($evt);
    }

    public function testReportingAllFail()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['a@b.com' => 'A', 'c@d.com' => 'C']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn(['a@b.com', 'c@d.com']);
        $reporter->shouldReceive('notify')->once()->with($message, 'a@b.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $reporter->shouldReceive('notify')->once()->with($message, 'c@d.com', Swift_Plugins_Reporter::RESULT_FAIL);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReportingAllPass()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['a@b.com' => 'A', 'c@d.com' => 'C']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn([]);
        $reporter->shouldReceive('notify')->once()->with($message, 'a@b.com', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'c@d.com', Swift_Plugins_Reporter::RESULT_PASS);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReportingWithNoRecipients()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn([]);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn([]);
        $reporter->shouldNotReceive('notify');

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReportingMixedCcAndBccResults()
    {
        $message  = $this->createMessage();
        $evt      = $this->createSendEvent();
        $reporter = $this->createReporter();

        $message->shouldReceive('getTo')->zeroOrMoreTimes()->andReturn(['to@test.com' => 'To']);
        $message->shouldReceive('getCc')->zeroOrMoreTimes()->andReturn(['cc-pass@test.com' => 'CC Pass', 'cc-fail@test.com' => 'CC Fail']);
        $message->shouldReceive('getBcc')->zeroOrMoreTimes()->andReturn(['bcc-pass@test.com' => 'BCC Pass', 'bcc-fail@test.com' => 'BCC Fail']);
        $evt->shouldReceive('getMessage')->zeroOrMoreTimes()->andReturn($message);
        $evt->shouldReceive('getFailedRecipients')->zeroOrMoreTimes()->andReturn(['cc-fail@test.com', 'bcc-fail@test.com']);

        $reporter->shouldReceive('notify')->once()->with($message, 'to@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'cc-pass@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'cc-fail@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $reporter->shouldReceive('notify')->once()->with($message, 'bcc-pass@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $reporter->shouldReceive('notify')->once()->with($message, 'bcc-fail@test.com', Swift_Plugins_Reporter::RESULT_FAIL);

        $plugin = new Swift_Plugins_ReporterPlugin($reporter);
        $plugin->sendPerformed($evt);
    }

    public function testReporterConstants()
    {
        $this->assertSame(0x01, Swift_Plugins_Reporter::RESULT_PASS);
        $this->assertSame(0x10, Swift_Plugins_Reporter::RESULT_FAIL);
    }
}
