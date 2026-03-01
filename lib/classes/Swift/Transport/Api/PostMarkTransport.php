<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * Postmark HTTP API transport.
 *
 * Sends email via the Postmark transactional email API.
 *
 * @see https://postmarkapp.com/developer/api/email-api
 */
class Swift_Transport_Api_PostMarkTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]),
            'json'        => $payload,
            'http_errors' => false,
        ]);

        $result = $this->parseResponse($response);

        if (!isset($result['ErrorCode']) || 0 !== $result['ErrorCode']) {
            throw new Swift_TransportException(\sprintf('Postmark API error %d: %s', $result['ErrorCode'] ?? -1, $result['Message'] ?? 'Unknown error'));
        }

        return [
            'message_id' => $result['MessageID'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://api.postmarkapp.com/email';
    }

    protected function getAuthHeaders(): array
    {
        return [
            'X-Postmark-Server-Token' => $this->apiKey,
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.postmarkapp.com/server';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags     = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);

        $from        = $message->getFrom();
        $fromAddress = \array_key_first($from);
        $fromName    = $from[$fromAddress] ?? null;

        $payload = [
            'From'    => $this->formatAddress($fromAddress, $fromName),
            'To'      => \implode(', ', $this->formatAddresses($message->getTo() ?? [])),
            'Subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['Cc'] = \implode(', ', $this->formatAddresses($cc));
        }

        if ($bcc = $message->getBcc()) {
            $payload['Bcc'] = \implode(', ', $this->formatAddresses($bcc));
        }

        if ($replyTo = $message->getReplyTo()) {
            $payload['ReplyTo'] = \implode(', ', $this->formatAddresses($replyTo));
        }

        $body = $this->getMessageBody($message);

        if (null !== $body['text']) {
            $payload['TextBody'] = $body['text'];
        }

        if (null !== $body['html']) {
            $payload['HtmlBody'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['Attachments'] = \array_map(static function (array $attachment): array {
                $item = [
                    'Name'        => $attachment['filename'],
                    'Content'     => \base64_encode($attachment['content']),
                    'ContentType' => $attachment['contentType'],
                ];

                if ('inline' === $attachment['disposition'] && $attachment['contentId']) {
                    $item['ContentID'] = 'cid:'.$attachment['contentId'];
                }

                return $item;
            }, $attachments);
        }

        // Tag → single string (first tag only)
        if (!empty($tags)) {
            $payload['Tag'] = $tags[0];
        }

        // Metadata → object
        if (!empty($metadata)) {
            $payload['Metadata'] = $metadata;
        }

        return $payload;
    }
}
