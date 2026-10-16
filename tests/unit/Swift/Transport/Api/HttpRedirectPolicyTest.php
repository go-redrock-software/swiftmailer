<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_HttpRedirectPolicyTest extends TestCase
{
    private const PROVIDERS = ['ahasend', 'azure', 'brevo', 'infobip', 'mailchimp', 'mailgun', 'mailjet', 'mailersend', 'mailomat', 'mailpace', 'mailtrap', 'postal', 'postmark', 'resend', 'scaleway', 'sendgrid', 'sweego'];

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const TARGETS = [
        'other HTTPS origin'   => 'https://redirect-target.safe.test/collect',
        'HTTP downgrade'       => 'http://redirect-target.safe.test/collect',
        'same origin relative' => '/secondary/send',
        'loopback destination' => 'http://127.0.0.1:8765/collect',
    ];

    /** @dataProvider redirectedSends */
    public function testSendsRefuseRedirectsBeforeAnotherRequest(string $provider, int $status, string $target, bool $explicit): void
    {
        $history   = [];
        $client    = $this->client([new Response($status, ['Location' => $target]), self::successResponse($provider)], $history, $explicit);
        $defaults  = $client->getConfig('allow_redirects');
        $transport = $this->transport($provider, $client);
        $message   = $this->message();
        $this->assertRejected($transport, $message, $explicit, ['allowed@safe.test', 'blocked@outside.test', 'copy@safe.test', 'blind@outside.test']);
        $this->assertCount(1, $history, 'A send must remain at its configured endpoint.');
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame($defaults, $client->getConfig('allow_redirects'), 'Caller-owned client defaults must not be changed.');
        $this->assertSame($client, $transport->httpClient);
    }

    public static function redirectedSends(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            foreach (self::REDIRECT_STATUSES as $status) {
                foreach (self::TARGETS as $label => $target) {
                    foreach ([false, true] as $explicit) {
                        yield $provider.' / '.$status.' / '.$label.' / envelope '.(int) $explicit => [$provider, $status, $target, $explicit];
                    }
                }
            }
        }
    }

    /** @dataProvider redirectedPings */
    public function testHealthChecksRefuseRedirectsBeforeAnotherRequest(string $provider, int $status, string $target): void
    {
        $history   = [];
        $client    = $this->client([new Response($status, ['Location' => $target]), self::pingSuccess($provider)], $history, $status >= 307);
        $defaults  = $client->getConfig('allow_redirects');
        $transport = $this->transport($provider, $client);
        $this->assertFalse($transport->ping());
        $this->assertCount(1, $history, 'An authenticated health check must not follow a Location.');
        $this->assertSame('mailchimp' === $provider ? 'POST' : 'GET', $history[0]['request']->getMethod());
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame($defaults, $client->getConfig('allow_redirects'));
        $this->assertSame($client, $transport->httpClient);
    }

    public static function redirectedPings(): iterable
    {
        foreach (\array_diff(self::PROVIDERS, ['mailpace']) as $provider) {
            foreach (self::REDIRECT_STATUSES as $status) {
                foreach (self::TARGETS as $label => $target) {
                    yield $provider.' / '.$status.' / '.$label => [$provider, $status, $target];
                }
            }
        }
    }

    /** @dataProvider recipientPolicies */
    public function testRedirectFailuresPreserveEffectiveRecipientPolicies(string $provider, string $policy, bool $explicit): void
    {
        $history   = [];
        $client    = $this->client([new Response(307, ['Location' => 'https://redirect-target.safe.test/collect']), self::successResponse($provider)], $history, true);
        $transport = $this->transport($provider, $client);
        $transport->registerPlugin('redirect' === $policy
            ? new Swift_Plugins_RedirectingPlugin('sink@safe.test')
            : new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catchall' === $policy ? 'sink@safe.test' : null));
        $expected = match ($policy) {
            'allowlist' => ['allowed@safe.test', 'copy@safe.test'],
            'catchall'  => ['allowed@safe.test', 'copy@safe.test', 'sink@safe.test'],
            'redirect'  => ['sink@safe.test'],
        };
        $this->assertRejected($transport, $this->message(), $explicit, $expected);
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['options']['allow_redirects']);
    }

    public static function recipientPolicies(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            foreach (['allowlist', 'catchall', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    yield $provider.' / '.$policy.' / envelope '.(int) $explicit => [$provider, $policy, $explicit];
                }
            }
        }
    }

    /** @dataProvider successfulSends */
    public function testValidSendAcknowledgementsStillWork(string $provider, bool $explicit): void
    {
        $history   = [];
        $client    = $this->client([self::successResponse($provider)], $history, true);
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $message   = $this->message();
        $original  = $message->toString();
        $failed    = [];
        $count     = $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
        $this->assertSame('mailchimp' === $provider ? 1 : 4, $count);
        $this->assertSame([], $failed);
        $this->assertSame(1, $observer->sent);
        $this->assertSame(0, $observer->failed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame($original, $message->toString());
    }

    public static function successfulSends(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            foreach ([false, true] as $explicit) {
                yield $provider.' / envelope '.(int) $explicit => [$provider, $explicit];
            }
        }
    }

    /** @dataProvider successfulPings */
    public function testValidHealthAcknowledgementsStillWork(string $provider): void
    {
        $history   = [];
        $transport = $this->transport($provider, $this->client([self::pingSuccess($provider)], $history, true));
        $this->assertTrue($transport->ping());
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['options']['allow_redirects']);
    }

    public static function successfulPings(): iterable
    {
        foreach (\array_diff(self::PROVIDERS, ['mailpace']) as $provider) {
            yield $provider => [$provider];
        }
    }

    public function testMailPacePingStartsLocallyWithoutMakingARequest(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        $transport = $this->transport('mailpace', $client);
        $this->assertTrue($transport->ping());
        $this->assertTrue($transport->isStarted());
    }

    /** @dataProvider azureHealthStatuses */
    public function testAzureHealthAcknowledgesOnlySuccessAndExpectedMissingOperation(int $status, bool $healthy): void
    {
        $history   = [];
        $transport = $this->transport('azure', $this->client([new Response($status)], $history));
        $this->assertSame($healthy, $transport->ping());
        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertTrue($history[0]['request']->hasHeader('Authorization'), 'The health probe remains HMAC-signed.');
    }

    public static function azureHealthStatuses(): iterable
    {
        foreach ([199 => false, 200 => true, 202 => true, 204 => true, 299 => true, 300 => false, 307 => false, 399 => false, 400 => false, 401 => false, 403 => false, 404 => true, 429 => false, 500 => false, 503 => false, 599 => false] as $status => $healthy) {
            yield (string) $status => [$status, $healthy];
        }
    }

    public function testAzureHealthRemainsFalseOnClientFailure(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willThrowException(new RuntimeException('fixture connection failure'));
        $this->assertFalse($this->transport('azure', $client)->ping());
    }

    private function assertRejected(Swift_Transport_AbstractHttpApiTransport $transport, Swift_Message $message, bool $explicit, array $expected): void
    {
        $observer = $this->observe($transport);
        $original = $message->toString();
        $failed   = ['earlier@safe.test'];
        $retry    = new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0);
        try {
            $retry->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('An HTTP redirect must be a transport failure.');
        } catch (Swift_TransportException $error) {
            $marker = false;
            for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
                $marker = $marker || $cause instanceof Swift_Transport_NonRetryableException;
            }
            $this->assertTrue($marker, 'A redirect failure must prevent automatic replay.');
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertSame(\spl_object_id($error), $observer->failureId);
        }
        $this->assertEqualsCanonicalizing(\array_merge(['earlier@safe.test'], $expected), $failed);
        $this->assertEqualsCanonicalizing($failed, $observer->failedAddresses);
        $this->assertSame(1, $observer->before);
        $this->assertSame(0, $observer->sent);
        $this->assertSame(1, $observer->failed);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertEqualsCanonicalizing($expected, $observer->completionFailed);
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class implements Swift_Events_SendListener, Swift_Events_FailedMessageListener, Swift_Events_SentMessageListener {
            public int $before = 0;

            public int $sent = 0;

            public int $failed = 0;

            public int $completed = 0;

            public int $result = 0;

            public ?int $failureId = null;

            public array $failedAddresses = [];

            public array $completionFailed = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->completed;
                $this->result           = $evt->getResult();
                $this->completionFailed = $evt->getFailedRecipients();
            }

            public function failedMessage(Swift_Events_FailedMessageEvent $evt): void
            {
                ++$this->failed;
                $this->failureId       = \spl_object_id($evt->getException());
                $this->failedAddresses = $evt->getFailedRecipients();
            }

            public function sentMessage(Swift_Events_SentMessageEvent $evt): void
            {
                ++$this->sent;
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }

    private function client(array $responses, array &$history, bool $strict = false): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client([
            'handler'         => $stack,
            'allow_redirects' => $strict ? ['max' => 10, 'strict' => true, 'protocols' => ['http', 'https']] : true,
        ]);
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('PRIVATE-FIXTURE-SUBJECT'))
            ->setFrom('sender@safe.test')
            ->setTo(['allowed@safe.test' => 'Allowed', 'blocked@outside.test' => 'Blocked'])
            ->setCc('copy@safe.test')
            ->setBcc('blind@outside.test')
            ->setBody('PRIVATE-FIXTURE-BODY');
    }

    private static function pingSuccess(string $provider): Response
    {
        return match ($provider) {
            'mailchimp' => new Response(200, [], '"PONG!"'),
            'azure'     => new Response(404),
            default     => new Response(200),
        };
    }

    private function transport(string $provider, ClientInterface $client, ?Swift_Events_EventDispatcher $dispatcher = null): Swift_Transport_AbstractHttpApiTransport
    {
        return match ($provider) {
            'ahasend'    => new Swift_Transport_Api_AhaSendTransport('fixture-key', $client, $dispatcher),
            'azure'      => new Swift_Transport_Api_AzureTransport('endpoint=https://fixture.communication.azure.com/;accesskey='.\base64_encode('fixture'), $client, $dispatcher),
            'brevo'      => new Swift_Transport_Api_BrevoTransport('fixture-key', $client, $dispatcher),
            'infobip'    => new Swift_Transport_Api_InfoBipTransport('fixture-key', 'fixture.api.infobip.com', $client, $dispatcher),
            'mailchimp'  => new Swift_Transport_Api_MailChimpTransport('fixture-key', $client, $dispatcher),
            'mailgun'    => new Swift_Transport_Api_MailGunTransport('fixture-key', 'mail.safe.test', 'https://api.mailgun.net', $client, $dispatcher),
            'mailjet'    => new Swift_Transport_Api_MailJetTransport('fixture-public', 'fixture-private', $client, $dispatcher),
            'mailersend' => new Swift_Transport_Api_MailerSendTransport('fixture-key', $client, $dispatcher),
            'mailomat'   => new Swift_Transport_Api_MailomatTransport('fixture-key', $client, $dispatcher),
            'mailpace'   => new Swift_Transport_Api_MailPaceTransport('fixture-key', $client, $dispatcher),
            'mailtrap'   => new Swift_Transport_Api_MailtrapTransport('fixture-key', false, null, $client, $dispatcher),
            'postal'     => new Swift_Transport_Api_PostalTransport('fixture-key', 'postal.safe.test', $client, $dispatcher),
            'postmark'   => new Swift_Transport_Api_PostMarkTransport('fixture-key', $client, $dispatcher),
            'resend'     => new Swift_Transport_Api_ResendTransport('fixture-key', $client, $dispatcher),
            'scaleway'   => new Swift_Transport_Api_ScalewayTransport('fixture-key', 'fixture-project', 'fr-par', $client, $dispatcher),
            'sendgrid'   => new Swift_Transport_Api_SendgridTransport('fixture-key', $client, $dispatcher),
            'sweego'     => new Swift_Transport_Api_SweegoTransport('fixture-key', $client, $dispatcher),
        };
    }

    private static function successResponse(string $provider): Response
    {
        return match ($provider) {
            'ahasend'    => new Response(200, [], '{"data":[{"id":"a-id"}]}'),
            'azure'      => new Response(202, [], '{"id":"az-id","status":"NotStarted"}'),
            'brevo'      => new Response(201, [], '{"messageId":"b-id"}'),
            'infobip'    => new Response(200, [], '{"messages":[{"messageId":"ib-id","status":{"groupName":"PENDING"}}]}'),
            'mailchimp'  => new Response(200, [], '[{"email":"to@safe.test","status":"queued","_id":"mc-id"}]'),
            'mailgun'    => new Response(200, [], '{"id":"mg-id"}'),
            'mailjet'    => new Response(200, [], '{"Messages":[{"Status":"success"}]}'),
            'mailersend' => new Response(202, ['x-message-id' => 'ms-id']),
            'mailomat'   => new Response(200, [], '{"messageUuid":"mo-id"}'),
            'mailpace'   => new Response(200, [], '{"id":123}'),
            'mailtrap'   => new Response(200, [], '{"success":true,"message_ids":["mt-id"]}'),
            'postal'     => new Response(200, [], '{"status":"success","data":{"message_id":"po-id"}}'),
            'postmark'   => new Response(200, [], '{"ErrorCode":0,"MessageID":"pm-id"}'),
            'resend'     => new Response(200, [], '{"id":"re-id"}'),
            'scaleway'   => new Response(200, [], '{"emails":[{"message_id":"sc-id"}]}'),
            'sendgrid'   => new Response(202),
            'sweego'     => new Response(200, [], '{"transaction_id":"4fd747f1-6475-4607-88cf-c8e032fdc236"}'),
        };
    }
}
