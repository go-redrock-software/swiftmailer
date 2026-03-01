<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailjet Event API webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailjet sends flat JSON objects with an 'event' field.
 * Mailjet does not use a signature header — security is handled via basic HTTP auth
 * on the webhook URL. The verify() method always returns true.
 *
 * @see https://dev.mailjet.com/email/guides/webhooks/
 */
class Swift_Webhook_Converter_MailjetConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'sent'    => ['delivery', 'delivered'],
        'blocked' => ['delivery', 'dropped'],
        'open'    => ['engagement', 'opened'],
        'click'   => ['engagement', 'clicked'],
        'spam'    => ['engagement', 'complained'],
        'unsub'   => ['engagement', 'unsubscribed'],
    ];

    public function getProviderName(): string
    {
        return 'mailjet';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        // Mailjet relies on basic HTTP authentication on the webhook URL.
        // Signature verification is not provided via headers.
        return true;
    }

    public function convert(array $payload, array $headers): array
    {
        $eventName = $payload['event'] ?? null;

        if (null === $eventName) {
            return [];
        }

        $messageId = (string) ($payload['Message_GUID'] ?? $payload['MessageID'] ?? '');
        $recipient = $payload['email'] ?? '';
        $timestamp = $this->parseTimestamp($payload['time'] ?? \time());
        $metadata  = $this->extractMetadata($payload);

        // Bounce has special handling: hard_bounce determines bounced vs deferred
        if ('bounce' === $eventName) {
            $isHard = $payload['hard_bounce'] ?? true;
            $name   = $isHard ? 'bounced' : 'deferred';

            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        if (!isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }

    private function extractMetadata(array $payload): array
    {
        $metadata = [];

        if (isset($payload['comment'])) {
            $metadata['reason'] = $payload['comment'];
        }
        if (isset($payload['url'])) {
            $metadata['url'] = $payload['url'];
        }
        if (isset($payload['ip'])) {
            $metadata['ip'] = $payload['ip'];
        }
        if (isset($payload['agent'])) {
            $metadata['user_agent'] = $payload['agent'];
        }
        if (isset($payload['geo'])) {
            $metadata['geo'] = $payload['geo'];
        }
        if (isset($payload['error'])) {
            $metadata['error'] = $payload['error'];
        }
        if (isset($payload['error_related_to'])) {
            $metadata['error_related_to'] = $payload['error_related_to'];
        }
        if (isset($payload['CustomID'])) {
            $metadata['custom_id'] = $payload['CustomID'];
        }
        if (isset($payload['Payload'])) {
            $metadata['payload'] = $payload['Payload'];
        }

        return $metadata;
    }
}
