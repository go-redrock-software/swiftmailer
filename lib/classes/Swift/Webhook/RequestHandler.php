<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Orchestrates webhook processing: verifies signatures, decodes JSON, delegates to converters.
 *
 * Usage in a controller/route handler:
 *
 *     $handler = new Swift_Webhook_RequestHandler();
 *     $events = $handler->handle($sendgridConverter, $rawBody, $headers, $secret);
 *     foreach ($events as $event) {
 *         // Process bounce, delivery, open, click, etc.
 *     }
 */
class Swift_Webhook_RequestHandler
{
    /**
     * Process a webhook request.
     *
     * @param Swift_Webhook_PayloadConverterInterface $converter Provider-specific converter
     * @param string                                  $rawBody   Raw HTTP request body
     * @param array                                   $headers   HTTP headers (keys lowercased)
     * @param string                                  $secret    Signing secret (provider-specific: HMAC key, token, or SNS Topic ARN)
     *
     * @return Swift_Webhook_Event[]
     *
     * @throws Swift_Webhook_SignatureVerificationException If signature is invalid
     * @throws InvalidArgumentException                     If body is not valid JSON or secret is empty
     */
    public function handle(
        Swift_Webhook_PayloadConverterInterface $converter,
        string $rawBody,
        array $headers,
        #[SensitiveParameter] string $secret,
    ): array {
        if ('' === $secret) {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }

        // Normalize header keys to lowercase
        $headers = \array_change_key_case($headers, CASE_LOWER);

        // Always verify signature — never skip
        if (!$converter->verify($rawBody, $headers, $secret)) {
            throw new Swift_Webhook_SignatureVerificationException($converter->getProviderName());
        }

        // Decode JSON
        $payload = \json_decode($rawBody, true);
        if (JSON_ERROR_NONE !== \json_last_error()) {
            throw new InvalidArgumentException(\sprintf('Invalid JSON in webhook body: %s', \json_last_error_msg()));
        }

        return $converter->convert($payload, $headers);
    }
}
