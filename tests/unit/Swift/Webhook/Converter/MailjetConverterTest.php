<?php

class Swift_Webhook_Converter_MailjetConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailjetConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailjetConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailjet', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'event'            => 'bounce',
            'time'             => 1706000000,
            'email'            => 'user@example.com',
            'MessageID'        => 12345678901234,
            'Message_GUID'     => 'msg-600',
            'hard_bounce'      => true,
            'comment'          => '550 User unknown',
            'error_related_to' => 'recipient',
            'error'            => 'user unknown',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-600', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('550 User unknown', $events[0]->getMetadata()['reason']);
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'event'        => 'bounce',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-601',
            'hard_bounce'  => false,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertSentEvent()
    {
        $payload = [
            'event'        => 'sent',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-602',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'event'        => 'open',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-603',
            'ip'           => '1.2.3.4',
            'agent'        => 'Mozilla/5.0',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['ip']);
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'event'        => 'click',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-604',
            'url'          => 'https://example.com/page',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'event'        => 'spam',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-605',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubEvent()
    {
        $payload = [
            'event'        => 'unsub',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-606',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertBlockedEvent()
    {
        $payload = [
            'event'        => 'blocked',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-607',
            'error'        => 'preblocked',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testSkipsUnknownEvent()
    {
        $payload = [
            'event'        => 'unknown_event',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-608',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyWithBasicAuth()
    {
        // Mailjet uses basic HTTP auth on the webhook URL, not a signature header.
        // The converter always returns true since verification happens at the HTTP layer.
        $this->assertTrue($this->converter->verify('{}', [], 'any-secret'));
    }

    public function testConvertEmptyPayload()
    {
        $this->assertSame([], $this->converter->convert([], []));
    }

    public function testConvertMissingEvent()
    {
        $payload = [
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-x',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testConvertFallsBackToMessageID()
    {
        $payload = [
            'event'     => 'sent',
            'time'      => 1706000000,
            'email'     => 'user@example.com',
            'MessageID' => 87654321,
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('87654321', $events[0]->getMessageId());
    }

    public function testConvertBounceDefaultsToHard()
    {
        // When hard_bounce is not specified, should default to hard bounce
        $payload = [
            'event'        => 'bounce',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-default-bounce',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertExtractsAllMetadata()
    {
        $payload = [
            'event'            => 'click',
            'time'             => 1706000000,
            'email'            => 'user@example.com',
            'Message_GUID'     => 'msg-meta',
            'url'              => 'https://example.com',
            'ip'               => '1.2.3.4',
            'agent'            => 'Mozilla/5.0',
            'geo'              => 'US',
            'error'            => 'some error',
            'error_related_to' => 'system',
            'CustomID'         => 'custom-123',
            'Payload'          => 'payload-data',
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('https://example.com', $metadata['url']);
        $this->assertSame('1.2.3.4', $metadata['ip']);
        $this->assertSame('Mozilla/5.0', $metadata['user_agent']);
        $this->assertSame('US', $metadata['geo']);
        $this->assertSame('some error', $metadata['error']);
        $this->assertSame('system', $metadata['error_related_to']);
        $this->assertSame('custom-123', $metadata['custom_id']);
        $this->assertSame('payload-data', $metadata['payload']);
    }

    public function testConvertBounceMetadata()
    {
        $payload = [
            'event'            => 'bounce',
            'time'             => 1706000000,
            'email'            => 'user@example.com',
            'Message_GUID'     => 'msg-bounce-meta',
            'hard_bounce'      => true,
            'comment'          => '550 User unknown',
            'error'            => 'user unknown',
            'error_related_to' => 'recipient',
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('550 User unknown', $metadata['reason']);
        $this->assertSame('user unknown', $metadata['error']);
        $this->assertSame('recipient', $metadata['error_related_to']);
    }

    public function testConvertMissingEmail()
    {
        $payload = [
            'event'        => 'sent',
            'time'         => 1706000000,
            'Message_GUID' => 'msg-no-email',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testConvertMissingMessageId()
    {
        $payload = [
            'event' => 'sent',
            'time'  => 1706000000,
            'email' => 'user@example.com',
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getMessageId());
    }

    public function testVerifyAlwaysReturnsTrue()
    {
        // Verify with empty body, empty headers, empty secret
        $this->assertTrue($this->converter->verify('', [], ''));
        $this->assertTrue($this->converter->verify('any body', ['any' => 'header'], 'any-secret'));
    }

    public function testConvertEngagementEventTypes()
    {
        $engagementTypes = [
            'open'  => 'opened',
            'click' => 'clicked',
            'spam'  => 'complained',
            'unsub' => 'unsubscribed',
        ];

        foreach ($engagementTypes as $mailjetEvent => $expectedName) {
            $payload = [
                'event'        => $mailjetEvent,
                'time'         => 1706000000,
                'email'        => 'user@example.com',
                'Message_GUID' => "msg-{$mailjetEvent}",
            ];

            $events = $this->converter->convert($payload, []);
            $this->assertCount(1, $events, "Failed for event: {$mailjetEvent}");
            $this->assertSame('engagement', $events[0]->getType(), "Failed for event: {$mailjetEvent}");
            $this->assertSame($expectedName, $events[0]->getName(), "Failed for event: {$mailjetEvent}");
        }
    }
}
