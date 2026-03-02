<?php

class Swift_Webhook_EventTest extends PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $timestamp = new DateTimeImmutable('2026-01-15 10:30:00');
        $event     = new Swift_Webhook_Event(
            'delivery',
            'bounced',
            'msg-123@example.com',
            'recipient@example.com',
            ['campaign' => 'jan'],
            $timestamp,
            ['raw' => 'data'],
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid event type');
        new Swift_Webhook_Event(
            'invalid',
            'bounced',
            'msg-123@example.com',
            'recipient@example.com',
            [],
            new DateTimeImmutable(),
            [],
        );
    }

    public function testIsReadonlyClass()
    {
        $ref = new ReflectionClass(Swift_Webhook_Event::class);
        $this->assertTrue($ref->isReadOnly());
    }

    public function testDeliveryTypeIsValid()
    {
        $event = new Swift_Webhook_Event(
            'delivery', 'bounced', 'msg-1', 'user@example.com',
            [], new DateTimeImmutable(), [],
        );
        $this->assertTrue($event->isDelivery());
        $this->assertFalse($event->isEngagement());
    }

    public function testEngagementTypeIsValid()
    {
        $event = new Swift_Webhook_Event(
            'engagement', 'opened', 'msg-1', 'user@example.com',
            [], new DateTimeImmutable(), [],
        );
        $this->assertTrue($event->isEngagement());
        $this->assertFalse($event->isDelivery());
    }

    public function testEmptyMetadata()
    {
        $event = new Swift_Webhook_Event(
            'delivery', 'delivered', 'msg-1', 'user@example.com',
            [], new DateTimeImmutable(), [],
        );
        $this->assertSame([], $event->getMetadata());
    }

    public function testMetadataWithMultipleKeys()
    {
        $metadata = ['key1' => 'val1', 'key2' => 'val2', 'nested' => ['a' => 'b']];
        $event = new Swift_Webhook_Event(
            'delivery', 'delivered', 'msg-1', 'user@example.com',
            $metadata, new DateTimeImmutable(), [],
        );
        $this->assertSame($metadata, $event->getMetadata());
    }

    public function testRawPayloadIsPreserved()
    {
        $raw = ['event' => 'bounce', 'email' => 'test@example.com', 'nested' => ['data' => true]];
        $event = new Swift_Webhook_Event(
            'delivery', 'bounced', 'msg-1', 'test@example.com',
            [], new DateTimeImmutable(), $raw,
        );
        $this->assertSame($raw, $event->getRawPayload());
    }

    public function testTimestampIsPreserved()
    {
        $timestamp = new DateTimeImmutable('2026-06-15 12:00:00');
        $event = new Swift_Webhook_Event(
            'delivery', 'delivered', 'msg-1', 'user@example.com',
            [], $timestamp, [],
        );
        $this->assertSame($timestamp, $event->getTimestamp());
        $this->assertSame('2026-06-15', $event->getTimestamp()->format('Y-m-d'));
    }

    public function testEmptyMessageId()
    {
        $event = new Swift_Webhook_Event(
            'delivery', 'delivered', '', 'user@example.com',
            [], new DateTimeImmutable(), [],
        );
        $this->assertSame('', $event->getMessageId());
    }

    public function testEmptyRecipient()
    {
        $event = new Swift_Webhook_Event(
            'delivery', 'delivered', 'msg-1', '',
            [], new DateTimeImmutable(), [],
        );
        $this->assertSame('', $event->getRecipient());
    }

    public function testDeliveryEventNames()
    {
        $names = ['bounced', 'delivered', 'deferred', 'dropped'];
        foreach ($names as $name) {
            $event = new Swift_Webhook_Event(
                'delivery', $name, 'msg-1', 'user@example.com',
                [], new DateTimeImmutable(), [],
            );
            $this->assertSame($name, $event->getName());
        }
    }

    public function testEngagementEventNames()
    {
        $names = ['opened', 'clicked', 'unsubscribed', 'complained'];
        foreach ($names as $name) {
            $event = new Swift_Webhook_Event(
                'engagement', $name, 'msg-1', 'user@example.com',
                [], new DateTimeImmutable(), [],
            );
            $this->assertSame($name, $event->getName());
        }
    }

    public function testInvalidTypeDeliveryTypo()
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Webhook_Event(
            'deliveries', 'bounced', 'msg-1', 'user@example.com',
            [], new DateTimeImmutable(), [],
        );
    }

    public function testInvalidTypeEmptyString()
    {
        $this->expectException(InvalidArgumentException::class);
        new Swift_Webhook_Event(
            '', 'bounced', 'msg-1', 'user@example.com',
            [], new DateTimeImmutable(), [],
        );
    }
}
