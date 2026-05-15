<?php

class Swift_Webhook_Converter_SendgridConverterTest extends PHPUnit\Framework\TestCase
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
                'event'         => 'bounce',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-001.filter0001',
                'timestamp'     => 1706000000,
                'reason'        => '550 User unknown',
                'type'          => 'bounce',
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
                'event'         => 'delivered',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-002',
                'timestamp'     => 1706000000,
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
                'event'         => 'open',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-003',
                'timestamp'     => 1706000000,
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
                'event'         => 'click',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-004',
                'timestamp'     => 1706000000,
                'url'           => 'https://example.com/link',
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
                'event'         => 'spamreport',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-005',
                'timestamp'     => 1706000000,
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
                'event'         => 'delivered',
                'email'         => 'a@example.com',
                'sg_message_id' => 'msg-a',
                'timestamp'     => 1706000000,
            ],
            [
                'event'         => 'open',
                'email'         => 'b@example.com',
                'sg_message_id' => 'msg-b',
                'timestamp'     => 1706000001,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
    }

    public function testConvertUnknownEventIsSkipped()
    {
        $payload = [
            [
                'event'         => 'some_future_event',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-x',
                'timestamp'     => 1706000000,
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
            $this->converter->verify('{}', [], 'some-key'),
        );
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            [
                'event'         => 'deferred',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-006',
                'timestamp'     => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertDroppedEvent()
    {
        $payload = [
            [
                'event'         => 'dropped',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-007',
                'timestamp'     => 1706000000,
                'reason'        => 'Bounced Address',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
        $this->assertSame('Bounced Address', $events[0]->getMetadata()['reason']);
    }

    public function testConvertUnsubscribeEvent()
    {
        $payload = [
            [
                'event'         => 'unsubscribe',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-008',
                'timestamp'     => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('unsubscribed', $events[0]->getName());
    }

    public function testConvertEmptyPayload()
    {
        $events = $this->converter->convert([], []);
        $this->assertCount(0, $events);
    }

    public function testConvertStripsFilterSuffixFromMessageId()
    {
        $payload = [
            [
                'event'         => 'delivered',
                'email'         => 'user@example.com',
                'sg_message_id' => 'abc-123.filter0002.34567.p1',
                'timestamp'     => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('abc-123', $events[0]->getMessageId());
    }

    public function testConvertMessageIdWithoutFilterSuffix()
    {
        $payload = [
            [
                'event'         => 'delivered',
                'email'         => 'user@example.com',
                'sg_message_id' => 'plain-id',
                'timestamp'     => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('plain-id', $events[0]->getMessageId());
    }

    public function testConvertPreservesMetadata()
    {
        $payload = [
            [
                'event'         => 'click',
                'email'         => 'user@example.com',
                'sg_message_id' => 'msg-m',
                'timestamp'     => 1706000000,
                'url'           => 'https://example.com',
                'useragent'     => 'Mozilla/5.0',
                'ip'            => '192.168.1.1',
                'category'      => ['marketing', 'newsletter'],
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $meta   = $events[0]->getMetadata();

        $this->assertSame('https://example.com', $meta['url']);
        $this->assertSame('Mozilla/5.0', $meta['user_agent']);
        $this->assertSame('192.168.1.1', $meta['ip']);
        $this->assertSame(['marketing', 'newsletter'], $meta['categories']);
    }

    public function testVerifyMissingTimestampHeader()
    {
        $this->assertFalse($this->converter->verify(
            '{}',
            ['x-twilio-email-event-webhook-signature' => 'sig'],
            'key',
        ));
    }

    public function testVerifyMissingSignatureHeader()
    {
        $this->assertFalse($this->converter->verify(
            '{}',
            ['x-twilio-email-event-webhook-timestamp' => '123'],
            'key',
        ));
    }

    public function testVerifyInvalidBase64Signature()
    {
        $this->assertFalse($this->converter->verify(
            '{}',
            [
                'x-twilio-email-event-webhook-signature' => '!!!invalid-base64!!!',
                'x-twilio-email-event-webhook-timestamp' => '123',
            ],
            'key',
        ));
    }

    public function testConvertMixedKnownAndUnknownEvents()
    {
        $payload = [
            [
                'event'         => 'delivered',
                'email'         => 'a@example.com',
                'sg_message_id' => 'msg-a',
                'timestamp'     => 1706000000,
            ],
            [
                'event'         => 'unknown_custom_event',
                'email'         => 'b@example.com',
                'sg_message_id' => 'msg-b',
                'timestamp'     => 1706000000,
            ],
            [
                'event'         => 'open',
                'email'         => 'c@example.com',
                'sg_message_id' => 'msg-c',
                'timestamp'     => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertCount(2, $events);
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('opened', $events[1]->getName());
    }

    public function testConvertMissingEmailField()
    {
        $payload = [
            [
                'event'         => 'delivered',
                'sg_message_id' => 'msg-x',
                'timestamp'     => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getRecipient());
    }

    public function testConvertMissingMessageId()
    {
        $payload = [
            [
                'event'     => 'delivered',
                'email'     => 'user@example.com',
                'timestamp' => 1706000000,
            ],
        ];

        $events = $this->converter->convert($payload, []);
        $this->assertSame('', $events[0]->getMessageId());
    }

    public function testExtractTimestamp()
    {
        $headers = ['x-twilio-email-event-webhook-timestamp' => '1706000000'];

        $this->assertSame(1706000000, $this->converter->extractTimestamp('{}', $headers));
        $this->assertNull($this->converter->extractTimestamp('{}', []));
    }

    public function testVerifyWithValidEcdsaSignature(): void
    {
        // Generate an ECDSA key pair for testing
        $privateKey = \openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = \openssl_pkey_get_details($privateKey);
        $publicKeyPem = $details['key'];

        $timestamp = '1706000000';
        $body = '[{"event":"delivered"}]';
        $payload = $timestamp . $body;

        \openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $encodedSig = \base64_encode($signature);

        $headers = [
            'x-twilio-email-event-webhook-signature' => $encodedSig,
            'x-twilio-email-event-webhook-timestamp' => $timestamp,
        ];

        $this->assertTrue($this->converter->verify($body, $headers, $publicKeyPem));
    }

    public function testVerifyReturnsFalseWithInvalidPublicKey(): void
    {
        $headers = [
            'x-twilio-email-event-webhook-signature' => \base64_encode('sig'),
            'x-twilio-email-event-webhook-timestamp' => '123',
        ];

        $this->assertFalse($this->converter->verify('{}', $headers, 'not-a-valid-pem-key'));
    }
}
