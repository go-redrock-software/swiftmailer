<?php

use Google\Client;

use function Swift\getRawMessage;


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

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
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
            // Replace this with your own logging or error handling
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
            if ($evt = $this->eventDispatcher->createTransportChangeEvent($this)) {
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
