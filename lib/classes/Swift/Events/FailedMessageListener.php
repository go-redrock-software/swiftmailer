<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Listens for messages that failed to send.
 */
interface Swift_Events_FailedMessageListener extends Swift_Events_EventListener
{
    public function failedMessage(Swift_Events_FailedMessageEvent $evt);
}
