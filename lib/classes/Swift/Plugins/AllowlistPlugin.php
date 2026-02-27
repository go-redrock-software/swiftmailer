<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Restricts email delivery to a configured allowlist of recipients.
 *
 * Intended for dev/staging environments to prevent accidental sends to real users.
 * Supports exact email addresses and domain wildcards (e.g., '*@example.com').
 *
 * Usage:
 *     $plugin = new Swift_Plugins_AllowlistPlugin(['*@mycompany.com', 'tester@gmail.com']);
 *     $mailer->registerPlugin($plugin);
 *
 * Behavior:
 * - Recipients not matching any pattern are removed from To/Cc/Bcc
 * - If no recipients remain, the send is cancelled
 * - Original recipients are restored after sending (message is not permanently modified)
 */
class Swift_Plugins_AllowlistPlugin implements Swift_Events_SendListener
{
    /** @var string[] Lowercased allowlist patterns */
    private array $patterns;

    /** @var array|null Stored original recipients for restoration */
    private ?array $originalRecipients = null;

    /**
     * @param string[] $allowedPatterns Exact addresses or '*@domain' wildcards
     */
    public function __construct(array $allowedPatterns)
    {
        $this->patterns = array_map('strtolower', $allowedPatterns);
    }

    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        $message = $evt->getMessage();

        // Store original recipients
        $this->originalRecipients = [
            'to' => $message->getTo(),
            'cc' => $message->getCc(),
            'bcc' => $message->getBcc(),
        ];

        // Filter each recipient field
        $filteredTo = $this->filterRecipients($this->originalRecipients['to'] ?? []);
        $filteredCc = $this->filterRecipients($this->originalRecipients['cc'] ?? []);
        $filteredBcc = $this->filterRecipients($this->originalRecipients['bcc'] ?? []);

        // If no recipients left at all, cancel the send
        if (empty($filteredTo) && empty($filteredCc) && empty($filteredBcc)) {
            $evt->cancelBubble(true);

            return;
        }

        // Apply filtered recipients
        $message->setTo($filteredTo);

        if (null !== $this->originalRecipients['cc']) {
            $message->setCc($filteredCc);
        }
        if (null !== $this->originalRecipients['bcc']) {
            $message->setBcc($filteredBcc);
        }
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        if (null === $this->originalRecipients) {
            return;
        }

        $message = $evt->getMessage();

        // Restore original recipients
        $message->setTo($this->originalRecipients['to'] ?? []);

        if (null !== $this->originalRecipients['cc']) {
            $message->setCc($this->originalRecipients['cc']);
        }
        if (null !== $this->originalRecipients['bcc']) {
            $message->setBcc($this->originalRecipients['bcc']);
        }

        $this->originalRecipients = null;
    }

    /**
     * Filter recipients, keeping only those matching the allowlist.
     *
     * @param array $recipients ['email' => 'name'] format
     *
     * @return array Filtered recipients
     */
    private function filterRecipients(array $recipients): array
    {
        $filtered = [];

        foreach ($recipients as $email => $name) {
            if ($this->isAllowed((string) $email)) {
                $filtered[$email] = $name;
            }
        }

        return $filtered;
    }

    private function isAllowed(string $email): bool
    {
        $email = strtolower($email);

        foreach ($this->patterns as $pattern) {
            // Exact match
            if ($pattern === $email) {
                return true;
            }

            // Domain wildcard: *@domain
            if (str_starts_with($pattern, '*@')) {
                $domain = substr($pattern, 2);
                $emailDomain = substr($email, strrpos($email, '@') + 1);

                if ($domain === $emailDomain) {
                    return true;
                }
            }
        }

        return false;
    }
}
