<?php

class Swift_Integration_ApiDefaultDispatcherTest extends PHPUnit\Framework\TestCase
{
    /** @dataProvider provideProvidersAndEnvelopes */
    public function testDefaultConstructorPolicyBlocksDeliveryBeforeProviderRequests(string $provider, bool $explicit): void
    {
        $transport = $this->transport($provider);
        $this->assertNull($transport->eventDispatcher);
        $transport->registerPlugin(new Swift_Plugins_AllowlistPlugin(['*@safe.test']));
        $message = $this->message();
        if ($explicit) {
            $message->setTo('visible@safe.test')->setCc([])->setBcc([]);
        }
        $original = $message->toString();
        $envelope = $explicit ? new Swift_Envelope('author@safe.test', ['blocked@outside.test']) : null;
        $failed   = [];

        $this->assertSame(0, $transport->send($message, $failed, $envelope));
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
        $this->assertInstanceOf(Swift_Events_SimpleEventDispatcher::class, $transport->eventDispatcher);
        if ($envelope) {
            $this->assertSame(['blocked@outside.test'], $envelope->getRecipients());
        }
    }

    /** @dataProvider provideProvidersAndEnvelopes */
    public function testDefaultConstructorRedirectsActualProviderPayloadAndRestoresHeaders(string $provider, bool $explicit): void
    {
        $captured  = null;
        $transport = $this->transport($provider, static function (string $payload) use (&$captured): void {
            $captured = $payload;
        });
        $transport->registerPlugin(new Swift_Plugins_RedirectingPlugin('sink@safe.test'));
        $dispatcher = $transport->eventDispatcher;
        $observer   = $this->observer();
        $transport->registerPlugin($observer);
        $transport->registerPlugin($observer); // The reused simple dispatcher deduplicates this instance.
        $message          = $this->message();
        $original         = [$message->getTo(), $message->getCc(), $message->getBcc()];
        $failed           = [];
        $envelope         = $explicit ? Swift_Envelope::fromMessage($message) : null;
        $originalEnvelope = $envelope?->getRecipients();

        $this->assertSame(1, $transport->send($message, $failed, $envelope));
        $this->assertStringContainsString('sink@safe.test', $captured);
        $this->assertStringNotContainsString('@outside.test', $captured);
        $this->assertSame([], $failed);
        $this->assertSame($original, [$message->getTo(), $message->getCc(), $message->getBcc()]);
        $this->assertSame($dispatcher, $transport->eventDispatcher);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
        $this->assertSame($originalEnvelope, $envelope?->getRecipients());
    }

    /** @dataProvider provideProvidersAndEnvelopes */
    public function testDefaultConstructorFailureRunsCleanupOnceAndReportsRedirectAudience(string $provider, bool $explicit): void
    {
        $captured  = null;
        $transport = $this->transport($provider, static function (string $payload) use (&$captured): void {
            $captured = $payload;
        }, true);
        $transport->registerPlugin(new Swift_Plugins_RedirectingPlugin('sink@safe.test'));
        $observer = $this->observer();
        $transport->registerPlugin($observer);
        $message          = $this->message();
        $original         = $message->toString();
        $failed           = [];
        $envelope         = $explicit ? Swift_Envelope::fromMessage($message) : null;
        $originalEnvelope = $envelope?->getRecipients();
        try {
            $sent = $transport->send($message, $failed, $envelope);
            $this->assertSame('Google', $provider, 'Only the existing Gmail error path returns zero instead of throwing');
            $this->assertSame(0, $sent);
        } catch (Swift_TransportException $e) {
            $this->assertNotSame('Google', $provider);
        }
        $this->assertStringContainsString('sink@safe.test', $captured);
        $this->assertStringNotContainsString('@outside.test', $captured);
        $this->assertSame($original, $message->toString());
        $this->assertSame(['sink@safe.test'], $failed);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame($failed, $observer->failures);
        $this->assertSame($originalEnvelope, $envelope?->getRecipients());
    }

    public function testDsnFactoryRetainsItsDispatcherAndEnforcesRegisteredPolicy(): void
    {
        $transport  = (new Swift_Transport_DsnTransportFactory())->fromDsnString('sendgrid://mock-key@default');
        $dispatcher = $transport->eventDispatcher;
        $client     = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->never())->method('request');
        $transport->httpClient = $client;
        $transport->registerPlugin(new Swift_Plugins_AllowlistPlugin(['*@safe.test']));

