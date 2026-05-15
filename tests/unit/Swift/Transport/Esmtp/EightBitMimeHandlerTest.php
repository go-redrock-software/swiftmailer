<?php

class Swift_Transport_Esmtp_EightBitMimeHandlerTest extends SwiftMailerTestCase
{
    public function testGetHandledKeywordReturns8BITMIME()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $this->assertEquals('8BITMIME', $handler->getHandledKeyword());
    }

    public function testSetKeywordParamsIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $handler->setKeywordParams(['PARAM1', 'PARAM2']);
        // No exception = pass; this method is a no-op
        $this->addToAssertionCount(1);
    }

    public function testAfterEhloIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
        $handler->afterEhlo($agent);
        $this->addToAssertionCount(1);
    }

    public function testGetMailParamsReturnsBodyEncoding()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $this->assertEquals(['BODY=8BITMIME'], $handler->getMailParams());
    }

    public function testGetMailParamsWithCustomEncoding()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler('7BIT');
        $this->assertEquals(['BODY=7BIT'], $handler->getMailParams());
    }

    public function testGetRcptParamsReturnsEmptyArray()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $this->assertEquals([], $handler->getRcptParams());
    }

    public function testOnCommandIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $agent = $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
        $failedRecipients = null;
        $stop = false;
        $handler->onCommand($agent, "MAIL FROM:<foo@bar>\r\n", [250], $failedRecipients, $stop);
        $this->assertFalse($stop);
    }

    public function testGetPriorityOverReturnsZero()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $this->assertEquals(0, $handler->getPriorityOver('AUTH'));
    }

    public function testExposeMixinMethodsReturnsEmptyArray()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $this->assertEquals([], $handler->exposeMixinMethods());
    }

    public function testResetStateIsNoOp()
    {
        $handler = new Swift_Transport_Esmtp_EightBitMimeHandler();
        $handler->resetState();
        $this->addToAssertionCount(1);
    }
}
