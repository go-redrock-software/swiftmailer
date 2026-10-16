<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Generated when a message is being sent.
 *
 * @author Chris Corbyn
 */
class Swift_Events_SendEvent extends Swift_Events_EventObject
{
    /** Sending has yet to occur */
    public const int RESULT_PENDING = 0x0001;

    /** Email is spooled, ready to be sent */
    public const int RESULT_SPOOLED = 0x0011;

    /** Sending was successful */
    public const int RESULT_SUCCESS = 0x0010;

    /** Sending worked, but there were some failures */
    public const int RESULT_TENTATIVE = 0x0100;

    /** Sending failed */
    public const int RESULT_FAILED = 0x1000;

    /**
     * The Message being sent.
     *
     * @var Swift_Mime_SimpleMessage
     */
    private $message;

    /**
     * Any recipients which failed after sending.
     *
     * @var string[]
     */
    private $failedRecipients = [];

    /**
     * The overall result as a bitmask from the class constants.
     *
     * @var int
     */
    private $result;

    /** Whether this message has been rejected by a listener. */
    private bool $rejected = false;

    /** Optional reason for rejection. */
    private ?string $rejectionReason = null;

    /** Optional explicit SMTP envelope. */
    private ?Swift_Envelope $envelope = null;

    /** @var array|null Recipient state before the first recipient policy rewrites the message. */
    private ?array $recipientState = null;

    /** @var array<string, array<string, string|null>> Additional delivery audiences. */
    private array $recipientGroups = [];

    /** @var list<callable(array<string, string|null>): array<string, string|null>> */
    private array $recipientFilters = [];

    /**
     * Create a new SendEvent for $source and $message.
     */
    public function __construct(Swift_Transport $source, Swift_Mime_SimpleMessage $message)
    {
        parent::__construct($source);
        $this->message = $message;
        $this->result  = self::RESULT_PENDING;
    }

    /**
     * Get the Transport used to send the Message.
     *
     * @return Swift_Transport
     */
    public function getTransport()
    {
        return $this->getSource();
    }

    /**
     * Get the Message being sent.
     *
     * @return Swift_Mime_SimpleMessage
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Capture the original To/Cc/Bcc fields once for cooperating recipient policies.
     *
     * Completion listeners run in registration order, so every recipient policy
     * must restore this shared snapshot rather than another policy's temporary fields.
     *
     * @return array{to: array|null, cc: array|null, bcc: array|null}
     */
    public function captureRecipientState(): array
    {
        if (null === $this->recipientState) {
            $message              = $this->getMessage();
            $this->recipientState = [
                'to'  => $message->getTo(),
                'cc'  => $message->getCc(),
                'bcc' => $message->getBcc(),
            ];
        }

        return $this->recipientState;
    }

    /**
     * Set transport-specific audiences, applying policies already registered on this event.
     *
     * @param array<string, array<string, string|null>> $groups
     * @param bool                                      $applyFilters False when recording an already-verified delivery decision
     */
    public function setRecipientGroups(array $groups, bool $applyFilters = true): void
    {
        $this->recipientGroups = [];
        foreach ($groups as $name => $recipients) {
            $this->recipientGroups[$name] = $applyFilters ? $this->filterRecipients($recipients) : $recipients;
        }
    }

    /** @return array<string, array<string, string|null>> */
    public function getRecipientGroups(): array
    {
        return $this->recipientGroups;
    }

    /**
     * Register a reusable policy in listener order, without another send-event dispatch.
     *
     * @param callable(array<string, string|null>): array<string, string|null> $filter
     */
    public function addRecipientFilter(callable $filter): void
    {
        $this->recipientFilters[] = $filter;
        foreach ($this->recipientGroups as $name => $recipients) {
            $this->recipientGroups[$name] = $filter($recipients);
        }
    }

    public function hasRecipientFilters(): bool
    {
        return [] !== $this->recipientFilters;
    }

    /**
     * Apply the same policy to an audience discovered later (for example a stored event).
     *
     * @param array<string, string|null> $recipients
     *
     * @return array<string, string|null>
     */
    public function filterRecipients(array $recipients): array
    {
        foreach ($this->recipientFilters as $filter) {
            $recipients = $filter($recipients);
        }

        return $recipients;
    }

    /**
     * Set the array of addresses that failed in sending.
     *
     * @param array $recipients
     */
    public function setFailedRecipients($recipients)
    {
        $this->failedRecipients = $recipients;
    }

    /**
     * Get an recipient addresses which were not accepted for delivery.
     *
     * @return string[]
     */
    public function getFailedRecipients()
    {
        return $this->failedRecipients;
    }

    /**
     * Set the result of sending.
     *
     * @param int $result
     */
    public function setResult($result)
    {
        $this->result = $result;
    }

    /**
     * Get the result of this Event.
     *
     * The return value is a bitmask from
     * {@see RESULT_PENDING, RESULT_SUCCESS, RESULT_TENTATIVE, RESULT_FAILED}
     *
     * @return int
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Reject this message, preventing it from being sent.
     *
     * Calling this in a beforeSendPerformed listener will prevent
     * the transport from sending the message. Also cancels bubble
     * to stop further listener processing.
     *
     * @param string|null $reason Optional human-readable reason for rejection
     */
    public function reject(?string $reason = null): void
    {
        $this->rejected        = true;
        $this->rejectionReason = $reason;
        $this->cancelBubble(true);
    }

    /**
     * Whether the message has been rejected.
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * Get the rejection reason, if any.
     */
    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /**
     * Set an explicit SMTP envelope for this send operation.
     *
     * Plugins can read this in beforeSendPerformed to inspect or modify
     * the envelope before the transport uses it.
     */
    public function setEnvelope(?Swift_Envelope $envelope): void
    {
        $this->envelope = null !== $envelope ? clone $envelope : null;
    }

    /**
     * Get the explicit SMTP envelope, if one was provided.
     */
    public function getEnvelope(): ?Swift_Envelope
    {
        return null !== $this->envelope ? clone $this->envelope : null;
    }
}
