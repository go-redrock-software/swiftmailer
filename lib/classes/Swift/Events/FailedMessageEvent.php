<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Generated when a message fails to send.
 */
class Swift_Events_FailedMessageEvent extends Swift_Events_EventObject
{
    private Swift_Mime_SimpleMessage $message;
    private Swift_TransportException $exception;
    private array $failedRecipients;

    public function __construct(
        Swift_Transport $source,
        Swift_Mime_SimpleMessage $message,
        Swift_TransportException $exception,
        array $failedRecipients = [],
    ) {
        parent::__construct($source);
        $this->message = $message;
        $this->exception = $exception;
        $this->failedRecipients = $failedRecipients;
    }

    public function getMessage(): Swift_Mime_SimpleMessage
    {
        return $this->message;
    }

    public function getException(): Swift_TransportException
    {
        return $this->exception;
    }

    /** @return string[] */
    public function getFailedRecipients(): array
    {
        return $this->failedRecipients;
    }

    public function getTransport(): Swift_Transport
    {
        return $this->getSource();
    }
}
