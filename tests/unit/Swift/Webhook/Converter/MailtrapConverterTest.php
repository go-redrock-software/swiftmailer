<?php

class Swift_Webhook_Converter_MailtrapConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailtrapConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailtrapConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailtrap', $this->converter->getProviderName());
    }

    public function testConvertDeliveryEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'delivery',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1000',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-1',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('msg-1000', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'bounce',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1001',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-2',
                    'response'            => '550 User not found',
                    'response_code'       => 550,
                    'bounce_category'     => 'spam',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('550 User not found', $events[0]->getMetadata()['reason']);
    }

    public function testConvertSoftBounceEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'soft bounce',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1002',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-3',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'open',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1003',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-4',
                    'ip'                  => '1.2.3.4',
                    'user_agent'          => 'Mozilla/5.0',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
        $this->assertSame('1.2.3.4', $events[0]->getMetadata()['ip']);
    }

    public function testConvertClickEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'click',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1004',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-5',
                    'url'                 => 'https://example.com/page',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/page', $events[0]->getMetadata()['url']);
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'spam',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1005',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-6',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertUnsubscribeEvent()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'unsubscribe',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1006',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-7',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertSuspensionAsDropped()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'suspension',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1007',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-8',
                    'reason'              => 'Daily limit reached',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertRejectAsDropped()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'reject',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-1008',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-9',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testConvertMultipleEvents()
    {
        $payload = [
            'events' => [
                ['event' => 'delivery',  'timestamp' => 1706000000, 'message_id' => 'a', 'email' => 'a@example.com', 'event_id' => 'e1', 'sending_stream' => 'transactional', 'sending_domain_name' => 'example.com'],
                ['event' => 'open',      'timestamp' => 1706000001, 'message_id' => 'b', 'email' => 'b@example.com', 'event_id' => 'e2', 'sending_stream' => 'transactional', 'sending_domain_name' => 'example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testSkipsActivityLogEvent()
    {
        $payload = [
            'events' => [
                ['event' => 'activity_log.user.login', 'timestamp' => 1706000000],
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidSignature()
    {
        $secret = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        $body   = '{"events":[]}';
        $sig    = \hash_hmac('sha256', $body, $secret);

        $headers = ['mailtrap-signature' => $sig];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $headers = ['mailtrap-signature' => 'invalid'];

        $this->assertFalse($this->converter->verify('{}', $headers, 'secret'));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'secret'));
    }
}
