<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Default retry classifier for transport exceptions.
 *
 * Classifies exceptions as retryable based on:
 * - Exception code: SMTP 4xx codes, HTTP 429/5xx codes, code 0 (connection-level)
 * - Exception message: connection timeouts, resets, refused, rate limits
 *
 * Permanent failures (SMTP 5xx, auth errors) are NOT retried.
 */
class Swift_Transport_DefaultRetryClassifier implements Swift_Transport_RetryClassifier
{
    /**
     * SMTP/HTTP codes that are permanently non-retryable.
     * 5xx SMTP = permanent failure, 401/403 = auth errors.
     */
    private const PERMANENT_CODES = [
        501, 502, 503, 504, 530, 535, 550, 551, 552, 553, 554, // SMTP permanent
        401, 403, // HTTP auth errors
    ];

    /**
     * SMTP/HTTP codes that are explicitly retryable.
     */
    private const RETRYABLE_CODES = [
        421, 450, 451, 452, // SMTP temporary failures
        429, // HTTP too many requests
        500, 502, 503, 504, // HTTP server errors (note: SMTP 500 is permanent, but HTTP 500 is transient)
    ];

    /**
     * Message substrings that indicate a transient/connection-level failure.
     */
    private const RETRYABLE_PATTERNS = [
        'connection could not be established',
        'connection timed out',
        'connection reset',
        'connection refused',
        'broken pipe',
        'stream_socket_client',
        'rate limit',
        'too many requests',
        'try again',
        'temporarily unavailable',
        'service unavailable',
        'internal server error',
    ];

    /**
     * Message substrings that indicate a permanent failure (never retry).
     */
    private const PERMANENT_PATTERNS = [
        'authentication failed',
        'authentication required',
        'invalid api key',
        'unauthorized',
        'forbidden',
        'mailbox not found',
        'user unknown',
        'relay access denied',
    ];

    public function isRetryable(Swift_TransportException $e): bool
    {
        $code    = $e->getCode();
        $message = \strtolower($e->getMessage());

        // Check permanent message patterns first (highest priority)
        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (\str_contains($message, $pattern)) {
                return false;
            }
        }

        // Check permanent codes
        if (\in_array($code, self::PERMANENT_CODES, true)) {
            return false;
        }

        // Check explicit retryable codes
        if (\in_array($code, self::RETRYABLE_CODES, true)) {
            return true;
        }

        // Check retryable message patterns
        foreach (self::RETRYABLE_PATTERNS as $pattern) {
            if (\str_contains($message, $pattern)) {
                return true;
            }
        }

        // Code 0 typically means connection-level failure (no SMTP/HTTP code received)
        if (0 === $code) {
            return true;
        }

        // Unknown code in 4xx range = retryable
        if ($code >= 400 && $code < 500) {
            return true;
        }

        // Everything else is considered permanent
        return false;
    }
}
