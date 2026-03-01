<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Value object capturing the result of sending a message.
 *
 * Populated by transports after a successful send and made available
 * through SentMessageEvent for plugin consumption.
 *
 * Supports PHP 8.5 clone with syntax for immutable modifications.
 */
readonly class Swift_SentMessage
{
    private Swift_Mime_SimpleMessage $originalMessage;

    private Swift_Transport $transport;

    private ?string $messageId;

    private int $recipientCount;

    private array $debug;

    private array $failedRecipients;

    /**
     * @param Swift_Mime_SimpleMessage $originalMessage The message that was sent
     * @param Swift_Transport          $transport       The transport that sent it
     * @param array{
     *     message_id?: string,
     *     recipients?: int,
     *     debug?: array,
     *     failed_recipients?: string[]
     * } $result Send result data from the transport
     */
    public function __construct(
        Swift_Mime_SimpleMessage $originalMessage,
        Swift_Transport $transport,
        array $result = [],
    ) {
        $this->originalMessage  = $originalMessage;
        $this->transport        = $transport;
        $this->messageId        = $result['message_id']        ?? null;
        $this->recipientCount   = $result['recipients']        ?? 0;
        $this->debug            = $result['debug']             ?? [];
        $this->failedRecipients = $result['failed_recipients'] ?? [];
    }

    /**
     * Named constructor for creating from individual values.
     */
    public static function fromResult(
        Swift_Mime_SimpleMessage $message,
        Swift_Transport $transport,
        array $result,
    ): self {
        return new self(
            $message,
            $transport,
            $result,
        );
    }

    /**
     * Return a clone with a different message ID (PHP 8.5 clone with).
     */
    public function withMessageId(?string $messageId): self
    {
        return clone($this, ['messageId' => $messageId]);
    }

    /**
     * Return a clone with a different recipient count (PHP 8.5 clone with).
     */
    public function withRecipientCount(int $recipientCount): self
    {
        return clone($this, ['recipientCount' => $recipientCount]);
    }

    public function getOriginalMessage(): Swift_Mime_SimpleMessage
    {
        return $this->originalMessage;
    }

    public function getTransport(): Swift_Transport
    {
        return $this->transport;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function getDebug(): array
    {
        return $this->debug;
    }

    public function getFailedRecipients(): array
    {
        return $this->failedRecipients;
    }
}
