<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailomat webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailomat sends flat JSON objects with an 'eventType' field.
 * Event types: accepted, not_accepted, delivered, failure_tmp, failure_perm, opened, clicked.
 *
 * Signature verification uses HMAC-SHA256 of "{id}.{event}.{timestamp}" (from headers)
 * with the webhook secret. The signature header is prefixed with "sha256=".
 *
 * @see https://api.mailomat.swiss/docs
 */
class Swift_Webhook_Converter_MailomatConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'    => ['delivery', 'delivered'],
        'failure_perm' => ['delivery', 'bounced'],
        'failure_tmp'  => ['delivery', 'deferred'],
        'opened'       => ['engagement', 'opened'],
        'clicked'      => ['engagement', 'clicked'],
    ];

    public function getProviderName(): string
    {
        return 'mailomat';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $id        = $headers['x-mom-webhook-id']        ?? null;
        $event     = $headers['x-mom-webhook-event']     ?? null;
        $timestamp = $headers['x-mom-webhook-timestamp'] ?? null;
        $signature = $headers['x-mom-webhook-signature'] ?? null;

        if (null === $id || null === $event || null === $timestamp || null === $signature) {
            return false;
        }

        // Split "sha256=<hash>" to get the algorithm and hash
        $parts = \explode('=', $signature, 2);
        if (2 !== \count($parts)) {
            return false;
        }

        [$algo, $hash] = $parts;

        if ('sha256' !== $algo) {
            return false;
        }

        $payload  = \implode('.', [$id, $event, $timestamp]);
        $expected = \hash_hmac('sha256', $payload, $secret);

        return \hash_equals($expected, $hash);
    }

    public function convert(array $payload, array $headers): array
    {
        $eventType = $payload['eventType'] ?? null;

        if (null === $eventType || !isset(self::EVENT_MAP[$eventType])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventType];
        $messageId     = $payload['messageId'] ?? '';
        $recipient     = $payload['recipient'] ?? '';
        $timestamp     = $this->parseTimestamp($payload['occurredAt'] ?? 'now');
        $metadata      = [];

        if (!empty($payload['payload']) && \is_array($payload['payload'])) {
            $metadata = $payload['payload'];
        }

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
    }
}
