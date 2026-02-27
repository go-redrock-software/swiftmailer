<?php

class Swift_Plugins_AllowlistPluginTest extends \PHPUnit\Framework\TestCase
{
    private function createSendEvent(Swift_Message $message): Swift_Events_SendEvent
    {
        $transport = $this->createMock(Swift_Transport::class);

        return new Swift_Events_SendEvent($transport, $message);
    }

    public function testAllowedExactEmailPassesThrough()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['dev@example.com' => 'Dev User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertArrayHasKey('dev@example.com', $message->getTo());
        $this->assertFalse($event->bubbleCancelled());
    }

    public function testNonAllowedRecipientIsRemoved()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com' => 'Dev User',
                'real-user@external.com' => 'Real User',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertArrayHasKey('dev@example.com', $to);
        $this->assertArrayNotHasKey('real-user@external.com', $to);
    }

    public function testDomainWildcardAllowsEntireDomain()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'anyone@example.com' => 'Internal',
                'user@external.com' => 'External',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertArrayHasKey('anyone@example.com', $to);
        $this->assertArrayNotHasKey('user@external.com', $to);
    }

    public function testAllRecipientRemovedCancelsSend()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['external@other.com' => 'External User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertTrue($event->bubbleCancelled());
    }

    public function testCcAndBccAreAlsoFiltered()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@safe.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['to@safe.com' => 'Safe To'])
            ->setCc([
                'cc-safe@safe.com' => 'Safe CC',
                'cc-unsafe@other.com' => 'Unsafe CC',
            ])
            ->setBcc([
                'bcc-safe@safe.com' => 'Safe BCC',
                'bcc-unsafe@other.com' => 'Unsafe BCC',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $cc = $message->getCc();
        $this->assertArrayHasKey('cc-safe@safe.com', $cc);
        $this->assertArrayNotHasKey('cc-unsafe@other.com', $cc);

        $bcc = $message->getBcc();
        $this->assertArrayHasKey('bcc-safe@safe.com', $bcc);
        $this->assertArrayNotHasKey('bcc-unsafe@other.com', $bcc);
    }

    public function testOriginalRecipientsRestoredAfterSend()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com' => 'Dev',
                'real@external.com' => 'Real',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        // During send: only dev@example.com
        $this->assertCount(1, $message->getTo());

        // After send: originals restored
        $plugin->sendPerformed($event);
        $to = $message->getTo();
        $this->assertCount(2, $to);
        $this->assertArrayHasKey('dev@example.com', $to);
        $this->assertArrayHasKey('real@external.com', $to);
    }

    public function testMultiplePatternsWork()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin([
            'specific@allowed.com',
            '*@internal.corp',
        ]);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'specific@allowed.com' => 'Specific',
                'anyone@internal.corp' => 'Internal',
                'blocked@external.com' => 'Blocked',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertCount(2, $to);
        $this->assertArrayHasKey('specific@allowed.com', $to);
        $this->assertArrayHasKey('anyone@internal.corp', $to);
    }

    public function testCaseInsensitiveMatching()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['Dev@Example.COM']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['dev@example.com' => 'Dev'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertFalse($event->bubbleCancelled());
    }
}
