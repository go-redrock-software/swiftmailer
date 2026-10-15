<?php

require_once \dirname(__DIR__).'/SesSdkFixture.php';

class Swift_Integration_ApiEnvelopeTest extends PHPUnit\Framework\TestCase
{
    public function testMatchingGmailEnvelopeSubmitsUnchangedMime(): void
    {
        $message  = $this->createMessage();
        $original = $message->toString();
        $handler  = new GuzzleHttp\Handler\MockHandler([function ($request) use ($original) {
            $payload = \json_decode((string) $request->getBody(), true);
            $this->assertSame($original, Swift\base64url_decode($payload['raw']));

            return new GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"id":"gmail-id"}');
        }]);
        $client = new Google\Client();
        $client->setHttpClient(new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create($handler)]));
        $transport = new Swift_Transport_Api_GoogleTransport($client, new Swift_Events_SimpleEventDispatcher());
        $failed    = [];

        $this->assertSame(3, $transport->send($message, $failed, Swift_Envelope::fromMessage($message)));
        $this->assertSame($original, $message->toString());
        $this->assertSame([], $failed);
    }

    public function testMatchingGraphEnvelopeSetsFromAndCountsOnlyDeliveryRecipients(): void
    {
        $fixture   = new Swift_GraphSdkFixture();
        $client    = $fixture->client;
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client, 'user-id', new Swift_Events_SimpleEventDispatcher());
        $message   = $this->createMessage()->setReplyTo('reply@example.com');
        $original  = $message->toString();
        $failed    = [];

        $this->assertSame(3, $transport->send($message, $failed, Swift_Envelope::fromMessage($message)));
        $this->assertSame($original, $message->toString());
        $this->assertSame([], $failed);
        $payload = $fixture->payload()['Message'];
        $this->assertSame('author@example.com', $payload['from']['emailAddress']['address']);
        $this->assertSame('visible@example.com', $payload['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('cc@example.com', $payload['ccRecipients'][0]['emailAddress']['address']);
        $this->assertSame('hidden@example.com', $payload['bccRecipients'][0]['emailAddress']['address']);
        $this->assertSame('reply@example.com', $payload['replyTo'][0]['emailAddress']['address']);
        $this->assertSame(['POST /v1.0/users/user-id/sendMail'], $fixture->paths());
    }

    public function testGraphEnvelopeCannotBeSilentlyUsedForCalendarConversion(): void
    {
        $transport = $this->createSdkTransportWithNoRequests('MicrosoftGraph');
        $transport->enableCalendarEventConversion();
        $message = $this->createMessage();
        $ics     = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:envelope-test\r\nDTSTART:20261015T120000Z\r\nDTEND:20261015T130000Z\r\nSUMMARY:Envelope test\r\nORGANIZER:mailto:author@example.com\r\nATTENDEE:mailto:visible@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $message->attach(new Swift_Attachment($ics, 'invite.ics', 'text/calendar'));
        $failed = [];

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('does not support explicit SMTP envelopes when converting');
        $transport->send($message, $failed, Swift_Envelope::fromMessage($message));
    }

    /** @dataProvider provideSesTransports */
    public function testSesFailureAlwaysRestoresRecipientsAndReportsCompletion(string $kind): void
    {
        $fixture            = new SesSdkFixture();
        $fixture->onRequest = static fn () => throw new RuntimeException('Provider unavailable');
        $client             = $fixture->client;
        $class              = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport          = new $class($client, new Swift_Events_SimpleEventDispatcher());
        $transport->registerPlugin(new Swift_Plugins_AllowlistPlugin(['*@safe.test']));
        $observer = new class implements Swift_Events_SendListener {
            public int $completed = 0;

            public ?int $result = null;

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->completed;
                $this->result = $event->getResult();
            }
        };
        $transport->registerPlugin($observer);
        $message  = (new Swift_Message('Failure regression'))->setFrom('author@safe.test')->setTo(['kept@safe.test' => 'Kept', 'blocked@outside.test' => 'Blocked'])->setBody('Body');
        $original = $message->getTo();
        $failed   = [];
        try {
            $transport->send($message, $failed);
            $this->fail('Provider exception must propagate');
        } catch (Swift_TransportException $e) {
            $cause = $e;
            while (null !== $cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }
            $this->assertSame('Provider unavailable', $cause->getMessage());
        }
        $this->assertSame($original, $message->getTo());
        $this->assertSame(['kept@safe.test'], $failed);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
    }

    /** @dataProvider provideSesTransports */
    public function testSesFailureReportsOnlyTheExplicitDeliveryAudience(string $kind): void
    {
        $fixture            = new SesSdkFixture();
        $fixture->onRequest = static fn () => throw new RuntimeException('Provider unavailable');
        $client             = $fixture->client;
        $class              = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport          = new $class($client, new Swift_Events_SimpleEventDispatcher());
        $message            = $this->createMessage();
        $failed             = [];
        $envelope           = new Swift_Envelope('bounce@example.com', ['delivery@example.com', 'blind@example.com']);

        try {
            $transport->send($message, $failed, $envelope);
            $this->fail('Provider exception must propagate');
        } catch (Swift_TransportException $e) {
            $this->assertSame($envelope->getRecipients(), $failed);
        }
    }

    /** @dataProvider provideSesTransports */
    public function testSesDoesNotReportSuccessWithoutAProviderMessageId(string $kind): void
    {
        $fixture   = new SesSdkFixture([new Symfony\Component\HttpClient\Response\MockResponse('{}', ['http_code' => 200])]);
        $client    = $fixture->client;
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = new $class($client, new Swift_Events_SimpleEventDispatcher());
        $message   = $this->createMessage();
        $failed    = [];

        $this->assertSame(0, $transport->send($message, $failed));
        $this->assertSame(['visible@example.com', 'cc@example.com', 'hidden@example.com'], $failed);
        $this->assertFalse($message->getHeaders()->has('X-SES-Message-ID'));
    }

    /** @dataProvider provideHeaderTransports */
    public function testMatchingEnvelopeKeepsProviderPayloadAndCount(string $provider): void
    {
        $client = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, $options) use ($provider) {
            $payload = \json_encode($options);
            foreach (['author@example.com', 'visible@example.com', 'cc@example.com', 'hidden@example.com'] as $address) {
                $this->assertStringContainsString($address, $payload);
            }

            return new GuzzleHttp\Psr7\Response('MailerSend' === $provider ? 202 : 200, [], \json_encode([
                'id'       => 'provider-id', 'messageId' => 'provider-id', 'ErrorCode' => 0, 'success' => true,
                'Messages' => [['Status' => 'success']],
                'messages' => [['messageId' => 'provider-id', 'status' => ['groupName' => 'PENDING']]],
            ]));
        });
        $transport = $this->createHeaderTransport($provider, $client);
        $message   = $this->createMessage();
        $original  = $message->toString();
        $envelope  = new Swift_Envelope('author@EXAMPLE.COM', ['hidden@EXAMPLE.COM', 'cc@EXAMPLE.COM', 'visible@EXAMPLE.COM']);
        $failed    = [];

        $this->assertSame(3, $transport->send($message, $failed, $envelope));
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
    }

    public static function provideHeaderTransports(): iterable
    {
        foreach (self::provideHeaderTransportsAndOverrides() as [$provider, $sender]) {
            if (!$sender) {
                yield $provider => [$provider];
            }
        }
    }

    public function testInfobipRawEnvelopeControlsRecipientsWithoutReplacingHeaders(): void
    {
        $message = $this->createMessage();
        $message->attach(new Swift_Attachment('Attachment body', 'test.txt', 'text/plain'));
        $original = $message->toString();
        $client   = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://example.com/email/4/mime', $this->callback(function (array $options) use ($message): bool {
            $this->assertSame(['delivery@example.com', 'blind@example.com'], $options['json']['destinations']);
            $this->assertArrayNotHasKey('from', $options['json']);
            $raw = \base64_decode($options['json']['mimeMessage']);
            $this->assertRawHeaders($raw, $message);
            $this->assertStringContainsString('test.txt', $raw);

            return true;
        }))->willReturn(new GuzzleHttp\Psr7\Response(200, [], '{"messages":[{"messageId":"infobip-id","status":{"groupName":"PENDING"}}]}'));
        $transport = $this->createHeaderTransport('InfoBip', $client);
        $failed    = [];
        $envelope  = new Swift_Envelope('author@example.com', ['delivery@example.com', 'blind@example.com', 'delivery@example.com']);

        $this->assertSame(2, $transport->send($message, $failed, $envelope));
        $this->assertSame($original, $message->toString());
        $this->assertSame([], $failed);
    }

    public function testMandrillRawSupportsIndependentSenderRecipientsAndMetadata(): void
    {
        $message = $this->createMessage();
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'transactional');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-reference', 'reference-123');
        $client = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://mandrillapp.com/api/1.0/messages/send-raw.json', $this->callback(function (array $options) use ($message): bool {
            $this->assertSame('bounce@example.com', $options['json']['from_email']);
            $this->assertSame(['delivery@example.com', 'blind@example.com'], $options['json']['to']);
            $raw = $options['json']['raw_message'];
            $this->assertRawHeaders($raw, $message);
            $this->assertStringContainsString('X-MC-Tags: transactional', $raw);
            $this->assertStringContainsString('X-MC-Metadata:', $raw);
            $this->assertStringContainsString('reference-123', $raw);
            $this->assertStringNotContainsString('X-Mailer-', $raw);

            return true;
        }))->willReturn(new GuzzleHttp\Psr7\Response(200, [], '[{"email":"delivery@example.com","status":"sent"},{"email":"blind@example.com","status":"queued"}]'));
        $transport = new Swift_Transport_Api_MailChimpTransport('test-key', $client, new Swift_Events_SimpleEventDispatcher());
        $failed    = [];
        $envelope  = new Swift_Envelope('bounce@example.com', ['delivery@example.com', 'blind@example.com']);

        $this->assertSame(2, $transport->send($message, $failed, $envelope));
        $this->assertSame(['hidden@example.com' => 'Hidden'], $message->getBcc());
    }

    /** @dataProvider provideSdkTransportsAndOverrides */
    public function testSdkRejectsUnsupportedEnvelopeBeforeClientCalls(string $provider, bool $sender): void
    {
        $transport = $this->createSdkTransportWithNoRequests($provider);
        $message   = $this->createMessage();
        $original  = $message->toString();
        $envelope  = new Swift_Envelope($sender ? 'bounce@example.com' : 'author@example.com', $sender ? ['visible@example.com', 'cc@example.com', 'hidden@example.com'] : ['delivery@example.com']);
        $failed    = [];

        try {
            $transport->send($message, $failed, $envelope);
            $this->fail('Unsupported envelope must not reach the SDK');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('does not support', $e->getMessage());
        }
        $this->assertSame($envelope->getRecipients(), $failed);
        $this->assertSame($original, $message->toString());
    }

    /** @dataProvider provideSdkTransports */
    public function testSdkCannotBypassEnvelopeRecipientPolicy(string $provider): void
    {
        $transport = $this->createSdkTransportWithNoRequests($provider);
        $transport->registerPlugin(new Swift_Plugins_AllowlistPlugin(['*@safe.test']));
        $message  = (new Swift_Message('SDK envelope policy'))->setFrom('author@safe.test')->setTo('visible@safe.test');
        $original = $message->getTo();
        $failed   = [];

        $this->assertSame(0, $transport->send($message, $failed, new Swift_Envelope('author@safe.test', ['blocked@outside.test'])));
        $this->assertSame($original, $message->getTo());
        $this->assertSame([], $failed);
    }

    public static function provideSdkTransports(): iterable
    {
        yield 'Gmail' => ['Google'];
        yield 'Microsoft Graph' => ['MicrosoftGraph'];
    }

    public static function provideSdkTransportsAndOverrides(): iterable
    {
        foreach (['Google', 'MicrosoftGraph'] as $provider) {
            yield $provider.' sender' => [$provider, true];
            yield $provider.' recipients' => [$provider, false];
        }
    }

    private function createSdkTransportWithNoRequests(string $provider): Swift_Transport
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        if ('Google' === $provider) {
            $client = $this->createMock(Google\Client::class);
            $client->expects($this->never())->method('execute');

            return new Swift_Transport_Api_GoogleTransport($client, $dispatcher);
        }
        $client = $this->createMock(Microsoft\Graph\GraphServiceClient::class);
        $client->expects($this->never())->method('users');
        $client->expects($this->never())->method('me');

        return new Swift_Transport_Api_MicrosoftGraphTransport($client, null, $dispatcher);
    }

    /** @dataProvider provideSesTransports */
    public function testSesRawEnvelopeControlsDestinationAndBounceAddress(string $kind): void
    {
        $message            = $this->createMessage();
        $original           = $message->toString();
        $fixture            = new SesSdkFixture();
        $fixture->onRequest = function (array $request) use ($message): void {
            $payload = \json_decode($request['body'], true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame(['delivery@example.com', 'blind@example.com'], $payload['Destination']['ToAddresses']);
            $this->assertSame('bounce@example.com', $payload['FeedbackForwardingEmailAddress']);
            $this->assertRawHeaders(\base64_decode($payload['Content']['Raw']['Data'], true), $message);
        };
        $client    = $fixture->client;
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = new $class($client, new Swift_Events_SimpleEventDispatcher());
        $failed    = [];
        $envelope  = new Swift_Envelope('bounce@example.com', ['delivery@example.com', 'blind@example.com', 'delivery@example.com']);

        $this->assertSame(2, $transport->send($message, $failed, $envelope));
        // The HTTP wrapper deliberately adds the provider id; recipient fields stay unchanged.
        $message->getHeaders()->removeAll('X-SES-Message-ID');
        $this->assertSame($original, $message->toString());
    }

    /** @dataProvider provideSesTransportsAndPolicies */
    public function testSesCannotBypassRecipientPolicy(string $kind, bool $explicitEnvelope): void
    {
        $client = $this->createMock(AsyncAws\Ses\SesClient::class);
        $client->expects($this->never())->method('sendEmail');
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = new $class($client, new Swift_Events_SimpleEventDispatcher());
        $transport->registerPlugin(new Swift_Plugins_AllowlistPlugin(['*@safe.test']));
        $message  = (new Swift_Message('Policy regression'))->setFrom('author@safe.test')->setTo($explicitEnvelope ? 'visible@safe.test' : 'blocked@outside.test');
        $original = $message->getTo();
        $envelope = $explicitEnvelope ? new Swift_Envelope('author@safe.test', ['blocked@outside.test']) : null;
        $failed   = [];

        $this->assertSame(0, $transport->send($message, $failed, $envelope));
        $this->assertSame($original, $message->getTo());
        $this->assertSame([], $failed);
    }

    public static function provideSesTransports(): iterable
    {
        yield 'async-aws request object' => ['Api'];
        yield 'array request wrapper' => ['Http'];
    }

    public static function provideSesTransportsAndPolicies(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            yield $kind.' header policy' => [$kind, false];
            yield $kind.' envelope policy' => [$kind, true];
        }
    }

    /** @dataProvider provideHeaderTransportsAndOverrides */
    public function testUnsupportedOverrideNeverSubmitsARequest(string $provider, bool $overrideSender): void
    {
        $client = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->never())->method('request');
        $transport = $this->createHeaderTransport($provider, $client);
        $message   = $this->createMessage();
        $envelope  = new Swift_Envelope(
            $overrideSender ? 'bounce@example.com' : 'author@example.com',
            $overrideSender ? ['visible@example.com', 'cc@example.com', 'hidden@example.com'] : ['delivery@example.com'],
        );
        $original = $message->toString();
        $failed   = [];

        try {
            $transport->send($message, $failed, $envelope);
            $this->fail('An unsupported override must be rejected before submitting a provider request');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('does not support', $e->getMessage());
        }
        $this->assertSame($original, $message->toString());
        $this->assertSame($envelope->getRecipients(), $failed);
    }

    public static function provideHeaderTransportsAndOverrides(): iterable
    {
        foreach (['AhaSend', 'Azure', 'Brevo', 'MailJet', 'MailerSend', 'Mailomat', 'MailPace', 'Mailtrap', 'PostMark', 'Resend', 'Scaleway', 'Sendgrid', 'Sweego'] as $provider) {
            yield $provider.' recipient override' => [$provider, false];
            yield $provider.' sender override' => [$provider, true];
        }
        yield 'Mailgun independent sender unsupported' => ['MailGun', true];
        yield 'Infobip independent sender unsupported' => ['InfoBip', true];
    }

    public function testPostalRawEnvelopeControlsDeliveryWithoutChangingVisibleHeaders(): void
    {
        $message = $this->createMessage();
        $message->attach(new Swift_Attachment('attachment content', 'example.txt'));
        $original = $message->toString();
        $envelope = new Swift_Envelope('bounce@example.com', ['delivery@example.com', 'blind@example.com', 'delivery@example.com']);
        $client   = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://example.com/api/v1/send/raw', $this->callback(function (array $options) use ($message): bool {
            $this->assertSame('bounce@example.com', $options['json']['mail_from']);
            $this->assertSame(['delivery@example.com', 'blind@example.com'], $options['json']['rcpt_to']);
            $raw = \base64_decode($options['json']['data'], true);
            $this->assertRawHeaders($raw, $message);
            $this->assertStringContainsString('example.txt', $raw);
            $this->assertStringContainsString(\base64_encode('attachment content'), $raw);

            return true;
        }))->willReturn(new GuzzleHttp\Psr7\Response(200, [], \json_encode(['status' => 'success', 'data' => [
            'message_id' => 'postal-id', 'messages' => ['delivery@example.com' => ['id' => 1], 'blind@example.com' => ['id' => 2]],
        ]])));
        $transport = new Swift_Transport_Api_PostalTransport('test-key', 'example.com', $client, new Swift_Events_SimpleEventDispatcher());
        $failed    = [];

        $this->assertSame(2, $transport->send($message, $failed, $envelope));
        $this->assertSame($original, $message->toString());
        $this->assertSame(['delivery@example.com', 'blind@example.com', 'delivery@example.com'], $envelope->getRecipients());
    }

    public function testMailgunMimeEnvelopePreservesHeadersAndProviderOptions(): void
    {
        $message = $this->createMessage();
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'transactional');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-reference', 'reference-123');
        $envelope = new Swift_Envelope('author@example.com', ['delivery@example.com', 'blind@example.com', 'delivery@example.com']);
        $client   = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://api.mailgun.net/v3/example.com/messages.mime', $this->callback(function (array $options) use ($message): bool {
            $parts = \array_column($options['multipart'], null, 'name');
            $this->assertSame('delivery@example.com, blind@example.com', $parts['to']['contents']);
            $this->assertSame('message.eml', $parts['message']['filename']);
            $this->assertRawHeaders($parts['message']['contents'], $message);
            $this->assertStringNotContainsString('X-Mailer-', $parts['message']['contents']);
            $this->assertSame('transactional', $parts['o:tag']['contents']);
            $this->assertSame('reference-123', $parts['v:reference']['contents']);

            return true;
        }))->willReturn(new GuzzleHttp\Psr7\Response(200, [], '{"id":"mailgun-id"}'));
        $transport = new Swift_Transport_Api_MailGunTransport('test-key', 'example.com', 'https://api.mailgun.net', $client, new Swift_Events_SimpleEventDispatcher());
        $failed    = [];

        $this->assertSame(2, $transport->send($message, $failed, $envelope));
        $this->assertSame(['hidden@example.com' => 'Hidden'], $message->getBcc());
    }

    private function assertRawHeaders(string $raw, Swift_Mime_SimpleMessage $message): void
    {
        $this->assertStringContainsString('From: Author <author@example.com>', $raw);
        $this->assertStringContainsString('To: Visible <visible@example.com>', $raw);
        $this->assertStringContainsString('Cc: Copy <cc@example.com>', $raw);
        $this->assertStringContainsString('Message-ID: <'.$message->getId().'>', $raw);
        $this->assertStringNotContainsString('Bcc:', $raw);
        $this->assertStringNotContainsString('hidden@example.com', $raw);
        $this->assertStringNotContainsString('delivery@example.com', $raw);
        $this->assertStringNotContainsString('blind@example.com', $raw);
    }

    private function createMessage(): Swift_Message
    {
        return (new Swift_Message('API envelope regression'))->setFrom(['author@example.com' => 'Author'])
            ->setTo(['visible@example.com' => 'Visible'])->setCc(['cc@example.com' => 'Copy'])
            ->setBcc(['hidden@example.com' => 'Hidden'])->setBody('Original body');
    }

    private function createHeaderTransport(string $provider, GuzzleHttp\ClientInterface $client): Swift_Transport
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $class      = 'Swift_Transport_Api_'.$provider.'Transport';

        return match ($provider) {
            'Azure'    => new $class('endpoint=https://azure.example.com;accesskey='.\base64_encode('test-key'), $client, $dispatcher),
            'InfoBip'  => new $class('test-key', 'example.com', $client, $dispatcher),
            'MailJet'  => new $class('test-key', 'test-secret', $client, $dispatcher),
            'MailGun'  => new $class('test-key', 'example.com', 'https://api.mailgun.net', $client, $dispatcher),
            'Mailtrap' => new $class('test-key', false, null, $client, $dispatcher),
            'Scaleway' => new $class('test-key', 'test-project', 'fr-par', $client, $dispatcher),
            default    => new $class('test-key', $client, $dispatcher),
        };
    }
}
