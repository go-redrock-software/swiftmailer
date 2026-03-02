<?php

class Swift_Plugins_AllowlistPluginTest extends PHPUnit\Framework\TestCase
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
                'dev@example.com'        => 'Dev User',
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
                'user@external.com'  => 'External',
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
                'cc-safe@safe.com'    => 'Safe CC',
                'cc-unsafe@other.com' => 'Unsafe CC',
            ])
            ->setBcc([
                'bcc-safe@safe.com'    => 'Safe BCC',
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
                'dev@example.com'   => 'Dev',
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

    public function testRedirectModeReplacesAllRecipients()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin([], 'catchall@dev.example.com');

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'real-user@external.com' => 'Real User',
                'another@external.com'   => 'Another',
            ])
            ->setCc(['cc@external.com' => 'CC'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        // All recipients should be replaced with the catch-all
        $to = $message->getTo();
        $this->assertCount(1, $to);
        $this->assertArrayHasKey('catchall@dev.example.com', $to);

        // Cc and Bcc should be cleared
        $this->assertEmpty($message->getCc());
        $this->assertEmpty($message->getBcc());

        // Original To should be preserved as X-Original-To header
        $header = $message->getHeaders()->get('X-Original-To');
        $this->assertNotNull($header);
        $this->assertStringContainsString('real-user@external.com', $header->getFieldBody());

        // Send should NOT be cancelled
        $this->assertFalse($event->bubbleCancelled());
    }

    public function testRedirectModeRestoresOriginalRecipients()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin([], 'catchall@dev.example.com');

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['real@external.com' => 'Real'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        // After send, originals should be restored
        $plugin->sendPerformed($event);

        $to = $message->getTo();
        $this->assertArrayHasKey('real@external.com', $to);
        $this->assertArrayNotHasKey('catchall@dev.example.com', $to);

        // X-Original-To header should be removed
        $this->assertFalse($message->getHeaders()->has('X-Original-To'));
    }

    public function testRedirectWithAllowlistCombined()
    {
        // Allowed recipients go through normally, non-allowed get redirected
        $plugin = new Swift_Plugins_AllowlistPlugin(
            ['dev@example.com'],
            'catchall@dev.example.com',
        );

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com'   => 'Dev',
                'real@external.com' => 'Real',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        // dev@example.com passes through, real@external.com gets redirected to catch-all
        $this->assertArrayHasKey('dev@example.com', $to);
        $this->assertArrayHasKey('catchall@dev.example.com', $to);
        $this->assertArrayNotHasKey('real@external.com', $to);
    }

    public function testAllRecipientsFilteredUsesRejectWithReason()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['external@other.com' => 'External User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertTrue($event->isRejected());
        $reason = $event->getRejectionReason();
        $this->assertNotNull($reason);
        $this->assertStringContainsString('external@other.com', $reason);
        $this->assertStringContainsString('allowlist', \strtolower($reason));
    }

    public function testAllRecipientsFilteredIncludesAllRemovedAddressesInReason()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'blocked1@other.com' => 'Blocked One',
                'blocked2@other.com' => 'Blocked Two',
            ])
            ->setCc(['blocked3@other.com' => 'Blocked Three'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertTrue($event->isRejected());
        $reason = $event->getRejectionReason();
        $this->assertStringContainsString('blocked1@other.com', $reason);
        $this->assertStringContainsString('blocked2@other.com', $reason);
        $this->assertStringContainsString('blocked3@other.com', $reason);
    }

    public function testPartialFilterDoesNotReject()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com'   => 'Dev',
                'real@external.com' => 'Real',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertFalse($event->isRejected());
        $this->assertNull($event->getRejectionReason());
    }

    public function testPluginImplementsSendListener()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['test@example.com']);
        $this->assertInstanceOf(Swift_Events_SendListener::class, $plugin);
    }

    public function testSendPerformedWithoutBeforeSendIsNoop()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['user@example.com' => 'User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);

        // Should not crash
        $plugin->sendPerformed($event);

        // Message should remain unchanged
        $this->assertArrayHasKey('user@example.com', $message->getTo());
    }

    public function testEmptyAllowlistBlocksAll()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin([]);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['user@example.com' => 'User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertTrue($event->isRejected());
    }

    public function testDomainWildcardDoesNotMatchPartialDomain()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@example.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['user@notexample.com' => 'User'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertTrue($event->isRejected());
    }

    public function testAllowedOnlyCcPreservesToField()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@safe.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['user@unsafe.com' => 'Unsafe'])
            ->setCc(['safe@safe.com' => 'Safe CC'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        // To is empty but CC has someone, so not rejected
        $this->assertFalse($event->isRejected());
        $this->assertEmpty($message->getTo());
    }

    public function testAllowedOnlyBccPreservesMessage()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['bcc@safe.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['user@unsafe.com' => 'Unsafe'])
            ->setBcc(['bcc@safe.com' => 'Safe BCC'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $this->assertFalse($event->isRejected());
    }

    public function testRedirectModeWithNoAllowedRecipients()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin([], 'catchall@dev.example.com');

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['real@external.com' => 'Real'])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);

        $to = $message->getTo();
        $this->assertArrayHasKey('catchall@dev.example.com', $to);
        $this->assertFalse($event->bubbleCancelled());
    }

    public function testMultipleSendsWithSamePlugin()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['dev@example.com']);

        // First send
        $message1 = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo([
                'dev@example.com'   => 'Dev',
                'real@external.com' => 'Real',
            ])
            ->setSubject('Test 1');

        $event1 = $this->createSendEvent($message1);
        $plugin->beforeSendPerformed($event1);
        $this->assertCount(1, $message1->getTo());
        $plugin->sendPerformed($event1);
        $this->assertCount(2, $message1->getTo());

        // Second send
        $message2 = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['dev@example.com' => 'Dev'])
            ->setSubject('Test 2');

        $event2 = $this->createSendEvent($message2);
        $plugin->beforeSendPerformed($event2);
        $this->assertCount(1, $message2->getTo());
        $plugin->sendPerformed($event2);
    }

    public function testCcAndBccRestoredAfterSend()
    {
        $plugin = new Swift_Plugins_AllowlistPlugin(['*@safe.com']);

        $message = (new Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['to@safe.com' => 'To'])
            ->setCc([
                'cc-safe@safe.com'    => 'Safe',
                'cc-unsafe@other.com' => 'Unsafe',
            ])
            ->setBcc([
                'bcc-safe@safe.com'    => 'Safe',
                'bcc-unsafe@other.com' => 'Unsafe',
            ])
            ->setSubject('Test');

        $event = $this->createSendEvent($message);
        $plugin->beforeSendPerformed($event);
        $plugin->sendPerformed($event);

        $cc = $message->getCc();
        $this->assertArrayHasKey('cc-safe@safe.com', $cc);
        $this->assertArrayHasKey('cc-unsafe@other.com', $cc);

        $bcc = $message->getBcc();
        $this->assertArrayHasKey('bcc-safe@safe.com', $bcc);
        $this->assertArrayHasKey('bcc-unsafe@other.com', $bcc);
    }
}
