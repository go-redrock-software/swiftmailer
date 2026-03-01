<?php

class Swift_Webhook_Converter_BrevoConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_BrevoConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_BrevoConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('brevo', $this->converter->getProviderName());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            'event'      => 'hardBounce',
            'email'      => 'user@example.com',
            'message-id' => '<msg-300@example.com>',
            'ts_epoch'   => 1706000000000,
            'reason'     => '550 User unknown',
            'tag'        => 'campaign-1',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('<msg-300@example.com>', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('550 User unknown', $events[0]->getMetadata()['reason']);
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'event'      => 'softBounce',
            'email'      => 'user@example.com',
            'message-id' => '<msg-301@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event'      => 'delivered',
            'email'      => 'user@example.com',
            'message-id' => '<msg-302@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'event'      => 'opened',
            'email'      => 'user@example.com',
            'message-id' => '<msg-303@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'event'      => 'click',
            'email'      => 'user@example.com',
            'message-id' => '<msg-304@example.com>',
            'ts_epoch'   => 1706000000000,
            'link'       => 'https://example.com/page',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'event'      => 'spam',
            'email'      => 'user@example.com',
            'message-id' => '<msg-305@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubscribedEvent()
    {
        $payload = [
            'event'      => 'unsubscribed',
            'email'      => 'user@example.com',
            'message-id' => '<msg-306@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            'event'      => 'deferred',
            'email'      => 'user@example.com',
            'message-id' => '<msg-307@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertBlockedAsDropped()
    {
        $payload = [
            'event'      => 'blocked',
            'email'      => 'user@example.com',
            'message-id' => '<msg-308@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'event'      => 'request',
            'email'      => 'user@example.com',
            'message-id' => '<msg-309@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyWithTokenHeader()
    {
        // Brevo uses a simple token comparison via a custom header set when creating the webhook
        $secret  = 'my-brevo-webhook-token';
        $headers = ['x-brevo-webhook-token' => $secret];

        $this->assertTrue($this->converter->verify('{}', $headers, $secret));
        $this->assertFalse($this->converter->verify('{}', $headers, 'wrong'));
        $this->assertFalse($this->converter->verify('{}', [], $secret));
    }
}
