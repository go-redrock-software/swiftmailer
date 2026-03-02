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

    public function testConvertUniqueOpenedEvent()
    {
        $payload = [
            'event'      => 'uniqueOpened',
            'email'      => 'user@example.com',
            'message-id' => '<msg-310@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertInvalidEvent()
    {
        $payload = [
            'event'      => 'invalid',
            'email'      => 'user@example.com',
            'message-id' => '<msg-311@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertErrorEvent()
    {
        $payload = [
            'event'      => 'error',
            'email'      => 'user@example.com',
            'message-id' => '<msg-312@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertMissingEvent()
    {
        $events = $this->converter->convert([], []);
        $this->assertCount(0, $events);
    }

    public function testMetadataExtractsLink()
    {
        $payload = [
            'event'      => 'click',
            'email'      => 'user@example.com',
            'message-id' => '<msg-313@example.com>',
            'ts_epoch'   => 1706000000000,
            'link'       => 'https://example.com/cta',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('https://example.com/cta', $events[0]->getMetadata()['url']);
    }

    public function testMetadataExtractsTag()
    {
        $payload = [
            'event'      => 'delivered',
            'email'      => 'user@example.com',
            'message-id' => '<msg-314@example.com>',
            'ts_epoch'   => 1706000000000,
            'tag'        => 'welcome-email',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('welcome-email', $events[0]->getMetadata()['tag']);
    }

    public function testMetadataExtractsTags()
    {
        $payload = [
            'event'      => 'delivered',
            'email'      => 'user@example.com',
            'message-id' => '<msg-315@example.com>',
            'ts_epoch'   => 1706000000000,
            'tags'       => ['tag1', 'tag2'],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame(['tag1', 'tag2'], $events[0]->getMetadata()['tags']);
    }

    public function testMetadataExtractsSendingIp()
    {
        $payload = [
            'event'      => 'delivered',
            'email'      => 'user@example.com',
            'message-id' => '<msg-316@example.com>',
            'ts_epoch'   => 1706000000000,
            'sending_ip' => '1.2.3.4',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['sending_ip']);
    }

    public function testMetadataExtractsSubject()
    {
        $payload = [
            'event'      => 'delivered',
            'email'      => 'user@example.com',
            'message-id' => '<msg-317@example.com>',
            'ts_epoch'   => 1706000000000,
            'subject'    => 'Test Subject',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('Test Subject', $events[0]->getMetadata()['subject']);
    }

    public function testMissingEmailDefaultsToEmpty()
    {
        $payload = [
            'event'      => 'delivered',
            'message-id' => '<msg-318@example.com>',
            'ts_epoch'   => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testMissingMessageIdDefaultsToEmpty()
    {
        $payload = [
            'event'    => 'delivered',
            'email'    => 'user@example.com',
            'ts_epoch' => 1706000000000,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getMessageId());
    }
}
