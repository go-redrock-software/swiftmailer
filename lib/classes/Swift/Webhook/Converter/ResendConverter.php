<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Resend webhook payloads into Swift_Webhook_Event objects.
 *
 * Resend sends JSON with a 'type' field (e.g. "email.delivered").
 * Signature verification uses Svix: HMAC-SHA256 of "{svix-id}.{svix-timestamp}.{body}"
 * with the base64-decoded portion of the whsec_ prefixed secret.
 *
 * @see https://resend.com/docs/dashboard/webhooks/event-types
 */
class Swift_Webhook_Converter_ResendConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'email.delivered'        => ['delivery', 'delivered'],
        'email.bounced'          => ['delivery', 'bounced'],
        'email.delivery_delayed' => ['delivery', 'deferred'],
        'email.opened'           => ['engagement', 'opened'],
        'email.clicked'          => ['engagement', 'clicked'],
        'email.complained'       => ['engagement', 'complained'],
    ];

    #[Override]
    public function getProviderName(): string
    {
        return 'resend';
    }

    #[Override]
    public function extractTimestamp(string $rawBody, array $headers): ?int
    {
        return isset($headers['svix-timestamp']) ? (int) $headers['svix-timestamp'] : null;
    }

    #[Override]
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $svixId    = $headers['svix-id']        ?? null;
        $timestamp = $headers['svix-timestamp'] ?? null;
        $signature = $headers['svix-signature'] ?? null;

        if (null === $svixId || null === $timestamp || null === $signature) {
            return false;
        }

        // Strip the "whsec_" prefix and base64-decode the secret
        if (\str_starts_with($secret, 'whsec_')) {
            $secretKey = \base64_decode(\substr($secret, 6), true);
        } else {
            $secretKey = \base64_decode($secret, true);
        }
        if (false === $secretKey) {
            return false;
        }

        $signedContent = $svixId.'.'.$timestamp.'.'.$rawBody;
        $expectedSig   = \base64_encode(\hash_hmac('sha256', $signedContent, $secretKey, true));

        // Svix signature header may contain multiple signatures separated by spaces, each prefixed with "v1,"
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
        $data          = $payload['data']  ?? [];
        $messageId     = $data['email_id'] ?? '';
        $recipients    = (array) ($data['to'] ?? []);
        $recipient     = $recipients[0] ?? '';
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
        if (isset($data['from'])) {
            $metadata['from'] = $data['from'];
        }
        if (isset($data['tags'])) {
            $metadata['tags'] = (array) $data['tags'];
        }

        return $metadata;
    }
}
