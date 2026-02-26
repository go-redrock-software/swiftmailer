<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Postmark webhook payloads into Swift_Webhook_Event objects.
 *
 * Postmark sends flat JSON objects with a 'RecordType' field.
 * Signature verification uses the X-Postmark-Webhook-Token header.
 *
 * @see https://postmarkapp.com/developer/webhooks/webhooks-overview
 */
class Swift_Webhook_Converter_PostmarkConverter extends Swift_Webhook_AbstractPayloadConverter
{
    public function getProviderName(): string
    {
        return 'postmark';
    }

    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool
    {
        $token = $headers['x-postmark-webhook-token'] ?? null;

        if (null === $token) {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function convert(array $payload, array $headers): array
    {
        $recordType = $payload['RecordType'] ?? null;

        if (null === $recordType) {
            return [];
        }

        return match ($recordType) {
            'Bounce' => [$this->convertBounce($payload)],
            'Delivery' => [$this->convertDelivery($payload)],
            'Open' => [$this->convertOpen($payload)],
            'Click' => [$this->convertClick($payload)],
            'SpamComplaint' => [$this->convertSpamComplaint($payload)],
            'SubscriptionChange' => [$this->convertSubscriptionChange($payload)],
            default => [],
        };
    }

    private function convertBounce(array $payload): Swift_Webhook_Event
    {
        $metadata = [];
        if (isset($payload['Type'])) {
            $metadata['bounce_type'] = $payload['Type'];
        }
        if (isset($payload['Description'])) {
            $metadata['reason'] = $payload['Description'];
        }

        return $this->createDeliveryEvent(
            'bounced',
            $payload['MessageID'] ?? '',
            $payload['Email'] ?? '',
            $metadata,
            $this->parseTimestamp($payload['BouncedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertDelivery(array $payload): Swift_Webhook_Event
    {
        return $this->createDeliveryEvent(
            'delivered',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            [],
            $this->parseTimestamp($payload['DeliveredAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertOpen(array $payload): Swift_Webhook_Event
    {
        $metadata = [];
        if (isset($payload['UserAgent'])) {
            $metadata['user_agent'] = $payload['UserAgent'];
        }

        return $this->createEngagementEvent(
            'opened',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            $metadata,
            $this->parseTimestamp($payload['ReceivedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertClick(array $payload): Swift_Webhook_Event
    {
        $metadata = [];
        if (isset($payload['OriginalLink'])) {
            $metadata['url'] = $payload['OriginalLink'];
        }

        return $this->createEngagementEvent(
            'clicked',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            $metadata,
            $this->parseTimestamp($payload['ReceivedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertSpamComplaint(array $payload): Swift_Webhook_Event
    {
        return $this->createEngagementEvent(
            'complained',
            $payload['MessageID'] ?? '',
            $payload['Email'] ?? '',
            [],
            $this->parseTimestamp($payload['BouncedAt'] ?? 'now'),
            $payload,
        );
    }

    private function convertSubscriptionChange(array $payload): Swift_Webhook_Event
    {
        return $this->createEngagementEvent(
            'unsubscribed',
            $payload['MessageID'] ?? '',
            $payload['Recipient'] ?? '',
            ['suppress_sending' => $payload['SuppressSending'] ?? false],
            $this->parseTimestamp($payload['ChangedAt'] ?? 'now'),
            $payload,
        );
    }
}
