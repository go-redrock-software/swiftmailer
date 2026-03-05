<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts SendGrid Event Webhook payloads into Swift_Webhook_Event objects.
 *
 * SendGrid sends an array of event objects. Each has an 'event' field.
 * Signature verification uses the SendGrid-provided ECDSA public key
 * and the X-Twilio-Email-Event-Webhook-Signature/Timestamp headers.
 *
 * @see https://docs.sendgrid.com/for-developers/tracking-events/event
 */
class Swift_Webhook_Converter_SendgridConverter extends Swift_Webhook_AbstractPayloadConverter
{
    /**
     * Map SendGrid event names to our canonical [type, name] pairs.
     */
    private const EVENT_MAP = [
        'bounce'      => ['delivery', 'bounced'],
        'deferred'    => ['delivery', 'deferred'],
        'delivered'   => ['delivery', 'delivered'],
        'dropped'     => ['delivery', 'dropped'],
        'open'        => ['engagement', 'opened'],
        'click'       => ['engagement', 'clicked'],
        'unsubscribe' => ['engagement', 'unsubscribed'],
        'spamreport'  => ['engagement', 'complained'],
    ];

    #[Override]
    public function getProviderName(): string
    {
        return 'sendgrid';
    }

    #[Override]
    public function extractTimestamp(string $rawBody, array $headers): ?int
    {
        return isset($headers['x-twilio-email-event-webhook-timestamp']) ? (int) $headers['x-twilio-email-event-webhook-timestamp'] : null;
    }

    #[Override]
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['x-twilio-email-event-webhook-signature'] ?? null;
        $timestamp = $headers['x-twilio-email-event-webhook-timestamp'] ?? null;

        if (null === $signature || null === $timestamp) {
            return false;
        }

        // SendGrid uses ECDSA with the public verification key
        $payload    = $timestamp.$rawBody;
        $decodedSig = \base64_decode($signature, true);

        if (false === $decodedSig) {
            return false;
        }

        $publicKey = \openssl_pkey_get_public($secret);
        if (false === $publicKey) {
            return false;
        }

        return 1 === \openssl_verify($payload, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
    }

    #[Override]
    public function convert(array $payload, array $headers): array
    {
        $events = [];

        foreach ($payload as $entry) {
            $sgEvent = $entry['event'] ?? null;
            if (null === $sgEvent || !isset(self::EVENT_MAP[$sgEvent])) {
                continue;
            }

            [$type, $name] = self::EVENT_MAP[$sgEvent];
            $messageId     = $this->extractMessageId($entry);
            $recipient     = $entry['email'] ?? '';
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

    private function extractMessageId(array $entry): string
    {
        $id = $entry['sg_message_id'] ?? '';

        // SendGrid appends filter IDs like "msg-001.filter0001" — strip the suffix
        if (false !== $pos = \strpos($id, '.')) {
            $id = \substr($id, 0, $pos);
        }

        return $id;
    }

    private function extractMetadata(array $entry): array
    {
        $metadata = [];

        if (isset($entry['reason'])) {
            $metadata['reason'] = $entry['reason'];
        }
        if (isset($entry['url'])) {
            $metadata['url'] = $entry['url'];
        }
        if (isset($entry['useragent'])) {
            $metadata['user_agent'] = $entry['useragent'];
        }
        if (isset($entry['ip'])) {
            $metadata['ip'] = $entry['ip'];
        }
        if (isset($entry['category'])) {
            $metadata['categories'] = (array) $entry['category'];
        }

        return $metadata;
    }
}
