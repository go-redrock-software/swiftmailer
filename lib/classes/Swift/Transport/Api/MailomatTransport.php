<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * Mailomat HTTP API transport.
 *
 * Sends email via the Mailomat REST API (https://api.mailomat.swiss).
 */
class Swift_Transport_Api_MailomatTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'json'        => $payload,
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();
        $parsed     = $this->parseResponse($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMessage = $parsed['message'] ?? $parsed['error'] ?? 'Unknown error';
            throw new Swift_TransportException(\sprintf('Mailomat API error (%d): %s', $statusCode, $errorMessage));
        }

        return [
            'message_id' => $parsed['messageUuid'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://api.mailomat.swiss/message';
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        return \json_decode($body, true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.mailomat.swiss/events';
    }

    /**
     * Build the Mailomat API request payload from a Swift message.
     */
    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail];

        $payload = [
            'from' => \array_filter([
                'email' => $fromEmail,
                'name'  => $fromName,
            ]),
            'to'      => $this->mapAddresses($message->getTo() ?? []),
            'subject' => $message->getSubject(),
        ];

        $cc = $message->getCc();
        if (!empty($cc)) {
            $payload['cc'] = $this->mapAddresses($cc);
        }

        $bcc = $message->getBcc();
        if (!empty($bcc)) {
            $payload['bcc'] = $this->mapAddresses($bcc);
        }

        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $payload['replyTo'] = $this->mapAddresses($replyTo);
        }

        $body = $this->getMessageBody($message);
        if (null !== $body['text']) {
            $payload['text'] = $body['text'];
        }
        if (null !== $body['html']) {
            $payload['html'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = \array_map(static function (array $attachment): array {
                return \array_filter([
                    'filename'      => $attachment['filename'],
                    'contentBase64' => \base64_encode($attachment['content']),
                    'contentType'   => $attachment['contentType'],
                    'contentId'     => $attachment['contentId'] ?? null,
                ]);
            }, $attachments);
        }

        return $payload;
    }

    /**
     * Map a SwiftMailer address array to Mailomat's address format.
     *
     * @param array<string, string|null> $addresses
     *
     * @return array<int, array{email: string, name?: string}>
     */
    private function mapAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = \array_filter([
                'email' => $email,
                'name'  => $name,
            ]);
        }

        return $mapped;
    }
}
