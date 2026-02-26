<?php

use Psr\Http\Message\ResponseInterface;

/**
 * Resend HTTP API transport.
 *
 * Sends email via the Resend REST API (https://resend.com/docs/api-reference).
 */
class Swift_Transport_Api_ResendTransport extends Swift_Transport_AbstractHttpApiTransport
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

        $parsed     = $this->parseResponse($response);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMessage = $parsed['message'] ?? 'Unknown error';
            throw new Swift_TransportException(\sprintf('Resend API error (%d): %s', $statusCode, $errorMessage));
        }

        return [
            'message_id' => $parsed['id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://api.resend.com/emails';
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
        return 'https://api.resend.com/api-keys';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags     = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);

        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail] ?? null;

        $payload = [
            'from'    => $this->formatAddress($fromEmail, $fromName),
            'to'      => $this->formatAddresses($message->getTo()),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->formatAddresses($cc);
        }

        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->formatAddresses($bcc);
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyToEmail        = \array_key_first($replyTo);
            $replyToName         = $replyTo[$replyToEmail] ?? null;
            $payload['reply_to'] = $this->formatAddress($replyToEmail, $replyToName);
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
                return [
                    'filename' => $attachment['filename'],
                    'content'  => \base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        // Tags → array of {name, value} objects
        if (!empty($tags)) {
            $payload['tags'] = \array_map(static function (string $tag): array {
                return ['name' => $tag, 'value' => $tag];
            }, $tags);
        }

        // Metadata → headers object
        if (!empty($metadata)) {
            $payload['headers'] = $metadata;
        }

        return $payload;
    }
}
