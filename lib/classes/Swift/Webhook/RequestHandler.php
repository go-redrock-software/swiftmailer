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
     * @param int                                     $maxAge    Maximum webhook age in seconds (0 to disable timestamp validation)
     *
     * @return Swift_Webhook_Event[]
     *
     * @throws Swift_Webhook_SignatureVerificationException If signature is invalid or timestamp expired
     * @throws InvalidArgumentException                     If body is not valid JSON or secret is empty
     */
    public function handle(
        Swift_Webhook_PayloadConverterInterface $converter,
        string $rawBody,
        array $headers,
        #[SensitiveParameter] string $secret,
        int $maxAge = 300,
        ?array $allowedIps = null,
        ?string $remoteIp = null,
    ): array {
        if ('' === $secret) {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }

        if (null !== $allowedIps && null !== $remoteIp) {
            if (!\in_array($remoteIp, $allowedIps, true)) {
                throw new Swift_Webhook_SignatureVerificationException(
                    $converter->getProviderName() . ': remote IP not in allowlist'
                );
            }
        }

        // Normalize header keys to lowercase
        $headers = \array_change_key_case($headers, CASE_LOWER);

        // Always verify signature — never skip
        if (!$converter->verify($rawBody, $headers, $secret)) {
            throw new Swift_Webhook_SignatureVerificationException($converter->getProviderName());
        }

        // Validate timestamp for replay prevention
        if ($maxAge > 0 && $converter instanceof Swift_Webhook_TimestampExtractorInterface) {
            $timestamp = $converter->extractTimestamp($rawBody, $headers);
            if (null !== $timestamp && \abs(\time() - $timestamp) > $maxAge) {
                throw new Swift_Webhook_SignatureVerificationException($converter->getProviderName());
            }
        }

        // Decode JSON
        $payload = \json_decode($rawBody, true);
        if (JSON_ERROR_NONE !== \json_last_error()) {
            throw new InvalidArgumentException(\sprintf('Invalid JSON in webhook body: %s', \json_last_error_msg()));
        }

        return $converter->convert($payload, $headers);
    }
}
