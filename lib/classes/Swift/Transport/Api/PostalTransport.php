<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Postal HTTP API transport.
 *
 * Sends email via the Postal self-hosted mail server API.
 *
 * @see https://docs.postalserver.io/developer/api
 */
class Swift_Transport_Api_PostalTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private string $host;

    public function __construct(
        string $apiKey,
        string $host,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        $this->host = rtrim($host, '/');
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'json' => $payload,
            'http_errors' => false,
        ]);

        $result = $this->parseResponse($response);

        if (($result['status'] ?? null) !== 'success') {
            $errorCode = $result['data']['code'] ?? 'UnknownError';
            $errorMessage = $result['data']['message'] ?? 'Unknown error';
            throw new Swift_TransportException(
                sprintf('Postal API error (%s): %s', $errorCode, $errorMessage),
            );
        }

        return [
            'message_id' => $result['data']['message_id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://' . $this->host . '/api/v1/send/message';
    }

    protected function getAuthHeaders(): array
    {
        return [
            'X-Server-API-Key' => $this->apiKey,
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://' . $this->host . '/api/v1/messages/deliveries';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);
        $fromName = $from[$fromEmail] ?? null;

        $payload = [
            'from' => $this->formatAddress($fromEmail, $fromName),
            'sender' => $fromEmail,
            'to' => $this->getPlainAddresses($message->getTo() ?? []),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->getPlainAddresses($cc);
        }

        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->getPlainAddresses($bcc);
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyToEmail = array_key_first($replyTo);
            $payload['reply_to'] = $replyToEmail;
        }

        $body = $this->getMessageBody($message);

        if ($body['text'] !== null) {
            $payload['plain_body'] = $body['text'];
        }

        if ($body['html'] !== null) {
            $payload['html_body'] = $body['html'];
        }

        // Tags — Postal supports a single tag string
        $tags = $this->extractTags($message);
        if (!empty($tags)) {
            $payload['tag'] = $tags[0];
        }

        // Attachments
        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = array_map(static function (array $attachment): array {
                return [
                    'name' => $attachment['filename'],
                    'content_type' => $attachment['contentType'],
                    'data' => base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        return $payload;
    }

    /**
     * Get plain email addresses from a SwiftMailer address array.
     *
     * Postal expects plain string arrays (just email addresses), not objects.
     *
     * @return string[]
     */
    private function getPlainAddresses(array $addresses): array
    {
        return array_keys($addresses);
    }
}
