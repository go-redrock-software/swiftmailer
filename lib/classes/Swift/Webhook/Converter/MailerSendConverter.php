<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts MailerSend webhook payloads into Swift_Webhook_Event objects.
 *
 * MailerSend sends JSON with a 'type' field (e.g. "activity.delivered").
 * Signature verification uses HMAC-SHA256 of the raw body with the signing secret.
 * The signature is sent in the 'Signature' header as a hex string.
 *
 * @see https://developers.mailersend.com/api/v1/webhooks.html
 */
class Swift_Webhook_Converter_MailerSendConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'activity.delivered'      => ['delivery', 'delivered'],
        'activity.hard_bounced'   => ['delivery', 'bounced'],
        'activity.soft_bounced'   => ['delivery', 'deferred'],
        'activity.deferred'       => ['delivery', 'deferred'],
        'activity.opened'         => ['engagement', 'opened'],
        'activity.opened_unique'  => ['engagement', 'opened'],
        'activity.clicked'        => ['engagement', 'clicked'],
        'activity.clicked_unique' => ['engagement', 'clicked'],
        'activity.unsubscribed'   => ['engagement', 'unsubscribed'],
        'activity.spam_complaint' => ['engagement', 'complained'],
    ];

    #[\Override]
    public function getProviderName(): string
    {
        return 'mailersend';
    }

    #[\Override]
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        return $this->verifyHmac($rawBody, $signature, $secret, 'sha256');
    }

    #[\Override]
    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['type'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $data          = $payload['data']    ?? [];
        $messageId     = $data['message_id'] ?? '';
        $recipient     = $data['email']      ?? '';
        $timestamp     = $this->parseTimestamp($payload['created_at'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $data): array
    {
        $metadata = [];

        if (isset($data['subject'])) {
            $metadata['subject'] = $data['subject'];
        }
        if (isset($data['tags'])) {
            $metadata['tags'] = (array) $data['tags'];
        }

        return $metadata;
    }
}
