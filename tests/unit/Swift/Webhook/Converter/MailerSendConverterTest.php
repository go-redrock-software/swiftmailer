<?php

class Swift_Webhook_Converter_MailerSendConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailerSendConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailerSendConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailersend', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'type'       => 'activity.delivered',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-500',
                'email'      => 'user@example.com',
                'subject'    => 'Hello',
                'tags'       => ['welcome'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-500', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertHardBouncedEvent()
    {
        $payload = [
            'type'       => 'activity.hard_bounced',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-501',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertSoftBouncedEvent()
    {
        $payload = [
            'type'       => 'activity.soft_bounced',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-502',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            'type'       => 'activity.deferred',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-503',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'type'       => 'activity.opened',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-504',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'type'       => 'activity.clicked',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-505',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
    }

    public function testConvertSpamComplaintEvent()
    {
        $payload = [
            'type'       => 'activity.spam_complaint',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-506',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubscribedEvent()
    {
        $payload = [
            'type'       => 'activity.unsubscribed',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => [
                'message_id' => 'msg-507',
                'email'      => 'user@example.com',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'type'       => 'activity.sent',
            'created_at' => '2026-01-15T10:30:00.000000Z',
            'data'       => ['message_id' => 'msg-508', 'email' => 'user@example.com'],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        $secret  = 'test-signing-secret';
        $body    = '{"type":"activity.delivered"}';
        $sig     = \hash_hmac('sha256', $body, $secret);
        $headers = ['signature' => $sig];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = ['signature' => 'invalid'];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'secret'));
    }
}
