<?php

class Swift_Webhook_Converter_AhaSendConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_AhaSendConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_AhaSendConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('ahasend', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'type'      => 'message.delivered',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-800',
                'recipient'         => 'user@example.com',
                'from'              => 'sender@example.com',
                'subject'           => 'Hello',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-800', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            'type'      => 'message.hard_bounced',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-801',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'type'      => 'message.soft_bounced',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-802',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'type'      => 'message.opened',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-803',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'type'      => 'message.clicked',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-804',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testConvertComplainedEvent()
    {
        $payload = [
            'type'      => 'message.complained',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => [
                'message_id_header' => 'msg-805',
                'recipient'         => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'type'      => 'suppression.created',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => ['recipient' => 'user@example.com'],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidStandardWebhookSignature()
    {
        // AhaSend follows Standard Webhooks: HMAC-SHA256 of "{id}.{timestamp}.{body}" with base64-decoded secret
        $secretRaw = \random_bytes(32);
        $secret    = \base64_encode($secretRaw);
        $id        = 'wh_test123';
        $timestamp = '1706000000';
        $body      = '{"type":"message.delivered"}';

        $signedContent = $id.'.'.$timestamp.'.'.$body;
        $signature     = 'v1,'.\base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'webhook-id'        => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => $signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = [
            'webhook-id'        => 'wh_test',
            'webhook-timestamp' => '1706000000',
            'webhook-signature' => 'v1,invalidsig',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, \base64_encode('secret')));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], \base64_encode('secret')));
    }

    public function testConvertEmptyPayload()
    {
        $events = $this->converter->convert([], []);
        $this->assertCount(0, $events);
    }

    public function testConvertMissingType()
    {
        $payload = [
            'timestamp' => '2026-01-15T10:30:00.000000Z',
            'data'      => ['message_id_header' => 'msg-x', 'recipient' => 'user@example.com'],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(0, $events);
    }

    public function testConvertMissingData()
    {
        $payload = [
            'type'      => 'message.delivered',
            'timestamp' => '2026-01-15T10:30:00.000000Z',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getMessageId());
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testVerifyPartialHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', ['webhook-id' => 'x'], \base64_encode('secret')));
    }

    public function testVerifyWithMultipleSignatures()
    {
        $secretRaw = \random_bytes(32);
        $secret    = \base64_encode($secretRaw);
        $id        = 'wh_multi';
        $timestamp = '1706000000';
        $body      = '{}';

        $signedContent = $id.'.'.$timestamp.'.'.$body;
        $validSig      = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'webhook-id'        => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,invalid v1,'.$validSig,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }
}
