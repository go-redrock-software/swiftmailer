<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Generated after a message has been successfully sent.
 */
class Swift_Events_SentMessageEvent extends Swift_Events_EventObject
{
    private Swift_SentMessage $sentMessage;

    public function __construct(Swift_Transport $source, Swift_SentMessage $sentMessage)
    {
        parent::__construct($source);
        $this->sentMessage = $sentMessage;
    }

    public function getSentMessage(): Swift_SentMessage
    {
        return $this->sentMessage;
    }

    public function getTransport(): Swift_Transport
    {
        return $this->getSource();
    }
}
