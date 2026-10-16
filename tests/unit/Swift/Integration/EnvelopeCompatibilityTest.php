<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__).'/GraphSdkFixture.php';

class Swift_Integration_EnvelopeCompatibilityTest extends TestCase
{
    /** @dataProvider repeatedRecipients */
    public function testEnvelopeDerivedFromHeadersRemainsValidWithRepeatedAddresses(string $kind, string $pattern, bool $repeatEnvelope): void
    {
        $message = $this->message();
        if ('all fields' === $pattern) {
            $message->setBcc('shared@example.test');
        }
        if ('domain case' === $pattern) {
            $message->setTo('shared@EXAMPLE.TEST');
        }
        $original = $message->toString();
        $envelope = Swift_Envelope::fromMessage($message);
        if ($repeatEnvelope) {
            $envelope = new Swift_Envelope($envelope->getSender(), \array_merge($envelope->getRecipients(), $envelope->getRecipients()));
        }
        $transport = $this->transport($kind, $calls);
        $sent      = $transport->send($message, $failed, $envelope);
        $this->assertGreaterThan(0, $sent);
        if ('http' === $kind) {
            $this->assertSame(\count($envelope->getRecipients()), $sent);
        }
        $this->assertSame(1, $calls);
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function repeatedRecipients(): iterable
    {
        foreach (['http', 'gmail', 'graph'] as $kind) {
            foreach (['To and Cc', 'all fields', 'domain case'] as $pattern) {
                foreach ([false, true] as $repeat) {
                    yield $kind.' / '.$pattern.' / repeated envelope '.(int) $repeat => [$kind, $pattern, $repeat];
                }
            }
        }
    }

    /** @dataProvider changedAudiences */
    public function testTrueOverridesStillFailBeforeAnyRequest(string $kind, string $change): void
    {
        $message    = $this->message();
        $recipients = ['shared@example.test', 'blind@example.test'];
        $sender     = 'sender@example.test';
        if ('missing' === $change) {
            $recipients = ['shared@example.test'];
        }
        if ('extra' === $change) {
            $recipients[] = 'extra@example.test';
        }
        if ('sender' === $change) {
            $sender = 'other@example.test';
        }
        if ('local case' === $change) {
            $message->setTo('Shared@example.test');
        }
        $transport = $this->transport($kind, $calls);
        try {
            $transport->send($message, $failed, new Swift_Envelope($sender, $recipients));
            $this->fail('An actual envelope override must not be silently ignored.');
        } catch (Swift_TransportException $error) {
            $this->assertSame(0, $calls);
        }
    }

    public static function changedAudiences(): iterable
    {
        foreach (['http', 'gmail', 'graph'] as $kind) {
            foreach (['missing', 'extra', 'sender', 'local case'] as $change) {
                yield $kind.' / '.$change => [$kind, $change];
            }
        }
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('Compatibility'))->setFrom('sender@example.test')->setTo('shared@example.test')->setCc('shared@example.test')->setBcc('blind@example.test')->setBody('Body');
    }

    private function transport(string $kind, ?int &$calls): Swift_Transport
    {
        $calls = 0;
        if ('graph' === $kind) {
            $fixture = new Swift_GraphSdkFixture(static function () use (&$calls): Response {
                ++$calls;

                return new Response(202);
            });

            return new Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        }
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([static function () use (&$calls): Response {
            ++$calls;

            return new Response(200, ['Content-Type' => 'application/json'], '{"id":"fixture-id"}');
        }]))]);
        if ('gmail' === $kind) {
            $client = new Google\Client();
            $client->setAccessToken(['access_token' => 'fixture-bearer', 'created' => \time(), 'expires_in' => 3600]);
            $client->setHttpClient($http);

            return new Swift_Transport_Api_GoogleTransport($client);
        }

        return new Swift_Transport_Api_ResendTransport('fixture-key', $http);
    }
}
