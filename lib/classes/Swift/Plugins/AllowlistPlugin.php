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
 *
 * Supports exact email addresses and domain wildcards (e.g., '*@example.com').
 *
 * Usage:
 *
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

    private ?string $redirectTo;

    /** @var array|null Stored original recipients for restoration */
    private ?array $originalRecipients = null;

    /**
     * @param string[]    $allowedPatterns Exact addresses or '*@domain' wildcards
     * @param string|null $redirectTo      Optional catch-all address for non-allowed recipients
     */
    public function __construct(array $allowedPatterns, ?string $redirectTo = null)
    {
        $this->patterns   = \array_map('strtolower', $allowedPatterns);
        $this->redirectTo = $redirectTo;
    }

    #[Override]
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        $message = $evt->getMessage();
        $evt->captureRecipientState();

        // Store original recipients
        $this->originalRecipients = [
            'to'  => $message->getTo(),
            'cc'  => $message->getCc(),
            'bcc' => $message->getBcc(),
        ];

        $envelope = $evt->getEnvelope();
        if (null !== $envelope && !$this->filterEnvelope($evt, $envelope)) {
            return;
        }

        if (null !== $this->redirectTo) {
            $this->applyRedirect($message);

            return;
        }

        // Filter each recipient field
        $filteredTo  = $this->filterRecipients($this->originalRecipients['to'] ?? []);
        $filteredCc  = $this->filterRecipients($this->originalRecipients['cc'] ?? []);
        $filteredBcc = $this->filterRecipients($this->originalRecipients['bcc'] ?? []);

        // If no recipients left at all, reject with reason
        if (null === $envelope && empty($filteredTo) && empty($filteredCc) && empty($filteredBcc)) {
            $allOriginalAddresses = \array_keys(\array_merge(
                $this->originalRecipients['to']  ?? [],
                $this->originalRecipients['cc']  ?? [],
                $this->originalRecipients['bcc'] ?? [],
            ));

            $evt->reject(\sprintf(
                'AllowlistPlugin: all recipients removed by allowlist filter (%s)',
                \implode(', ', $allOriginalAddresses),
            ));

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

    #[Override]
    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        if (null === $this->originalRecipients) {
            return;
        }

        $message = $evt->getMessage();

        // Restore original recipients
        foreach ($evt->captureRecipientState() as $field => $recipients) {
            if (null === $recipients) {
                $message->getHeaders()->removeAll($field);
            } else {
                $message->{'set'.\ucfirst($field)}($recipients);
            }
        }

        $this->originalRecipients = null;
    }

    private function filterEnvelope(Swift_Events_SendEvent $evt, Swift_Envelope $envelope): bool
    {
        $original   = $envelope->getRecipients();
        $recipients = \array_values(\array_filter($original, fn (string $address): bool => $this->isAllowed($address)));
        if (null !== $this->redirectTo && \count($recipients) < \count($original) && !\in_array($this->redirectTo, $recipients, true)) {
            $recipients[] = $this->redirectTo;
        }

        if (!$recipients) {
            $evt->reject('AllowlistPlugin: all envelope recipients removed by allowlist filter');

            return false;
        }

        $evt->setEnvelope(new Swift_Envelope($envelope->getSender(), $recipients));

        return true;
    }

    private function applyRedirect(Swift_Mime_SimpleMessage $message): void
    {
        $filtered      = [];
        $needsRedirect = false;
        foreach ($this->originalRecipients as $field => $recipients) {
            $filtered[$field] = $this->filterRecipients($recipients ?? []);
            if (\count($filtered[$field]) < \count($recipients ?? [])) {
                $needsRedirect = true;
            }
        }

        // Allowed recipients go through, add catch-all for any non-allowed
        $newTo = $filtered['to'];
        if ($needsRedirect && !\array_key_exists($this->redirectTo, $newTo)) {
            $newTo[$this->redirectTo] = $this->redirectTo;
        }

        $message->setTo($newTo);
        if (null !== $this->originalRecipients['cc']) {
            $message->setCc($filtered['cc']);
        }
        if (null !== $this->originalRecipients['bcc']) {
            $message->setBcc($filtered['bcc']);
        }
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
        $email = \strtolower($email);

        foreach ($this->patterns as $pattern) {
            // Exact match
            if ($pattern === $email) {
                return true;
            }

            // Domain wildcard: *@domain
            if (\str_starts_with($pattern, '*@')) {
                $domain      = \substr($pattern, 2);
                $emailDomain = \substr($email, \strrpos($email, '@') + 1);

                if ($domain === $emailDomain) {
                    return true;
                }
            }
        }

        return false;
    }
}
