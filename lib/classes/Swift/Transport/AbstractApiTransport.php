<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

/**
 * Abstract class representing an API transport for the Swift Mailer library.
 *
 * Child classes should extend this abstract class and implement the necessary methods to handle API-specific functionality.
 */
abstract class Swift_Transport_AbstractApiTransport implements Swift_Transport
{
    /**
     * @var true
     */
    public bool $started = false;

    public ?Swift_Events_EventDispatcher $eventDispatcher = null;

    #[Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Method should be overriden by child classes to set the API connection accordingly.
     *
     * {@inheritDoc}
     */
    abstract public function start(): void;

    /**
     * Generally, we perform a lightweight request to the API, like getting current user profile
     * Method should be overriden by child classes to properly handle their API's ping process.
     *
     * {@inheritDoc}
     */
    abstract public function ping(): bool;

    /**
     * Generally, we would use the API's mail send functionality here
     * Method should be overriden by child classes to properly handle their API's send process.
     *
     * {@inheritDoc}
     */
    abstract public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int;

    /**
     * Plugins would be registered differently based on the API's options/parameters
     * Method should be overriden by child classes to properly handle their API's plugin registration.
     *
     * {@inheritDoc}
     */
    #[Override]
    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
        $this->eventDispatcher ??= new Swift_Events_SimpleEventDispatcher();
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * Destructor.
     */
    /**
     * @codeCoverageIgnore Destructor runs during GC
     */
    public function __destruct()
    {
        try {
            $this->stop();
        } catch (Exception $e) {
        }
    }

    /**
     * Method should be overriden by child classes to unset the API connection accordingly.
     *
     * {@inheritDoc}
     */
    #[Override]
    public function stop(): void
    {
        if ($this->started && $evt = $this->eventDispatcher?->createTransportChangeEvent($this)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStopped');
            if ($evt->bubbleCancelled()) {
                return;
            }
        }
        $this->started = false;
    }

    /** @noinspection MagicMethodsValidityInspection */
    public function __sleep()
    {
        throw new BadMethodCallException('Cannot serialize '.__CLASS__);
    }

    public function __wakeup()
    {
        throw new BadMethodCallException('Cannot unserialize '.__CLASS__);
    }

    abstract protected function getApiConnection(): mixed;

    /**
     * Reject envelope fields a header-based provider cannot submit independently.
     */
    protected function validateEnvelope(
        Swift_Mime_SimpleMessage $message,
        ?Swift_Envelope $envelope,
        bool $supportsSenderOverride = false,
        bool $supportsRecipientOverride = false,
    ): void {
        if (null === $envelope) {
            return;
        }

        $from = \array_key_first($message->getFrom() ?? []);
        if (!$supportsSenderOverride && $this->normalizeEnvelopeAddress($envelope->getSender()) !== $this->normalizeEnvelopeAddress((string) $from)) {
            throw new Swift_TransportException(static::class.' does not support an envelope sender different from the message From address.');
        }

        if (!$supportsRecipientOverride) {
            $headers = [];
            foreach (['getTo', 'getCc', 'getBcc'] as $method) {
                $headers = \array_merge($headers, \array_keys($message->$method() ?? []));
            }
            $headers    = \array_map($this->normalizeEnvelopeAddress(...), $headers);
            $recipients = \array_map($this->normalizeEnvelopeAddress(...), $envelope->getRecipients());
            \sort($headers);
            \sort($recipients);
            if ($headers !== $recipients) {
                throw new Swift_TransportException(static::class.' does not support envelope recipients different from the message To/Cc/Bcc addresses.');
            }
        }
    }

    private function normalizeEnvelopeAddress(string $address): string
    {
        $separator = \strrpos($address, '@');

        return false === $separator ? $address : \substr($address, 0, $separator + 1).\strtolower(\substr($address, $separator + 1));
    }

    /** Render a separate MIME copy without disclosing blind recipients. */
    protected function getRawMessageWithoutBcc(Swift_Mime_SimpleMessage $message, array $additionalHeaders = []): string
    {
        $copy = clone $message;
        $copy->getHeaders()->removeAll('Bcc');
        $copy->getHeaders()->removeAll('Resent-Bcc');
        foreach ($copy->getHeaders()->getAll() as $header) {
            $name = \strtolower($header->getFieldName());
            if ('x-mailer-tag' === $name || \str_starts_with($name, 'x-mailer-metadata-')) {
                $copy->getHeaders()->removeAll($name);
            }
        }
        foreach ($additionalHeaders as $name => $value) {
            $copy->getHeaders()->removeAll($name);
            $copy->getHeaders()->addTextHeader($name, $value);
        }

        return $copy->toString();
    }

    /**
     * Run an SDK send with the same envelope-aware policy lifecycle as HTTP sends.
     *
     * @param callable(?Swift_Envelope, ?Swift_Events_SendEvent): int $send
     * @param array<string, array<string, string|null>>               $recipientGroups
     */
    protected function sendWithEvents(Swift_Mime_SimpleMessage $message, &$failedRecipients, ?Swift_Envelope $envelope, #[SensitiveParameter] callable $send, array $recipientGroups = []): int
    {
        $failedRecipients ??= [];
        $evt            = $this->eventDispatcher?->createSendEvent($this, $message);
        $dispatchEvents = null !== $evt;
        // Keep delivery metadata even when callers do not enable event listeners.
        $evt ??= new Swift_Events_SendEvent($this, $message);

        try {
            $evt->setEnvelope($envelope);
            $evt->setRecipientGroups($recipientGroups);
            if ($dispatchEvents) {
                $this->eventDispatcher?->dispatchEvent($evt, 'beforeSendPerformed');
            }
            if ($evt->bubbleCancelled()) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);

                return 0;
            }
            $envelope = $evt->getEnvelope();

            $sent = $send($envelope, $evt);
            if (0 === $sent) {
                $recipients       = $this->getSendRecipients($message, $envelope, $evt);
                $failedRecipients = \array_values(\array_unique(\array_merge($failedRecipients, $recipients)));
            }
            $evt->setResult($sent > 0 ? Swift_Events_SendEvent::RESULT_SUCCESS : Swift_Events_SendEvent::RESULT_FAILED);
            $evt->setFailedRecipients($failedRecipients);

            return $sent;
        } catch (Throwable $e) {
            $recipients       = $this->getSendRecipients($message, $envelope, $evt);
            $failedRecipients = \array_values(\array_unique(\array_merge($failedRecipients, $recipients)));
            $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            $evt->setFailedRecipients($failedRecipients);

            throw $e;
        } finally {
            $evt->cancelBubble(false);
            if ($dispatchEvents) {
                $this->eventDispatcher?->dispatchEvent($evt, 'sendPerformed');
            }
        }
    }

    private function getSendRecipients(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope, ?Swift_Events_SendEvent $evt): array
    {
        $groups = $evt?->getRecipientGroups() ?? [];
        if ($groups) {
            return \array_keys(\array_merge(...\array_values($groups)));
        }

        return null !== $envelope ? $envelope->getRecipients() : \array_keys(\array_merge($message->getTo() ?? [], $message->getCc() ?? [], $message->getBcc() ?? []));
    }

    /**
     * Throw a TransportException, first sending it to any listeners.
     *
     * @throws Swift_TransportException
     */
    protected function throwException(Swift_TransportException $e): void
    {
        if ($evt = $this->eventDispatcher?->createTransportExceptionEvent($this, $e)) {
            $this->eventDispatcher->dispatchEvent($evt, 'exceptionThrown');
            if (!$evt->bubbleCancelled()) {
                throw $e;
            }
        } else {
            throw $e;
        }
    }
}
