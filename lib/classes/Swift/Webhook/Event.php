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
class Swift_Webhook_Event
{
    private const VALID_TYPES = ['delivery', 'engagement'];

    public function __construct(
        private readonly string $type,
        private readonly string $name,
        private readonly string $messageId,
        private readonly string $recipient,
        private readonly array $metadata,
        private readonly \DateTimeImmutable $timestamp,
        private readonly array $rawPayload,
    ) {
        if (!\in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid event type "%s". Valid types: %s', $type, implode(', ', self::VALID_TYPES))
            );
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

    public function getTimestamp(): \DateTimeImmutable
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
