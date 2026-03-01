<?php

class Swift_Webhook_Converter_SweegoConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_SweegoConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_SweegoConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('sweego', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event_type'     => 'delivered',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1100',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-1',
            'domain_from'    => 'example.com',
            'details'        => 'ACCEPTED (250 OK)',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('tx-1100', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertHardBounceEvent()
    {
        $payload = [
            'event_type'     => 'hard_bounce',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1101',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-2',
            'domain_from'    => 'example.com',
            'response_code'  => 550,
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'event_type'     => 'soft-bounce',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1102',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-3',
            'domain_from'    => 'example.com',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'event_type'     => 'email_opened',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1103',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-4',
            'domain_from'    => 'example.com',
            'open'           => [
                'ip_address' => '1.2.3.4',
                'user_agent' => 'Mozilla/5.0',
                'proxy'      => false,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['ip']);
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'event_type'     => 'email_clicked',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1104',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-5',
            'domain_from'    => 'example.com',
            'click'          => [
                'url'        => 'https://example.com/page',
                'ip_address' => '1.2.3.4',
                'user_agent' => 'Mozilla/5.0',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertComplaintEvent()
    {
        $payload = [
            'event_type'     => 'complaint',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1105',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-6',
            'domain_from'    => 'example.com',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertListUnsubEvent()
    {
        $payload = [
            'event_type'     => 'list_unsub',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1106',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-7',
            'domain_from'    => 'example.com',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testSkipsEmailSentEvent()
    {
        $payload = [
            'event_type'     => 'email_sent',
            'timestamp'      => '2026-01-15T10:30:00+00:00',
            'transaction_id' => 'tx-1107',
            'recipient'      => 'user@example.com',
            'channel'        => 'email',
            'event_id'       => 'evt-8',
            'domain_from'    => 'example.com',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        // Sweego: HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}" with base64-decoded secret
        $secretRaw = \random_bytes(32);
        $secret    = \base64_encode($secretRaw);
        $id        = 'wh_sweego_test';
        $timestamp = '1706000000';
        $body      = '{"event_type":"delivered"}';

        $signedContent = $id.'.'.$timestamp.'.'.$body;
        $signature     = \base64_encode(\hash_hmac('sha256', $signedContent, $secretRaw, true));

        $headers = [
            'webhook-id'        => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => $signature,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = [
            'webhook-id'        => 'test',
            'webhook-timestamp' => '1706000000',
            'webhook-signature' => 'invalidsig',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, \base64_encode('secret')));
    }

    public function testVerifyMissingHeaders()
    {
        $this->assertFalse($this->converter->verify('{}', [], \base64_encode('secret')));
    }
}
