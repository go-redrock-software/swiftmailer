<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

require_once __DIR__.'/../../mailtrap_bootstrap.php';

/**
 * Live smoke test: send a real message through the Mailtrap sandbox API and
 * (optionally) verify it was captured by the inbox.
 *
 * This is the first end-to-end proof that an HTTP API transport's payload is
 * accepted by a real provider endpoint -- the unit and contract tests only check
 * request shape, never a live round-trip. The sandbox captures rather than
 * delivers, so it is safe to run repeatedly.
 *
 * Skipped unless MAILTRAP_SANDBOX_TOKEN and MAILTRAP_INBOX_ID are set (see
 * tests/smoke/mailtrap_bootstrap.php).
 */
class Swift_Smoke_MailtrapSandboxSmokeTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        if ('' === MAILTRAP_SANDBOX_TOKEN || '' === MAILTRAP_INBOX_ID) {
            $this->markTestSkipped(
                'Set MAILTRAP_SANDBOX_TOKEN and MAILTRAP_INBOX_ID to run the Mailtrap sandbox smoke test.',
            );
        }
    }

    public function testSandboxAcceptsAndCapturesMessage(): void
    {
        // Unique subject so the read-back step can find exactly this message.
        $subject = 'Swiftmailer sandbox smoke '.\bin2hex(\random_bytes(8));

        $transport = new Swift_Transport_Api_MailtrapTransport(
            MAILTRAP_SANDBOX_TOKEN,
            true,
            (string) MAILTRAP_INBOX_ID,
        );
        $mailer = new Swift_Mailer($transport);

        $message = (new Swift_Message($subject))
            ->setFrom(['smoke@example.com' => 'Swift Smoke'])
            ->setTo(['recipient@example.com' => 'Recipient'])
            ->setBody('<p>Live Mailtrap sandbox smoke test.</p>', 'text/html')
            ->addPart('Live Mailtrap sandbox smoke test.', 'text/plain');

        // Tier 1: the real Mailtrap sandbox endpoint must accept our payload.
        $sent = $mailer->send($message);
        $this->assertSame(1, $sent, 'Mailtrap sandbox should accept and queue the message.');

        // Tier 2 (opt-in): confirm the message actually landed in the inbox.
        if ('' === MAILTRAP_ACCOUNT_ID) {
            return;
        }

        $subjects = \array_column($this->fetchInboxMessages(), 'subject');
        $this->assertContains(
            $subject,
            $subjects,
            'The sent message should be captured in the Mailtrap sandbox inbox.',
        );
    }

    /**
     * Read the sandbox inbox via the Mailtrap API.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchInboxMessages(): array
    {
        // Give Mailtrap a moment to index the freshly-sent message.
        \usleep(750_000);

        $url = \sprintf(
            '%s/accounts/%s/inboxes/%s/messages',
            \rtrim(MAILTRAP_API_URL, '/'),
            MAILTRAP_ACCOUNT_ID,
            MAILTRAP_INBOX_ID,
        );

        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER     => ['Api-Token: '.MAILTRAP_API_TOKEN, 'Accept: application/json'],
            \CURLOPT_TIMEOUT        => 10,
        ]);
        $response = \curl_exec($ch);
        $code     = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        \curl_close($ch);

        $this->assertSame(200, $code, 'Mailtrap read API should return 200 (check MAILTRAP_API_URL / MAILTRAP_API_TOKEN).');

        return \json_decode((string) $response, true) ?: [];
    }
}
