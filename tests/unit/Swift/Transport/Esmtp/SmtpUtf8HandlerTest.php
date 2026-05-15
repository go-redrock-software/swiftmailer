<?php

class Swift_Transport_Esmtp_SmtpUtf8HandlerTest extends SwiftMailerTestCase
{
    public function testGetHandledKeywordReturnsSMTPUTF8()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $this->assertEquals('SMTPUTF8', $handler->getHandledKeyword());
    }

    public function testSetKeywordParamsIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $handler->setKeywordParams(['PARAM1']);
        $this->addToAssertionCount(1);
    }

    public function testAfterEhloIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
        $handler->afterEhlo($agent);
        $this->addToAssertionCount(1);
    }

    public function testGetMailParamsReturnsSMTPUTF8()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $this->assertEquals(['SMTPUTF8'], $handler->getMailParams());
    }

    public function testGetRcptParamsReturnsEmptyArray()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $this->assertEquals([], $handler->getRcptParams());
    }

    public function testOnCommandIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
        $failedRecipients = null;
        $stop = false;
        $handler->onCommand($agent, "RCPT TO:<foo@bar>\r\n", [250], $failedRecipients, $stop);
        $this->assertFalse($stop);
    }

    public function testGetPriorityOverReturnsZero()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $this->assertEquals(0, $handler->getPriorityOver('8BITMIME'));
    }

    public function testExposeMixinMethodsReturnsEmptyArray()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $this->assertEquals([], $handler->exposeMixinMethods());
    }

    public function testResetStateIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_SmtpUtf8Handler();
        $handler->resetState();
        $this->addToAssertionCount(1);
    }
}
