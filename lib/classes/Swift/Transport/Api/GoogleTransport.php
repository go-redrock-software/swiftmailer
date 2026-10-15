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

        // Get the Gmail Service from the Google Client
        $service = new Google\Service\Gmail($this->getApiConnection());

        $toRecipients  = \count($message->getTo() ?? []);
        $ccRecipients  = \count($message->getCc() ?? []);
        $bccRecipients = \count($message->getBcc() ?? []);

        $totalRecipients = $toRecipients + $ccRecipients + $bccRecipients;

        try {
            // Create base64url encoded RFC 2822 formatted message and add to the Gmail service
            $msg = new Google\Service\Gmail\Message();
            $msg->setRaw(getRawMessage($message));
            // Send the email
            /** @noinspection CallableParameterUseCaseInTypeContextInspection */
            $message = $service->users_messages->send('me', $msg);

            return $totalRecipients;
        } catch (Exception $e) {
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
