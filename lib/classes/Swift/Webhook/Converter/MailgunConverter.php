<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mailgun webhook payloads into Swift_Webhook_Event objects.
 *
 * Mailgun sends individual events wrapped in {"signature":{...}, "event-data":{...}}.
 * Signature verification uses HMAC-SHA256 of (timestamp + token) with the API key.
 *
 * @see https://documentation.mailgun.com/docs/mailgun/api-reference/openapi-final/tag/Webhooks/
 */
class Swift_Webhook_Converter_MailgunConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'delivered'    => ['delivery', 'delivered'],
        'opened'       => ['engagement', 'opened'],
        'clicked'      => ['engagement', 'clicked'],
        'unsubscribed' => ['engagement', 'unsubscribed'],
        'complained'   => ['engagement', 'complained'],
    ];

    public function getProviderName(): string
    {
        return 'mailgun';
    }

    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $decoded = \json_decode($rawBody, true);
        $sig     = $decoded['signature'] ?? [];

        $timestamp = $sig['timestamp'] ?? null;
        $token     = $sig['token']     ?? null;
        $signature = $sig['signature'] ?? null;

        if (null === $timestamp || null === $token || null === $signature) {
            return false;
        }

        return $this->verifyHmac($timestamp.$token, $signature, $secret, 'sha256');
    }

    public function convert(array $payload, array $headers): array
    {
        $eventData = $payload['event-data'] ?? [];
        $eventName = $eventData['event']    ?? null;

        if (null === $eventName) {
            return [];
        }

        $recipient = $eventData['recipient']                        ?? '';
        $messageId = $eventData['message']['headers']['message-id'] ?? '';
        $timestamp = $this->parseTimestamp((int) ($eventData['timestamp'] ?? \time()));
        $metadata  = $this->extractMailgunMetadata($eventData);

        // Handle 'failed' event which maps to bounced or deferred based on severity
        if ('failed' === $eventName) {
            $severity = $eventData['severity'] ?? 'permanent';
            $name     = 'permanent' === $severity ? 'bounced' : 'deferred';

            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $eventData)];
        }

        if (!isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];

        if ('delivery' === $type) {
            return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $eventData)];
        }

        return [$this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $eventData)];
    }

    private function extractMailgunMetadata(array $eventData): array
    {
        $metadata = [];

        if (isset($eventData['delivery-status']['message'])) {
            $metadata['reason'] = $eventData['delivery-status']['message'];
        }
        if (isset($eventData['url'])) {
            $metadata['url'] = $eventData['url'];
        }
        if (isset($eventData['client-info'])) {
            $metadata['client_info'] = $eventData['client-info'];
        }
        if (isset($eventData['tags'])) {
            $metadata['tags'] = $eventData['tags'];
        }

        return $metadata;
    }
}
