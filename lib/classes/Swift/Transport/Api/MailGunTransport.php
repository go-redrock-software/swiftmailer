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
 * Mailgun HTTP API transport.
 *
 * Sends email via the Mailgun REST API using multipart/form-data.
 *
 * @see https://documentation.mailgun.com/en/latest/api-sending-messages.html
 */
class Swift_Transport_Api_MailGunTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private string $domain;

    private string $host;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $domain,
        string $host = 'https://api.mailgun.net',
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        $this->domain = $domain;
        $this->host = $host;
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => $this->getAuthHeaders(),
            'multipart' => $this->getFormData($message),
            'http_errors' => false,
        ]);

        $result = $this->parseResponse($response);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMsg = $result['message'] ?? 'Unknown Mailgun error';
            throw new Swift_TransportException('Mailgun API error: ' . $errorMsg);
        }

        return [
            'message_id' => $result['id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return rtrim($this->host, '/') . '/v3/' . urlencode($this->domain) . '/messages';
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode('api:' . $this->apiKey),
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return rtrim($this->host, '/') . '/v3/domains/' . urlencode($this->domain);
    }

    /**
     * Build the multipart/form-data fields for the Mailgun API.
     *
     * @return array<array{name: string, contents: string, filename?: string, headers?: array}>
     */
    private function getFormData(Swift_Mime_SimpleMessage $message): array
    {
        $tags = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);

        $from = $message->getFrom();
        $fromEmail = array_key_first($from);
        $fromName = $from[$fromEmail] ?? null;

        $fields = [
            ['name' => 'from', 'contents' => $this->formatAddress($fromEmail, $fromName)],
            ['name' => 'to', 'contents' => implode(', ', $this->formatAddresses($message->getTo() ?? []))],
            ['name' => 'subject', 'contents' => $message->getSubject()],
        ];

        if ($cc = $message->getCc()) {
            $fields[] = ['name' => 'cc', 'contents' => implode(', ', $this->formatAddresses($cc))];
        }

        if ($bcc = $message->getBcc()) {
            $fields[] = ['name' => 'bcc', 'contents' => implode(', ', $this->formatAddresses($bcc))];
        }

        if ($replyTo = $message->getReplyTo()) {
            $fields[] = ['name' => 'h:Reply-To', 'contents' => implode(', ', $this->formatAddresses($replyTo))];
        }

        $body = $this->getMessageBody($message);

        if ($body['text'] !== null) {
            $fields[] = ['name' => 'text', 'contents' => $body['text']];
        }

        if ($body['html'] !== null) {
            $fields[] = ['name' => 'html', 'contents' => $body['html']];
        }

        $attachments = $this->getMessageAttachments($message);
        foreach ($attachments as $attachment) {
            $fields[] = [
                'name' => 'attachment',
                'contents' => $attachment['content'],
                'filename' => $attachment['filename'],
                'headers' => ['Content-Type' => $attachment['contentType']],
            ];
        }

        // Tags → o:tag (multiple values)
        foreach ($tags as $tag) {
            $fields[] = ['name' => 'o:tag', 'contents' => $tag];
        }

        // Metadata → v:key=value (prefixed params)
        foreach ($metadata as $key => $value) {
            $fields[] = ['name' => 'v:' . $key, 'contents' => $value];
        }

        return $fields;
    }
}
