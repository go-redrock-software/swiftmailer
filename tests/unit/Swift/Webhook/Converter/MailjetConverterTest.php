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
}
