<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts raw webhook payloads from email providers into Swift_Webhook_Event objects.
 *
 * Implementations are provider-specific (SendGrid, Mailgun, etc.).
 * The framework's HTTP layer is responsible for parsing the request body
 * into an array — this interface is framework-agnostic.
 */
interface Swift_Webhook_PayloadConverterInterface
{
    /**
     * Convert a webhook payload into one or more events.
     *
     * @param array $payload The decoded JSON payload (or form data)
     * @param array $headers HTTP request headers (keys lowercased, e.g. 'x-sendgrid-signature')
     *
     * @return Swift_Webhook_Event[]
     */
    public function convert(array $payload, array $headers): array;

    /**
     * Verify the webhook signature.
     *
     * @param string $rawBody The raw request body string (before JSON decoding)
     * @param array  $headers HTTP request headers (keys lowercased)
     * @param string $secret  The signing secret configured with the provider
     *
     * @return bool True if signature is valid
     */
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool;

    /**
     * Get the provider name (e.g. 'sendgrid', 'mailgun').
     */
    public function getProviderName(): string;
}
