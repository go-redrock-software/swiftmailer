<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converters implementing this interface can extract a webhook timestamp
 * for replay prevention. The RequestHandler uses this to reject stale webhooks.
 */
interface Swift_Webhook_TimestampExtractorInterface
{
    /**
     * Extract the webhook timestamp from the raw body or headers.
     *
     * @param string $rawBody Raw HTTP request body
     * @param array  $headers HTTP headers (keys lowercased)
     *
     * @return int|null Unix timestamp, or null if not available
     */
    public function extractTimestamp(string $rawBody, array $headers): ?int;
}
