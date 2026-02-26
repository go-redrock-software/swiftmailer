<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Amazon SES webhook payloads (via SNS) into Swift_Webhook_Event objects.
 *
 * SES sends notifications through SNS. The outer payload has Type and Message fields.
 * The Message field is a JSON string containing the SES notification with notificationType.
 *
 * Note: Full SNS signature verification requires fetching the signing certificate from AWS.
 * This converter performs basic validation (SNS header presence). For production use,
 * validate SNS signatures using the AWS SDK or a dedicated SNS verification library.
 *
 * @see https://docs.aws.amazon.com/ses/latest/dg/notification-contents.html
 */
class Swift_Webhook_Converter_AmazonSesConverter extends Swift_Webhook_AbstractPayloadConverter
{
    public function getProviderName(): string
    {
        return 'amazon-ses';
    }

    public function verify(string $rawBody, array $headers, #[\SensitiveParameter] string $secret): bool
    {
        // Basic validation: ensure this comes from SNS
        return isset($headers['x-amz-sns-message-type']);
    }

    public function convert(array $payload, array $headers): array
    {
        $type = $payload['Type'] ?? null;

        // Skip subscription confirmations — the user should handle those separately
        if ('SubscriptionConfirmation' === $type || 'UnsubscribeConfirmation' === $type) {
            return [];
        }

        $message = json_decode($payload['Message'] ?? '{}', true);
        $notificationType = $message['notificationType'] ?? null;
        $messageId = $message['mail']['messageId'] ?? '';

        return match ($notificationType) {
            'Bounce' => $this->convertBounce($message, $messageId),
            'Delivery' => $this->convertDelivery($message, $messageId),
            'Complaint' => $this->convertComplaint($message, $messageId),
            default => [],
        };
    }

    /** @return Swift_Webhook_Event[] */
    private function convertBounce(array $message, string $messageId): array
    {
        $bounce = $message['bounce'] ?? [];
        $timestamp = $this->parseTimestamp($bounce['timestamp'] ?? 'now');
        $bounceType = $bounce['bounceType'] ?? 'Permanent';
        $name = 'Transient' === $bounceType ? 'deferred' : 'bounced';

        $events = [];
        foreach ($bounce['bouncedRecipients'] ?? [] as $recipient) {
            $metadata = ['bounce_type' => $bounceType];
            if (isset($recipient['diagnosticCode'])) {
                $metadata['reason'] = $recipient['diagnosticCode'];
            }

            $events[] = $this->createDeliveryEvent(
                $name,
                $messageId,
                $recipient['emailAddress'] ?? '',
                $metadata,
                $timestamp,
                $message,
            );
        }

        return $events;
    }

    /** @return Swift_Webhook_Event[] */
    private function convertDelivery(array $message, string $messageId): array
    {
        $delivery = $message['delivery'] ?? [];
        $timestamp = $this->parseTimestamp($delivery['timestamp'] ?? 'now');

        $events = [];
        foreach ($delivery['recipients'] ?? [] as $recipient) {
            $events[] = $this->createDeliveryEvent(
                'delivered',
                $messageId,
                $recipient,
                [],
                $timestamp,
                $message,
            );
        }

        return $events;
    }

    /** @return Swift_Webhook_Event[] */
    private function convertComplaint(array $message, string $messageId): array
    {
        $complaint = $message['complaint'] ?? [];
        $timestamp = $this->parseTimestamp($complaint['timestamp'] ?? 'now');

        $events = [];
        foreach ($complaint['complainedRecipients'] ?? [] as $recipient) {
            $metadata = [];
            if (isset($complaint['complaintFeedbackType'])) {
                $metadata['feedback_type'] = $complaint['complaintFeedbackType'];
            }

            $events[] = $this->createEngagementEvent(
                'complained',
                $messageId,
                $recipient['emailAddress'] ?? '',
                $metadata,
                $timestamp,
                $message,
            );
        }

        return $events;
    }
}
