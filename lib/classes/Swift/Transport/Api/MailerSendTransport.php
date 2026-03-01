<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Http\Message\ResponseInterface;

/**
 * MailerSend HTTP API transport.
 *
 * Sends email via the MailerSend transactional email API.
 *
 * @see https://developers.mailersend.com/api/v1/email.html
 */
class Swift_Transport_Api_MailerSendTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.mailersend.com';

    #[\Override]
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
        if (202 !== $statusCode) {
            $result   = $this->parseResponse($response);
            $errorMsg = $result['message'] ?? 'Unknown MailerSend error';

            if (!empty($result['errors'])) {
                $details = [];
                foreach ($result['errors'] as $field => $messages) {
                    foreach ((array) $messages as $msg) {
                        $details[] = \sprintf('%s: %s', $field, $msg);
                    }
                }
                $errorMsg .= ' ('.\implode('; ', $details).')';
            }

            throw new Swift_TransportException('MailerSend API error: '.$errorMsg);
        }

        $messageId = $response->getHeaderLine('x-message-id');

        return [
            'message_id' => $messageId ?: null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[\Override]
    protected function getEndpoint(): string
    {
        return self::HOST.'/v1/email';
    }

    #[\Override]
    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
        ];
    }

    #[\Override]
    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true) ?? [];
    }

    #[\Override]
    protected function getPingEndpoint(): string
    {
        return self::HOST.'/v1/api-quota';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags = $this->extractTags($message);

        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail] ?? null;

        $payload = [
            'from'    => \array_filter(['email' => $fromEmail, 'name' => $fromName]),
            'to'      => $this->formatAddressObjects($message->getTo() ?? []),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->formatAddressObjects($cc);
        }

        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->formatAddressObjects($bcc);
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyEmail          = \array_key_first($replyTo);
            $payload['reply_to'] = \array_filter([
                'email' => $replyEmail,
                'name'  => $replyTo[$replyEmail],
            ]);
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
                    'content'     => \base64_encode($attachment['content']),
                    'filename'    => $attachment['filename'],
                    'disposition' => $attachment['disposition'],
                ]);
            }, $attachments);
        }

        // Tags → tags (array of strings); metadata N/A for MailerSend
        if (!empty($tags)) {
            $payload['tags'] = $tags;
        }

        return $payload;
    }

    /**
     * Format a SwiftMailer address array as MailerSend address objects.
     *
     * @param array<string, string|null> $addresses
     *
     * @return array<int, array{email: string, name?: string}>
     */
    private function formatAddressObjects(array $addresses): array
    {
        $formatted = [];
        foreach ($addresses as $email => $name) {
            $formatted[] = \array_filter(['email' => $email, 'name' => $name]);
        }

        return $formatted;
    }
}
