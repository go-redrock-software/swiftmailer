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
 * Mailjet HTTP API transport.
 *
 * Sends email via the Mailjet Send API v3.1.
 *
 * @see https://dev.mailjet.com/email/reference/send-emails/
 */
class Swift_Transport_Api_MailJetTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private string $privateKey;

    public function __construct(
        #[SensitiveParameter] string $publicKey,
        #[SensitiveParameter] string $privateKey,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($publicKey, $httpClient, $eventDispatcher);
        $this->privateKey = $privateKey;
    }

    #[Override]
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

        $result = $this->parseResponse($response);

        if (!isset($result['Messages'][0]['Status']) || 'success' !== $result['Messages'][0]['Status']) {
            $errorMessage = $result['Messages'][0]['Errors'][0]['ErrorMessage'] ?? 'Unknown error';

            throw new Swift_TransportException(\sprintf('Mailjet API error: %s', $errorMessage));
        }

        return [
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return 'https://api.mailjet.com/v3.1/send';
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Basic '.\base64_encode($this->apiKey.':'.$this->privateKey),
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
        return 'https://api.mailjet.com/v3/REST/apikey';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags     = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);

        $from        = $message->getFrom();
        $fromAddress = \array_key_first($from);
        $fromName    = $from[$fromAddress] ?? null;

        $msg = [
            'From'    => $this->mapAddress($fromAddress, $fromName),
            'To'      => $this->mapAddresses($message->getTo() ?? []),
            'Subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $msg['Cc'] = $this->mapAddresses($cc);
        }

        if ($bcc = $message->getBcc()) {
            $msg['Bcc'] = $this->mapAddresses($bcc);
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyToAddress = \array_key_first($replyTo);
            $replyToName    = $replyTo[$replyToAddress] ?? null;
            $msg['ReplyTo'] = $this->mapAddress($replyToAddress, $replyToName);
        }

        $body = $this->getMessageBody($message);

        if (null !== $body['text']) {
            $msg['TextPart'] = $body['text'];
        }

        if (null !== $body['html']) {
            $msg['HTMLPart'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $msg['Attachments'] = \array_map(static function (array $attachment): array {
                return [
                    'ContentType'   => $attachment['contentType'],
                    'Filename'      => $attachment['filename'],
                    'Base64Content' => \base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        // Tag → CustomCampaign (single string, first tag only)
        if (!empty($tags)) {
            $msg['CustomCampaign'] = $tags[0];
        }

        // Metadata → EventPayload (JSON string). Mailjet Send v3.1 has no
        // metadata/Properties object; EventPayload carries arbitrary caller data
        // that is echoed back in event webhooks.
        if (!empty($metadata)) {
            $msg['EventPayload'] = \json_encode($metadata);
        }

        return ['Messages' => [$msg]];
    }

    /**
     * Map a single address to Mailjet's {Email, Name} format.
     */
    private function mapAddress(string $email, ?string $name = null): array
    {
        return \array_filter([
            'Email' => $email,
            'Name'  => $name,
        ]);
    }

    /**
     * Map a SwiftMailer address array to Mailjet's [{Email, Name}, ...] format.
     */
    private function mapAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = $this->mapAddress($email, $name);
        }

        return $mapped;
    }
}
