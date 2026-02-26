<?php

class Swift_Integration_WebhookFlowTest extends \PHPUnit\Framework\TestCase
{
    public function testFullSendgridWebhookFlow()
    {
        $handler = new Swift_Webhook_RequestHandler();
        $converter = new Swift_Webhook_Converter_SendgridConverter();

        $rawBody = json_encode([
            [
                'event' => 'bounce',
                'email' => 'bounce@example.com',
                'sg_message_id' => 'test-msg-001.filter',
                'timestamp' => 1706000000,
                'reason' => '550 No such user',
            ],
            [
                'event' => 'open',
                'email' => 'reader@example.com',
                'sg_message_id' => 'test-msg-002',
                'timestamp' => 1706000001,
            ],
        ]);

        // Skip signature verification for integration test
        $events = $handler->handle($converter, $rawBody, [], null);

        $this->assertCount(2, $events);

        // First event: bounce
        $this->assertTrue($events[0]->isDelivery());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('test-msg-001', $events[0]->getMessageId());
        $this->assertSame('550 No such user', $events[0]->getMetadata()['reason']);

        // Second event: open
        $this->assertTrue($events[1]->isEngagement());
        $this->assertSame('opened', $events[1]->getName());
    }

    public function testFullMailgunWebhookFlow()
    {
        $handler = new Swift_Webhook_RequestHandler();
        $converter = new Swift_Webhook_Converter_MailgunConverter();

        $secret = 'test-key';
        $timestamp = '1706000000';
        $token = 'random-token';
        $signature = hash_hmac('sha256', $timestamp . $token, $secret);

        $rawBody = json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
            'event-data' => [
                'event' => 'failed',
                'severity' => 'permanent',
                'recipient' => 'bad@example.com',
                'message' => ['headers' => ['message-id' => 'mg-msg-001']],
                'timestamp' => 1706000000.0,
            ],
        ]);

        $events = $handler->handle($converter, $rawBody, [], $secret);

        $this->assertCount(1, $events);
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('mg-msg-001', $events[0]->getMessageId());
    }

    public function testSignatureVerificationFailure()
    {
        $handler = new Swift_Webhook_RequestHandler();
        $converter = new Swift_Webhook_Converter_MailgunConverter();

        $rawBody = json_encode([
            'signature' => [
                'timestamp' => '123',
                'token' => 'abc',
                'signature' => 'tampered',
            ],
            'event-data' => [],
        ]);

        $this->expectException(Swift_Webhook_SignatureVerificationException::class);
        $handler->handle($converter, $rawBody, [], 'real-secret');
    }
}
