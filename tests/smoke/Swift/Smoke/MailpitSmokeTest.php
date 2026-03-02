<?php

require_once __DIR__.'/../../mailpit_bootstrap.php';

/**
 * Smoke test that sends real emails via Mailpit and verifies delivery via its REST API.
 *
 * Requires Mailpit running: docker compose -f docker-compose.test.yml up -d
 */
class Swift_Smoke_MailpitSmokeTest extends PHPUnit\Framework\TestCase
{
    private Swift_Mailer $mailer;

    protected function setUp(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not running at '.MAILPIT_API_URL);
        }

        $this->purgeMailpit();

        $transport    = new Swift_SmtpTransport(MAILPIT_SMTP_HOST, MAILPIT_SMTP_PORT);
        $this->mailer = new Swift_Mailer($transport);
    }

    public function testSendBasicEmail(): void
    {
        $message = (new Swift_Message('Smoke Test'))
            ->setFrom(['smoke@example.com' => 'Smoke Sender'])
            ->setTo(['recipient@example.com' => 'Smoke Recipient'])
            ->setBody('Hello from Swiftmailer smoke test!');

        $sent = $this->mailer->send($message);
        $this->assertSame(1, $sent);

        // Verify via Mailpit API
        $messages = $this->getMailpitMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('Smoke Test', $messages[0]['Subject']);
    }

    public function testSendHtmlEmail(): void
    {
        $message = (new Swift_Message('HTML Smoke Test'))
            ->setFrom(['smoke@example.com' => 'Smoke Sender'])
            ->setTo(['recipient@example.com' => 'Smoke Recipient'])
            ->setBody('<h1>Hello</h1><p>HTML email from Swiftmailer.</p>', 'text/html');

        $sent = $this->mailer->send($message);
        $this->assertSame(1, $sent);

        $messages = $this->getMailpitMessages();
        $this->assertCount(1, $messages);
    }

    public function testSendWithAttachment(): void
    {
        $message = (new Swift_Message('Attachment Smoke Test'))
            ->setFrom(['smoke@example.com' => 'Smoke Sender'])
            ->setTo(['recipient@example.com' => 'Smoke Recipient'])
            ->setBody('Email with attachment')
            ->attach(new Swift_Attachment('test content', 'test.txt', 'text/plain'));

        $sent = $this->mailer->send($message);
        $this->assertSame(1, $sent);

        $messages = $this->getMailpitMessages();
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]['Attachments'] > 0);
    }

    public function testSendToMultipleRecipients(): void
    {
        $message = (new Swift_Message('Multi-Recipient Smoke Test'))
            ->setFrom(['smoke@example.com' => 'Smoke Sender'])
            ->setTo(['a@example.com' => 'A', 'b@example.com' => 'B'])
            ->setCc(['c@example.com' => 'C'])
            ->setBody('Multi-recipient test');

        $sent = $this->mailer->send($message);
        $this->assertSame(3, $sent);
    }

    private function isMailpitAvailable(): bool
    {
        $ch = \curl_init(MAILPIT_API_URL.'/api/v1/messages');
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 2,
            \CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $result = \curl_exec($ch);
        $code   = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        \curl_close($ch);

        return false !== $result && 200 === $code;
    }

    private function purgeMailpit(): void
    {
        $ch = \curl_init(MAILPIT_API_URL.'/api/v1/messages');
        \curl_setopt_array($ch, [
            \CURLOPT_CUSTOMREQUEST  => 'DELETE',
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 5,
        ]);
        \curl_exec($ch);
        \curl_close($ch);
    }

    private function getMailpitMessages(): array
    {
        // Brief pause to allow Mailpit to process
        \usleep(200_000);

        $ch = \curl_init(MAILPIT_API_URL.'/api/v1/messages');
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 5,
        ]);
        $response = \curl_exec($ch);
        \curl_close($ch);

        $data = \json_decode($response, true);

        return $data['messages'] ?? [];
    }
}
