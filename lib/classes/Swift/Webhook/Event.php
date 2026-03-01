<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Represents a parsed webhook event from an email service provider.
 *
 * Two types: 'delivery' (bounced, delivered, deferred, dropped) and
 * 'engagement' (opened, clicked, unsubscribed, complained).
 */
readonly class Swift_Webhook_Event
{
    private const array VALID_TYPES = ['delivery', 'engagement'];

    public function __construct(
        private string $type,
        private string $name,
        private string $messageId,
        private string $recipient,
        private array $metadata,
        private DateTimeImmutable $timestamp,
        private array $rawPayload,
    ) {
        if (!\in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(\sprintf('Invalid event type "%s". Valid types: %s', $type, \implode(', ', self::VALID_TYPES)));
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    public function isDelivery(): bool
    {
        return 'delivery' === $this->type;
    }

    public function isEngagement(): bool
    {
        return 'engagement' === $this->type;
    }
}
