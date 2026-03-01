<?php

class Swift_Webhook_Converter_MandrillConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MandrillConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MandrillConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mandrill', $this->converter->getProviderName());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            [
                'event' => 'hard_bounce',
                'ts'    => 1706000000,
                '_id'   => 'msg-700',
                'msg'   => [
                    'email'       => 'user@example.com',
                    'sender'      => 'sender@example.com',
                    'subject'     => 'Test',
                    'bounce_description' => '550 User unknown',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-700', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            [
                'event' => 'soft_bounce',
                'ts'    => 1706000000,
                '_id'   => 'msg-701',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            [
                'event' => 'delivered',
                'ts'    => 1706000000,
                '_id'   => 'msg-702',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertDeferralEvent()
    {
        $payload = [
            [
                'event' => 'deferral',
                'ts'    => 1706000000,
                '_id'   => 'msg-703',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            [
                'event' => 'open',
                'ts'    => 1706000000,
                '_id'   => 'msg-704',
                'msg'   => ['email' => 'user@example.com'],
                'ip'    => '1.2.3.4',
                'user_agent' => 'Mozilla/5.0',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            [
                'event' => 'click',
                'ts'    => 1706000000,
                '_id'   => 'msg-705',
                'msg'   => ['email' => 'user@example.com'],
                'url'   => 'https://example.com/page',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            [
                'event' => 'spam',
                'ts'    => 1706000000,
                '_id'   => 'msg-706',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubEvent()
    {
        $payload = [
            [
                'event' => 'unsub',
                'ts'    => 1706000000,
                '_id'   => 'msg-707',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertRejectEvent()
    {
        $payload = [
            [
                'event' => 'reject',
                'ts'    => 1706000000,
                '_id'   => 'msg-708',
                'msg'   => ['email' => 'user@example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertMultipleEvents()
    {
        $payload = [
            ['event' => 'delivered', 'ts' => 1706000000, '_id' => 'a', 'msg' => ['email' => 'a@example.com']],
            ['event' => 'open',      'ts' => 1706000001, '_id' => 'b', 'msg' => ['email' => 'b@example.com']],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            ['event' => 'send', 'ts' => 1706000000, '_id' => 'msg-709', 'msg' => ['email' => 'user@example.com']],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        // Mandrill uses HMAC-SHA1 of (url + sorted POST keys/values), base64-encoded.
        // The raw body for Mandrill is form-encoded: mandrill_events=[...]
        $webhookKey = 'test-webhook-key';
        $webhookUrl = 'https://example.com/webhook';
        $eventsJson = '[{"event":"delivered"}]';

        // Mandrill signs: url + "mandrill_events" + eventsJson
        $signedData = $webhookUrl.'mandrill_events'.$eventsJson;
        $signature  = \base64_encode(\hash_hmac('sha1', $signedData, $webhookKey, true));

        $rawBody = 'mandrill_events='.\urlencode($eventsJson);
        $headers = [
            'x-mandrill-signature' => $signature,
        ];

        // Secret format: "key|url" — the converter splits on pipe
        $secret = $webhookKey.'|'.$webhookUrl;

        $this->assertTrue($this->converter->verify($rawBody, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = ['x-mandrill-signature' => 'invalid'];
        $this->assertFalse($this->converter->verify('mandrill_events=[]', $headers, 'key|https://example.com'));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('mandrill_events=[]', [], 'key|https://example.com'));
    }
}
