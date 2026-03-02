<?php

class Swift_Webhook_Converter_ResendConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_ResendConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_ResendConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('resend', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-400',
                'from'     => 'sender@example.com',
                'to'       => ['user@example.com'],
                'subject'  => 'Hello',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-400', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertBouncedEvent()
    {
        $payload = [
            'type'       => 'email.bounced',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-401',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertDeliveryDelayedEvent()
    {
        $payload = [
            'type'       => 'email.delivery_delayed',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-402',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'type'       => 'email.opened',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-403',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'type'       => 'email.clicked',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-404',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testConvertComplainedEvent()
    {
        $payload = [
            'type'       => 'email.complained',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-405',
                'to'       => ['user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'type'       => 'email.sent',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-406',
                'to'       => ['user@example.com'],
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSvixSignature()
    {
        // Resend uses Svix: HMAC-SHA256 of "{svix-id}.{svix-timestamp}.{body}" with base64-decoded secret
        $secretRaw = \random_bytes(32);
        $secret    = 'whsec_'.\base64_encode($secretRaw);
        $svixId    = 'msg_test123';
        $timestamp = '1706000000';
        $body      = '{"type":"email.delivered"}';

        $signedContent = $svixId.'.'.$timestamp.'.'.$body;
        $signature     = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'svix-id'        => $svixId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,'.$signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $secret  = 'whsec_'.\base64_encode(\random_bytes(32));
        $headers = [
            'svix-id'        => 'msg_test',
            'svix-timestamp' => '1706000000',
            'svix-signature' => 'v1,invalidsignature',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'whsec_dGVzdA=='));
    }

    public function testVerifyWithoutWhsecPrefix()
    {
        $secretRaw = \random_bytes(32);
        $secret    = \base64_encode($secretRaw);
        $svixId    = 'msg_test456';
        $timestamp = '1706000000';
        $body      = '{}';

        $signedContent = $svixId.'.'.$timestamp.'.'.$body;
        $signature     = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'svix-id'        => $svixId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,'.$signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyWithMultipleSignatures()
    {
        $secretRaw = \random_bytes(32);
        $secret    = 'whsec_'.\base64_encode($secretRaw);
        $svixId    = 'msg_multi';
        $timestamp = '1706000000';
        $body      = '{"type":"email.delivered"}';

        $signedContent = $svixId.'.'.$timestamp.'.'.$body;
        $validSig      = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'svix-id'        => $svixId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,invalidsig v1,'.$validSig,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyWithInvalidBase64Secret()
    {
        $headers = [
            'svix-id'        => 'msg_test',
            'svix-timestamp' => '123',
            'svix-signature' => 'v1,sig',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, 'whsec_!!!invalid!!!'));
    }

    public function testVerifyPartialHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', ['svix-id' => 'x'], 'whsec_dGVzdA=='));
        $this->assertFalse($this->converter->verify('{}', ['svix-id' => 'x', 'svix-timestamp' => '1'], 'whsec_dGVzdA=='));
    }

    public function testConvertMissingType()
    {
        $payload = [
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => ['email_id' => 'msg-x', 'to' => ['user@example.com']],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(0, $events);
    }

    public function testConvertEmptyPayload()
    {
        $events = $this->converter->convert([], []);
        $this->assertCount(0, $events);
    }

    public function testConvertMissingData()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getMessageId());
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testConvertExtractsSubject()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-410',
                'to'       => ['user@example.com'],
                'subject'  => 'Test Subject',
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('Test Subject', $events[0]->getMetadata()['subject']);
    }

    public function testConvertExtractsFrom()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-411',
                'to'       => ['user@example.com'],
                'from'     => 'sender@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('sender@example.com', $events[0]->getMetadata()['from']);
    }

    public function testConvertExtractsTags()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-412',
                'to'       => ['user@example.com'],
                'tags'     => ['marketing', 'campaign-1'],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame(['marketing', 'campaign-1'], $events[0]->getMetadata()['tags']);
    }

    public function testConvertMissingToArray()
    {
        $payload = [
            'type'       => 'email.delivered',
            'created_at' => '2026-01-15T10:30:00.000Z',
            'data'       => [
                'email_id' => 'msg-413',
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getRecipient());
    }
}
