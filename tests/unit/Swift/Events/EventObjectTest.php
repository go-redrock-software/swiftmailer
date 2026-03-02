<?php

class Swift_Events_EventObjectTest extends PHPUnit\Framework\TestCase
{
    public function testEventSourceCanBeReturnedViaGetter()
    {
        $source = new stdClass();
        $evt    = $this->createEvent($source);
        $ref    = $evt->getSource();
        $this->assertEquals($source, $ref);
    }

    public function testEventDoesNotHaveCancelledBubbleWhenNew()
    {
        $source = new stdClass();
        $evt    = $this->createEvent($source);
        $this->assertFalse($evt->bubbleCancelled());
    }

    public function testBubbleCanBeCancelledInEvent()
    {
        $source = new stdClass();
        $evt    = $this->createEvent($source);
        $evt->cancelBubble();
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testBubbleCanBeUncancelledAfterCancellation()
    {
        $source = new stdClass();
        $evt    = $this->createEvent($source);
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());
        $evt->cancelBubble(false);
        $this->assertFalse($evt->bubbleCancelled());
    }

    public function testCancelBubbleDefaultParameterIsTrue()
    {
        $source = new stdClass();
        $evt    = $this->createEvent($source);
        $evt->cancelBubble();
        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testSourceCanBeAnyObject()
    {
        $source = new ArrayObject();
        $evt    = $this->createEvent($source);
        $this->assertSame($source, $evt->getSource());
    }

    public function testSourceIdentityIsPreserved()
    {
        $source     = new stdClass();
        $source->id = 'test-123';
        $evt        = $this->createEvent($source);
        $this->assertSame('test-123', $evt->getSource()->id);
    }

    public function testMultipleCancelBubbleCallsWork()
    {
        $source = new stdClass();
        $evt    = $this->createEvent($source);

        $evt->cancelBubble(true);
        $evt->cancelBubble(true);
        $this->assertTrue($evt->bubbleCancelled());

        $evt->cancelBubble(false);
        $this->assertFalse($evt->bubbleCancelled());
    }

    private function createEvent($source)
    {
        return new Swift_Events_EventObject($source);
    }
}
