<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Listens for messages that have been successfully sent.
 */
interface Swift_Events_SentMessageListener extends Swift_Events_EventListener
{
    public function sentMessage(Swift_Events_SentMessageEvent $evt);
}
