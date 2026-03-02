<?php

class Swift_Webhook_Converter_MailomatConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailomatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailomatConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailomat', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'id'         => '81a9813b-70e8-4d1f-8e8c-4c6885d849f7',
            'eventType'  => 'delivered',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-900@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-900@example.com', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertFailurePermanentEvent()
    {
        $payload = [
            'eventType'  => 'failure_perm',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-901@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertFailureTemporaryEvent()
    {
        $payload = [
            'eventType'  => 'failure_tmp',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-902@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'eventType'  => 'opened',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-903@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'eventType'  => 'clicked',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-904@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testSkipsAcceptedEvent()
    {
        $payload = [
            'eventType'  => 'accepted',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-905@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testSkipsNotAcceptedEvent()
    {
        $payload = [
            'eventType'  => 'not_accepted',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-906@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidHmacSignature()
    {
        // Mailomat signs: implode('.', [X-MOM-Webhook-Id, X-MOM-Webhook-Event, X-MOM-Webhook-Timestamp])
        $secret    = 'test-webhook-secret';
        $id        = '68f7add5-3470-4187-b02a-d7a795ed4345';
        $event     = 'delivered';
        $timestamp = '1712240232';

        $payload   = \implode('.', [$id, $event, $timestamp]);
        $signature = 'sha256='.\hash_hmac('sha256', $payload, $secret);

        $headers = [
            'x-mom-webhook-id'        => $id,
            'x-mom-webhook-event'     => $event,
            'x-mom-webhook-timestamp' => $timestamp,
            'x-mom-webhook-signature' => $signature,
        ];

        $this->assertTrue($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = [
            'x-mom-webhook-id'        => 'test-id',
            'x-mom-webhook-event'     => 'delivered',
            'x-mom-webhook-timestamp' => '1712240232',
            'x-mom-webhook-signature' => 'sha256=invalid',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'secret'));
    }

    public function testConvertEmptyPayload()
    {
        $this->assertSame([], $this->converter->convert([], []));
    }

    public function testConvertMissingEventType()
    {
        $payload = [
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-x@example.com',
            'recipient'  => 'user@example.com',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testConvertMissingRecipientAndMessageId()
    {
        $payload = [
            'eventType'  => 'delivered',
            'occurredAt' => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(1, $events);
        $this->assertSame('', $events[0]->getMessageId());
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testConvertWithPayloadMetadata()
    {
        $payload = [
            'eventType'  => 'delivered',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-meta@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [
                'custom_key' => 'custom_value',
                'another'    => 123,
            ],
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('custom_value', $metadata['custom_key']);
        $this->assertSame(123, $metadata['another']);
    }

    public function testConvertWithEmptyPayloadArray()
    {
        $payload = [
            'eventType'  => 'delivered',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-empty-payload@example.com',
            'recipient'  => 'user@example.com',
            'payload'    => [],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(1, $events);
        $this->assertSame([], $events[0]->getMetadata());
    }

    public function testConvertUnknownEventType()
    {
        $payload = [
            'eventType'  => 'unknown_type',
            'occurredAt' => '2026-01-15T10:30:00Z',
            'messageId'  => 'msg-unk@example.com',
            'recipient'  => 'user@example.com',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testConvertDeliveryVsEngagementTypes()
    {
        // Delivery types
        $deliveryTypes = ['delivered', 'failure_perm', 'failure_tmp'];
        foreach ($deliveryTypes as $type) {
            $payload = [
                'eventType'  => $type,
                'occurredAt' => '2026-01-15T10:30:00Z',
                'messageId'  => "msg-{$type}@example.com",
                'recipient'  => 'user@example.com',
                'payload'    => [],
            ];
            $events = $this->converter->convert($payload, []);
            $this->assertSame('delivery', $events[0]->getType(), "Expected delivery type for: {$type}");
        }

        // Engagement types
        $engagementTypes = ['opened', 'clicked'];
        foreach ($engagementTypes as $type) {
            $payload = [
                'eventType'  => $type,
                'occurredAt' => '2026-01-15T10:30:00Z',
                'messageId'  => "msg-{$type}@example.com",
                'recipient'  => 'user@example.com',
                'payload'    => [],
            ];
            $events = $this->converter->convert($payload, []);
            $this->assertSame('engagement', $events[0]->getType(), "Expected engagement type for: {$type}");
        }
    }

    public function testVerifyPartialHeaders()
    {
        // Missing signature header
        $headers = [
            'x-mom-webhook-id'        => 'test-id',
            'x-mom-webhook-event'     => 'delivered',
            'x-mom-webhook-timestamp' => '1712240232',
        ];
        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));

        // Missing id header
        $headers = [
            'x-mom-webhook-event'     => 'delivered',
            'x-mom-webhook-timestamp' => '1712240232',
            'x-mom-webhook-signature' => 'sha256=abc',
        ];
        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyWrongAlgorithmPrefix()
    {
        $headers = [
            'x-mom-webhook-id'        => 'test-id',
            'x-mom-webhook-event'     => 'delivered',
            'x-mom-webhook-timestamp' => '1712240232',
            'x-mom-webhook-signature' => 'sha512=somehash',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifySignatureWithoutEquals()
    {
        $headers = [
            'x-mom-webhook-id'        => 'test-id',
            'x-mom-webhook-event'     => 'delivered',
            'x-mom-webhook-timestamp' => '1712240232',
            'x-mom-webhook-signature' => 'noprefixhash',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }
}
