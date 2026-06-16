<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * MailPace HTTP API transport.
 *
 * Sends email via the MailPace transactional email API.
 *
 * @see https://docs.mailpace.com/reference/send
 */
class Swift_Transport_Api_MailPaceTransport extends Swift_Transport_AbstractHttpApiTransport
{
    /**
     * MailPace has no dedicated health/ping endpoint, so we just auto-start
     * and return true.
     */
    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        return true;
    }

    #[Override]
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

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $parsed       = $this->parseResponse($response);
            $errorMessage = $parsed['error'] ?? 'Unknown error';

            throw new Swift_TransportException(\sprintf('MailPace API error (%d): %s', $statusCode, $errorMessage));
        }

        $parsed = $this->parseResponse($response);

        return [
            'message_id' => $parsed['id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return 'https://app.mailpace.com/api/v1/send';
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        return [
            'MailPace-Server-Token' => $this->apiKey,
        ];
    }

    #[Override]
    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode($this->getResponseBody($response), true) ?? [];
    }

    #[Override]
    protected function getPingEndpoint(): string
    {
        return 'https://app.mailpace.com/api/v1/send';
    }

    /**
     * Build the MailPace API request payload from a Swift message.
     */
    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags = $this->extractTags($message);

        $from        = $message->getFrom();
        $fromAddress = \array_key_first($from);
        $fromName    = $from[$fromAddress] ?? null;

        $payload = [
            'from'    => $this->formatAddress($fromAddress, $fromName),
            'to'      => \implode(', ', $this->formatAddresses($message->getTo() ?? [])),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = \implode(', ', $this->formatAddresses($cc));
        }

        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = \implode(', ', $this->formatAddresses($bcc));
        }

        if ($replyTo = $message->getReplyTo()) {
            $payload['replyto'] = \implode(', ', $this->formatAddresses($replyTo));
        }

        $body = $this->getMessageBody($message);

        if (null !== $body['text']) {
            $payload['textbody'] = $body['text'];
        }

        if (null !== $body['html']) {
            $payload['htmlbody'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = \array_map(static function (array $attachment): array {
                return [
                    'name'         => $attachment['filename'],
                    'content_type' => $attachment['contentType'],
                    'content'      => \base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        // Tags → tags (array). MailPace's API has no metadata field, so
        // X-Mailer-Metadata-* headers are intentionally not forwarded.
        if (!empty($tags)) {
            $payload['tags'] = $tags;
        }

        return $payload;
    }
}
