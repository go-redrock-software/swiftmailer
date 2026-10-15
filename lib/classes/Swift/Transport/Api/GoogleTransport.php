<?php

use Google\Client;

use function Swift\getRawMessage;

/**
 * Gmail HTTP API transport.
 *
 * Sends email through the Gmail API (users.messages.send) using an authenticated
 * google/apiclient Google\Client. The Swift message is rendered to a raw RFC 2822
 * string and submitted as the authenticated user ("me").
 *
 * @see https://developers.google.com/gmail/api/reference/rest/v1/users.messages/send
 */
class Swift_Transport_Api_GoogleTransport extends Swift_Transport_AbstractApiTransport
{
    private Client $googleClient;

    public function __construct(
        Client $googleClient,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        $this->googleClient    = $googleClient;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function ping(): bool
    {
        return true;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        return $this->sendWithEvents($message, $failedRecipients, $envelope, fn (?Swift_Envelope $effective): int => $this->sendMessage($message, $effective));
    }

    private function sendMessage(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope): int
    {
        $this->validateEnvelope($message, $envelope);

        $toRecipients  = \count($message->getTo() ?? []);
        $ccRecipients  = \count($message->getCc() ?? []);
        $bccRecipients = \count($message->getBcc() ?? []);

        $totalRecipients = $toRecipients + $ccRecipients + $bccRecipients;

        $policy = new Swift_Transport_Api_GmailRequestPolicy();
        $http   = null;
        try {
            $client = $this->getApiConnection();
            if (!(new ReflectionObject($client))->isCloneable()) {
                throw new Swift_Transport_NonRetryableException('The Google client must support cloning for isolated HTTP requests.');
            }
            // Authentication normally initializes this cache. Share it across copies
            // so refresh credentials and token callbacks retain their normal behavior.
            $client->getCache();
            $scoped = clone $client;
            $http   = new Swift_Transport_Api_NoRedirectClient($client->getHttpClient(), $policy->assertUsable(...), $policy->validateResponse(...));
            $scoped->setHttpClient($http);
            $scoped->setDefer(false);
            if ($scoped->getHttpClient() !== $http || $scoped->shouldDefer()) {
                throw new Swift_Transport_NonRetryableException('The Google client must honor scoped HTTP configuration and immediate execution.');
            }
            $service = new Google\Service\Gmail($scoped);

            // Create base64url encoded RFC 2822 formatted message and add to the Gmail service
            $msg = new Google\Service\Gmail\Message();
            $msg->setRaw(getRawMessage($message));
            // Send the email
            /** @noinspection CallableParameterUseCaseInTypeContextInspection */
            $result = $service->users_messages->send('me', $msg);
            if ($result instanceof Psr\Http\Message\RequestInterface) {
                throw new Swift_Transport_NonRetryableException('Gmail returned a deferred request instead of a send result.');
            }
            $http->assertNoRedirect();
            $policy->assertAccepted($result);

            return $totalRecipients;
        } catch (Throwable $error) {
            try {
                $http?->assertNoRedirect();
                $policy->assertAfterFailure();
            } catch (Throwable $refusal) {
                $error = $refusal;
            }
            if (Swift_Transport_RetryPolicy::isTerminalFailure($error)) {
                // SDK traces can retain OAuth clients, requests and token bodies.
                throw new Swift_Transport_NonRetryableException('Gmail send failed permanently; automatic retry is disabled.', $error->getCode());
            }

            return 0;
        }
    }

    protected function getApiConnection(): Client
    {
        return $this->googleClient;
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

            // nothing to "start" with an API connection, but we should still honor the event dispatcher expectations
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
            }

            $this->started = true;
        }
    }
}
