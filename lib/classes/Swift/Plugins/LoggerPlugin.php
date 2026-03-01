<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Does real time logging of Transport level information.
 *
 * @author     Chris Corbyn
 */
class Swift_Plugins_LoggerPlugin implements Swift_Events_CommandListener, Swift_Events_ResponseListener, Swift_Events_TransportChangeListener, Swift_Events_TransportExceptionListener, Swift_Events_SendListener, Swift_Events_SentMessageListener, Swift_Events_FailedMessageListener, Swift_Plugins_Logger
{
    /** The logger which is delegated to */
    private $logger;

    /**
     * Create a new LoggerPlugin using $logger.
     */
    public function __construct(Swift_Plugins_Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Add a log entry.
     *
     * @param string $entry
     */
    #[Override]
    public function add($entry)
    {
        $this->logger->add($entry);
    }

    /**
     * Clear the log contents.
     */
    #[Override]
    public function clear()
    {
        $this->logger->clear();
    }

    /**
     * Get this log as a string.
     *
     * @return string
     */
    #[Override]
    public function dump()
    {
        return $this->logger->dump();
    }

    /**
     * Invoked immediately following a command being sent.
     */
    #[Override]
    public function commandSent(Swift_Events_CommandEvent $evt)
    {
        $command = $evt->getCommand();
        $this->logger->add(\sprintf('>> %s', $command));
    }

    /**
     * Invoked immediately following a response coming back.
     */
    #[Override]
    public function responseReceived(Swift_Events_ResponseEvent $evt)
    {
        $response = $evt->getResponse();
        $this->logger->add(\sprintf('<< %s', $response));
    }

    /**
     * Invoked just before a Transport is started.
     */
    #[Override]
    public function beforeTransportStarted(Swift_Events_TransportChangeEvent $evt)
    {
        $transportName = \get_class($evt->getSource());
        $this->logger->add(\sprintf('++ Starting %s', $transportName));
    }

    /**
     * Invoked immediately after the Transport is started.
     */
    #[Override]
    public function transportStarted(Swift_Events_TransportChangeEvent $evt)
    {
        $transportName = \get_class($evt->getSource());
        $this->logger->add(\sprintf('++ %s started', $transportName));
    }

    /**
     * Invoked just before a Transport is stopped.
     */
    #[Override]
    public function beforeTransportStopped(Swift_Events_TransportChangeEvent $evt)
    {
        $transportName = \get_class($evt->getSource());
        $this->logger->add(\sprintf('++ Stopping %s', $transportName));
    }

    /**
     * Invoked immediately after the Transport is stopped.
     */
    #[Override]
    public function transportStopped(Swift_Events_TransportChangeEvent $evt)
    {
        $transportName = \get_class($evt->getSource());
        $this->logger->add(\sprintf('++ %s stopped', $transportName));
    }

    /**
     * Invoked as a TransportException is thrown in the Transport system.
     */
    #[Override]
    public function exceptionThrown(Swift_Events_TransportExceptionEvent $evt)
    {
        $e       = $evt->getException();
        $message = $e->getMessage();
        $code    = $e->getCode();
        $this->logger->add(\sprintf('!! %s (code: %s)', $message, $code));
        $message .= PHP_EOL;
        $message .= 'Log data:'.PHP_EOL;
        $message .= $this->logger->dump();
        $evt->cancelBubble();
        throw new Swift_TransportException($message, $code, $e->getPrevious());
    }

    /**
     * Invoked immediately before the Message is sent.
     */
    #[Override]
    public function beforeSendPerformed(Swift_Events_SendEvent $evt)
    {
    }

    /**
     * Invoked immediately after the Message is sent.
     */
    #[Override]
    public function sendPerformed(Swift_Events_SendEvent $evt)
    {
        if ($evt->isRejected()) {
            $reason = $evt->getRejectionReason() ?? 'no reason given';
            $this->logger->add(\sprintf(
                ">> Message rejected before sending: %s\n",
                $reason,
            ));

            return;
        }
    }

    /**
     * Log when a message has been sent successfully.
     */
    #[Override]
    public function sentMessage(Swift_Events_SentMessageEvent $evt): void
    {
        $sm = $evt->getSentMessage();
        $id = $sm->getMessageId() ?? '(no id)';
        $this->logger->add(\sprintf(
            '== Message sent via %s (id: %s, recipients: %d)',
            \get_class($evt->getSource()),
            $id,
            $sm->getRecipientCount(),
        ));
    }

    /**
     * Log when a message fails to send.
     */
    #[Override]
    public function failedMessage(Swift_Events_FailedMessageEvent $evt): void
    {
        $this->logger->add(\sprintf(
            '!! Message failed via %s: %s (failed recipients: %s)',
            \get_class($evt->getSource()),
            $evt->getException()->getMessage(),
            \implode(', ', $evt->getFailedRecipients()),
        ));
    }
}