        $this->assertInstanceOf(Swift_Events_SimpleEventDispatcher::class, $dispatcher);
        $this->assertSame($dispatcher, $transport->eventDispatcher);
        $this->assertSame(0, $transport->send($this->message()));
    }

    public static function provideProviders(): iterable
    {
        foreach (['AhaSend', 'Azure', 'Brevo', 'InfoBip', 'MailChimp', 'MailGun', 'MailJet', 'MailPace', 'MailerSend', 'Mailomat', 'Mailtrap', 'PostMark', 'Postal', 'Resend', 'Scaleway', 'Sendgrid', 'Sweego', 'AmazonSesApi', 'AmazonSesHttp', 'Google', 'MicrosoftGraph'] as $provider) {
            yield $provider => [$provider];
        }
    }

    public static function provideProvidersAndEnvelopes(): iterable
    {
        foreach (self::provideProviders() as [$provider]) {
            yield $provider.' headers' => [$provider, false];
            yield $provider.' envelope' => [$provider, true];
        }
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('Default API dispatcher'))->setFrom('author@safe.test')
            ->setTo(['to@outside.test' => 'Public'])->setCc(['cc@outside.test' => 'Copy'])
            ->setBcc(['bcc@outside.test' => 'Blind'])->setBody('Body');
    }

    private function observer(): Swift_Events_SendListener
    {
        return new class implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public ?int $result = null;

            public array $failures = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->completed;
                $this->result   = $evt->getResult();
                $this->failures = $evt->getFailedRecipients();
            }
        };
    }

    /** Create real transports with default dispatcher options and mocked provider clients. */
    private function transport(string $provider, ?callable $inspect = null, bool $fail = false): Swift_Transport_AbstractApiTransport
    {
        $class = 'Swift_Transport_Api_'.$provider.'Transport';
        if (\str_starts_with($provider, 'AmazonSes')) {
            $client = $this->createMock(AsyncAws\Ses\SesClient::class);
            if (null === $inspect) {
                $client->expects($this->never())->method('sendEmail');
            } else {
                $response = $this->getMockBuilder(AsyncAws\Ses\Result\SendEmailResponse::class)->disableOriginalConstructor()->onlyMethods(['getMessageId'])->getMock();
                $response->method('getMessageId')->willReturn('ses-id');
                $client->expects($this->once())->method('sendEmail')->willReturnCallback(static function ($request) use ($inspect, $fail, $response) {
                    $destination = \is_array($request) ? $request['Destination'] : [
                        'ToAddresses'  => $request->getDestination()->getToAddresses(),
                        'CcAddresses'  => $request->getDestination()->getCcAddresses(),
                        'BccAddresses' => $request->getDestination()->getBccAddresses(),
                    ];
                    $raw = \is_array($request) ? ($request['Content']['Raw']['Data'] ?? null) : $request->getContent()->getRaw()?->getData();
                    $inspect(\json_encode(['Destination' => $destination, 'Raw' => $raw]));
                    if ($fail) {
                        throw new RuntimeException('Provider failure');
                    }

                    return $response;
                });
            }

            return new $class($client);
        }
        if (\in_array($provider, ['Google', 'MicrosoftGraph'], true)) {
            if (null === $inspect) {
                if ('Google' === $provider) {
                    $client = $this->createMock(Google\Client::class);
                    $client->expects($this->never())->method('execute');
                } else {
                    $client = $this->createMock(Microsoft\Graph\GraphServiceClient::class);
                    $client->expects($this->never())->method('me');
                    $client->expects($this->never())->method('users');
                }

                return new $class($client);
            }
            $handler = static function ($request) use ($provider, $inspect, $fail) {
                $body = (string) $request->getBody();
                $inspect('Google' === $provider ? Swift\base64url_decode(\json_decode($body, true)['raw']) : $body);
                if ($fail) {
                    throw new RuntimeException('Provider failure');
                }

                return new GuzzleHttp\Psr7\Response('Google' === $provider ? 200 : 202, ['Content-Type' => 'application/json'], '{"id":"sdk-id"}');
            };
            $http = new GuzzleHttp\Client(['handler' => new GuzzleHttp\Handler\MockHandler([$handler])]);
            if ('Google' === $provider) {
                $client = new Google\Client();
                $client->setHttpClient($http);
            } else {
                $adapter = new Microsoft\Graph\GraphRequestAdapter(new Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider(), $http);
                $client  = Microsoft\Graph\GraphServiceClient::createWithRequestAdapter($adapter);
            }

            return new $class($client);
        }

        $client = $this->createMock(GuzzleHttp\ClientInterface::class);
        if (null === $inspect) {
            $client->expects($this->never())->method('request');
        } else {
            $client->expects($this->once())->method('request')->willReturnCallback(static function ($method, $url, $options) use ($provider, $inspect, $fail) {
                $payload = \json_encode($options);
                foreach (['data', 'mimeMessage'] as $field) {
                    if (isset($options['json'][$field])) {
                        $payload .= "\n".\base64_decode($options['json'][$field]);
                    }
                }
                $inspect($payload);
                if ($fail) {
                    throw new RuntimeException('Provider failure');
                }
                $data = match ($provider) {
                    'MailChimp' => [['email' => 'sink@safe.test', 'status' => 'sent']],
                    'Postal'    => ['status' => 'success', 'data' => ['message_id' => 'postal-id']],
                    default     => ['id' => 'http-id', 'messageId' => 'http-id', 'ErrorCode' => 0, 'success' => true,
                        'Messages'       => [['Status' => 'success']],
                        'messages'       => [['messageId' => 'http-id', 'status' => ['groupName' => 'PENDING']]]],
                };

                return new GuzzleHttp\Psr7\Response('MailerSend' === $provider ? 202 : 200, [], \json_encode($data));
            });
        }

        return match ($provider) {
            'Azure'    => new $class('endpoint=https://azure.example.com;accesskey='.\base64_encode('mock-key'), $client),
            'InfoBip'  => new $class('mock-key', 'example.com', $client),
            'Postal'   => new $class('mock-key', 'example.com', $client),
            'MailGun'  => new $class('mock-key', 'example.com', 'https://api.mailgun.net', $client),
            'MailJet'  => new $class('mock-key', 'mock-secret', $client),
            'Mailtrap' => new $class('mock-key', false, null, $client),
            'Scaleway' => new $class('mock-key', 'mock-project', 'fr-par', $client),
            default    => new $class('mock-key', $client),
        };
    }
}
