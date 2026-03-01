<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2009 Fabien Potencier <fabien.potencier@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Stores Messages in a queue.
 *
 * @author Fabien Potencier
 */
class Swift_Transport_SpoolTransport implements Swift_Transport
{
    /** The spool instance */
    private $spool;

    /** The event dispatcher from the plugin API */
    private $eventDispatcher;

    /**
     * Constructor.
     */
    public function __construct(Swift_Events_EventDispatcher $eventDispatcher, ?Swift_Spool $spool = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->spool           = $spool;
    }

    /**
     * Sets the spool object.
     *
     * @return $this
     */
    public function setSpool(Swift_Spool $spool)
    {
        $this->spool = $spool;

        return $this;
    }

    /**
     * Get the spool object.
     *
     * @return Swift_Spool
     */
    public function getSpool()
    {
        return $this->spool;
    }

    /**
     * Tests if this Transport mechanism has started.
     *
     * @return bool
     */
    #[\Override]
    public function isStarted()
    {
        return true;
    }

    /**
     * Starts this Transport mechanism.
     */
    #[\Override]
    public function start()
    {
    }

    /**
     * Stops this Transport mechanism.
     */
    #[\Override]
    public function stop()
    {
    }

    #[\Override]
    public function ping()
    {
        return true;
    }

    /**
     * Sends the given message.
     *
     * @param string[] $failedRecipients An array of failures by-reference
     *
     * @return int The number of sent e-mail's
     */
    #[\Override]
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null)
    {
        if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->cancelBubble(false);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');

                return 0;
            }
        }

        $success = $this->spool->queueMessage($message);

        if ($evt) {
            $evt->setResult($success ? Swift_Events_SendEvent::RESULT_SPOOLED : Swift_Events_SendEvent::RESULT_FAILED);
            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        if ($envelope) {
            return \count($envelope->getRecipients());
        }

        return 1;
    }

    /**
     * Register a plugin.
     */
    #[\Override]
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }
}
