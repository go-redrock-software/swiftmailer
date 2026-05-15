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
 * Infobip HTTP API transport.
 *
 * Sends email via the Infobip REST API using multipart/form-data.
 *
 * @see https://www.infobip.com/docs/api/channels/email/send-email
 */
class Swift_Transport_Api_InfoBipTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private string $baseUrl;

    public function __construct(
        #[SensitiveParameter] string $apiKey,
        string $baseUrl,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        Swift_Transport_UrlValidator::validate('https://'.$baseUrl);
        $this->baseUrl = \rtrim($baseUrl, '/');
    }

    #[Override]
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers'     => $this->getAuthHeaders(),
            'multipart'   => $this->getFormData($message),
            'http_errors' => false,
        ]);

        $result = $this->parseResponse($response);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMsg = $result['requestError']['serviceException']['text'] ?? 'Unknown Infobip error';
            throw new Swift_TransportException('Infobip API error: '.$errorMsg);
        }

        $groupName = $result['messages'][0]['status']['groupName'] ?? null;
        if ('PENDING' !== $groupName) {
            $description = $result['messages'][0]['status']['description'] ?? 'Unknown error';
            throw new Swift_TransportException('Infobip API error: '.$description);
        }

        return [
            'message_id' => $result['messages'][0]['messageId'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return 'https://'.$this->baseUrl.'/email/3/send';
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'App '.$this->apiKey,
        ];
    }

    #[Override]
    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode($this->getResponseBody($response), true) ?? [];
    }

    #[Override]
    protected function getPingEndpoint(): string
    {
        return 'https://'.$this->baseUrl.'/email/1/domains';
    }

    /**
     * Build the multipart/form-data fields for the Infobip API.
     *
     * @return array<array{name: string, contents: string, filename?: string, headers?: array}>
     */
    private function getFormData(Swift_Mime_SimpleMessage $message): array
    {
        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail] ?? null;

        $fields = [
            ['name' => 'from', 'contents' => $this->formatAddress($fromEmail, $fromName)],
            ['name' => 'subject', 'contents' => $message->getSubject()],
        ];

        // To — repeat field for each recipient
        foreach ($message->getTo() ?? [] as $email => $name) {
            $fields[] = ['name' => 'to', 'contents' => $this->formatAddress($email, $name)];
        }

        // CC — repeat field for each recipient
        if ($cc = $message->getCc()) {
            foreach ($cc as $email => $name) {
                $fields[] = ['name' => 'cc', 'contents' => $this->formatAddress($email, $name)];
            }
        }

        // BCC — repeat field for each recipient
        if ($bcc = $message->getBcc()) {
            foreach ($bcc as $email => $name) {
                $fields[] = ['name' => 'bcc', 'contents' => $this->formatAddress($email, $name)];
            }
        }

        // Reply-To
        if ($replyTo = $message->getReplyTo()) {
            $replyToEmail = \array_key_first($replyTo);
            $fields[]     = ['name' => 'replyTo', 'contents' => $replyToEmail];
        }

        // Body parts
        $body = $this->getMessageBody($message);

        if (null !== $body['text']) {
            $fields[] = ['name' => 'text', 'contents' => $body['text']];
        }

        if (null !== $body['html']) {
            $fields[] = ['name' => 'html', 'contents' => $body['html']];
        }

        // Attachments and inline images
        $attachments = $this->getMessageAttachments($message);
        foreach ($attachments as $attachment) {
            $fieldName = 'inline' === $attachment['disposition'] ? 'inlineImage' : 'attachment';
            $fields[]  = [
                'name'     => $fieldName,
                'contents' => $attachment['content'],
                'filename' => $attachment['filename'],
                'headers'  => ['Content-Type' => $attachment['contentType']],
            ];
        }

        return $fields;
    }
}
