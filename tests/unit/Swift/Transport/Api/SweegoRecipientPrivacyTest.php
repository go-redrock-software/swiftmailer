<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SweegoRecipientPrivacyTest extends TestCase
{
    private ClientInterface $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
    }

    /** @dataProvider recipientFields */
    public function testRecipientFieldsPreserveTheirRoles(array $to, array $cc, array $bcc, array $expected, int $count, bool $explicit): void
    {
        $message   = $this->message($to, $cc, $bcc);
        $original  = $message->toString();
        $submitted = [];
        $this->client->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, $options) use (&$submitted) {
            $submitted = ['method' => $method, 'url' => $url, 'payload' => $options['json']];

            return new Response(200, [], '{"transaction_id":"privacy-id"}');
        });
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $this->client);
        $failed    = [];

        $this->assertSame($count, $transport->send($message, $failed, $explicit ? \Swift_Envelope::fromMessage($message) : null));
        $this->assertSame('POST', $submitted['method']);
        $this->assertSame('https://api.sweego.io/send', $submitted['url']);
        $this->assertPrivatePayload($submitted['payload'], $expected);
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function recipientFields(): iterable
    {
        foreach ([false, true] as $explicit) {
            yield 'unnamed To / envelope '.(int) $explicit => [
                ['to@safe.test' => null], [], [],
                ['recipients' => [['email' => 'to@safe.test']]], 1, $explicit,
            ];
            yield 'all roles / envelope '.(int) $explicit => [
                ['to@safe.test' => 'Public'], ['cc@safe.test' => 'Copy'], ['blind@safe.test' => 'Private'],
                ['recipients' => [['email' => 'to@safe.test', 'name' => 'Public']], 'cc' => [['email' => 'cc@safe.test', 'name' => 'Copy']], 'bcc' => [['email' => 'blind@safe.test', 'name' => 'Private']]], 3, $explicit,
            ];
            yield 'optional names / envelope '.(int) $explicit => [
                ['zero@safe.test' => '0', 'unnamed@safe.test' => null], ['empty@safe.test' => '', 'copy@safe.test' => 'Copy, Name'], ['private@safe.test' => '0', 'hidden@safe.test' => null],
                ['recipients' => [['email' => 'zero@safe.test', 'name' => '0'], ['email' => 'unnamed@safe.test']], 'cc' => [['email' => 'empty@safe.test'], ['email' => 'copy@safe.test', 'name' => 'Copy, Name']], 'bcc' => [['email' => 'private@safe.test', 'name' => '0'], ['email' => 'hidden@safe.test']]], 6, $explicit,
            ];
            yield 'multiple public recipients / envelope '.(int) $explicit => [
                ['first@safe.test' => 'First', 'second@safe.test' => 'Second'], [], [],
                ['recipients' => [['email' => 'first@safe.test', 'name' => 'First'], ['email' => 'second@safe.test', 'name' => 'Second']]], 2, $explicit,
            ];
        }
    }

    /** @dataProvider missingPublicRecipients */
    public function testNoPublicRecipientIsRejectedBeforeHttp(array $cc, array $bcc, bool $explicit): void
    {
        $message  = $this->message([], $cc, $bcc);
        $original = $message->toString();
        $this->client->expects($this->never())->method('request');
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $this->client);
        $failed    = ['earlier@safe.test'];

        try {
            $transport->send($message, $failed, $explicit ? \Swift_Envelope::fromMessage($message) : null);
            $this->fail('A copy recipient must not be promoted into a public To field.');
        } catch (\Swift_TransportException $error) {
            $this->assertStringContainsString('requires at least one To recipient', $error->getMessage());
        }
        $this->assertSame(\array_merge(['earlier@safe.test'], \array_keys($cc), \array_keys($bcc)), $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function missingPublicRecipients(): iterable
    {
        foreach ([false, true] as $explicit) {
            yield 'Cc only / envelope '.(int) $explicit => [['cc@safe.test' => 'Copy'], [], $explicit];
            yield 'Bcc only / envelope '.(int) $explicit => [[], ['blind@safe.test' => 'Private'], $explicit];
            yield 'both copy roles / envelope '.(int) $explicit => [['cc@safe.test' => 'Copy'], ['blind@safe.test' => 'Private'], $explicit];
        }
        yield 'no recipients' => [[], [], false];
    }

    /** @dataProvider recipientPolicies */
    public function testPoliciesControlEverySubmittedRoleAndRestoreTheMessage(string $policy, bool $failure, bool $explicit): void
    {
        $message = $this->message(
            ['to@safe.test' => 'Public', 'blocked-to@outside.test' => 'Blocked'],
            ['cc@safe.test' => 'Copy', 'blocked-cc@outside.test' => 'Blocked'],
            ['blind@safe.test' => 'Private', 'blocked-blind@outside.test' => 'Blocked'],
        );
        $original = $message->toString();
        $expected = 'redirect' === $policy ? ['recipients' => [['email' => 'sink@safe.test']]] : [
            'recipients' => [['email' => 'to@safe.test', 'name' => 'Public']],
            'cc'         => [['email' => 'cc@safe.test', 'name' => 'Copy']],
            'bcc'        => [['email' => 'blind@safe.test', 'name' => 'Private']],
        ];
        if ('catchall' === $policy) {
            $expected['recipients'][] = ['email' => 'sink@safe.test', 'name' => 'sink@safe.test'];
        }
        $expectedAddresses = 'redirect' === $policy ? ['sink@safe.test'] : ('catchall' === $policy ? ['to@safe.test', 'sink@safe.test', 'cc@safe.test', 'blind@safe.test'] : ['to@safe.test', 'cc@safe.test', 'blind@safe.test']);
        $submitted         = [];
        $this->client->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, $options) use (&$submitted, $failure) {
            $submitted = $options['json'];

            return new Response($failure ? 503 : 200, [], $failure ? '{"message":"provider down"}' : '{"transaction_id":"policy-id"}');
        });
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $this->client);
        $transport->registerPlugin('redirect' === $policy ? new \Swift_Plugins_RedirectingPlugin('sink@safe.test') : new \Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catchall' === $policy ? 'sink@safe.test' : null));
        $failed = [];

        try {
            $count = $transport->send($message, $failed, $explicit ? \Swift_Envelope::fromMessage($message) : null);
            $this->assertFalse($failure, 'The provider failure must be propagated.');
            $this->assertSame(\count($expectedAddresses), $count);
        } catch (\Swift_TransportException $error) {
            if (!$failure) {
                throw $error;
            }
            $this->assertStringContainsString('Sweego API error (503): provider down', $error->getMessage());
        }
        $this->assertPrivatePayload($submitted, $expected);
        $this->assertStringNotContainsString('@outside.test', \json_encode($submitted));
        $this->assertEqualsCanonicalizing($failure ? $expectedAddresses : [], $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function recipientPolicies(): iterable
    {
        foreach (['allowlist', 'catchall', 'redirect'] as $policy) {
            foreach ([false, true] as $failure) {
                foreach ([false, true] as $explicit) {
                    yield $policy.' / failure '.(int) $failure.' / envelope '.(int) $explicit => [$policy, $failure, $explicit];
                }
            }
        }
    }

    /** @dataProvider envelopeOverrides */
    public function testUnsupportedEnvelopeDoesNotSubmitAnyRecipient(string $sender, array $recipients): void
    {
        $message  = $this->message(['to@safe.test' => 'Public'], [], ['blind@safe.test' => 'Private']);
        $original = $message->toString();
        $this->client->expects($this->never())->method('request');
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $this->client);
        $failed    = [];

        try {
            $transport->send($message, $failed, new \Swift_Envelope($sender, $recipients));
            $this->fail('An unsupported envelope must be rejected before HTTP.');
        } catch (\Swift_TransportException $error) {
            $this->assertStringContainsString('does not support', $error->getMessage());
        }
        $this->assertSame($recipients, $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function envelopeOverrides(): iterable
    {
        yield 'recipient override' => ['sender@safe.test', ['override@safe.test']];
        yield 'sender override' => ['other@safe.test', ['to@safe.test', 'blind@safe.test']];
        yield 'both overrides' => ['other@safe.test', ['override@safe.test']];
    }

    /** @dataProvider explicitEnvelopes */
    public function testAllowlistRemovingTheLastToDoesNotPromoteCopyRecipients(bool $explicit): void
    {
        $message  = $this->message(['blocked@outside.test' => 'Blocked'], ['cc@safe.test' => 'Copy'], ['blind@safe.test' => 'Private']);
        $original = $message->toString();
        $this->client->expects($this->never())->method('request');
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $this->client);
        $transport->registerPlugin(new \Swift_Plugins_AllowlistPlugin(['*@safe.test']));
        $failed = [];

        try {
            $transport->send($message, $failed, $explicit ? \Swift_Envelope::fromMessage($message) : null);
            $this->fail('A filtered To list must not expose remaining copy recipients.');
        } catch (\Swift_TransportException $error) {
            $this->assertStringContainsString('requires at least one To recipient', $error->getMessage());
        }
        $this->assertSame(['cc@safe.test', 'blind@safe.test'], $failed);
        $this->assertSame($original, $message->toString());
    }

    /** @dataProvider explicitEnvelopes */
    public function testRedirectCanSafelySupplyAPublicRecipientBeforeValidation(bool $explicit): void
    {
        $message  = $this->message([], [], ['blind@outside.test' => 'Private']);
        $original = $message->toString();
        $this->client->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, $options) {
            $this->assertPrivatePayload($options['json'], ['recipients' => [['email' => 'sink@safe.test']]]);

            return new Response(200, [], '{"transaction_id":"redirect-id"}');
        });
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $this->client);
        $transport->registerPlugin(new \Swift_Plugins_RedirectingPlugin('sink@safe.test'));
        $failed = [];

        $this->assertSame(1, $transport->send($message, $failed, $explicit ? \Swift_Envelope::fromMessage($message) : null));
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function explicitEnvelopes(): iterable
    {
        yield 'implicit' => [false];
        yield 'explicit' => [true];
    }

    private function message(array $to, array $cc, array $bcc): \Swift_Message
    {
        return (new \Swift_Message('Privacy fixture'))->setFrom('sender@safe.test')->setTo($to)->setCc($cc)->setBcc($bcc)->setReplyTo('reply@safe.test')->setBody('Body');
    }

    private function assertPrivatePayload(array $payload, array $expected): void
    {
        foreach (['recipients', 'cc', 'bcc'] as $field) {
            if (isset($expected[$field])) {
                $this->assertSame($expected[$field], $payload[$field] ?? null);
            } else {
                $this->assertArrayNotHasKey($field, $payload);
            }
        }
        $this->assertSame(['Reply-To' => 'reply@safe.test'], $payload['headers']);
        $this->assertSame(['email' => 'sender@safe.test'], $payload['from']);
        $this->assertSame('Privacy fixture', $payload['subject']);
        $this->assertSame('Body', $payload['message-txt']);
        $public = $payload;
        unset($public['bcc']);
        foreach ($expected['bcc'] ?? [] as $recipient) {
            $this->assertStringNotContainsString($recipient['email'], \json_encode($public));
        }
    }
}
