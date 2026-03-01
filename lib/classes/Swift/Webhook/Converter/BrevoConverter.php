<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Brevo transactional webhook payloads into Swift_Webhook_Event objects.
 *
 * Brevo sends flat JSON objects with an 'event' field.
 * Signature verification uses a token header set when creating the webhook.
 *
 * @see https://developers.brevo.com/docs/transactional-webhooks
 */
class Swift_Webhook_Converter_BrevoConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'    => ['delivery', 'delivered'],
        'hardBounce'   => ['delivery', 'bounced'],
        'softBounce'   => ['delivery', 'deferred'],
        'deferred'     => ['delivery', 'deferred'],
        'blocked'      => ['delivery', 'dropped'],
        'invalid'      => ['delivery', 'dropped'],
        'error'        => ['delivery', 'dropped'],
        'opened'       => ['engagement', 'opened'],
        'uniqueOpened' => ['engagement', 'opened'],
        'click'        => ['engagement', 'clicked'],
        'spam'         => ['engagement', 'complained'],
        'unsubscribed' => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'brevo';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $token = $headers['x-brevo-webhook-token'] ?? null;

        if (null === $token) {
            return false;
        }

        return \hash_equals($secret, $token);
    }

    public function convert(array $payload, array $headers): array
    {
        $eventName = $payload['event'] ?? null;

        if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];
        $messageId     = $payload['message-id'] ?? '';
        $recipient     = $payload['email'] ?? '';
        $timestamp     = $this->parseTimestamp((int) (($payload['ts_epoch'] ?? \time() * 1000) / 1000));
        $metadata      = $this->extractMetadata($payload);

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $payload): array
    {
        $metadata = [];

        if (isset($payload['reason'])) {
            $metadata['reason'] = $payload['reason'];
        }
        if (isset($payload['link'])) {
            $metadata['url'] = $payload['link'];
        }
        if (isset($payload['tag'])) {
            $metadata['tag'] = $payload['tag'];
        }
        if (isset($payload['tags'])) {
            $metadata['tags'] = (array) $payload['tags'];
        }
        if (isset($payload['sending_ip'])) {
            $metadata['sending_ip'] = $payload['sending_ip'];
        }
        if (isset($payload['subject'])) {
            $metadata['subject'] = $payload['subject'];
        }

        return $metadata;
    }
}
