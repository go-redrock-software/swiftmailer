<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts MailPace webhook payloads into Swift_Webhook_Event objects.
 *
 * MailPace sends JSON with an 'event' field (e.g. "email.delivered") and a nested 'payload' object.
 * Event types: email.queued, email.delivered, email.deferred, email.bounced, email.spam.
 *
 * Signature verification uses Ed25519. The signature (base64-encoded) is sent in the
 * X-MailPace-Signature header. The $secret parameter is the base64-encoded public key.
 *
 * @see https://docs.mailpace.com/guide/webhooks/
 */
class Swift_Webhook_Converter_MailPaceConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'email.delivered' => ['delivery', 'delivered'],
        'email.bounced'   => ['delivery', 'bounced'],
        'email.deferred'  => ['delivery', 'deferred'],
        'email.spam'      => ['delivery', 'dropped'],
    ];

    #[\Override]
    public function getProviderName(): string
    {
        return 'mailpace';
    }

    #[\Override]
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['x-mailpace-signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        $publicKey    = \base64_decode($secret, true);
        $signatureRaw = \base64_decode($signature, true);

        if (false === $publicKey || false === $signatureRaw) {
            return false;
        }

        try {
            return \sodium_crypto_sign_verify_detached($signatureRaw, $rawBody, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    #[\Override]
    public function convert(array $payload, array $headers): array
    {
        $eventName = $payload['event'] ?? null;

        if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
            return [];
        }

        [$type, $name] = self::EVENT_MAP[$eventName];
        $data          = $payload['payload'] ?? [];
        $messageId     = $data['message_id'] ?? '';
        $recipient     = $data['to']         ?? '';
        $timestamp     = $this->parseTimestamp($data['updated_at'] ?? $data['created_at'] ?? 'now');
        $metadata      = $this->extractMetadata($data);

        return [$this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $payload)];
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
        if (isset($data['tags'])) {
            $metadata['tags'] = (array) $data['tags'];
        }
        if (isset($data['status'])) {
            $metadata['status'] = $data['status'];
        }

        return $metadata;
    }
}
