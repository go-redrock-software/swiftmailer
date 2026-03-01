<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Represents the SMTP envelope (MAIL FROM + RCPT TO) independently of message headers.
 *
 * This allows sender rewriting, recipient overriding, and BCC handling
 * without modifying the Swift_Mime_SimpleMessage headers.
 */
readonly class Swift_Envelope
{
    private string $sender;

    /** @var string[] */
    private array $recipients;

    /**
     * @param string   $sender     The envelope sender (MAIL FROM address)
     * @param string[] $recipients The envelope recipients (RCPT TO addresses)
     *
     * @throws InvalidArgumentException
     */
    public function __construct(string $sender, array $recipients)
    {
        if ('' === $sender) {
            throw new InvalidArgumentException('The envelope sender address must not be empty.');
        }

        if (empty($recipients)) {
            throw new InvalidArgumentException('The envelope must have at least one recipient.');
        }

        foreach ($recipients as $recipient) {
            if (!\is_string($recipient)) {
                throw new InvalidArgumentException(\sprintf('Each envelope recipient must be a string, got "%s".', \get_debug_type($recipient)));
            }
        }

        $this->sender     = $sender;
        $this->recipients = \array_values($recipients);
    }

    /**
     * Create an Envelope from a message, using the same sender/recipient
     * derivation logic the transports use today.
     *
     * Sender priority: Return-Path > Sender header > From header.
     * Recipients: To + Cc + Bcc merged.
     *
     * @throws Swift_SwiftException If sender or recipients cannot be determined
     */
    public static function fromMessage(Swift_Mime_SimpleMessage $message): self
    {
        $sender = self::resolveSender($message);
        if (null === $sender) {
            throw new Swift_SwiftException('Cannot determine envelope sender from message headers.');
        }

        $recipients = self::resolveRecipients($message);
        if (empty($recipients)) {
            throw new Swift_SwiftException('Cannot determine envelope recipients from message headers.');
        }

        return new self($sender, $recipients);
    }

    /**
     * Get the envelope sender address (used as MAIL FROM).
     */
    public function getSender(): string
    {
        return $this->sender;
    }

    /**
     * Get the envelope recipient addresses (used as RCPT TO).
     *
     * @return string[]
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * Resolve the sender address from message headers.
     *
     * Uses the same priority as AbstractSmtpTransport::getReversePath():
     * Return-Path > Sender > From.
     */
    private static function resolveSender(Swift_Mime_SimpleMessage $message): ?string
    {
        $return = $message->getReturnPath();
        if (!empty($return)) {
            return $return;
        }

        $sender = $message->getSender();
        if (!empty($sender)) {
            return \array_key_first($sender);
        }

        $from = $message->getFrom();
        if (!empty($from)) {
            return \array_key_first($from);
        }

        return null;
    }

    /**
     * Resolve all recipient addresses from message headers: To + Cc + Bcc.
     *
     * @return string[]
     */
    private static function resolveRecipients(Swift_Mime_SimpleMessage $message): array
    {
        $to  = (array) $message->getTo();
        $cc  = (array) $message->getCc();
        $bcc = (array) $message->getBcc();

        return \array_keys(\array_merge($to, $cc, $bcc));
    }
}
