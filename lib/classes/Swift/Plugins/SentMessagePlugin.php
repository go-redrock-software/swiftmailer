<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Plugin that captures SentMessage objects for easy retrieval after sending.
 */
class Swift_Plugins_SentMessagePlugin implements Swift_Events_SentMessageListener
{
    /** @var Swift_SentMessage[] */
    private array $sentMessages = [];

    public function sentMessage(Swift_Events_SentMessageEvent $evt): void
    {
        $this->sentMessages[] = $evt->getSentMessage();
    }

    public function getLastSentMessage(): ?Swift_SentMessage
    {
        if (empty($this->sentMessages)) {
            return null;
        }

        return $this->sentMessages[array_key_last($this->sentMessages)];
    }

    /** @return Swift_SentMessage[] */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function reset(): void
    {
        $this->sentMessages = [];
    }
}
