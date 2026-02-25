<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * Mandrill (MailChimp) HTTP API transport.
 *
 * Sends email via the Mandrill transactional email API.
 *
 * @see https://mandrillapp.com/api/docs/messages.html
 */
class Swift_Transport_Api_MailChimpTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $result = $this->parseResponse($response);

        if (isset($result['status']) && $result['status'] === 'error') {
            throw new Swift_TransportException(
                sprintf(
                    'Mandrill API error: %s',
                    $result['message'] ?? 'Unknown error',
                ),
            );
        }

        // Result is an array of per-recipient statuses
        $successCount = 0;
        foreach ($result as $recipientResult) {
            if (isset($recipientResult['status']) && in_array($recipientResult['status'], ['sent', 'queued'], true)) {
                ++$successCount;
            }
        }

        return [
            'recipients' => $successCount,
        ];
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $response = $this->httpClient->request('POST', $this->getPingEndpoint(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => ['key' => $this->apiKey],
            ]);

            $body = (string) $response->getBody();

            return $response->getStatusCode() === 200 && trim($body, '"') === 'PONG!';
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getEndpoint(): string
    {
        return 'https://mandrillapp.com/api/1.0/messages/send';
    }

    protected function getAuthHeaders(): array
    {
        return [];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://mandrillapp.com/api/1.0/users/ping';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromAddress = array_key_first($from);
        $fromName = $from[$fromAddress] ?? null;

        $messagePayload = [
            'from_email' => $fromAddress,
            'subject' => $message->getSubject(),
        ];

        if ($fromName) {
            $messagePayload['from_name'] = $fromName;
        }

        // Build unified "to" array with type field for To, CC, BCC
        $to = [];

        foreach ($message->getTo() ?? [] as $email => $name) {
            $entry = ['email' => $email, 'type' => 'to'];
            if ($name) {
                $entry['name'] = $name;
            }
            $to[] = $entry;
        }

        foreach ($message->getCc() ?? [] as $email => $name) {
            $entry = ['email' => $email, 'type' => 'cc'];
            if ($name) {
                $entry['name'] = $name;
            }
            $to[] = $entry;
        }

        foreach ($message->getBcc() ?? [] as $email => $name) {
            $entry = ['email' => $email, 'type' => 'bcc'];
            if ($name) {
                $entry['name'] = $name;
            }
            $to[] = $entry;
        }

        $messagePayload['to'] = $to;

        // Reply-To goes in headers
        if ($replyTo = $message->getReplyTo()) {
            $formatted = $this->formatAddresses($replyTo);
            $messagePayload['headers'] = [
                'Reply-To' => implode(', ', $formatted),
            ];
        }

        // Body
        $body = $this->getMessageBody($message);

        if ($body['text'] !== null) {
            $messagePayload['text'] = $body['text'];
        }

        if ($body['html'] !== null) {
            $messagePayload['html'] = $body['html'];
        }

        // Attachments and inline images
        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $regularAttachments = [];
            $inlineImages = [];

            foreach ($attachments as $attachment) {
                $item = [
                    'type' => $attachment['contentType'],
                    'name' => $attachment['filename'],
                    'content' => base64_encode($attachment['content']),
                ];

                if ($attachment['disposition'] === 'inline' && $attachment['contentId']) {
                    $item['name'] = $attachment['contentId'];
                    $inlineImages[] = $item;
                } else {
                    $regularAttachments[] = $item;
                }
            }

            if (!empty($regularAttachments)) {
                $messagePayload['attachments'] = $regularAttachments;
            }

            if (!empty($inlineImages)) {
                $messagePayload['images'] = $inlineImages;
            }
        }

        return [
            'key' => $this->apiKey,
            'message' => $messagePayload,
        ];
    }
}
