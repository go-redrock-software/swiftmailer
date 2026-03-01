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
 * Mailtrap HTTP API transport.
 *
 * Sends email via the Mailtrap REST API, supporting both live and sandbox modes.
 *
 * @see https://api-docs.mailtrap.io/docs/mailtrap-api-docs/
 */
class Swift_Transport_Api_MailtrapTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private bool $sandbox;

    private ?string $inboxId;

    public function __construct(
        #[SensitiveParameter] string $apiKey,
        bool $sandbox = false,
        ?string $inboxId = null,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        $this->sandbox = $sandbox;
        $this->inboxId = $inboxId;
    }

    #[\Override]
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $payload = $this->buildPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'json'        => $payload,
            'http_errors' => false,
        ]);

        $parsed     = $this->parseResponse($response);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300 || (isset($parsed['success']) && false === $parsed['success'])) {
            $errorMessage = 'Unknown error';
            if (!empty($parsed['errors'])) {
                $errorMessage = \implode('; ', $parsed['errors']);
            }

            throw new Swift_TransportException(\sprintf('Mailtrap API error (%d): %s', $statusCode, $errorMessage));
        }

        $messageId = $parsed['message_ids'][0] ?? null;

        return [
            'message_id' => $messageId,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[\Override]
    protected function getEndpoint(): string
    {
        if ($this->sandbox) {
            return \sprintf('https://sandbox.api.mailtrap.io/api/send/%s', $this->inboxId);
        }

        return 'https://send.api.mailtrap.io/api/send';
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
        $body = (string) $response->getBody();

        return \json_decode($body, true) ?? [];
    }

    #[\Override]
    protected function getPingEndpoint(): string
    {
        return $this->getEndpoint();
    }

    private function buildPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail] ?? null;

        $payload = [
            'from' => \array_filter([
                'email' => $fromEmail,
                'name'  => $fromName,
            ]),
            'to'      => $this->mapAddresses($message->getTo() ?? []),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->mapAddresses($cc);
        }

        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->mapAddresses($bcc);
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
                $item = [
                    'content'     => \base64_encode($attachment['content']),
                    'type'        => $attachment['contentType'],
                    'filename'    => $attachment['filename'],
                    'disposition' => $attachment['disposition'],
                ];

                if ('inline' === $attachment['disposition'] && $attachment['contentId']) {
                    $item['content_id'] = $attachment['contentId'];
                }

                return $item;
            }, $attachments);
        }

        // Tags — Mailtrap supports a single category string
        $tags = $this->extractTags($message);
        if (!empty($tags)) {
            $payload['category'] = $tags[0];
        }

        // Metadata — Mailtrap supports custom_variables
        $metadata = $this->extractMetadata($message);
        if (!empty($metadata)) {
            $payload['custom_variables'] = $metadata;
        }

        return $payload;
    }

    /**
     * Map a SwiftMailer address array to Mailtrap's address format.
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
