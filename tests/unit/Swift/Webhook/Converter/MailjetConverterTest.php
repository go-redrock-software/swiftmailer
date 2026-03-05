<?php

class Swift_Webhook_Converter_MailjetConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_MailjetConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_MailjetConverter();
    }

    // ── Verify tests ────────────────────────────────────────────────

    public function testVerifyReturnsTrueWithValidBasicAuth()
    {
        $secret  = 's3cr3t';
        $headers = ['authorization' => 'Basic '.\base64_encode("mailjet:{$secret}")];

        $this->assertTrue($this->converter->verify('{}', $headers, $secret));
    }

    public function testVerifyReturnsFalseWithWrongPassword()
    {
        $headers = ['authorization' => 'Basic '.\base64_encode('mailjet:wrong-password')];

        $this->assertFalse($this->converter->verify('{}', $headers, 'correct-password'));
    }

    public function testVerifyReturnsFalseWithMissingAuthHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], 'some-secret'));
    }

    public function testVerifyReturnsFalseWithNonBasicAuthScheme()
    {
        $headers = ['authorization' => 'Bearer some-token'];

        $this->assertFalse($this->converter->verify('{}', $headers, 'some-secret'));
    }

    public function testVerifyReturnsFalseWithMalformedBasicAuth()
    {
        // Invalid base64 that does not decode properly
        $headers = ['authorization' => 'Basic !!!'];

        $this->assertFalse($this->converter->verify('{}', $headers, 'some-secret'));
    }

    public function testVerifyReturnsFalseWithEmptyPassword()
    {
        // base64(user:) — empty password portion, but secret is non-empty
        $headers = ['authorization' => 'Basic '.\base64_encode('mailjet:')];

        $this->assertFalse($this->converter->verify('{}', $headers, 'non-empty-secret'));
    }

    // ── Convert tests ───────────────────────────────────────────────

    public function testGetProviderName()
    {
        $this->assertSame('mailjet', $this->converter->getProviderName());
    }

    public function testConvertBounceHardBounce()
    {
        $payload = [
            'event'        => 'bounce',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-600',
            'hard_bounce'  => true,
            'comment'      => '550 User unknown',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('msg-600', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('550 User unknown', $events[0]->getMetadata()['reason']);
    }

    public function testConvertDeliveredEvent()
    {
        $payload = [
            'event'        => 'sent',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-602',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertOpenEvent()
    {
        $payload = [
            'event'        => 'open',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-603',
            'ip'           => '1.2.3.4',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('opened', $events[0]->getName());
    }

    public function testConvertUnknownEventReturnsEmpty()
    {
        $payload = [
            'event'        => 'unknown_event',
            'time'         => 1706000000,
            'email'        => 'user@example.com',
            'Message_GUID' => 'msg-608',
        ];

        $this->assertSame([], $this->converter->convert($payload, []));
    }
}
