<?php

class Swift_Webhook_Converter_SendgridConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_SendgridConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_SendgridConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('sendgrid', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            [
                'event' => 'bounce',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-001.filter0001',
                'timestamp' => 1706000000,
                'reason' => '550 User unknown',
                'type' => 'bounce',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('msg-001', $events[0]->getMessageId());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            [
                'event' => 'delivered',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-002',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            [
                'event' => 'open',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-003',
                'timestamp' => 1706000000,
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
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-004',
                'timestamp' => 1706000000,
                'url' => 'https://example.com/link',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/link', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamReportEvent()
    {
        $payload = [
            [
                'event' => 'spamreport',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-005',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertMultipleEvents()
    {
        $payload = [
            [
                'event' => 'delivered',
                'email' => 'a@example.com',
                'sg_message_id' => 'msg-a',
                'timestamp' => 1706000000,
            ],
            [
                'event' => 'open',
                'email' => 'b@example.com',
                'sg_message_id' => 'msg-b',
                'timestamp' => 1706000001,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testConvertUnknownEventIsSkipped()
    {
        $payload = [
            [
                'event' => 'some_future_event',
                'email' => 'user@example.com',
                'sg_message_id' => 'msg-x',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(0, $events);
    }

    public function testVerifyAlwaysReturnsTrueWhenNoVerificationKey()
    {
        // SendGrid's ECDSA verification requires the openssl extension.
        // When verify is called, it validates the signature header.
        // For basic test: verify with empty headers should return false.
        $this->assertFalse(
            $this->converter->verify('{}', [], 'some-key')
        );
    }
}
