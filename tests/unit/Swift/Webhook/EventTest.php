<?php

class Swift_Webhook_EventTest extends \PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $timestamp = new \DateTimeImmutable('2026-01-15 10:30:00');
        $event = new Swift_Webhook_Event(
            'delivery',
            'bounced',
            'msg-123@example.com',
            'recipient@example.com',
            ['campaign' => 'jan'],
            $timestamp,
            ['raw' => 'data']
        );

        $this->assertSame('delivery', $event->getType());
        $this->assertSame('bounced', $event->getName());
        $this->assertSame('msg-123@example.com', $event->getMessageId());
        $this->assertSame('recipient@example.com', $event->getRecipient());
        $this->assertSame(['campaign' => 'jan'], $event->getMetadata());
        $this->assertSame($timestamp, $event->getTimestamp());
        $this->assertSame(['raw' => 'data'], $event->getRawPayload());
    }

    public function testInvalidTypeThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid event type');
        new Swift_Webhook_Event(
            'invalid',
            'bounced',
            'msg-123@example.com',
            'recipient@example.com',
            [],
            new \DateTimeImmutable(),
            []
        );
    }
}
