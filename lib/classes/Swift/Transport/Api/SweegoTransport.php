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
    #[Override]
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $this->validateEnvelope($message, $envelope);

        $payload = $this->buildPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]),
            'json'            => $payload,
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $parsed       = $this->parseResponse($response);
            $errorMessage = $this->responseText($parsed['message'] ?? $parsed['error'] ?? null, 'Unknown error');

            throw new Swift_TransportException(\sprintf('Sweego API error (%d): %s', $statusCode, $errorMessage));
        }

        $parsed = $this->parseResponse($response);

        return [
            'message_id' => $parsed['transaction_id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return 'https://api.sweego.io/send';
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        return [
            'Api-Key' => $this->apiKey,
        ];
    }

    #[Override]
    protected function parseResponse(ResponseInterface $response): array
    {
        return $this->decodeJsonResponse($response);
    }

    #[Override]
    protected function getPingEndpoint(): string
    {
        return 'https://api.sweego.io/send';
    }

    /**
     * Build the Sweego API request payload from a Swift message.
     */
    private function buildPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail] ?? null;

        $recipients = $this->formatAddressList($message->getTo() ?? []);
        if (!$recipients) {
            throw new Swift_TransportException('Sweego requires at least one To recipient; Cc/Bcc-only messages are not supported.');
        }

        $headers = [];

        // Reply-To goes in the headers object
        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $replyToParts = [];
            foreach ($replyTo as $email => $name) {
                $replyToParts[] = $this->formatAddress($email, $name);
            }
            $headers['Reply-To'] = \implode(', ', $replyToParts);
        }

        $payload = [
            'channel'  => 'email',
            'provider' => 'sweego',
            // Sweego's documented value is 'transac' (see the send API reference and the
            // Symfony sweego-mailer bridge), NOT 'transactional'.
            'campaign-type' => 'transac',
            'from'          => $this->formatAddressList([$fromEmail => $fromName])[0],
            'recipients'    => $recipients,
            'subject'       => $message->getSubject(),
        ];
        // Sweego exposes every `recipients` address in To. Copy roles have native fields.
        foreach (['cc' => $message->getCc(), 'bcc' => $message->getBcc()] as $field => $addresses) {
            if ($addresses) {
                $payload[$field] = $this->formatAddressList($addresses);
            }
        }

        $body = $this->getMessageBody($message);
        if (null !== $body['text']) {
            $payload['message-txt'] = $body['text'];
        }
        if (null !== $body['html']) {
            $payload['message-html'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = \array_map(static function (array $attachment): array {
                return \array_merge(
                    [
                        'content'  => \base64_encode($attachment['content'] ?? ''),
                        'filename' => $attachment['filename'],
                    ],
                    \array_filter([
                        'disposition' => $attachment['disposition'],
                        'content_id'  => $attachment['contentId'],
                    ], static fn (?string $value): bool => null !== $value && '' !== $value),
                );
            }, $attachments);
        }

        if (!empty($headers)) {
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    /** Omit absent names while preserving strings such as "0" for every address role. */
    private function formatAddressList(array $addresses): array
    {
        $recipients = [];
        foreach ($addresses as $email => $name) {
            $recipient = ['email' => $email];
            if (null !== $name && '' !== $name) {
                $recipient['name'] = $name;
            }
            $recipients[] = $recipient;
        }

        return $recipients;
    }
}
