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
 * Verification performs full SNS signature validation:
 *  1. Requires the x-amz-sns-message-type header
 *  2. Validates the TopicArn against the expected value ($secret)
 *  3. Validates the SigningCertURL is HTTPS from *.amazonaws.com
 *  4. Fetches the signing certificate and verifies the RSA signature
 *  5. Supports SignatureVersion "1" (SHA1) and "2" (SHA256)
 *
 * @see https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
 * @see https://docs.aws.amazon.com/ses/latest/dg/notification-contents.html
 */
class Swift_Webhook_Converter_AmazonSesConverter extends Swift_Webhook_AbstractPayloadConverter
{
    #[Override]
    public function getProviderName(): string
    {
        return 'amazon-ses';
    }

    #[Override]
    public function extractTimestamp(string $rawBody, array $headers): ?int
    {
        $decoded = \json_decode($rawBody, true);

        if (!isset($decoded['Timestamp'])) {
            return null;
        }

        $ts = \strtotime($decoded['Timestamp']);

        return false === $ts ? null : $ts;
    }

    #[Override]
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        // Require SNS message type header
        if (!isset($headers['x-amz-sns-message-type'])) {
            return false;
        }

        $payload = \json_decode($rawBody, true);
        if (!\is_array($payload)) {
            return false;
        }

        // Validate Topic ARN matches expected value ($secret)
        $topicArn = $payload['TopicArn'] ?? null;
        if (null === $topicArn || !\hash_equals($secret, $topicArn)) {
            return false;
        }

        // Validate SigningCertURL is from amazonaws.com over HTTPS
        $certUrl = $payload['SigningCertURL'] ?? '';
        if (!$this->isValidCertUrl($certUrl)) {
            return false;
        }

        // Fetch signing certificate
        $certPem = $this->fetchSigningCertificate($certUrl);
        if ('' === $certPem) {
            return false;
        }

        $publicKey = \openssl_pkey_get_public($certPem);
        if (false === $publicKey) {
            return false;
        }

        // Build string-to-sign based on message Type
        $stringToSign = $this->buildStringToSign($payload);

        // Decode the signature
        $signature = \base64_decode($payload['Signature'] ?? '', true);
        if (false === $signature) {
            return false;
        }

        // Determine hash algorithm from SignatureVersion
        $algo = ('1' === ($payload['SignatureVersion'] ?? '1'))
            ? \OPENSSL_ALGO_SHA1
            : \OPENSSL_ALGO_SHA256;

        return 1 === \openssl_verify($stringToSign, $signature, $publicKey, $algo);
    }

    #[Override]
    public function convert(array $payload, array $headers): array
    {
        $type = $payload['Type'] ?? null;

        // Skip subscription confirmations — the user should handle those separately
        if ('SubscriptionConfirmation' === $type || 'UnsubscribeConfirmation' === $type) {
            return [];
        }

        $message          = \json_decode($payload['Message'] ?? '{}', true);
        $notificationType = $message['notificationType']  ?? null;
        $messageId        = $message['mail']['messageId'] ?? '';

        return match ($notificationType) {
            'Bounce'    => $this->convertBounce($message, $messageId),
            'Delivery'  => $this->convertDelivery($message, $messageId),
            'Complaint' => $this->convertComplaint($message, $messageId),
            default     => [],
        };
    }

    /**
     * Validate that the signing certificate URL is an HTTPS endpoint at amazonaws.com.
     */
    private function isValidCertUrl(string $url): bool
    {
        $parsed = \parse_url($url);
        if (!$parsed || 'https' !== ($parsed['scheme'] ?? '')) {
            return false;
        }

        $host = $parsed['host'] ?? '';

        return (bool) \preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/i', $host);
    }

    /**
     * Fetch the PEM-encoded signing certificate from AWS.
     *
     * This method is protected so tests can override it to avoid network calls.
     */
    /** @codeCoverageIgnore Network I/O — tests override this method */
    protected function fetchSigningCertificate(string $url): string
    {
        $context = \stream_context_create([
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            'http' => ['timeout' => 10],
        ]);

        $cert = @\file_get_contents($url, false, $context);

        return false === $cert ? '' : $cert;
    }

    /**
     * Build the SNS canonical string-to-sign for signature verification.
     *
     * @see https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
     */
    private function buildStringToSign(array $payload): string
    {
        $type = $payload['Type'] ?? '';

        // Fields included depend on message type
        if ('Notification' === $type) {
            $fields = ['Message', 'MessageId'];
            if (isset($payload['Subject'])) {
                $fields[] = 'Subject';
            }
            $fields = \array_merge($fields, ['Timestamp', 'TopicArn', 'Type']);
        } else {
            // SubscriptionConfirmation / UnsubscribeConfirmation
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        }

        $stringToSign = '';
        foreach ($fields as $field) {
            if (isset($payload[$field])) {
                $stringToSign .= $field."\n".$payload[$field]."\n";
            }
        }

        return $stringToSign;
    }

    /** @return Swift_Webhook_Event[] */
    private function convertBounce(array $message, string $messageId): array
    {
        $bounce     = $message['bounce'] ?? [];
        $timestamp  = $this->parseTimestamp($bounce['timestamp'] ?? 'now');
        $bounceType = $bounce['bounceType'] ?? 'Permanent';
        $name       = 'Transient' === $bounceType ? 'deferred' : 'bounced';

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
        $delivery  = $message['delivery'] ?? [];
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
