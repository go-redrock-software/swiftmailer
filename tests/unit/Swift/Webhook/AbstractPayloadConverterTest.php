<?php

class Swift_Webhook_AbstractPayloadConverterTest extends PHPUnit\Framework\TestCase
{
    public function testVerifyHmacSha256()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $payload  = '{"event":"bounce"}';
        $secret   = 'test-secret-key';
        $validSig = \hash_hmac('sha256', $payload, $secret);

        // Use reflection to test protected method
        $method = new ReflectionMethod($converter, 'verifyHmac');

        $this->assertTrue($method->invoke($converter, $payload, $validSig, $secret, 'sha256'));
        $this->assertFalse($method->invoke($converter, $payload, 'invalid-sig', $secret, 'sha256'));
    }

    public function testVerifyHmacSha1()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $payload  = '{"event":"bounce"}';
        $secret   = 'test-secret-key';
        $validSig = \hash_hmac('sha1', $payload, $secret);

        $method = new ReflectionMethod($converter, 'verifyHmac');

        $this->assertTrue($method->invoke($converter, $payload, $validSig, $secret, 'sha1'));
    }

    public function testCreateDeliveryEvent()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'createDeliveryEvent');

        $event = $method->invoke(
            $converter,
            'bounced',
            'msg-123',
            'user@example.com',
            ['tag' => 'test'],
            new DateTimeImmutable('2026-01-15'),
            ['raw' => true],
        );

        $this->assertInstanceOf(Swift_Webhook_Event::class, $event);
        $this->assertSame('delivery', $event->getType());
        $this->assertSame('bounced', $event->getName());
    }

    public function testCreateEngagementEvent()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'createEngagementEvent');

        $event = $method->invoke(
            $converter,
            'opened',
            'msg-456',
            'user@example.com',
            [],
            new DateTimeImmutable('2026-01-15'),
            [],
        );

        $this->assertSame('engagement', $event->getType());
        $this->assertSame('opened', $event->getName());
    }

    public function testVerifyHmacWithDifferentPayloads()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'verifyHmac');
        $secret = 'my-secret';

        $data1 = 'payload-one';
        $data2 = 'payload-two';

        $sig1 = \hash_hmac('sha256', $data1, $secret);
        $sig2 = \hash_hmac('sha256', $data2, $secret);

        $this->assertTrue($method->invoke($converter, $data1, $sig1, $secret, 'sha256'));
        $this->assertTrue($method->invoke($converter, $data2, $sig2, $secret, 'sha256'));
        $this->assertFalse($method->invoke($converter, $data1, $sig2, $secret, 'sha256'));
    }

    public function testVerifyHmacWithEmptyPayload()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'verifyHmac');
        $secret = 'test-key';
        $sig    = \hash_hmac('sha256', '', $secret);

        $this->assertTrue($method->invoke($converter, '', $sig, $secret, 'sha256'));
    }

    public function testVerifyHmacWithEmptySecret()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'verifyHmac');
        $data   = 'some-data';
        $sig    = \hash_hmac('sha256', $data, '');

        $this->assertTrue($method->invoke($converter, $data, $sig, '', 'sha256'));
    }

    public function testParseTimestampFromInteger()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'parseTimestamp');
        $result = $method->invoke($converter, 1706000000);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame(1706000000, $result->getTimestamp());
    }

    public function testParseTimestampFromAtomString()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'parseTimestamp');
        $result = $method->invoke($converter, '2026-01-15T10:30:00+00:00');

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-01-15', $result->format('Y-m-d'));
    }

    public function testParseTimestampFromUnixString()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'parseTimestamp');
        $result = $method->invoke($converter, '1706000000');

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
    }

    public function testParseTimestampFromDateString()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method = new ReflectionMethod($converter, 'parseTimestamp');
        $result = $method->invoke($converter, '2026-01-15 10:30:00');

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
    }

    public function testCreateDeliveryEventPreservesAllFields()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method    = new ReflectionMethod($converter, 'createDeliveryEvent');
        $timestamp = new DateTimeImmutable('2026-06-01');
        $raw       = ['some' => 'raw data'];

        $event = $method->invoke(
            $converter,
            'deferred',
            'msg-789',
            'recipient@example.com',
            ['reason' => 'mailbox full'],
            $timestamp,
            $raw,
        );

        $this->assertSame('delivery', $event->getType());
        $this->assertSame('deferred', $event->getName());
        $this->assertSame('msg-789', $event->getMessageId());
        $this->assertSame('recipient@example.com', $event->getRecipient());
        $this->assertSame(['reason' => 'mailbox full'], $event->getMetadata());
        $this->assertSame($timestamp, $event->getTimestamp());
        $this->assertSame($raw, $event->getRawPayload());
    }

    public function testCreateEngagementEventPreservesAllFields()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class,
        );

        $method    = new ReflectionMethod($converter, 'createEngagementEvent');
        $timestamp = new DateTimeImmutable('2026-06-01');

        $event = $method->invoke(
            $converter,
            'clicked',
            'msg-abc',
            'user@test.com',
            ['url' => 'https://example.com/link'],
            $timestamp,
            ['raw' => true],
        );

        $this->assertSame('engagement', $event->getType());
        $this->assertSame('clicked', $event->getName());
        $this->assertSame('msg-abc', $event->getMessageId());
        $this->assertSame('user@test.com', $event->getRecipient());
        $this->assertSame('https://example.com/link', $event->getMetadata()['url']);
    }
}
