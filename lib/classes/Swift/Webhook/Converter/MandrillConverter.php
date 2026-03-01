<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Converts Mandrill (Mailchimp Transactional) webhook payloads into Swift_Webhook_Event objects.
 *
 * Mandrill POSTs form-encoded data with a 'mandrill_events' parameter containing
 * a JSON array of event objects. Each has an 'event' field and nested 'msg' object.
 *
 * Signature verification uses HMAC-SHA1 of (URL + sorted POST variable keys/values),
 * base64-encoded, compared to the X-Mandrill-Signature header.
 *
 * The $secret parameter must be formatted as "webhook_key|webhook_url".
 *
 * @see https://mailchimp.com/developer/transactional/docs/webhooks/
 */
class Swift_Webhook_Converter_MandrillConverter extends Swift_Webhook_AbstractPayloadConverter
{
    private const EVENT_MAP = [
        'hard_bounce' => ['delivery', 'bounced'],
        'soft_bounce' => ['delivery', 'deferred'],
        'delivered'   => ['delivery', 'delivered'],
        'deferral'    => ['delivery', 'deferred'],
        'reject'      => ['delivery', 'dropped'],
        'open'        => ['engagement', 'opened'],
        'click'       => ['engagement', 'clicked'],
        'spam'        => ['engagement', 'complained'],
        'unsub'       => ['engagement', 'unsubscribed'],
    ];

    #[Override]
    public function getProviderName(): string
    {
        return 'mandrill';
    }

    #[Override]
    public function verify(string $rawBody, array $headers, #[SensitiveParameter] string $secret): bool
    {
        $signature = $headers['x-mandrill-signature'] ?? null;

        if (null === $signature) {
            return false;
        }

        // Secret is "webhook_key|webhook_url"
        $parts = \explode('|', $secret, 2);
        if (2 !== \count($parts)) {
            return false;
        }

        [$webhookKey, $webhookUrl] = $parts;

        // Parse the form-encoded body to get POST variables
        \parse_str($rawBody, $postVars);

        // Build signed data: URL + sorted keys and values
        $signedData = $webhookUrl;
        \ksort($postVars);
        foreach ($postVars as $key => $value) {
            $signedData .= $key.$value;
        }

        $expected = \base64_encode(\hash_hmac('sha1', $signedData, $webhookKey, true));

        return \hash_equals($expected, $signature);
    }

    #[Override]
    public function convert(array $payload, array $headers): array
    {
        $events = [];

        foreach ($payload as $entry) {
            $eventName = $entry['event'] ?? null;

            if (null === $eventName || !isset(self::EVENT_MAP[$eventName])) {
                continue;
            }

            [$type, $name] = self::EVENT_MAP[$eventName];
            $msg           = $entry['msg'] ?? [];
            $messageId     = (string) ($entry['_id'] ?? '');
            $recipient     = $msg['email'] ?? '';
            $timestamp     = $this->parseTimestamp($entry['ts'] ?? \time());
            $metadata      = $this->extractMetadata($entry, $msg);

            if ('delivery' === $type) {
                $events[] = $this->createDeliveryEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            } else {
                $events[] = $this->createEngagementEvent($name, $messageId, $recipient, $metadata, $timestamp, $entry);
            }
        }

        return $events;
    }

    private function extractMetadata(array $entry, array $msg): array
    {
        $metadata = [];

        if (isset($msg['bounce_description'])) {
            $metadata['reason'] = $msg['bounce_description'];
        }
        if (isset($msg['diag'])) {
            $metadata['diagnostic'] = $msg['diag'];
        }
        if (isset($entry['url'])) {
            $metadata['url'] = $entry['url'];
        }
        if (isset($entry['ip'])) {
            $metadata['ip'] = $entry['ip'];
        }
        if (isset($entry['user_agent'])) {
            $metadata['user_agent'] = $entry['user_agent'];
        }
        if (isset($msg['subject'])) {
            $metadata['subject'] = $msg['subject'];
        }
        if (isset($msg['sender'])) {
            $metadata['sender'] = $msg['sender'];
        }
        if (isset($msg['tags'])) {
            $metadata['tags'] = (array) $msg['tags'];
        }
        if (isset($msg['metadata'])) {
            $metadata['custom_metadata'] = $msg['metadata'];
        }

        return $metadata;
    }
}
