<?php

class Swift_Webhook_Converter_MailgunConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailgunConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailgunConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailgun', $this->converter->getProviderName());
    }

    public function testConvertBounceEvent()
    {
        $payload = [
            'event-data' => [
                'event'           => 'failed',
                'severity'        => 'permanent',
                'recipient'       => 'user@example.com',
                'message'         => ['headers' => ['message-id' => 'msg-100']],
                'timestamp'       => 1706000000.0,
                'delivery-status' => ['message' => '550 User not found'],
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-100', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertTemporaryFailureAsDeferred()
    {
        $payload = [
            'event-data' => [
                'event'     => 'failed',
                'severity'  => 'temporary',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-101']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event-data' => [
                'event'     => 'delivered',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-102']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenedEvent()
    {
        $payload = [
            'event-data' => [
                'event'     => 'opened',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-103']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertClickedEvent()
    {
        $payload = [
            'event-data' => [
                'event'     => 'clicked',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-104']],
                'timestamp' => 1706000000.0,
                'url'       => 'https://example.com/tracked',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('clicked', $events[0]->getName());
        $this->assertSame('https://example.com/tracked', $events[0]->getMetadata()['url']);
    }

    public function testConvertComplainedEvent()
    {
        $payload = [
            'event-data' => [
                'event'     => 'complained',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-105']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('complained', $events[0]->getName());
    }

    public function testVerifyValidSignature()
    {
        $secret      = 'test-api-key';
        $timestamp   = '1706000000';
        $token       = 'random-token-abc';
        $expectedSig = \hash_hmac('sha256', $timestamp.$token, $secret);

        $rawBody = \json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token'     => $token,
                'signature' => $expectedSig,
            ],
            'event-data' => [],
        ]);

        $this->assertTrue($this->converter->verify($rawBody, [], $secret));
    }

    public function testVerifyInvalidSignature()
    {
        $rawBody = \json_encode([
            'signature' => [
                'timestamp' => '1706000000',
                'token'     => 'random-token',
                'signature' => 'invalid',
            ],
            'event-data' => [],
        ]);

        $this->assertFalse($this->converter->verify($rawBody, [], 'my-secret'));
    }

    public function testConvertUnsubscribedEvent()
    {
        $payload = [
            'event-data' => [
                'event'     => 'unsubscribed',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-106']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertEmptyEventData()
    {
        $payload = ['event-data' => []];
        $events  = $this->converter->convert($payload, []);
        $this->assertCount(0, $events);
    }

    public function testConvertMissingEventData()
    {
        $payload = [];
        $events  = $this->converter->convert($payload, []);
        $this->assertCount(0, $events);
    }

    public function testConvertUnknownEvent()
    {
        $payload = [
            'event-data' => [
                'event'     => 'stored',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-107']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(0, $events);
    }

    public function testMetadataExtractsUrl()
    {
        $payload = [
            'event-data' => [
                'event'     => 'clicked',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-108']],
                'timestamp' => 1706000000.0,
                'url'       => 'https://example.com/tracked',
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('https://example.com/tracked', $events[0]->getMetadata()['url']);
    }

    public function testMetadataExtractsDeliveryStatusReason()
    {
        $payload = [
            'event-data' => [
                'event'           => 'failed',
                'severity'        => 'permanent',
                'recipient'       => 'user@example.com',
                'message'         => ['headers' => ['message-id' => 'msg-109']],
                'timestamp'       => 1706000000.0,
                'delivery-status' => ['message' => '550 Mailbox not found'],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('550 Mailbox not found', $events[0]->getMetadata()['reason']);
    }

    public function testMetadataExtractsClientInfo()
    {
        $payload = [
            'event-data' => [
                'event'       => 'opened',
                'recipient'   => 'user@example.com',
                'message'     => ['headers' => ['message-id' => 'msg-110']],
                'timestamp'   => 1706000000.0,
                'client-info' => ['client-os' => 'macOS', 'user-agent' => 'Thunderbird'],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertArrayHasKey('client_info', $events[0]->getMetadata());
    }

    public function testMetadataExtractsTags()
    {
        $payload = [
            'event-data' => [
                'event'     => 'delivered',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-111']],
                'timestamp' => 1706000000.0,
                'tags'      => ['transactional', 'welcome'],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame(['transactional', 'welcome'], $events[0]->getMetadata()['tags']);
    }

    public function testVerifyMissingSignatureFields()
    {
        $rawBody = \json_encode(['signature' => []]);
        $this->assertFalse($this->converter->verify($rawBody, [], 'secret'));
    }

    public function testVerifyMissingSignatureKey()
    {
        $rawBody = \json_encode([]);
        $this->assertFalse($this->converter->verify($rawBody, [], 'secret'));
    }

    public function testFailedWithDefaultSeverity()
    {
        $payload = [
            'event-data' => [
                'event'     => 'failed',
                'recipient' => 'user@example.com',
                'message'   => ['headers' => ['message-id' => 'msg-112']],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        // Default severity is 'permanent' => 'bounced'
        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertMissingMessageHeaders()
    {
        $payload = [
            'event-data' => [
                'event'     => 'delivered',
                'recipient' => 'user@example.com',
                'message'   => [],
                'timestamp' => 1706000000.0,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getMessageId());
    }

    public function testExtractTimestamp()
    {
        $rawBody = \json_encode(['signature' => ['timestamp' => '1706000000']]);

        $this->assertSame(1706000000, $this->converter->extractTimestamp($rawBody, []));
        $this->assertNull($this->converter->extractTimestamp('{}', []));
        $this->assertNull($this->converter->extractTimestamp('invalid-json', []));
    }
}
