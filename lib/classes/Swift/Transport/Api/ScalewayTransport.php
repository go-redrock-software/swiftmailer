<?php

use Psr\Http\Message\ResponseInterface;

/**
 * Scaleway Transactional Email HTTP API transport.
 *
 * Sends email via the Scaleway TEM REST API.
 *
 * @see https://www.scaleway.com/en/developers/api/transactional-email/
 */
class Swift_Transport_Api_ScalewayTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private string $projectId;

    private string $region;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $projectId,
        string $region = 'fr-par',
        ?GuzzleHttp\ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        $this->projectId = $projectId;
        $this->region = $region;
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->buildPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'json' => $payload,
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $parsed = $this->parseResponse($response);
            $errorMessage = $parsed['message'] ?? 'Unknown error';

            throw new Swift_TransportException(
                sprintf('Scaleway API error (%d): %s', $statusCode, $errorMessage),
            );
        }

        $parsed = $this->parseResponse($response);
        $messageId = $parsed['emails'][0]['message_id'] ?? null;

        return [
            'message_id' => $messageId,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return sprintf(
            'https://api.scaleway.com/transactional-email/v1alpha1/regions/%s/emails',
            $this->region,
        );
    }

    protected function getAuthHeaders(): array
    {
        return [
            'X-Auth-Token' => $this->apiKey,
        ];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return sprintf(
            'https://api.scaleway.com/transactional-email/v1alpha1/regions/%s/domains',
            $this->region,
        );
    }

    private function buildPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);
        $fromName = $from[$fromEmail] ?? null;

        $toRecipients = $this->mapAddresses($message->getTo() ?? []);
        $additionalHeaders = [];

        // CC recipients must be added to the `to` array for delivery,
        // and also declared via an additional_header so the CC header appears.
        $cc = $message->getCc();
        if (!empty($cc)) {
            $ccAddresses = $this->mapAddresses($cc);
            $toRecipients = array_merge($toRecipients, $ccAddresses);

            $ccHeaderParts = [];
            foreach ($cc as $email => $name) {
                $ccHeaderParts[] = $this->formatAddress($email, $name);
            }
            $additionalHeaders[] = [
                'key' => 'Cc',
                'value' => implode(', ', $ccHeaderParts),
            ];
        }

        // BCC recipients are added to the `to` array for delivery only — no header.
        $bcc = $message->getBcc();
        if (!empty($bcc)) {
            $bccAddresses = $this->mapAddresses($bcc);
            $toRecipients = array_merge($toRecipients, $bccAddresses);
        }

        // Reply-To via additional_headers
        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $replyToParts = [];
            foreach ($replyTo as $email => $name) {
                $replyToParts[] = $this->formatAddress($email, $name);
            }
            $additionalHeaders[] = [
                'key' => 'Reply-To',
                'value' => implode(', ', $replyToParts),
            ];
        }

        $payload = [
            'from' => array_filter([
                'email' => $fromEmail,
                'name' => $fromName,
            ]),
            'to' => $toRecipients,
            'subject' => $message->getSubject(),
            'project_id' => $this->projectId,
        ];

        $body = $this->getMessageBody($message);
        if ($body['text'] !== null) {
            $payload['text'] = $body['text'];
        }
        if ($body['html'] !== null) {
            $payload['html'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = array_map(static function (array $attachment): array {
                return [
                    'name' => $attachment['filename'],
                    'type' => $attachment['contentType'],
                    'content' => base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        if (!empty($additionalHeaders)) {
            $payload['additional_headers'] = $additionalHeaders;
        }

        return $payload;
    }

    /**
     * Map a SwiftMailer address array to Scaleway's address format.
     *
     * @param array<string, string|null> $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    private function mapAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = array_filter([
                'email' => $email,
                'name' => $name,
            ]);
        }

        return $mapped;
    }
}
