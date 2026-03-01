<?php

class Swift_Webhook_Converter_MailPaceConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailPaceConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailPaceConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('mailpace', $this->converter->getProviderName());
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event'   => 'email.delivered',
            'payload' => [
                'status'     => 'delivered',
                'id'         => 1,
                'message_id' => '<msg-1200@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'from'       => 'sender@example.com',
                'subject'    => 'Hello',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
        $this->assertSame('<msg-1200@mailer.mailpace.com>', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
    }

    public function testConvertBouncedEvent()
    {
        $payload = [
            'event'   => 'email.bounced',
            'payload' => [
                'status'     => 'bounced',
                'message_id' => '<msg-1201@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('bounced', $events[0]->getName());
    }

    public function testConvertDeferredEvent()
    {
        $payload = [
            'event'   => 'email.deferred',
            'payload' => [
                'status'     => 'deferred',
                'message_id' => '<msg-1202@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('deferred', $events[0]->getName());
    }

    public function testConvertSpamEvent()
    {
        $payload = [
            'event'   => 'email.spam',
            'payload' => [
                'status'     => 'spam',
                'message_id' => '<msg-1203@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertSame('dropped', $events[0]->getName());
    }

    public function testSkipsQueuedEvent()
    {
        $payload = [
            'event'   => 'email.queued',
            'payload' => [
                'status'     => 'queued',
                'message_id' => '<msg-1204@mailer.mailpace.com>',
                'to'         => 'user@example.com',
                'created_at' => '2026-01-15T10:30:00.000Z',
                'updated_at' => '2026-01-15T10:30:05.000Z',
            ],
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }

    public function testVerifyValidEd25519Signature()
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 verification.');
        }

        // Generate an Ed25519 keypair for testing
        $keypair    = \sodium_crypto_sign_keypair();
        $privateKey = \sodium_crypto_sign_secretkey($keypair);
        $publicKey  = \sodium_crypto_sign_publickey($keypair);

        $body      = '{"event":"email.delivered"}';
        $signature = \base64_encode(\sodium_crypto_sign_detached($body, $privateKey));

        // Secret is the base64-encoded public key
        $secret  = \base64_encode($publicKey);
        $headers = ['x-mailpace-signature' => $signature];

        $this->assertTrue($this->converter->verify($body, $headers, $secret));
    }

    public function testVerifyInvalidSignature()
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 verification.');
        }

        $keypair   = \sodium_crypto_sign_keypair();
        $publicKey = \sodium_crypto_sign_publickey($keypair);
        $secret    = \base64_encode($publicKey);

        $headers = ['x-mailpace-signature' => \base64_encode(\str_repeat("\0", 64))];

        $this->assertFalse($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyMissingHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], \base64_encode(\str_repeat("\0", 32))));
    }
}
