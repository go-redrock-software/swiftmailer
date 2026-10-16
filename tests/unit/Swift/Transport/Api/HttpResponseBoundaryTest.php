<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_HttpResponseBoundaryTest extends TestCase
{
    private const PROVIDERS = ['ahasend', 'azure', 'brevo', 'infobip', 'mailchimp', 'mailgun', 'mailjet', 'mailersend', 'mailomat', 'mailpace', 'mailtrap', 'postal', 'postmark', 'resend', 'scaleway', 'sendgrid', 'sweego'];

    /** @dataProvider errorResponses */
    public function testHttpErrorsKeepFailureLifecycle(string $provider, string $body, bool $injected): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(new Response(503, [], $body));
        $dispatcher = $injected ? new Swift_Events_SimpleEventDispatcher() : null;
        $transport  = $this->transport($provider, $client, $dispatcher);
        $observer   = $this->observe($transport);
        if ($injected) {
            $this->assertSame($dispatcher, $transport->eventDispatcher);
        }
        $message  = $this->message();
        $original = $message->toString();
        $failed   = ['earlier@safe.test'];
        $warnings = [];
        \set_error_handler(static function ($severity, $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_WARNING | \E_NOTICE);

        try {
            try {
                $transport->send($message, $failed);
                $this->fail('An HTTP error must not be reported as a successful send.');
            } catch (Swift_TransportException $error) {
                $this->assertSame($error, $observer->failures[0]->getException());
            }
        } finally {
            \restore_error_handler();
        }
        $this->assertSame([], $warnings, 'Remote error values must not trigger PHP warnings.');
        $this->assertSame(['earlier@safe.test', 'to@safe.test'], $failed);
        $this->assertFailureLifecycle($observer, $failed, ['to@safe.test']);
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);
    }

    public static function errorResponses(): iterable
    {
        $bodies = [
            'json string'            => '"upstream unavailable"',
            'true'                   => 'true',
            'false'                  => 'false',
            'integer'                => '503',
            'null'                   => 'null',
            'malformed JSON'         => '{"SECRET-RESPONSE":',
            'plain text'             => 'upstream unavailable',
            'empty body'             => '',
            'empty object'           => '{}',
            'empty list'             => '[]',
            'mixed list'             => '[null,true,3,"value",[],{}]',
            'malformed error fields' => '{"message":[],"Message":[],"error":{"message":[],"code":[]},"errors":"not a list","ErrorCode":[],"Messages":[{"Status":"error","Errors":[{"ErrorMessage":[]}]}],"requestError":{"serviceException":{"text":[]}},"data":{"code":[],"message":[]}}',
        ];
        foreach (self::PROVIDERS as $provider) {
            foreach ([false, true] as $injected) {
                foreach ($bodies as $label => $body) {
                    yield $provider.' / '.$label.' / dispatcher '.(int) $injected => [$provider, $body, $injected];
                }
                yield $provider.' / contradictory success data / dispatcher '.(int) $injected => [$provider, self::successResponse($provider)->getBody()->getContents(), $injected];
            }
        }
    }

    /** @dataProvider validResponses */
    public function testValidProviderResponsesRetainDeliveryMetadata(string $provider, bool $injected): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(self::successResponse($provider));
        $transport = $this->transport($provider, $client, $injected ? new Swift_Events_SimpleEventDispatcher() : null);
        $observer  = $this->observe($transport);
        $message   = $this->message();
        $failed    = [];

        $this->assertSame(1, $transport->send($message, $failed));
        $this->assertSame([], $failed);
        $this->assertCount(0, $observer->failures);
        $this->assertCount(1, $observer->sent);
        $this->assertSame(1, $observer->sent[0]->getSentMessage()->getRecipientCount());
        $this->assertSame(self::messageId($provider), $observer->sent[0]->getSentMessage()->getMessageId());
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->completions[0]['result']);
    }

    public static function validResponses(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            foreach ([false, true] as $injected) {
                yield $provider.' / dispatcher '.(int) $injected => [$provider, $injected];
            }
        }
    }

    /** @dataProvider providerDiagnostics */
    public function testValidProviderErrorDiagnosticsArePreserved(string $provider, string $body): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(new Response(503, [], $body));
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $failed    = [];
        try {
            $transport->send($this->message(), $failed);
            $this->fail('A valid provider error must remain a failure.');
        } catch (Swift_TransportException $error) {
            $this->assertStringContainsString('EXPECTEDDETAIL', $error->getMessage());
        }
        $this->assertFailureLifecycle($observer, ['to@safe.test'], ['to@safe.test']);
    }

    public static function providerDiagnostics(): iterable
    {
        foreach ([
            'ahasend'    => '{"error":{"type":"Expected","message":"EXPECTEDDETAIL"}}',
            'azure'      => '{"error":{"code":"Expected","message":"EXPECTEDDETAIL"}}',
            'brevo'      => '{"code":"Expected","message":"EXPECTEDDETAIL"}',
            'infobip'    => '{"requestError":{"serviceException":{"text":"EXPECTEDDETAIL"}}}',
            'mailchimp'  => '{"status":"error","message":"EXPECTEDDETAIL"}',
            'mailgun'    => '{"message":"EXPECTEDDETAIL"}',
            'mailjet'    => '{"Messages":[{"Status":"error","Errors":[{"ErrorMessage":"EXPECTEDDETAIL"}]}]}',
            'mailersend' => '{"message":"EXPECTEDDETAIL","errors":{"to":["EXPECTEDFIELD"]}}',
            'mailomat'   => '{"message":"EXPECTEDDETAIL"}',
            'mailpace'   => '{"error":"EXPECTEDDETAIL"}',
            'mailtrap'   => '{"errors":["EXPECTEDDETAIL"]}',
            'postal'     => '{"status":"error","data":{"code":"Expected","message":"EXPECTEDDETAIL"}}',
            'postmark'   => '{"ErrorCode":422,"Message":"EXPECTEDDETAIL"}',
            'resend'     => '{"message":"EXPECTEDDETAIL"}',
            'scaleway'   => '{"message":"EXPECTEDDETAIL"}',
            'sendgrid'   => '{"errors":[{"message":"EXPECTEDDETAIL"}]}',
            'sweego'     => '{"message":"EXPECTEDDETAIL","error":"Lower priority error"}',
        ] as $provider => $body) {
            yield $provider => [$provider, $body];
        }
    }

    /** @dataProvider emptyAcknowledgements */
    public function testEmptyResponseBodiesKeepProviderSpecificAcknowledgementRules(string $provider, ?int $count): void
    {
        $valid    = self::successResponse($provider);
        $response = new Response($valid->getStatusCode(), $valid->getHeaders(), '');
        if (null === $count) {
            $this->assertNotReplayed($provider, $response, false);

            return;
        }
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn($response);
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $failed    = [];

        $this->assertSame($count, $transport->send($this->message(), $failed));
        $this->assertSame([], $failed);
        $this->assertCount(0, $observer->failures);
        $this->assertSame($count, $observer->sent[0]->getSentMessage()->getRecipientCount());
    }

    public static function emptyAcknowledgements(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            $count = \in_array($provider, ['infobip', 'mailjet', 'postal', 'postmark'], true) ? null : ('mailchimp' === $provider ? 0 : 1);
            yield $provider => [$provider, $count];
        }
    }

    /** @dataProvider unexpectedRedirects */
    public function testUnexpectedRedirectStatusCannotBeReplayedAsAConfirmedSend(string $provider): void
    {
        $this->assertNotReplayed($provider, self::successResponse($provider)->withStatus(302), false);
    }

    public static function unexpectedRedirects(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            yield $provider => [$provider];
        }
    }

    public function testUnexpectedMailerSendSuccessStatusCannotCauseAReplay(): void
    {
        $this->assertNotReplayed('mailersend', new Response(200, [], '{"message":"unexpected acknowledgement"}'), false);
    }

    public function testExplicitProviderRejectionRemainsDistinctFromAmbiguousJson(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(new Response(200, [], '{"ErrorCode":422,"Message":"EXPECTEDDETAIL"}'));
        $transport = $this->transport('postmark', $client);
        $this->observe($transport);
        $failed = [];
        try {
            $transport->send($this->message(), $failed);
            $this->fail('An explicit provider rejection must fail.');
        } catch (Swift_TransportException $error) {
            $this->assertStringContainsString('EXPECTEDDETAIL', $error->getMessage());
            for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
                $this->assertNotInstanceOf(Swift_Transport_NonRetryableException::class, $cause);
            }
        }
    }

    /** @dataProvider ambiguousResponses */
    public function testMalformedSuccessIsNotAutomaticallyReplayed(string $provider, string $body, bool $explicit): void
    {
        $error = $this->assertNotReplayed($provider, new Response(200, [], $body), $explicit);
        $this->assertStringNotContainsString('SECRET-RESPONSE', $error->getMessage());
    }

    public static function ambiguousResponses(): iterable
    {
        foreach (\array_diff(self::PROVIDERS, ['mailersend', 'sendgrid']) as $provider) {
            foreach (['false', 'null', '{"SECRET-RESPONSE":', 'SECRET-RESPONSE upstream text'] as $body) {
                foreach ([false, true] as $explicit) {
                    yield $provider.' / '.$body.' / envelope '.(int) $explicit => [$provider, $body, $explicit];
                }
            }
        }
    }

    /** @dataProvider contradictoryResponses */
    public function testContradictoryAcceptanceIsNotAutomaticallyReplayed(string $provider, bool $explicit): void
    {
        $response = self::successResponse($provider)->withStatus(503);
        $this->assertNotReplayed($provider, $response, $explicit);
    }

    public static function contradictoryResponses(): iterable
    {
        foreach (['infobip', 'mailchimp', 'mailjet', 'mailtrap', 'postal', 'postmark'] as $provider) {
            foreach ([false, true] as $explicit) {
                yield $provider.' / envelope '.(int) $explicit => [$provider, $explicit];
            }
        }
    }

    /** @dataProvider headerAcknowledgements */
    public function testStatusAndHeaderAcknowledgementsDoNotRequireAJsonBody(string $provider, string $body): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(new Response(202, ['x-message-id' => 'ms-id'], $body));
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $failed    = [];

        $this->assertSame(1, $transport->send($this->message(), $failed));
        $this->assertSame([], $failed);
        $this->assertCount(0, $observer->failures);
        $this->assertSame(self::messageId($provider), $observer->sent[0]->getSentMessage()->getMessageId());
    }

    public static function headerAcknowledgements(): iterable
    {
        foreach (['mailersend', 'sendgrid'] as $provider) {
            foreach (['', 'not JSON', 'true', 'null'] as $body) {
                yield $provider.' / '.$body => [$provider, $body];
            }
        }
    }

    /** @dataProvider policyFailures */
    public function testResponseErrorsReportEffectiveRecipientsAndRestorePolicies(string $provider, string $policy, bool $explicit): void
    {
        $message = $this->message()->setTo(['to@safe.test' => 'Allowed', 'blocked@outside.test' => 'Blocked'])
            ->setCc('cc@safe.test')->setBcc('blind@outside.test');
        $original = $message->toString();
        $client   = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(new Response(503, [], 'true'));
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $transport->registerPlugin('redirect' === $policy ? new Swift_Plugins_RedirectingPlugin('sink@safe.test') : new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catchall' === $policy ? 'sink@safe.test' : null));
        $expected = match ($policy) {
            'allowlist' => ['to@safe.test', 'cc@safe.test'],
            'catchall'  => ['to@safe.test', 'sink@safe.test', 'cc@safe.test'],
            'redirect'  => ['sink@safe.test'],
        };
        $failed = [];
        try {
            $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('The response must produce a transport failure.');
        } catch (Swift_TransportException $error) {
            $this->assertSame($error, $observer->failures[0]->getException());
        }
        $this->assertEqualsCanonicalizing($expected, $failed);
        $this->assertFailureLifecycle($observer, $expected, $expected);
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);
    }

    public static function policyFailures(): iterable
    {
        foreach (self::PROVIDERS as $provider) {
            foreach (['allowlist', 'catchall', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    yield $provider.' / '.$policy.' / envelope '.(int) $explicit => [$provider, $policy, $explicit];
                }
            }
        }
    }

    /** @dataProvider envelopePolicyFailures */
    public function testEnvelopeOverridesUseDeliveryRecipientsForFailureEvents(string $provider, string $policy): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn(new Response(503, [], '"upstream"'));
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $transport->registerPlugin('redirect' === $policy ? new Swift_Plugins_RedirectingPlugin('sink@safe.test') : new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catchall' === $policy ? 'sink@safe.test' : null));
        $message  = $this->message()->setBcc('hidden@outside.test');
        $original = $message->toString();
        $envelope = new Swift_Envelope('sender@safe.test', ['envelope-only@safe.test', 'blocked@outside.test']);
        $expected = match ($policy) {
            'allowlist' => ['envelope-only@safe.test'],
            'catchall'  => ['envelope-only@safe.test', 'sink@safe.test'],
            'redirect'  => ['sink@safe.test'],
        };
        $failed = [];
        try {
            $transport->send($message, $failed, $envelope);
            $this->fail('The response must produce a transport failure.');
        } catch (Swift_TransportException $error) {
            $this->assertSame($error, $observer->failures[0]->getException());
        }
        $this->assertEqualsCanonicalizing($expected, $failed);
        $this->assertFailureLifecycle($observer, $expected, $expected);
        $this->assertSame(['envelope-only@safe.test', 'blocked@outside.test'], $envelope->getRecipients());
        $this->assertSame($original, $message->toString());
    }

    public static function envelopePolicyFailures(): iterable
    {
        foreach (['infobip', 'mailchimp', 'mailgun', 'postal'] as $provider) {
            foreach (['allowlist', 'catchall', 'redirect'] as $policy) {
                yield $provider.' / '.$policy => [$provider, $policy];
            }
        }
    }

    /** @dataProvider invalidIdentifiers */
    public function testContainerIdentifiersFailWithoutReplayOrTypeError(string $provider, string $body, int $status, bool $explicit): void
    {
        $this->assertNotReplayed($provider, new Response($status, [], $body), $explicit);
    }

    public static function invalidIdentifiers(): iterable
    {
        $paths = [
            'ahasend'  => ['data', 0, 'id'], 'azure' => ['id'], 'brevo' => ['messageId'],
            'infobip'  => ['messages', 0, 'messageId'], 'mailgun' => ['id'], 'mailomat' => ['messageUuid'],
            'mailpace' => ['id'], 'mailtrap' => ['message_ids', 0], 'postal' => ['data', 'message_id'],
            'postmark' => ['MessageID'], 'resend' => ['id'], 'scaleway' => ['emails', 0, 'message_id'], 'sweego' => ['transaction_id'],
        ];
        foreach ($paths as $provider => $path) {
            $response = self::successResponse($provider);
            $data     = \json_decode($response->getBody()->getContents(), true);
            $value    = &$data;
            foreach ($path as $key) {
                $value = &$value[$key];
            }
            $value = ['invalid identifier'];
            unset($value);
            foreach ([false, true] as $explicit) {
                yield $provider.' / envelope '.(int) $explicit => [$provider, \json_encode($data), $response->getStatusCode(), $explicit];
            }
        }
    }

    /** @dataProvider invalidContainers */
    public function testMalformedSuccessContainersAreNotSilentlyAccepted(string $provider, string $body, bool $explicit): void
    {
        $this->assertNotReplayed($provider, new Response(200, [], $body), $explicit);
    }

    public static function invalidContainers(): iterable
    {
        foreach ([
            'ahasend'   => '{"data":"invalid list"}',
            'infobip'   => '{"messages":"invalid list"}',
            'mailtrap'  => '{"success":true,"message_ids":"invalid list"}',
            'scaleway'  => '{"emails":"invalid list"}',
            'postal'    => '{"status":"success","data":"invalid object"}',
            'mailchimp' => '["invalid recipient result"]',
            'mailjet'   => '{"Messages":[{"Status":[]}]}',
            'postmark'  => '{"ErrorCode":[]}',
        ] as $provider => $body) {
            foreach ([false, true] as $explicit) {
                yield $provider.' / envelope '.(int) $explicit => [$provider, $body, $explicit];
            }
        }
    }

    /** @dataProvider invalidPostalCounts */
    public function testInvalidPostalRecipientCountsDoNotCauseTypeError(string $messages): void
    {
        $body = '{"status":"success","data":{"messages":'.$messages.'}}';
        $this->assertNotReplayed('postal', new Response(200, [], $body), true);
    }

    public static function invalidPostalCounts(): iterable
    {
        yield 'string' => ['"invalid list"'];
        yield 'boolean' => ['true'];
        yield 'integer' => ['42'];
    }

    /** @dataProvider unreadableResponses */
    public function testUnreadableResponsesKeepLimitsAndDoNotReplayAcceptedRequests(string $mode, int $status): void
    {
        $body = $this->createMock(Psr\Http\Message\StreamInterface::class);
        $body->method('getSize')->willReturn('known size' === $mode ? 1048577 : null);
        $body->method('eof')->willReturn(false);
        if ('known size' === $mode) {
            $body->expects($this->never())->method('read');
        } elseif ('read failure' === $mode) {
            $body->method('read')->willThrowException(new RuntimeException('SECRET-RESPONSE stream failed'));
        } else {
            $body->method('read')->willReturnCallback(static fn ($length): string => \str_repeat('x', $length));
        }
        if (200 === $status) {
            $error = $this->assertNotReplayed('sweego', new Response($status, [], $body), true);
            $this->assertStringNotContainsString('SECRET-RESPONSE', $error->getMessage());
        } else {
            $client = $this->createMock(ClientInterface::class);
            $client->expects($this->once())->method('request')->willReturn(new Response($status, [], $body));
            $transport = $this->transport('sweego', $client);
            $observer  = $this->observe($transport);
            $failed    = [];
            try {
                $transport->send($this->message(), $failed);
                $this->fail('An unreadable HTTP error response must fail normally.');
            } catch (Swift_TransportException $error) {
                $this->assertStringNotContainsString('SECRET-RESPONSE', $error->getMessage());
            }
            $this->assertFailureLifecycle($observer, ['to@safe.test'], ['to@safe.test']);
            $this->assertSame(['to@safe.test'], $failed);
        }
    }

    public static function unreadableResponses(): iterable
    {
        foreach (['known size', 'unknown size overflow', 'read failure'] as $mode) {
            foreach ([200, 503] as $status) {
                yield $mode.' / '.$status => [$mode, $status];
            }
        }
    }

    public function testProgrammingTypeErrorIsNotConvertedIntoAnApiFailure(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        $transport = new class('fixture-key', $client) extends Swift_Transport_Api_SweegoTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
            {
                throw new TypeError('intentional programming error');
            }
        };
        $observer = $this->observe($transport);
        $failed   = [];
        try {
            $transport->send($this->message(), $failed);
            $this->fail('A programming error must remain visible.');
        } catch (TypeError $error) {
            $this->assertSame('intentional programming error', $error->getMessage());
        }
        $this->assertSame([], $failed);
        $this->assertCount(0, $observer->failures);
    }

    public function testNamespacedNonRetryableMarkerIsAvailableAndHonoredWhenWrapped(): void
    {
        $marker  = new Swift\Transport\NonRetryableException('connection timed out', 503);
        $wrapped = new Swift_TransportException('outer connection failure', 0, $marker);
        $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $marker);
        $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($wrapped));
    }

    private function assertNotReplayed(string $provider, Response $response, bool $explicit): Swift_TransportException
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn($response);
        $transport = $this->transport($provider, $client);
        $observer  = $this->observe($transport);
        $retry     = new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0);
        $message   = $this->message();
        $original  = $message->toString();
        $failed    = [];
        try {
            $retry->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
            $this->fail('An ambiguous response must not be reported as a successful send.');
        } catch (Swift_TransportException $error) {
            $marker = null;
            for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof Swift_Transport_NonRetryableException) {
                    $marker = $cause;
                    break;
                }
            }
            $this->assertNotNull($marker, 'The failure must prevent an automatic replay.');
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertSame(['to@safe.test'], $failed);
        $this->assertFailureLifecycle($observer, ['to@safe.test'], ['to@safe.test']);
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);

        return $error;
    }

    private function assertFailureLifecycle(object $observer, array $allFailures, array $attemptFailures): void
    {
        $this->assertSame(1, $observer->before);
        $this->assertCount(1, $observer->failures);
        $this->assertEqualsCanonicalizing($allFailures, $observer->failures[0]->getFailedRecipients());
        $this->assertCount(0, $observer->sent);
        $this->assertCount(1, $observer->completions);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->completions[0]['result']);
        $this->assertEqualsCanonicalizing($attemptFailures, $observer->completions[0]['failed']);
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class implements Swift_Events_SendListener, Swift_Events_FailedMessageListener, Swift_Events_SentMessageListener {
            public int $before = 0;

            public array $failures = [];

            public array $sent = [];

            public array $completions = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                $this->completions[] = ['result' => $evt->getResult(), 'failed' => $evt->getFailedRecipients()];
            }

            public function failedMessage(Swift_Events_FailedMessageEvent $evt): void
            {
                $this->failures[] = $evt;
            }

            public function sentMessage(Swift_Events_SentMessageEvent $evt): void
            {
                $this->sent[] = $evt;
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('Response boundary'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setBody('Body');
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

    private static function messageId(string $provider): ?string
    {
        return match ($provider) {
            'ahasend'                          => 'a-id', 'azure' => 'az-id', 'brevo' => 'b-id', 'infobip' => 'ib-id',
            'mailchimp', 'mailjet', 'sendgrid' => null,
            'mailgun'                          => 'mg-id', 'mailersend' => 'ms-id', 'mailomat' => 'mo-id', 'mailpace' => '123',
            'mailtrap'                         => 'mt-id', 'postal' => 'po-id', 'postmark' => 'pm-id', 'resend' => 're-id', 'scaleway' => 'sc-id',
            'sweego'                           => '4fd747f1-6475-4607-88cf-c8e032fdc236',
        };
    }
}
