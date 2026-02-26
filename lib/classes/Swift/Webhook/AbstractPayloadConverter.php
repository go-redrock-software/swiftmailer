<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Base class for webhook payload converters with HMAC helpers and event factories.
 */
abstract class Swift_Webhook_AbstractPayloadConverter implements Swift_Webhook_PayloadConverterInterface
{
    /**
     * Verify an HMAC signature using timing-safe comparison.
     */
    protected function verifyHmac(
        string $data,
        string $signature,
        #[\SensitiveParameter] string $secret,
        string $algo = 'sha256',
    ): bool {
        $expected = hash_hmac($algo, $data, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Create a delivery event (delivered, bounced, deferred, dropped).
     */
    protected function createDeliveryEvent(
        string $name,
        string $messageId,
        string $recipient,
        array $metadata,
        \DateTimeImmutable $timestamp,
        array $rawPayload,
    ): Swift_Webhook_Event {
        return new Swift_Webhook_Event('delivery', $name, $messageId, $recipient, $metadata, $timestamp, $rawPayload);
    }

    /**
     * Create an engagement event (opened, clicked, unsubscribed, complained).
     */
    protected function createEngagementEvent(
        string $name,
        string $messageId,
        string $recipient,
        array $metadata,
        \DateTimeImmutable $timestamp,
        array $rawPayload,
    ): Swift_Webhook_Event {
        return new Swift_Webhook_Event('engagement', $name, $messageId, $recipient, $metadata, $timestamp, $rawPayload);
    }

    /**
     * Parse a timestamp from various formats providers use.
     */
    protected function parseTimestamp(int|string $timestamp): \DateTimeImmutable
    {
        if (\is_int($timestamp)) {
            return (new \DateTimeImmutable())->setTimestamp($timestamp);
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp)
            ?: \DateTimeImmutable::createFromFormat('U', $timestamp)
            ?: new \DateTimeImmutable($timestamp);

        return $parsed;
    }
}
