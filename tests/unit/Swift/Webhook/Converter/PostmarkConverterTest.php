<?php

class Swift_Webhook_Converter_PostmarkConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_PostmarkConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_PostmarkConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('postmark', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'RecordType' => 'Bounce',
            'MessageID' => 'msg-200',
            'Email' => 'user@example.com',
            'BouncedAt' => '2026-01-15T10:30:00Z',
            'Type' => 'HardBounce',
            'Description' => 'The server was unable to deliver',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-200', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('HardBounce', $events[0]->getMetadata()['bounce_type']);
    }

    public function testConvertDeliveryEvent()
    {
        $payload = [
            'RecordType' => 'Delivery',
            'MessageID' => 'msg-201',
            'Recipient' => 'user@example.com',
            'DeliveredAt' => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'RecordType' => 'Open',
            'MessageID' => 'msg-202',
            'Recipient' => 'user@example.com',
            'ReceivedAt' => '2026-01-15T10:30:00Z',
            'UserAgent' => 'Mozilla/5.0',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'RecordType' => 'Click',
            'MessageID' => 'msg-203',
            'Recipient' => 'user@example.com',
            'ReceivedAt' => '2026-01-15T10:30:00Z',
            'OriginalLink' => 'https://example.com/page',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamComplaintEvent()
    {
        $payload = [
            'RecordType' => 'SpamComplaint',
            'MessageID' => 'msg-204',
            'Email' => 'user@example.com',
            'BouncedAt' => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertSubscriptionChangeEvent()
    {
        $payload = [
            'RecordType' => 'SubscriptionChange',
            'MessageID' => 'msg-205',
            'Recipient' => 'user@example.com',
            'ChangedAt' => '2026-01-15T10:30:00Z',
            'SuppressSending' => true,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testVerifyWithWebhookToken()
    {
        $token = 'my-postmark-webhook-token';
        $headers = ['x-postmark-webhook-token' => $token];

        $this->assertTrue($this->converter->verify('{}', $headers, $token));
        $this->assertFalse($this->converter->verify('{}', $headers, 'wrong-token'));
        $this->assertFalse($this->converter->verify('{}', [], $token));
    }
}
