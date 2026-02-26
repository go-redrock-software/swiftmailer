<?php

class Swift_Webhook_AbstractPayloadConverterTest extends \PHPUnit\Framework\TestCase
{
    public function testVerifyHmacSha256()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $payload = '{"event":"bounce"}';
        $secret = 'test-secret-key';
        $validSig = hash_hmac('sha256', $payload, $secret);

        // Use reflection to test protected method
        $method = new \ReflectionMethod($converter, 'verifyHmac');

        $this->assertTrue($method->invoke($converter, $payload, $validSig, $secret, 'sha256'));
        $this->assertFalse($method->invoke($converter, $payload, 'invalid-sig', $secret, 'sha256'));
    }

    public function testVerifyHmacSha1()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $payload = '{"event":"bounce"}';
        $secret = 'test-secret-key';
        $validSig = hash_hmac('sha1', $payload, $secret);

        $method = new \ReflectionMethod($converter, 'verifyHmac');

        $this->assertTrue($method->invoke($converter, $payload, $validSig, $secret, 'sha1'));
    }

    public function testCreateDeliveryEvent()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $method = new \ReflectionMethod($converter, 'createDeliveryEvent');

        $event = $method->invoke(
            $converter,
            'bounced',
            'msg-123',
            'user@example.com',
            ['tag' => 'test'],
            new \DateTimeImmutable('2026-01-15'),
            ['raw' => true]
        );

        $this->assertInstanceOf(Swift_Webhook_Event::class, $event);
        $this->assertSame('delivery', $event->getType());
        $this->assertSame('bounced', $event->getName());
    }

    public function testCreateEngagementEvent()
    {
        $converter = $this->getMockForAbstractClass(
            Swift_Webhook_AbstractPayloadConverter::class
        );

        $method = new \ReflectionMethod($converter, 'createEngagementEvent');

        $event = $method->invoke(
            $converter,
            'opened',
            'msg-456',
            'user@example.com',
            [],
            new \DateTimeImmutable('2026-01-15'),
            []
        );

        $this->assertSame('engagement', $event->getType());
        $this->assertSame('opened', $event->getName());
    }
}
