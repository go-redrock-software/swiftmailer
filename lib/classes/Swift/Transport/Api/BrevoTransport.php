<?php

use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_BrevoTransport extends Swift_Transport_AbstractHttpApiTransport
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

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $parsed       = $this->parseResponse($response);
            $errorMessage = $parsed['message'] ?? 'Unknown error';
            $errorCode    = $parsed['code']    ?? $statusCode;

            throw new Swift_TransportException(\sprintf('Brevo API error (%s): %s', $errorCode, $errorMessage));
        }

        $parsed = $this->parseResponse($response);

        return [
            'message_id' => $parsed['messageId'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://api.brevo.com/v3/smtp/email';
    }

    protected function getAuthHeaders(): array
    {
        return ['api-key' => $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.brevo.com/v3/account';
    }

    /**
     * Build the Brevo API request payload from a Swift message.
     */
    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags     = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);

        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail];

        $payload = [
            'sender' => \array_filter([
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
            $replyToEmail       = \array_key_first($replyTo);
            $replyToName        = $replyTo[$replyToEmail];
            $payload['replyTo'] = \array_filter([
                'email' => $replyToEmail,
                'name'  => $replyToName,
            ]);
        }

        $body = $this->getMessageBody($message);
        if (null !== $body['html']) {
            $payload['htmlContent'] = $body['html'];
        }
        if (null !== $body['text']) {
            $payload['textContent'] = $body['text'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachment'] = \array_map(function (array $attachment) {
                return [
                    'name'    => $attachment['filename'],
                    'content' => \base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        // Tags → tags (array of strings)
        if (!empty($tags)) {
            $payload['tags'] = $tags;
        }

        // Metadata → custom X- headers
        if (!empty($metadata)) {
            $headers = [];
            foreach ($metadata as $key => $value) {
                $headers['X-Metadata-'.$key] = $value;
            }
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    /**
     * Map a SwiftMailer address array to Brevo's address format.
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
