<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * Sweego HTTP API transport.
 *
 * Sends email via the Sweego transactional email REST API.
 *
 * @see https://www.sweego.io/
 */
class Swift_Transport_Api_SweegoTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->buildPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $parsed = $this->parseResponse($response);
            $errorMessage = $parsed['message'] ?? $parsed['error'] ?? 'Unknown error';

            throw new Swift_TransportException(
                sprintf('Sweego API error (%d): %s', $statusCode, $errorMessage),
            );
        }

        $parsed = $this->parseResponse($response);

        return [
            'message_id' => $parsed['transaction_id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://api.sweego.io/send';
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Api-Key' => $this->apiKey,
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.sweego.io/send';
    }

    /**
     * Build the Sweego API request payload from a Swift message.
     */
    private function buildPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);
        $fromName = $from[$fromEmail] ?? null;

        $recipients = [];
        foreach ($message->getTo() ?? [] as $email => $name) {
            $recipients[] = ['email' => $email];
        }

        $headers = [];

        // CC recipients are merged into recipients, with a Cc header added
        $cc = $message->getCc();
        if (!empty($cc)) {
            foreach ($cc as $email => $name) {
                $recipients[] = ['email' => $email];
            }

            $ccParts = [];
            foreach ($cc as $email => $name) {
                $ccParts[] = $this->formatAddress($email, $name);
            }
            $headers['Cc'] = implode(', ', $ccParts);
        }

        // BCC recipients are merged into recipients — no header
        $bcc = $message->getBcc();
        if (!empty($bcc)) {
            foreach ($bcc as $email => $name) {
                $recipients[] = ['email' => $email];
            }
        }

        // Reply-To goes in the headers object
        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $replyToParts = [];
            foreach ($replyTo as $email => $name) {
                $replyToParts[] = $this->formatAddress($email, $name);
            }
            $headers['Reply-To'] = implode(', ', $replyToParts);
        }

        $payload = [
            'channel' => 'email',
            'provider' => 'sweego',
            'campaign-type' => 'transac',
            'from' => array_filter([
                'email' => $fromEmail,
                'name' => $fromName,
            ]),
            'recipients' => $recipients,
            'subject' => $message->getSubject(),
        ];

        $body = $this->getMessageBody($message);
        if ($body['text'] !== null) {
            $payload['message-txt'] = $body['text'];
        }
        if ($body['html'] !== null) {
            $payload['message-html'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = array_map(static function (array $attachment): array {
                return array_filter([
                    'content' => $attachment['content'],
                    'filename' => $attachment['filename'],
                    'disposition' => $attachment['disposition'],
                    'content_id' => $attachment['contentId'],
                ]);
            }, $attachments);
        }

        if (!empty($headers)) {
            $payload['headers'] = $headers;
        }

        return $payload;
    }
}
