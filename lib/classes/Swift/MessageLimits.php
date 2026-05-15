<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Opt-in validator that enforces size and count limits on a message
 * to prevent denial-of-service via oversized payloads.
 */
class Swift_MessageLimits
{
    public int $maxBodySize = 10485760;
    public int $maxAttachmentSize = 26214400;
    public int $maxTotalSize = 52428800;
    public int $maxAttachmentCount = 50;
    public int $maxRecipientCount = 1000;

    public function validate(Swift_Mime_SimpleMessage $message): void
    {
        $recipientCount = \count($message->getTo() ?? [])
            + \count($message->getCc() ?? [])
            + \count($message->getBcc() ?? []);
        if ($recipientCount > $this->maxRecipientCount) {
            throw new Swift_SwiftException(
                \sprintf('Message has %d recipients, maximum is %d', $recipientCount, $this->maxRecipientCount)
            );
        }

        $bodySize = \strlen($message->getBody() ?? '');
        if ($bodySize > $this->maxBodySize) {
            throw new Swift_SwiftException(
                \sprintf('Message body is %d bytes, maximum is %d', $bodySize, $this->maxBodySize)
            );
        }

        $attachmentCount = 0;
        $totalSize = $bodySize;
        foreach ($message->getChildren() ?? [] as $child) {
            if ($child instanceof Swift_Attachment || $child instanceof Swift_Image) {
                ++$attachmentCount;
                $attachSize = \strlen($child->getBody() ?? '');
                $totalSize += $attachSize;
                if ($attachSize > $this->maxAttachmentSize) {
                    throw new Swift_SwiftException(
                        \sprintf('Attachment "%s" is %d bytes, maximum is %d',
                            $child->getFilename(), $attachSize, $this->maxAttachmentSize)
                    );
                }
            }
        }
        if ($attachmentCount > $this->maxAttachmentCount) {
            throw new Swift_SwiftException(
                \sprintf('Message has %d attachments, maximum is %d', $attachmentCount, $this->maxAttachmentCount)
            );
        }
        if ($totalSize > $this->maxTotalSize) {
            throw new Swift_SwiftException(
                \sprintf('Total message size is %d bytes, maximum is %d', $totalSize, $this->maxTotalSize)
            );
        }
    }
}
