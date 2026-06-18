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
        $this->eventDispatcher?->bindEventListener($plugin);
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
