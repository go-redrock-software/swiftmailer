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
        #[SensitiveParameter] string $apiKey,
        string $host,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        Swift_Transport_UrlValidator::validate('https://'.$host);
        $this->host = \rtrim($host, '/');
    }

    #[Override]
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $recipients = null !== $envelope ? \array_values(\array_unique($envelope->getRecipients())) : null;
        $payload    = null === $envelope ? $this->getPayload($message) : [
            'mail_from' => $envelope->getSender(),
            'rcpt_to'   => $recipients,
            'data'      => \base64_encode($this->getRawMessageWithoutBcc($message)),
        ];

        $endpoint = null === $envelope ? $this->getEndpoint() : 'https://'.$this->host.'/api/v1/send/raw';
        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'json'        => $payload,
            'http_errors' => false,
        ]);

        $result = $this->parseResponse($response);

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300 && !\is_string($result['status'] ?? null)) {
            throw $this->invalidResponse($response, 'Invalid Postal acceptance status');
        }
        if ('success' === ($result['status'] ?? null) && ($status < 200 || $status >= 300)) {
            throw $this->invalidResponse($response, 'Postal acceptance conflicts with HTTP status', ambiguous: true);
        }
        if ($status < 200 || $status >= 300 || ($result['status'] ?? null) !== 'success') {
            $errorCode    = $this->responseText($result['data']['code'] ?? null, 'UnknownError');
            $errorMessage = $this->responseText($result['data']['message'] ?? null, 'Unknown error');
            throw new Swift_TransportException(\sprintf('Postal API error (%s): %s', $errorCode, $errorMessage));
        }
        if (isset($result['data']) && !\is_array($result['data'])) {
            throw $this->invalidResponse($response, 'Invalid Postal message results');
        }
        $acceptedRecipients = $result['data']['messages'] ?? $recipients;
        if (null !== $recipients && !\is_array($acceptedRecipients)) {
            throw $this->invalidResponse($response, 'Invalid Postal recipient results');
        }

        return [
            'message_id' => $result['data']['message_id'] ?? null,
            'recipients' => null === $recipients ? $this->countRecipients($message) : \count($acceptedRecipients),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return 'https://'.$this->host.'/api/v1/send/message';
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        return [
            'X-Server-API-Key' => $this->apiKey,
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
        return 'https://'.$this->host.'/api/v1/messages/deliveries';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail] ?? null;

        $payload = [
            'from'    => $this->formatAddress($fromEmail, $fromName),
            'sender'  => $fromEmail,
            'to'      => $this->getPlainAddresses($message->getTo() ?? []),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->getPlainAddresses($cc);
        }

        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->getPlainAddresses($bcc);
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyToEmail        = \array_key_first($replyTo);
            $payload['reply_to'] = $replyToEmail;
        }

        $body = $this->getMessageBody($message);

        if (null !== $body['text']) {
            $payload['plain_body'] = $body['text'];
        }

        if (null !== $body['html']) {
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
            $payload['attachments'] = \array_map(static function (array $attachment): array {
                return [
                    'name'         => $attachment['filename'],
                    'content_type' => $attachment['contentType'],
                    'data'         => \base64_encode($attachment['content']),
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
        return \array_keys($addresses);
    }
}
