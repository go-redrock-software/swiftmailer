<?php

class Swift_Plugins_Reporters_HitReporterTest extends PHPUnit\Framework\TestCase
{
    private $hitReporter;

    private $message;

    protected function setUp(): void
    {
        $this->hitReporter = new Swift_Plugins_Reporters_HitReporter();
        $this->message     = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
    }

    public function testReportingFail()
    {
        $this->hitReporter->notify(
            $this->message,
            'foo@bar.tld',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $this->assertEquals(
            ['foo@bar.tld'],
            $this->hitReporter->getFailedRecipients(),
        );
    }

    public function testMultipleReports()
    {
        $this->hitReporter->notify(
            $this->message,
            'foo@bar.tld',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $this->hitReporter->notify(
            $this->message,
            'zip@button',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $this->assertEquals(
            ['foo@bar.tld', 'zip@button'],
            $this->hitReporter->getFailedRecipients(),
        );
    }

    public function testReportingPassIsIgnored()
    {
        $this->hitReporter->notify(
            $this->message,
            'foo@bar.tld',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $this->hitReporter->notify(
            $this->message,
            'zip@button',
            Swift_Plugins_Reporter::RESULT_PASS,
        );
        $this->assertEquals(
            ['foo@bar.tld'],
            $this->hitReporter->getFailedRecipients(),
        );
    }

    public function testBufferCanBeCleared()
    {
        $this->hitReporter->notify(
            $this->message,
            'foo@bar.tld',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $this->hitReporter->notify(
            $this->message,
            'zip@button',
            Swift_Plugins_Reporter::RESULT_FAIL,
        );
        $this->assertEquals(
            ['foo@bar.tld', 'zip@button'],
            $this->hitReporter->getFailedRecipients(),
        );
        $this->hitReporter->clear();
        $this->assertEquals([], $this->hitReporter->getFailedRecipients());
    }

    public function testImplementsReporterInterface()
    {
        $this->assertInstanceOf(Swift_Plugins_Reporter::class, $this->hitReporter);
    }

    public function testEmptyByDefault()
    {
        $this->assertEquals([], $this->hitReporter->getFailedRecipients());
    }

    public function testDuplicateFailureIsNotRecorded()
    {
        $this->hitReporter->notify($this->message, 'dup@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $this->hitReporter->notify($this->message, 'dup@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $this->assertEquals(['dup@test.com'], $this->hitReporter->getFailedRecipients());
    }

    public function testClearResetsDuplicateCache()
    {
        $this->hitReporter->notify($this->message, 'dup@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $this->hitReporter->clear();
        $this->hitReporter->notify($this->message, 'dup@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $this->assertEquals(['dup@test.com'], $this->hitReporter->getFailedRecipients());
    }

    public function testPassAfterFailDoesNotRemoveFailure()
    {
        $this->hitReporter->notify($this->message, 'foo@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $this->hitReporter->notify($this->message, 'foo@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $this->assertEquals(['foo@test.com'], $this->hitReporter->getFailedRecipients());
    }

    public function testOnlyPassesReturnsEmpty()
    {
        $this->hitReporter->notify($this->message, 'a@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $this->hitReporter->notify($this->message, 'b@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $this->assertEquals([], $this->hitReporter->getFailedRecipients());
    }

    public function testMixedResultsPreservesOrder()
    {
        $this->hitReporter->notify($this->message, 'c@test.com', Swift_Plugins_Reporter::RESULT_FAIL);
        $this->hitReporter->notify($this->message, 'a@test.com', Swift_Plugins_Reporter::RESULT_PASS);
        $this->hitReporter->notify($this->message, 'b@test.com', Swift_Plugins_Reporter::RESULT_FAIL);

        $failures = $this->hitReporter->getFailedRecipients();
        $this->assertSame(['c@test.com', 'b@test.com'], $failures);
    }
}
