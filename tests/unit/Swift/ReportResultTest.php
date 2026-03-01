<?php

class Swift_ReportResultTest extends PHPUnit\Framework\TestCase
{
    public function testEnumCasesHaveExpectedValues()
    {
        $this->assertSame(0x01, Swift_ReportResult::PASS->value);
        $this->assertSame(0x10, Swift_ReportResult::FAIL->value);
    }

    public function testBackwardCompatibilityWithReporterConstants()
    {
        $this->assertSame(Swift_Plugins_Reporter::RESULT_PASS, Swift_ReportResult::PASS->value);
        $this->assertSame(Swift_Plugins_Reporter::RESULT_FAIL, Swift_ReportResult::FAIL->value);
    }
}
