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
    protected function doSend(Swift_Mime_SimpleMessage $message): array
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

        if ($response->getStatusCode() >= 400) {
            $error = $result['error'] ?? [];
            throw new Swift_TransportException(\sprintf('AhaSend API error [%s]: %s', $error['type'] ?? 'unknown', $error['message'] ?? 'Unknown error'));
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

    protected function getEndpoint(): string
    {
        return 'https://api.ahasend.com/v1/email/send';
    }

    protected function getAuthHeaders(): array
    {
        return [
            'X-Api-Key' => $this->apiKey,
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true) ?? [];
    }

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
            'subject'    => $message->getSubject(),
        ];

        $body    = $this->getMessageBody($message);
        $content = [];

        if (null !== $body['text']) {
            $content['text_body'] = $body['text'];
        }

        if (null !== $body['html']) {
            $content['html_body'] = $body['html'];
        }

        if (!empty($content)) {
            $payload['content'] = $content;
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = \array_map(static function (array $attachment): array {
                $item = [
                    'file_name'    => $attachment['filename'],
                    'content_type' => $attachment['contentType'],
                    'data'         => \base64_encode($attachment['content']),
                ];

                if ('inline' === $attachment['disposition'] && $attachment['contentId']) {
                    $item['content_id'] = $attachment['contentId'];
                }

                return $item;
            }, $attachments);
        }

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
