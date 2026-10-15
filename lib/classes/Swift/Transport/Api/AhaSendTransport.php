<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * AhaSend HTTP API transport.
 *
 * Sends email via the AhaSend transactional email API.
 *
 * @see https://ahasend.com/docs
 */
class Swift_Transport_Api_AhaSendTransport extends Swift_Transport_AbstractHttpApiTransport
{
    #[Override]
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $this->validateEnvelope($message, $envelope);

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

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $error = $result['error'] ?? [];
            throw new Swift_TransportException(\sprintf('AhaSend API error [%s]: %s', $this->responseText($error['type'] ?? null, 'unknown'), $this->responseText($error['message'] ?? null, 'Unknown error')));
        }

        if (isset($result['data']) && (!\is_array($result['data']) || !\array_is_list($result['data']) || (isset($result['data'][0]) && !\is_array($result['data'][0])))) {
            throw $this->invalidResponse($response, 'Invalid AhaSend message results');
        }
        $messageId = null;
        if (isset($result['data'][0]['id'])) {
            $messageId = $result['data'][0]['id'];
        }

        return [
            'message_id' => $messageId,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return 'https://api.ahasend.com/v1/email/send';
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        return [
            'X-Api-Key' => $this->apiKey,
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
        return 'https://api.ahasend.com/v1/email/send';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from        = $message->getFrom();
        $fromAddress = \array_key_first($from);
        $fromName    = $from[$fromAddress] ?? null;

        $fromField = ['email' => $fromAddress];
        if ($fromName) {
            $fromField['name'] = $fromName;
        }

        $recipients = $this->buildRecipients($message);

        $payload = [
            'from'       => $fromField,
            'recipients' => $recipients,
        ];

        // AhaSend v1 nests subject, bodies, and attachments inside `content`.
        $body    = $this->getMessageBody($message);
        $content = [
            'subject' => $message->getSubject(),
        ];

        if (null !== $body['text']) {
            $content['text_body'] = $body['text'];
        }

        if (null !== $body['html']) {
            $content['html_body'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $content['attachments'] = \array_map(static function (array $attachment): array {
                $item = [
                    'file_name'    => $attachment['filename'],
                    'content_type' => $attachment['contentType'],
                    'data'         => \base64_encode($attachment['content']),
                    'base64'       => true,
                ];

                if ('inline' === $attachment['disposition'] && $attachment['contentId']) {
                    $item['content_id'] = $attachment['contentId'];
                }

                return $item;
            }, $attachments);
        }

        $payload['content'] = $content;

        return $payload;
    }

    /**
     * Build the recipients array by merging To, CC, and BCC addresses.
     */
    private function buildRecipients(Swift_Mime_SimpleMessage $message): array
    {
        $recipients = [];

        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            foreach ($message->$method() ?? [] as $email => $name) {
                $recipient = ['email' => $email];
                if ($name) {
                    $recipient['name'] = $name;
                }
                $recipients[] = $recipient;
            }
        }

        return $recipients;
    }
}
