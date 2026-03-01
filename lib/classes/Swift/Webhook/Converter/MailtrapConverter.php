<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailtrap webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailtrap sends a JSON object with an 'events' array. Each entry has an 'event' field.
 * Event types: delivery, bounce, soft bounce, open, click, spam, unsubscribe, suspension, reject.
 *
 * Signature verification uses HMAC-SHA256 of the raw body with the signing secret.
 * The signature is sent in the 'Mailtrap-Signature' header as a hex string.
 *
 * @see https://docs.mailtrap.io/email-api-smtp/advanced/webhooks
 */
class Swift_Webhook_Converter_MailtrapConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
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

    public function getProviderName(): string
    {
        return 'mailtrap';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['mailtrap-signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        return $this->verifyHmac($rawBody, $signature, $secret, 'sha256');
    }

    public function convert(array $payload, array $headers): array
    {
        $entries = $payload['events'] ?? [];
        $events  = [];

        foreach ($entries as $entry) {
            $eventName = $entry['event'] ?? null;

            if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
                continue;
            }

            [$type, $name] = self::EVENT_MAP[$eventName];
            $messageId     = $entry['message_id'] ?? '';
            $recipient     = $entry['email']      ?? '';
            $timestamp     = $this->parseTimestamp($entry['timestamp'] ?? \time());
            $metadata      = $this->extractMetadata($entry);

            if ('delivery' === $type) {
                $events[] = $this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            } else {
                $events[] = $this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            }
        }

        return $events;
    }

    private function extractMetadata(array $entry): array
    {
        $metadata = [];

        if (isset($entry['response'])) {
            $metadata['reason'] = $entry['response'];
        }
        if (isset($entry['reason'])) {
            $metadata['reason'] = $entry['reason'];
        }
        if (isset($entry['response_code'])) {
            $metadata['response_code'] = $entry['response_code'];
        }
        if (isset($entry['bounce_category'])) {
            $metadata['bounce_category'] = $entry['bounce_category'];
        }
        if (isset($entry['url'])) {
            $metadata['url'] = $entry['url'];
        }
        if (isset($entry['ip'])) {
            $metadata['ip'] = $entry['ip'];
        }
        if (isset($entry['user_agent'])) {
            $metadata['user_agent'] = $entry['user_agent'];
        }
        if (isset($entry['category'])) {
            $metadata['category'] = $entry['category'];
        }
        if (isset($entry['custom_variables'])) {
            $metadata['custom_variables'] = $entry['custom_variables'];
        }

        return $metadata;
    }
}
