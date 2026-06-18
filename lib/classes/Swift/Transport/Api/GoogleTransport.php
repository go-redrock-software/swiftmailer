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

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        if ($evt = $this->eventDispatcher?->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->cancelBubble(false);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');

                return 0;
            }
        }

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

            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            }

            return $totalRecipients;
        } catch (Exception $e) {
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }

            return 0;
        } finally {
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }
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
