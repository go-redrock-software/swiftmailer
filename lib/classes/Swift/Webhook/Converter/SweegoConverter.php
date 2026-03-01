<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Sweego webhook payloads into Swift_Webhook_Event objects.
 *
 * Sweego sends flat JSON objects with an 'event_type' field.
 * Event types: email_sent, delivered, soft-bounce, hard_bounce, list_unsub,
 * complaint, email_opened, email_clicked.
 *
 * Signature verification uses HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}"
 * with the base64-decoded webhook secret. The signature is base64-encoded.
 *
 * @see https://learn.sweego.io/docs/webhooks
 */
class Swift_Webhook_Converter_SweegoConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'     => ['delivery', 'delivered'],
        'hard_bounce'   => ['delivery', 'bounced'],
        'soft-bounce'   => ['delivery', 'deferred'],
        'complaint'     => ['engagement', 'complained'],
        'list_unsub'    => ['engagement', 'unsubscribed'],
        'email_opened'  => ['engagement', 'opened'],
        'email_clicked' => ['engagement', 'clicked'],
    ];

    public function getProviderName(): string
    {
        return 'sweego';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $webhookId = $headers['webhook-id']        ?? null;
        $timestamp = $headers['webhook-timestamp'] ?? null;
        $signature = $headers['webhook-signature'] ?? null;

        if (null === $webhookId || null === $timestamp || null === $signature) {
            return false;
        }

        $secretKey = \base64_decode($secret, true);
        if (false === $secretKey) {
            return false;
        }

        $signedContent = $webhookId.'.'.$timestamp.'.'.$rawBody;
        $expectedSig   = \base64_encode(\hash_hmac('sha256', $signedContent, $secretKey, true));

        return \hash_equals($expectedSig, $signature);
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['event_type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $messageId     = $payload['transaction_id'] ?? '';
        $recipient     = $payload['recipient']      ?? '';
        $timestamp     = $this->parseTimestamp($payload['timestamp'] ?? 'now');
        $metadata      = $this->extractMetadata($payload);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $payload): array
    {
        $metadata = [];

        if (isset($payload['details'])) {
            $metadata['details'] = $payload['details'];
        }
        if (isset($payload['response_code'])) {
            $metadata['response_code'] = $payload['response_code'];
        }
        if (isset($payload['domain_from'])) {
            $metadata['domain_from'] = $payload['domain_from'];
        }
        if (isset($payload['campaign_id'])) {
            $metadata['campaign_id'] = $payload['campaign_id'];
        }
        if (isset($payload['campaign_tags'])) {
            $metadata['campaign_tags'] = $payload['campaign_tags'];
        }

        // Open tracking metadata
        if (isset($payload['open'])) {
            $open = $payload['open'];
            if (isset($open['ip_address'])) {
                $metadata['ip'] = $open['ip_address'];
            }
            if (isset($open['user_agent'])) {
                $metadata['user_agent'] = $open['user_agent'];
            }
            if (isset($open['proxy'])) {
                $metadata['proxy'] = $open['proxy'];
            }
        }

        // Click tracking metadata
        if (isset($payload['click'])) {
            $click = $payload['click'];
            if (isset($click['url'])) {
                $metadata['url'] = $click['url'];
            }
            if (isset($click['ip_address'])) {
                $metadata['ip'] = $click['ip_address'];
            }
            if (isset($click['user_agent'])) {
                $metadata['user_agent'] = $click['user_agent'];
            }
        }

        return $metadata;
    }
}
