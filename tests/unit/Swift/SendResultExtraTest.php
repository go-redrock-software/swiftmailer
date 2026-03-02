<?php

class Swift_SendResultExtraTest extends \PHPUnit\Framework\TestCase
{
    public function testPendingValue(): void
    {
        $this->assertSame(0x0001, Swift_SendResult::PENDING->value);
    }

    public function testSpooledValue(): void
    {
        $this->assertSame(0x0011, Swift_SendResult::SPOOLED->value);
    }

    public function testSuccessValue(): void
    {
        $this->assertSame(0x0010, Swift_SendResult::SUCCESS->value);
    }

    public function testTentativeValue(): void
    {
        $this->assertSame(0x0100, Swift_SendResult::TENTATIVE->value);
    }

    public function testFailedValue(): void
    {
        $this->assertSame(0x1000, Swift_SendResult::FAILED->value);
    }

    public function testFromValidInt(): void
    {
        $this->assertSame(Swift_SendResult::PENDING, Swift_SendResult::from(0x0001));
        $this->assertSame(Swift_SendResult::SPOOLED, Swift_SendResult::from(0x0011));
        $this->assertSame(Swift_SendResult::SUCCESS, Swift_SendResult::from(0x0010));
        $this->assertSame(Swift_SendResult::TENTATIVE, Swift_SendResult::from(0x0100));
        $this->assertSame(Swift_SendResult::FAILED, Swift_SendResult::from(0x1000));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(Swift_SendResult::tryFrom(0x9999));
        $this->assertNull(Swift_SendResult::tryFrom(0));
        $this->assertNull(Swift_SendResult::tryFrom(-1));
    }

    public function testFromInvalidThrows(): void
    {
        $this->expectException(ValueError::class);
        Swift_SendResult::from(0x9999);
    }

    public function testIsBackedEnum(): void
    {
        $this->assertInstanceOf(BackedEnum::class, Swift_SendResult::PENDING);
        $this->assertInstanceOf(BackedEnum::class, Swift_SendResult::SUCCESS);
        $this->assertInstanceOf(BackedEnum::class, Swift_SendResult::FAILED);
    }

    public function testCasesReturnsAllCases(): void
    {
        $cases = Swift_SendResult::cases();
        $this->assertCount(5, $cases);
    }

    public function testBitmaskSuccessAndTentative(): void
    {
        $combined = Swift_SendResult::SUCCESS->value | Swift_SendResult::TENTATIVE->value;
        $this->assertTrue((bool) ($combined & Swift_SendResult::SUCCESS->value));
        $this->assertTrue((bool) ($combined & Swift_SendResult::TENTATIVE->value));
        $this->assertFalse((bool) ($combined & Swift_SendResult::FAILED->value));
    }

    public function testBitmaskFailedAndPending(): void
    {
        $combined = Swift_SendResult::FAILED->value | Swift_SendResult::PENDING->value;
        $this->assertTrue((bool) ($combined & Swift_SendResult::FAILED->value));
        $this->assertTrue((bool) ($combined & Swift_SendResult::PENDING->value));
    }

    public function testEnumName(): void
    {
        $this->assertSame('PENDING', Swift_SendResult::PENDING->name);
        $this->assertSame('SPOOLED', Swift_SendResult::SPOOLED->name);
        $this->assertSame('SUCCESS', Swift_SendResult::SUCCESS->name);
        $this->assertSame('TENTATIVE', Swift_SendResult::TENTATIVE->name);
        $this->assertSame('FAILED', Swift_SendResult::FAILED->name);
    }

    public function testValuesAreDistinct(): void
    {
        $values = array_map(fn ($c) => $c->value, Swift_SendResult::cases());
        $this->assertSame($values, array_unique($values));
    }

    public function testCompatibilityWithSendEventConstants(): void
    {
        $this->assertSame(Swift_Events_SendEvent::RESULT_PENDING, Swift_SendResult::PENDING->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, Swift_SendResult::SUCCESS->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_TENTATIVE, Swift_SendResult::TENTATIVE->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, Swift_SendResult::FAILED->value);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SPOOLED, Swift_SendResult::SPOOLED->value);
    }

    public function testSuccessIsNotEqualToFailed(): void
    {
        $this->assertNotSame(Swift_SendResult::SUCCESS, Swift_SendResult::FAILED);
    }

    public function testSuccessIsNotEqualToPending(): void
    {
        $this->assertNotSame(Swift_SendResult::SUCCESS, Swift_SendResult::PENDING);
    }
}
