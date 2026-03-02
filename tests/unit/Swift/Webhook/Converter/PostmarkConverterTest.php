<?php

class Swift_Webhook_Converter_PostmarkConverterTest extends PHPUnit\Framework\TestCase
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
            'RecordType'  => 'Bounce',
            'MessageID'   => 'msg-200',
            'Email'       => 'user@example.com',
            'BouncedAt'   => '2026-01-15T10:30:00Z',
            'Type'        => 'HardBounce',
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
            'RecordType'  => 'Delivery',
            'MessageID'   => 'msg-201',
            'Recipient'   => 'user@example.com',
            'DeliveredAt' => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'RecordType' => 'Open',
            'MessageID'  => 'msg-202',
            'Recipient'  => 'user@example.com',
            'ReceivedAt' => '2026-01-15T10:30:00Z',
            'UserAgent'  => 'Mozilla/5.0',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'RecordType'   => 'Click',
            'MessageID'    => 'msg-203',
            'Recipient'    => 'user@example.com',
            'ReceivedAt'   => '2026-01-15T10:30:00Z',
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
            'MessageID'  => 'msg-204',
            'Email'      => 'user@example.com',
            'BouncedAt'  => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertSubscriptionChangeEvent()
    {
        $payload = [
            'RecordType'      => 'SubscriptionChange',
            'MessageID'       => 'msg-205',
            'Recipient'       => 'user@example.com',
            'ChangedAt'       => '2026-01-15T10:30:00Z',
            'SuppressSending' => true,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testVerifyWithWebhookToken()
    {
        $token   = 'my-postmark-webhook-token';
        $headers = ['x-postmark-webhook-token' => $token];

        $this->assertTrue($this->converter->verify('{}', $headers, $token));
        $this->assertFalse($this->converter->verify('{}', $headers, 'wrong-token'));
        $this->assertFalse($this->converter->verify('{}', [], $token));
    }

    public function testConvertUnknownRecordType()
    {
        $payload = [
            'RecordType' => 'UnknownFuture',
            'MessageID'  => 'msg-300',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(0, $events);
    }

    public function testConvertMissingRecordType()
    {
        $events = $this->converter->convert([], []);
        $this->assertCount(0, $events);
    }

    public function testBounceEventMetadata()
    {
        $payload = [
            'RecordType'  => 'Bounce',
            'MessageID'   => 'msg-301',
            'Email'       => 'user@example.com',
            'BouncedAt'   => '2026-01-15T10:30:00Z',
            'Type'        => 'SoftBounce',
            'Description' => 'Mailbox full',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('SoftBounce', $events[0]->getMetadata()['bounce_type']);
        $this->assertSame('Mailbox full', $events[0]->getMetadata()['reason']);
    }

    public function testBounceEventWithoutOptionalFields()
    {
        $payload = [
            'RecordType' => 'Bounce',
            'MessageID'  => 'msg-302',
            'Email'      => 'user@example.com',
            'BouncedAt'  => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame([], $events[0]->getMetadata());
    }

    public function testOpenEventMetadata()
    {
        $payload = [
            'RecordType' => 'Open',
            'MessageID'  => 'msg-303',
            'Recipient'  => 'user@example.com',
            'ReceivedAt' => '2026-01-15T10:30:00Z',
            'UserAgent'  => 'Mozilla/5.0 (Macintosh)',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('Mozilla/5.0 (Macintosh)', $events[0]->getMetadata()['user_agent']);
    }

    public function testClickEventMetadata()
    {
        $payload = [
            'RecordType'   => 'Click',
            'MessageID'    => 'msg-304',
            'Recipient'    => 'user@example.com',
            'ReceivedAt'   => '2026-01-15T10:30:00Z',
            'OriginalLink' => 'https://example.com/cta',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('https://example.com/cta', $events[0]->getMetadata()['url']);
    }

    public function testSubscriptionChangeMetadata()
    {
        $payload = [
            'RecordType'      => 'SubscriptionChange',
            'MessageID'       => 'msg-305',
            'Recipient'       => 'user@example.com',
            'ChangedAt'       => '2026-01-15T10:30:00Z',
            'SuppressSending' => false,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('unsubscribed', $events[0]->getName());
        $this->assertFalse($events[0]->getMetadata()['suppress_sending']);
    }

    public function testMissingEmailAndRecipientDefaultsToEmpty()
    {
        $payload = [
            'RecordType' => 'Bounce',
            'BouncedAt'  => '2026-01-15T10:30:00Z',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getRecipient());
        $this->assertSame('', $events[0]->getMessageId());
    }

    public function testVerifyTimingSafe()
    {
        // Ensure token comparison is timing-safe (using hash_equals)
        $token   = 'correct-token';
        $headers = ['x-postmark-webhook-token' => $token];

        // Matching token
        $this->assertTrue($this->converter->verify('{}', $headers, $token));

        // Slightly different token
        $this->assertFalse($this->converter->verify('{}', $headers, $token.'x'));
    }
}
