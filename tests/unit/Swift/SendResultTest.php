<?php

class Swift_SendResultTest extends PHPUnit\Framework\TestCase
{
    public function testEnumCasesHaveExpectedValues()
    {
        $this->assertSame(0x0001, Swift_SendResult::PENDING->value);
        $this->assertSame(0x0011, Swift_SendResult::SPOOLED->value);
        $this->assertSame(0x0010, Swift_SendResult::SUCCESS->value);
        $this->assertSame(0x0100, Swift_SendResult::TENTATIVE->value);
        $this->assertSame(0x1000, Swift_SendResult::FAILED->value);
    }

    public function testEnumIsBackedInt()
    {
        $this->assertInstanceOf(\BackedEnum::class, Swift_SendResult::PENDING);
    }

    public function testFromInt()
    {
        $result = Swift_SendResult::from(0x0010);
        $this->assertSame(Swift_SendResult::SUCCESS, $result);
    }

    public function testTryFromInvalidReturnsNull()
    {
        $this->assertNull(Swift_SendResult::tryFrom(0x9999));
    }

    public function testBackwardCompatibilityWithSendEventConstants()
    {
        $this->assertSame(Swift_Events_SendEvent::RESULT_PENDING, Swift_SendResult::PENDING->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, Swift_SendResult::SUCCESS->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_TENTATIVE, Swift_SendResult::TENTATIVE->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, Swift_SendResult::FAILED->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SPOOLED, Swift_SendResult::SPOOLED->value);
    }

    public function testBitmaskOperationsStillWork()
    {
        $combined = Swift_SendResult::SUCCESS->value | Swift_SendResult::TENTATIVE->value;
        $this->assertTrue((bool) ($combined & Swift_SendResult::SUCCESS->value));
        $this->assertTrue((bool) ($combined & Swift_SendResult::TENTATIVE->value));
        $this->assertFalse((bool) ($combined & Swift_SendResult::FAILED->value));
    }
}
