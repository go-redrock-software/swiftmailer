<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts AhaSend webhook payloads into Swift_Webhook_Event objects.
 *
 * AhaSend follows the Standard Webhooks specification. Payloads have a 'type' field
 * (e.g. "message.delivered") and nested 'data' object.
 *
 * Signature verification uses Standard Webhooks: HMAC-SHA256 of
 * "{webhook-id}.{webhook-timestamp}.{body}" with the base64-decoded secret.
 *
 * @see https://ahasend.com/docs/integrations/webhooks
 */
class Swift_Webhook_Converter_AhaSendConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'message.delivered'    => ['delivery', 'delivered'],
        'message.hard_bounced' => ['delivery', 'bounced'],
        'message.soft_bounced' => ['delivery', 'deferred'],
        'message.opened'       => ['engagement', 'opened'],
        'message.clicked'      => ['engagement', 'clicked'],
        'message.complained'   => ['engagement', 'complained'],
        'message.unsubscribed' => ['engagement', 'unsubscribed'],
    ];

    #[Override]
    public function getProviderName(): string
    {
        return 'ahasend';
    }

    #[Override]
    public function extractTimestamp(string $rawBody, array $headers): ?int
    {
        return isset($headers['webhook-timestamp']) ? (int) $headers['webhook-timestamp'] : null;
    }

    #[Override]
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

        // Standard Webhooks signature may contain multiple signatures separated by spaces
        foreach (\explode(' ', $signature) as $candidate) {
            $parts = \explode(',', $candidate, 2);
            if (2 === \count($parts) && 'v1' === $parts[0] && \hash_equals($expectedSig, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $data          = $payload['data']           ?? [];
        $messageId     = $data['message_id_header'] ?? $data['id'] ?? '';
        $recipient     = $data['recipient']         ?? '';
        $timestamp     = $this->parseTimestamp($payload['timestamp'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $data): array
    {
        $metadata = [];

        if (isset($data['from'])) {
            $metadata['from'] = $data['from'];
        }
        if (isset($data['subject'])) {
            $metadata['subject'] = $data['subject'];
        }
        if (isset($data['reason'])) {
            $metadata['reason'] = $data['reason'];
        }

        return $metadata;
    }
}
