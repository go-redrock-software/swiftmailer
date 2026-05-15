<?php

class Swift_Plugins_RedirectingPluginTest extends PHPUnit\Framework\TestCase
{
    public function testRecipientCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_RedirectingPlugin('fabien@example.com');
        $this->assertEquals('fabien@example.com', $plugin->getRecipient());
        $plugin->setRecipient('chris@example.com');
        $this->assertEquals('chris@example.com', $plugin->getRecipient());
    }

    public function testPluginChangesRecipients()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setTo($to = [
                'fabien-to@example.com' => 'Fabien (To)',
                'chris-to@example.com'  => 'Chris (To)',
            ])
            ->setCc($cc = [
                'fabien-cc@example.com' => 'Fabien (Cc)',
                'chris-cc@example.com'  => 'Chris (Cc)',
            ])
            ->setBcc($bcc = [
                'fabien-bcc@example.com' => 'Fabien (Bcc)',
                'chris-bcc@example.com'  => 'Chris (Bcc)',
            ])
            ->setBody('...')
        ;

        $plugin = new Swift_Plugins_RedirectingPlugin('god@example.com');

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($message->getTo(), ['god@example.com' => '']);
        $this->assertEquals($message->getCc(), []);
        $this->assertEquals($message->getBcc(), []);

        $plugin->sendPerformed($evt);

        $this->assertEquals($message->getTo(), $to);
        $this->assertEquals($message->getCc(), $cc);
        $this->assertEquals($message->getBcc(), $bcc);
    }

    public function testPluginRespectsUnsetToList()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setCc($cc = [
                'fabien-cc@example.com' => 'Fabien (Cc)',
                'chris-cc@example.com'  => 'Chris (Cc)',
            ])
            ->setBcc($bcc = [
                'fabien-bcc@example.com' => 'Fabien (Bcc)',
                'chris-bcc@example.com'  => 'Chris (Bcc)',
            ])
            ->setBody('...')
        ;

        $plugin = new Swift_Plugins_RedirectingPlugin('god@example.com');

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($message->getTo(), ['god@example.com' => '']);
        $this->assertEquals($message->getCc(), []);
        $this->assertEquals($message->getBcc(), []);

        $plugin->sendPerformed($evt);

        $this->assertEquals($message->getTo(), []);
        $this->assertEquals($message->getCc(), $cc);
        $this->assertEquals($message->getBcc(), $bcc);
    }

    public function testPluginRespectsAWhitelistOfPatterns()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setTo($to = [
                'fabien-to@example.com' => 'Fabien (To)',
                'chris-to@example.com'  => 'Chris (To)',
                'lars-to@internal.com'  => 'Lars (To)',
            ])
            ->setCc($cc = [
                'fabien-cc@example.com' => 'Fabien (Cc)',
                'chris-cc@example.com'  => 'Chris (Cc)',
                'lars-cc@internal.org'  => 'Lars (Cc)',
            ])
            ->setBcc($bcc = [
                'fabien-bcc@example.com' => 'Fabien (Bcc)',
                'chris-bcc@example.com'  => 'Chris (Bcc)',
                'john-bcc@example.org'   => 'John (Bcc)',
            ])
            ->setBody('...')
        ;

        $recipient = 'god@example.com';
        $patterns  = ['/^.*@internal.[a-z]+$/', '/^john-.*$/'];

        $plugin = new Swift_Plugins_RedirectingPlugin($recipient, $patterns);

        $this->assertEquals($recipient, $plugin->getRecipient());
        $this->assertEquals($plugin->getWhitelist(), $patterns);

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals($message->getTo(), ['lars-to@internal.com' => 'Lars (To)', 'god@example.com' => null]);
        $this->assertEquals($message->getCc(), ['lars-cc@internal.org' => 'Lars (Cc)']);
        $this->assertEquals($message->getBcc(), ['john-bcc@example.org' => 'John (Bcc)']);

        $plugin->sendPerformed($evt);

        $this->assertEquals($message->getTo(), $to);
        $this->assertEquals($message->getCc(), $cc);
        $this->assertEquals($message->getBcc(), $bcc);
    }

    public function testArrayOfRecipientsCanBeExplicitlyDefined()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setTo([
                'fabien@example.com'   => 'Fabien',
                'chris@example.com'    => 'Chris (To)',
                'lars-to@internal.com' => 'Lars (To)',
            ])
            ->setCc([
                'fabien@example.com'   => 'Fabien',
                'chris-cc@example.com' => 'Chris (Cc)',
                'lars-cc@internal.org' => 'Lars (Cc)',
            ])
            ->setBcc([
                'fabien@example.com'    => 'Fabien',
                'chris-bcc@example.com' => 'Chris (Bcc)',
                'john-bcc@example.org'  => 'John (Bcc)',
            ])
            ->setBody('...')
        ;

        $recipients = ['god@example.com', 'fabien@example.com'];
        $patterns   = ['/^.*@internal.[a-z]+$/'];

        $plugin = new Swift_Plugins_RedirectingPlugin($recipients, $patterns);

        $evt = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertEquals(
            $message->getTo(),
            ['fabien@example.com' => 'Fabien', 'lars-to@internal.com' => 'Lars (To)', 'god@example.com' => null],
        );
        $this->assertEquals(
            $message->getCc(),
            ['fabien@example.com' => 'Fabien', 'lars-cc@internal.org' => 'Lars (Cc)'],
        );
        $this->assertEquals($message->getBcc(), ['fabien@example.com' => 'Fabien']);
    }

    public function testWhitelistCanBeSetAndFetched()
    {
        $plugin = new Swift_Plugins_RedirectingPlugin('god@example.com');
        $this->assertEquals([], $plugin->getWhitelist());
        $patterns = ['/^.*@internal\.com$/'];
        $plugin->setWhitelist($patterns);
        $this->assertEquals($patterns, $plugin->getWhitelist());
    }

    public function testPluginImplementsSendListener()
    {
        $plugin = new Swift_Plugins_RedirectingPlugin('test@example.com');
        $this->assertInstanceOf(Swift_Events_SendListener::class, $plugin);
    }

    public function testEmptyWhitelistRedirectsEverything()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setTo([
                'alice@example.com' => 'Alice',
                'bob@example.com'   => 'Bob',
            ])
            ->setBody('...');

        $plugin = new Swift_Plugins_RedirectingPlugin('redirect@example.com');
        $evt    = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $to = $message->getTo();
        $this->assertArrayHasKey('redirect@example.com', $to);
        $this->assertArrayNotHasKey('alice@example.com', $to);
        $this->assertArrayNotHasKey('bob@example.com', $to);
    }

    public function testRecipientCanBeChangedBetweenSends()
    {
        $plugin = new Swift_Plugins_RedirectingPlugin('first@example.com');
        $this->assertEquals('first@example.com', $plugin->getRecipient());

        $plugin->setRecipient('second@example.com');
        $this->assertEquals('second@example.com', $plugin->getRecipient());
    }

    public function testMessageWithOnlyBcc()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setBcc(['secret@example.com' => 'Secret'])
            ->setBody('...');

        $plugin = new Swift_Plugins_RedirectingPlugin('redirect@example.com');
        $evt    = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $this->assertArrayHasKey('redirect@example.com', $message->getTo());
        $this->assertEquals([], $message->getBcc());
    }

    public function testRedirectingPluginDoesNotLeakBccInHeaders()
    {
        $message = (new Swift_Message())
            ->setSubject('...')
            ->setFrom(['john@example.com' => 'John Doe'])
            ->setTo(['to@example.com' => 'To'])
            ->setCc(['cc@example.com' => 'Cc'])
            ->setBcc(['bcc@example.com' => 'Bcc'])
            ->setBody('...');

        $plugin = new Swift_Plugins_RedirectingPlugin('redirect@example.com');
        $evt    = $this->createSendEvent($message);

        $plugin->beforeSendPerformed($evt);

        $headers = $message->getHeaders();
        $this->assertFalse($headers->has('X-Swift-To'), 'X-Swift-To header must not be present');
        $this->assertFalse($headers->has('X-Swift-Cc'), 'X-Swift-Cc header must not be present');
        $this->assertFalse($headers->has('X-Swift-Bcc'), 'X-Swift-Bcc header must not be present');

        $plugin->sendPerformed($evt);

        $this->assertFalse($headers->has('X-Swift-To'));
        $this->assertFalse($headers->has('X-Swift-Cc'));
        $this->assertFalse($headers->has('X-Swift-Bcc'));
    }

    private function createSendEvent(Swift_Mime_SimpleMessage $message)
    {
        $evt = $this->getMockBuilder('Swift_Events_SendEvent')
            ->disableOriginalConstructor()
            ->getMock();
        $evt->expects($this->any())
            ->method('getMessage')
            ->willReturn($message);

        return $evt;
    }
}
