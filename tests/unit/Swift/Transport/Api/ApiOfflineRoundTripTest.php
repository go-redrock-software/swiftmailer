<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Offline end-to-end round-trip for every HTTP API transport.
 *
 * The unit tests mock Guzzle's ClientInterface, so they never run the real
 * request pipeline -- the actual JSON/multipart/header serialization is never
 * produced. This drives each transport through a REAL GuzzleHttp\Client whose
 * responses are canned via MockHandler: the transport performs its real
 * serialization and parses a realistic provider response, with no network and
 * no credentials.
 *
 * It is the offline counterpart of the Mailtrap sandbox smoke test -- the same
 * code path, canned responses instead of a live endpoint -- so every provider
 * here gets end-to-end coverage that can run anywhere.
 */
class Swift_Transport_Api_ApiOfflineRoundTripTest extends TestCase
{
    /**
     * @dataProvider httpTransportProvider
     */
    public function testSendRoundTripsThroughRealGuzzleStack(string $provider): void
    {
        $history   = [];
        $transport = $this->makeTransport($provider, $this->realClient($this->successResponse($provider), $history));

        // The transport parses a realistic provider response into a success outcome.
        $sent = $transport->send($this->basicMessage());
        $this->assertSame(1, $sent, $provider.' should report one recipient sent');

        // Exactly one request, produced by the real Guzzle serialization pipeline.
        $this->assertCount(1, $history, $provider.' should issue exactly one HTTP request');

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod(), $provider.' send must POST');
        $this->assertSame('https', $request->getUri()->getScheme(), $provider.' must use https');
        $this->assertNotSame('', (string) $request->getBody(), $provider.' must serialize a non-empty body');
    }

    public function testAmazonSesHttpRoundTripsThroughRealAsyncAwsStack(): void
    {
        $transport = new Swift_Transport_Api_AmazonSesHttpTransport($this->mockSesClient('{"MessageId":"offline"}'));
        $this->assertSame(1, $transport->send($this->basicMessage()));
    }

    public function testAmazonSesApiRoundTripsThroughRealAsyncAwsStack(): void
    {
        $transport = new Swift_Transport_Api_AmazonSesApiTransport($this->mockSesClient('{"MessageId":"offline"}'));
        $this->assertSame(1, $transport->send($this->basicMessage()));
    }

    public function testGoogleRoundTripsThroughRealApiClientStack(): void
    {
        $history      = [];
        $googleClient = new Google\Client();
        $googleClient->setAccessToken(['access_token' => 'dummy', 'expires_in' => 3600, 'created' => \time()]);
        $googleClient->setHttpClient($this->realClient(new Response(200, [], '{"id":"gmail-id"}'), $history));

        $transport = new Swift_Transport_Api_GoogleTransport($googleClient, $this->stubDispatcher());

        // Gmail send returns the recipient count; the message round-trips through
        // the real google/apiclient HTTP stack (canned response, no network).
        $this->assertSame(1, $transport->send($this->basicMessage()));
    }

    public function testMicrosoftGraphRoundTripsThroughRealSdkStack(): void
    {
        // A real Graph SDK client whose HTTP layer is a Guzzle MockHandler, with an
        // anonymous auth provider so no token is fetched -- fully offline. History
        // middleware captures the request the kiota adapter actually serialized.
        $history = [];
        $stack   = HandlerStack::create(new MockHandler([new Response(202)]));
        $stack->push(Middleware::history($history));
        $adapter = new Microsoft\Graph\GraphRequestAdapter(
            new Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider(),
            new Client(['handler' => $stack]),
        );
        $graphClient = Microsoft\Graph\GraphServiceClient::createWithRequestAdapter($adapter);

        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'sender@example.com', $this->stubDispatcher());
        $transport->send($this->basicMessage());

        // The message was built into Graph SDK models and posted through the real
        // kiota request adapter + Guzzle stack -- one POST to /sendMail, no network.
        $this->assertCount(1, $history, 'Graph should issue exactly one HTTP request');
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('graph.microsoft.com', (string) $request->getUri());
        $this->assertStringContainsString('sendMail', (string) $request->getUri());
        $this->assertNotSame('', (string) $request->getBody(), 'Graph must serialize a non-empty sendMail body');
    }

    public function testMicrosoftGraphSendsWithoutDispatcher(): void
    {
        $guzzle  = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(202)]))]);
        $adapter = new Microsoft\Graph\GraphRequestAdapter(
            new Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider(),
            $guzzle,
        );
        $graphClient = Microsoft\Graph\GraphServiceClient::createWithRequestAdapter($adapter);

        // No event dispatcher: send() must not fatal on its dispatcher derefs
        // (inline transport-start event + the sendPerformed dispatch in finally).
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'sender@example.com');
        $this->assertSame(1, $transport->send($this->basicMessage()));
    }

    private function stubDispatcher(): Swift_Events_EventDispatcher
    {
        $change = $this->createMock(Swift_Events_TransportChangeEvent::class);
        $change->method('bubbleCancelled')->willReturn(false);
        $send = $this->createMock(Swift_Events_SendEvent::class);
        $send->method('bubbleCancelled')->willReturn(false);

        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($change);
        $dispatcher->method('createSendEvent')->willReturn($send);

        return $dispatcher;
    }

    private function mockSesClient(string $body): AsyncAws\Ses\SesClient
    {
        $http = new Symfony\Component\HttpClient\MockHttpClient(
            new Symfony\Component\HttpClient\Response\MockResponse($body, ['http_code' => 200]),
        );

        return new AsyncAws\Ses\SesClient(
            ['region' => 'us-east-1', 'accessKeyId' => 'dummy', 'accessKeySecret' => 'dummy'],
            null,
            $http,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function httpTransportProvider(): array
    {
        $cases = [];
        foreach (self::HTTP_TRANSPORT_KEYS as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    private const array HTTP_TRANSPORT_KEYS = [
        'ahasend', 'azure', 'brevo', 'infobip', 'mailchimp', 'mailgun', 'mailjet',
        'mailpace', 'mailersend', 'mailomat', 'mailtrap', 'postmark', 'postal',
        'resend', 'scaleway', 'sendgrid', 'sweego',
    ];

    /**
     * A real Guzzle client whose single response is canned (no network), with
     * history middleware so the actually-serialized request can be inspected.
     */
    private function realClient(Response $response, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function makeTransport(string $provider, ClientInterface $client): Swift_Transport_AbstractHttpApiTransport
    {
        return match ($provider) {
            'ahasend'    => new Swift_Transport_Api_AhaSendTransport('api-key', $client),
            'azure'      => new Swift_Transport_Api_AzureTransport('endpoint=https://test.communication.azure.com/;accesskey='.\base64_encode('secret'), $client),
            'brevo'      => new Swift_Transport_Api_BrevoTransport('api-key', $client),
            'infobip'    => new Swift_Transport_Api_InfoBipTransport('api-key', 'xyz.api.infobip.com', $client),
            'mailchimp'  => new Swift_Transport_Api_MailChimpTransport('api-key', $client),
            'mailgun'    => new Swift_Transport_Api_MailGunTransport('api-key', 'mail.example.com', 'https://api.mailgun.net', $client),
            'mailjet'    => new Swift_Transport_Api_MailJetTransport('public-key', 'private-key', $client),
            'mailpace'   => new Swift_Transport_Api_MailPaceTransport('api-key', $client),
            'mailersend' => new Swift_Transport_Api_MailerSendTransport('api-key', $client),
            'mailomat'   => new Swift_Transport_Api_MailomatTransport('api-key', $client),
            'mailtrap'   => new Swift_Transport_Api_MailtrapTransport('api-key', false, null, $client),
            'postmark'   => new Swift_Transport_Api_PostMarkTransport('api-key', $client),
            'postal'     => new Swift_Transport_Api_PostalTransport('api-key', 'postal.example.com', $client),
            'resend'     => new Swift_Transport_Api_ResendTransport('api-key', $client),
            'scaleway'   => new Swift_Transport_Api_ScalewayTransport('api-key', 'project-id', 'fr-par', $client),
            'sendgrid'   => new Swift_Transport_Api_SendgridTransport('api-key', $client),
            'sweego'     => new Swift_Transport_Api_SweegoTransport('api-key', $client),
            default      => throw new InvalidArgumentException($provider),
        };
    }

    /**
     * Realistic success responses, matching each provider's documented shape so
     * the transport's response-parsing path actually exercises.
     */
    private function successResponse(string $provider): Response
    {
        return match ($provider) {
            'ahasend'    => new Response(200, [], '{"object":"list","data":[{"object":"message","id":"a","status":"queued"}]}'),
            'azure'      => new Response(202, [], '{"id":"op-id","status":"NotStarted"}'),
            'brevo'      => new Response(201, [], '{"messageId":"b"}'),
            'infobip'    => new Response(200, [], '{"messages":[{"messageId":"ib","status":{"groupName":"PENDING"}}]}'),
            'mailchimp'  => new Response(200, [], '[{"email":"to@example.com","status":"queued","_id":"x"}]'),
            'mailgun'    => new Response(200, [], '{"id":"mg","message":"Queued. Thank you."}'),
            'mailjet'    => new Response(200, [], '{"Messages":[{"Status":"success"}]}'),
            'mailpace'   => new Response(200, [], '{"id":"mp","status":"queued"}'),
            'mailersend' => new Response(202, ['x-message-id' => 'ms']),
            'mailomat'   => new Response(200, [], '{"id":"mo","status":"queued"}'),
            'mailtrap'   => new Response(200, [], '{"success":true,"message_ids":["mt"]}'),
            'postmark'   => new Response(200, [], '{"ErrorCode":0,"MessageID":"pm","Message":"OK"}'),
            'postal'     => new Response(200, [], '{"status":"success","data":{"message_id":"po"}}'),
            'resend'     => new Response(200, [], '{"id":"re"}'),
            'scaleway'   => new Response(200, [], '{"emails":[{"message_id":"sc"}]}'),
            'sendgrid'   => new Response(202),
            'sweego'     => new Response(200, [], '{"transaction_id":"sw"}'),
            default      => throw new InvalidArgumentException($provider),
        };
    }

    private function basicMessage(): Swift_Message
    {
        $message = new Swift_Message('Offline round-trip');
        $message->setFrom(['from@example.com' => 'From']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setBody('Hello, offline world.');

        return $message;
    }
}
