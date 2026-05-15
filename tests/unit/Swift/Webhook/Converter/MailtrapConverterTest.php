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
        $this->assertSame('550 User not found', $events[0]->getMetadata()['response']);
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

    public function testConvertEmptyPayload()
    {
        $this->assertSame([], $this->converter->convert([], []));
    }

    public function testConvertEmptyEventsArray()
    {
        $payload = ['events' => []];
        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testConvertMissingEventField()
    {
        $payload = [
            'events' => [
                [
                    'timestamp'  => 1706000000,
                    'message_id' => 'msg-no-event',
                    'email'      => 'user@example.com',
                ],
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testConvertSkipsUnknownEventInBatch()
    {
        $payload = [
            'events' => [
                ['event' => 'delivery',      'timestamp' => 1706000000, 'message_id' => 'msg-a', 'email' => 'a@example.com', 'event_id' => 'e1', 'sending_stream' => 'transactional', 'sending_domain_name' => 'example.com'],
                ['event' => 'unknown_event', 'timestamp' => 1706000000, 'message_id' => 'msg-b', 'email' => 'b@example.com'],
                ['event' => 'open',          'timestamp' => 1706000000, 'message_id' => 'msg-c', 'email' => 'c@example.com', 'event_id' => 'e3', 'sending_stream' => 'transactional', 'sending_domain_name' => 'example.com'],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(2, $events);
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('opened', $events[1]->getName());
    }

    public function testConvertBounceExtractsResponseMetadata()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'bounce',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-bounce-meta',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-meta',
                    'response'            => '550 User not found',
                    'response_code'       => 550,
                    'bounce_category'     => 'spam',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('550 User not found', $metadata['response']);
        $this->assertSame(550, $metadata['response_code']);
        $this->assertSame('spam', $metadata['bounce_category']);
    }

    public function testConvertClickExtractsUrlAndIp()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'click',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-click-meta',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-click',
                    'url'                 => 'https://example.com/tracked',
                    'ip'                  => '10.0.0.1',
                    'user_agent'          => 'Chrome/120',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('https://example.com/tracked', $metadata['url']);
        $this->assertSame('10.0.0.1', $metadata['ip']);
        $this->assertSame('Chrome/120', $metadata['user_agent']);
    }

    public function testConvertExtractsCustomVariables()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'delivery',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-custom-vars',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-cv',
                    'custom_variables'    => ['key1' => 'val1', 'key2' => 'val2'],
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame(['key1' => 'val1', 'key2' => 'val2'], $metadata['custom_variables']);
    }

    public function testConvertMissingMessageIdAndEmail()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'delivery',
                    'timestamp'           => 1706000000,
                    'event_id'            => 'evt-missing',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(1, $events);
        $this->assertSame('', $events[0]->getMessageId());
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testConvertAllEventTypes()
    {
        $typesMap = [
            'delivery'    => ['delivery', 'delivered'],
            'bounce'      => ['delivery', 'bounced'],
            'soft bounce' => ['delivery', 'deferred'],
            'suspension'  => ['delivery', 'dropped'],
            'reject'      => ['delivery', 'dropped'],
            'open'        => ['engagement', 'opened'],
            'click'       => ['engagement', 'clicked'],
            'spam'        => ['engagement', 'complained'],
            'unsubscribe' => ['engagement', 'unsubscribed'],
        ];

        foreach ($typesMap as $mailtrapEvent => [$expectedType, $expectedName]) {
            $payload = [
                'events' => [
                    [
                        'event'               => $mailtrapEvent,
                        'timestamp'           => 1706000000,
                        'message_id'          => "msg-{$expectedName}",
                        'email'               => 'user@example.com',
                        'event_id'            => 'evt-all',
                        'sending_stream'      => 'transactional',
                        'sending_domain_name' => 'example.com',
                    ],
                ],
            ];

            $events = $this->converter->convert($payload, []);
            $this->assertCount(1, $events, "Failed for event: {$mailtrapEvent}");
            $this->assertSame($expectedType, $events[0]->getType(), "Wrong type for event: {$mailtrapEvent}");
            $this->assertSame($expectedName, $events[0]->getName(), "Wrong name for event: {$mailtrapEvent}");
        }
    }

    public function testConvertSuspensionExtractsReason()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'suspension',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-susp',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-susp',
                    'reason'              => 'Daily limit reached',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('Daily limit reached', $metadata['reason']);
    }

    public function testConvertExtractsCategoryMetadata()
    {
        $payload = [
            'events' => [
                [
                    'event'               => 'delivery',
                    'timestamp'           => 1706000000,
                    'message_id'          => 'msg-cat',
                    'email'               => 'user@example.com',
                    'event_id'            => 'evt-cat',
                    'category'            => 'transactional',
                    'sending_stream'      => 'transactional',
                    'sending_domain_name' => 'example.com',
                ],
            ],
        ];

        $events   = $this->converter->convert($payload, []);
        $metadata = $events[0]->getMetadata();
        $this->assertSame('transactional', $metadata['category']);
    }
}
