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
 * Azure Communication Services Email HTTP API transport.
 *
 * Sends email via the Azure Communication Services Email REST API using
 * HMAC-SHA256 request signing for authentication.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/communication/email/send
 */
class Swift_Transport_Api_AzureTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const API_VERSION = '2024-07-01-preview';

    private string $endpoint;

    private string $accessKey;

    public function __construct(
        #[SensitiveParameter] string $connectionString,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        $params = $this->parseConnectionString($connectionString);

        $this->endpoint  = \rtrim($params['endpoint'], '/');
        $this->accessKey = $params['accesskey'];

        parent::__construct($this->accessKey, $httpClient, $eventDispatcher);
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $fakeOperationId = '00000000-0000-0000-0000-000000000000';
            $url             = $this->endpoint.'/emails/operations/'.$fakeOperationId.'?api-version='.self::API_VERSION;

            $signedHeaders = $this->signRequest('GET', $url, '');

            $response = $this->httpClient->request('GET', $url, [
                'headers'     => $signedHeaders,
                'http_errors' => false,
            ]);

            // 404 means auth succeeded but operation not found (expected).
            // 401 means bad credentials.
            return 401 !== $response->getStatusCode();
        } catch (Exception $e) {
            return false;
        }
    }

    #[Override]
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $payload = $this->buildPayload($message);
        $url     = $this->getEndpoint();
        $body    = \json_encode($payload, JSON_THROW_ON_ERROR);

        $signedHeaders                 = $this->signRequest('POST', $url, $body);
        $signedHeaders['Content-Type'] = 'application/json';

        $response = $this->httpClient->request('POST', $url, [
            'headers'     => $signedHeaders,
            'body'        => $body,
            'http_errors' => false,
        ]);

        $result = $this->parseResponse($response);

        if ($response->getStatusCode() >= 400) {
            $errorCode    = $result['error']['code']    ?? 'Unknown';
            $errorMessage = $result['error']['message'] ?? 'Unknown error';

            throw new Swift_TransportException(\sprintf('Azure Communication Services API error %s: %s', $errorCode, $errorMessage));
        }

        return [
            'message_id' => $result['id'] ?? null,
            'recipients' => $this->countRecipients($message),
        ];
    }

    #[Override]
    protected function getEndpoint(): string
    {
        return $this->endpoint.'/emails:send?api-version='.self::API_VERSION;
    }

    #[Override]
    protected function getAuthHeaders(): array
    {
        // Auth is handled per-request via HMAC signing in doSend/ping.
        return [];
    }

    #[Override]
    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode($this->getResponseBody($response), true) ?? [];
    }

    #[Override]
    protected function getPingEndpoint(): string
    {
        // Not used — ping() is overridden entirely.
        return $this->endpoint.'/emails/operations/00000000-0000-0000-0000-000000000000?api-version='.self::API_VERSION;
    }

    /**
     * Sign an HTTP request using HMAC-SHA256 per Azure Communication Services requirements.
     *
     * @return array Signed headers to include in the request
     */
    private function signRequest(string $method, string $url, string $body): array
    {
        $contentHash  = \base64_encode(\hash('sha256', $body, true));
        $date         = \gmdate('D, d M Y H:i:s T');
        $host         = \parse_url($url, PHP_URL_HOST);
        $pathAndQuery = \parse_url($url, PHP_URL_PATH).'?'.\parse_url($url, PHP_URL_QUERY);

        $stringToSign = "{$method}\n{$pathAndQuery}\n{$date};{$host};{$contentHash}";
        $signature    = \base64_encode(\hash_hmac('sha256', $stringToSign, \base64_decode($this->accessKey), true));

        return [
            'x-ms-date'           => $date,
            'x-ms-content-sha256' => $contentHash,
            'host'                => $host,
            'Authorization'       => "HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature={$signature}",
        ];
    }

    /**
     * Parse an Azure Communication Services connection string into its components.
     *
     * @return array{endpoint: string, accesskey: string}
     */
    private function parseConnectionString(string $connectionString): array
    {
        $params = [];

        foreach (\explode(';', $connectionString) as $part) {
            $part = \trim($part);
            if ('' === $part) {
                continue;
            }

            $equalsPos = \strpos($part, '=');
            if (false === $equalsPos) {
                continue;
            }

            $key          = \strtolower(\substr($part, 0, $equalsPos));
            $value        = \substr($part, $equalsPos + 1);
            $params[$key] = $value;
        }

        if (!isset($params['endpoint'])) {
            throw new InvalidArgumentException('Connection string must contain an "endpoint" parameter.');
        }

        if (!isset($params['accesskey'])) {
            throw new InvalidArgumentException('Connection string must contain an "accesskey" parameter.');
        }

        return $params;
    }

    /**
     * Build the Azure Communication Services email payload from a SwiftMailer message.
     */
    private function buildPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from          = $message->getFrom();
        $senderAddress = \array_key_first($from);

        $payload = [
            'senderAddress' => $senderAddress,
            'content'       => [
                'subject' => $message->getSubject(),
            ],
            'recipients' => [
                'to' => $this->mapAddresses($message->getTo() ?? []),
            ],
        ];

        $body = $this->getMessageBody($message);

        if (null !== $body['text']) {
            $payload['content']['plainText'] = $body['text'];
        }

        if (null !== $body['html']) {
            $payload['content']['html'] = $body['html'];
        }

        if ($cc = $message->getCc()) {
            $payload['recipients']['cc'] = $this->mapAddresses($cc);
        }

        if ($bcc = $message->getBcc()) {
            $payload['recipients']['bcc'] = $this->mapAddresses($bcc);
        }

        if ($replyTo = $message->getReplyTo()) {
            $payload['replyTo'] = $this->mapAddresses($replyTo);
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = \array_map(static function (array $attachment): array {
                return [
                    'name'            => $attachment['filename'],
                    'contentType'     => $attachment['contentType'],
                    'contentInBase64' => \base64_encode($attachment['content']),
                ];
            }, $attachments);
        }

        return $payload;
    }

    /**
     * Map SwiftMailer address array to Azure Communication Services address format.
     *
     * @param array<string, string|null> $addresses
     *
     * @return array<int, array{address: string, displayName?: string}>
     */
    private function mapAddresses(array $addresses): array
    {
        $mapped = [];

        foreach ($addresses as $email => $name) {
            $entry = ['address' => $email];

            if (null !== $name && '' !== $name) {
                $entry['displayName'] = $name;
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }
}
