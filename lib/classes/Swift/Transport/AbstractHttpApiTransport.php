<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Abstract base class for HTTP API-based transports.
 *
 * Provides common lifecycle management (start/stop/ping), event dispatching,
 * and HTTP client handling. Concrete transports implement provider-specific
 * payload building, endpoint configuration, and response parsing.
 */
abstract class Swift_Transport_AbstractHttpApiTransport extends Swift_Transport_AbstractApiTransport
{
    public string $apiKey;

    public ClientInterface $httpClient;

    /** @var Swift_Envelope|null Active envelope during send */
    public ?Swift_Envelope $activeEnvelope = null;

    public function __construct(
        #[SensitiveParameter] string $apiKey,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        $this->apiKey          = $apiKey;
        $this->httpClient      = $httpClient ?? new Client(['verify' => true]);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function start(): void
    {
        if (!$this->started) {
            if ($evt = $this->eventDispatcher?->createTransportChangeEvent($this)) {
                $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStarted');
                if ($evt->bubbleCancelled()) {
                    return;
                }
            }

            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
            }

            $this->started = true;
        }
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $response = $this->httpClient->request('GET', $this->getPingEndpoint(), [
                'headers'     => $this->getAuthHeaders(),
                'http_errors' => false,
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (Exception $e) {
            return false;
        }
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        if (null === $failedRecipients) {
            $failedRecipients = [];
        }

        if (!$this->isStarted()) {
            $this->start();
        }

        // Store envelope so doSend()/countRecipients()/collectRecipients() can use it
        $this->activeEnvelope = $envelope;

        if ($evt = $this->eventDispatcher?->createSendEvent($this, $message)) {
            $evt->setEnvelope($envelope);
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                $evt->cancelBubble(false);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
                $this->activeEnvelope = null;

                return 0;
            }
            // Re-read envelope in case a listener modified it
            $envelope             = $evt->getEnvelope();
            $this->activeEnvelope = $envelope;
        }

        try {
            $result = $this->doSend($message, $envelope);

            $messageId = $result['message_id'] ?? null;
            if (null !== $messageId && !\is_scalar($messageId)) {
                throw new Swift_Transport_NonRetryableException('API returned an invalid message identifier. Delivery cannot be confirmed; automatic retry is disabled.');
            }
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            }

            $recipientCount = $result['recipients'] ?? $this->countRecipients($message);

            $sentMessage = new Swift_SentMessage($message, $this, [
                'message_id' => null === $messageId ? null : (string) $messageId,
                'recipients' => $recipientCount,
                'debug'      => $result,
            ]);

            if ($sentEvt = $this->eventDispatcher?->createSentMessageEvent($this, $sentMessage)) {
                $this->eventDispatcher->dispatchEvent($sentEvt, 'sentMessage');
            }

            return $recipientCount;
        } catch (Exception $e) {
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->setFailedRecipients($this->collectRecipients($message));
            }

            $failedRecipients = \array_merge($failedRecipients, $this->collectRecipients($message));

            $transportException = new Swift_TransportException(
                'Failed to send email via '.static::class.': '.$e->getMessage(),
                0,
                $e,
            );

            if ($failedEvt = $this->eventDispatcher?->createFailedMessageEvent($this, $message, $transportException, $failedRecipients)) {
                $this->eventDispatcher->dispatchEvent($failedEvt, 'failedMessage');
            }

            $this->throwException($transportException);

            return 0;
        } finally {
            $this->activeEnvelope = null;
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }
        }
    }

    protected function getApiConnection(): ClientInterface
    {
        return $this->httpClient;
    }

    /**
     * Send the message via the provider's HTTP API.
     *
     * @param Swift_Envelope|null $envelope Optional explicit SMTP envelope
     *
     * @return array{message_id?: mixed, recipients?: int} Untrusted provider result; send() validates identifier values before constructing a SentMessage
     */
    abstract protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array;

    /**
     * Get the API endpoint URL for sending email.
     */
    abstract protected function getEndpoint(): string;

    /**
     * Get the authentication headers for API requests.
     */
    abstract protected function getAuthHeaders(): array;

    /**
     * Parse the API response.
     */
    abstract protected function parseResponse(ResponseInterface $response): array;

    /**
     * Get the API endpoint URL for ping/health check.
     */
    abstract protected function getPingEndpoint(): string;

    protected const MAX_RESPONSE_SIZE = 1048576; // 1 MB

    /** Decode provider JSON without letting remote scalar values violate the array contract. */
    protected function decodeJsonResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || ($status >= 300 && $status < 400)) {
            throw $this->invalidResponse($response, 'Unexpected HTTP status');
        }
        try {
            $body = $this->getResponseBody($response);
        } catch (Exception $error) {
            throw $this->invalidResponse($response, 'Unable to read API response', $error);
        }
        // Some APIs acknowledge acceptance using only the status/headers.
        if ('' === \trim($body)) {
            return [];
        }
        try {
            $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw $this->invalidResponse($response, 'Malformed JSON in API response', $error);
        }
        if (!\is_array($decoded)) {
            throw $this->invalidResponse($response, 'API response must contain a JSON object or array');
        }

        return $decoded;
    }

    protected function invalidResponse(ResponseInterface $response, string $reason, ?Exception $previous = null, bool $ambiguous = false): Swift_TransportException
    {
        $status  = $response->getStatusCode();
        $message = \sprintf('%s (HTTP %d).', $reason, $status);
        if ($status < 400 || $ambiguous) {
            return new Swift_Transport_NonRetryableException($message.' Delivery cannot be confirmed; automatic retry is disabled.', $status, $previous);
        }

        return new Swift_TransportException($message, $status, $previous);
    }

    protected function responseText(mixed $value, string $fallback): string
    {
        return \is_scalar($value) ? (string) $value : $fallback;
    }

    /** Collect printable error details without assuming a provider's container shape. */
    protected function responseErrors(mixed $value): array
    {
        if (!\is_array($value)) {
            return \is_scalar($value) ? [(string) $value] : [];
        }
        $messages = [];
        \array_walk_recursive($value, static function ($detail) use (&$messages): void {
            if (\is_scalar($detail)) {
                $messages[] = (string) $detail;
            }
        });

        return $messages;
    }

    protected function getResponseBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $size = $body->getSize();

        if (null !== $size && $size > static::MAX_RESPONSE_SIZE) {
            throw new Swift_TransportException(\sprintf('API response body too large: %d bytes (max %d)', $size, static::MAX_RESPONSE_SIZE));
        }

        $contents = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            $contents .= $chunk;
            if (\strlen($contents) > static::MAX_RESPONSE_SIZE) {
                throw new Swift_TransportException(\sprintf('API response body exceeded max size of %d bytes', static::MAX_RESPONSE_SIZE));
            }
        }

        return $contents;
    }

    /**
     * Count total recipients on a message.
     */
    protected function countRecipients(Swift_Mime_SimpleMessage $message): int
    {
        if (null !== $this->activeEnvelope) {
            return \count($this->activeEnvelope->getRecipients());
        }

        return \count($message->getTo() ?? [])
            + \count($message->getCc() ?? [])
            + \count($message->getBcc() ?? []);
    }

    /**
     * Collect all recipient addresses from a message.
     */
    protected function collectRecipients(Swift_Mime_SimpleMessage $message): array
    {
        if (null !== $this->activeEnvelope) {
            return $this->activeEnvelope->getRecipients();
        }

        $recipients = [];
        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            foreach ($message->$method() ?? [] as $address => $name) {
                $recipients[] = $address;
            }
        }

        return $recipients;
    }

    /**
     * Get the envelope sender, preferring the active envelope over message headers.
     */
    protected function getEnvelopeSender(Swift_Mime_SimpleMessage $message): ?string
    {
        if (null !== $this->activeEnvelope) {
            return $this->activeEnvelope->getSender();
        }

        $from = $message->getFrom();
        if (!empty($from)) {
            return \array_key_first($from);
        }

        return null;
    }

    /**
     * Format a SwiftMailer address array entry as "Name <email>" or just "email".
     */
    protected function formatAddress(string $email, ?string $name = null): string
    {
        if ($name) {
            return \sprintf('%s <%s>', $name, $email);
        }

        return $email;
    }

    /**
     * Format all addresses from a SwiftMailer address array.
     */
    protected function formatAddresses(array $addresses): array
    {
        $formatted = [];
        foreach ($addresses as $email => $name) {
            $formatted[] = $this->formatAddress($email, $name);
        }

        return $formatted;
    }

    /**
     * Get attachments from the message as an array of arrays with keys:
     * 'filename', 'content', 'contentType', 'disposition', 'contentId'.
     */
    protected function getMessageAttachments(Swift_Mime_SimpleMessage $message): array
    {
        $attachments = [];
        foreach ($message->getChildren() ?? [] as $child) {
            if ($child instanceof Swift_Attachment || $child instanceof Swift_Image) {
                $attachments[] = [
                    'filename'    => $child->getFilename(),
                    'content'     => $child->getBody(),
                    'contentType' => $child->getContentType(),
                    'disposition' => $child->getDisposition(),
                    'contentId'   => $child->getId(),
                ];
            }
        }

        return $attachments;
    }

    /**
     * Get text and HTML parts from a message.
     *
     * @return array{text: ?string, html: ?string}
     */
    protected function getMessageBody(Swift_Mime_SimpleMessage $message): array
    {
        $body        = $message->getBody();
        $contentType = $message->getBodyContentType();
        $text        = null;
        $html        = null;

        if ('text/html' === $contentType) {
            $html = $body;
        } else {
            $text = $body;
        }

        // Check children for alternative parts
        foreach ($message->getChildren() ?? [] as $child) {
            if ($child instanceof Swift_MimePart) {
                if ('text/html' === $child->getContentType()) {
                    $html = $child->getBody();
                } elseif ('text/plain' === $child->getContentType()) {
                    $text = $child->getBody();
                }
            }
        }

        return ['text' => $text, 'html' => $html];
    }

    /**
     * Extract X-Mailer-Tag headers from message and remove them.
     *
     * @return string[]
     */
    protected function extractTags(Swift_Mime_SimpleMessage $message): array
    {
        $tags    = [];
        $headers = $message->getHeaders();

        foreach ($headers->getAll('X-Mailer-Tag') as $header) {
            $tags[] = $header->getFieldBody();
        }

        if ($tags) {
            $headers->removeAll('X-Mailer-Tag');
        }

        return $tags;
    }

    /**
     * Extract X-Mailer-Metadata-* headers from message and remove them.
     *
     * @return array<string, string>
     */
    protected function extractMetadata(Swift_Mime_SimpleMessage $message): array
    {
        $metadata = [];
        $headers  = $message->getHeaders();
        $prefix   = 'X-Mailer-Metadata-';

        $toRemove = [];
        foreach ($headers->getAll() as $header) {
            $name = $header->getFieldName();
            if (\str_starts_with($name, $prefix)) {
                $key            = \substr($name, \strlen($prefix));
                $metadata[$key] = $header->getFieldBody();
                $toRemove[]     = $name;
            }
        }

        foreach ($toRemove as $name) {
            $headers->removeAll($name);
        }

        return $metadata;
    }
}
