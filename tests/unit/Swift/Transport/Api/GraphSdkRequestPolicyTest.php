<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestInformation;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_Api_GraphSdkRequestPolicyTest extends TestCase
{
    /** @dataProvider redirects */
    public function testRedirectStopsTheOperationWithoutForwardingOrReplaying(int $status, string $target, string $stage, bool $callerRetry): void
    {
        [$message, $responses, $calendar] = $this->scenario($stage);
        $beforeRequests                   = \count($responses);
        $responses[]                      = new Response($status, ['Location' => $target]);
        for ($i = 0; $i < 12; ++$i) {
            $responses[] = $this->json(200, ['id' => 'unexpected-id', 'value' => [], 'nextExpectedRanges' => []]);
        }
        $wire                                     = [];
        [$client, $adapter, $http, $stack, $auth] = $this->sdk($responses, $wire, $callerRetry);
        $snapshot                                 = [$http->getConfig(), (string) $stack, $adapter->getBaseUrl()];
        $transport                                = new Swift_Transport_Api_MicrosoftGraphTransport($client, 'sender@safe.test');
        $transport->setLargeAttachmentThreshold(16);
        if ($calendar) {
            $transport->enableCalendarEventConversion();
        }
        $observer = $this->observe($transport);
        $original = $message->toString();
        $failed   = [];
        $retry    = new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0);
        try {
            $retry->send($message, $failed);
            $this->fail('A redirect must not be accepted or replayed.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertSame($status, $error->getCode());
            $this->assertStringNotContainsString('fixture-access-token', $error->getMessage());
            $this->assertStringNotContainsString('PRIVATE-GRAPH-BODY', $error->getMessage());
        }
        $this->assertCount($beforeRequests + 1, $wire);
        foreach ($wire as $request) {
            $this->assertFalse($request['options']['allow_redirects']);
            $this->assertContains($request['request']->getUri()->getHost(), ['graph.microsoft.com', 'uploads.safe.test']);
        }
        $this->assertEqualsCanonicalizing(['allowed@safe.test', 'blocked@outside.test', 'copy@safe.test', 'blind@outside.test'], $failed);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame($original, $message->toString());
        $this->assertSame($client, (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport));
        $this->assertSame($adapter, $client->getRequestAdapter());
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack, $adapter->getBaseUrl()]);
        $this->assertCount($beforeRequests + 1, $auth->contexts);
    }

    public static function redirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            foreach (['HTTPS origin' => 'https://other.safe.test/collect', 'HTTP downgrade' => 'http://other.safe.test/collect', 'relative' => '/secondary', 'loopback' => 'http://127.0.0.1:8765/collect'] as $label => $target) {
                foreach (['sendMail', 'draft', 'small attachment', 'upload session', 'upload chunk', 'draft send', 'event lookup', 'event create', 'event update', 'event cancel'] as $stage) {
                    foreach ([false, true] as $callerRetry) {
                        yield $status.' / '.$label.' / '.$stage.' / caller retry '.(int) $callerRetry => [$status, $target, $stage, $callerRetry];
                    }
                }
            }
        }
    }

    public function testMultiChunkUploadFinishesAndKeepsTheSessionPolicy(): void
    {
        $contents  = \str_repeat('A', 4 * 1024 * 1024)."\0\xff";
        [$message] = $this->scenario('sendMail');
        $message->attach(new Swift_Attachment($contents, 'large.bin', 'application/octet-stream'));
        $wire                              = [];
        [$client, $adapter, $http, $stack] = $this->sdk([
            $this->json(201, ['id' => 'draft-id']),
            $this->json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=fixture', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]),
            $this->json(200, ['nextExpectedRanges' => ['4194304-']]),
            new Response(201, ['Location' => 'https://outlook.office.com/attachments/fixture-id']),
            new Response(202),
        ], $wire);
        $snapshot  = [$http->getConfig(), (string) $stack];
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        $transport->setLargeAttachmentThreshold(16);
        $observer = $this->observe($transport);
        $failed   = [];
        $this->assertSame(4, $transport->send($message, $failed, Swift_Envelope::fromMessage($message)));
        $this->assertSame([], $failed);
        $this->assertCount(5, $wire);
        $this->assertSame($contents, $wire[2]['body'].$wire[3]['body']);
        $this->assertSame('bytes 0-4194303/4194306', $wire[2]['request']->getHeaderLine('Content-Range'));
        $this->assertSame('bytes 4194304-4194305/4194306', $wire[3]['request']->getHeaderLine('Content-Range'));
        foreach ([$wire[2], $wire[3]] as $chunk) {
            $this->assertSame('PUT', $chunk['request']->getMethod());
            $this->assertSame('uploads.safe.test', $chunk['request']->getUri()->getHost());
            $this->assertSame('token=fixture', $chunk['request']->getUri()->getQuery());
            $this->assertFalse($chunk['request']->hasHeader('Authorization'));
        }
        foreach ($wire as $request) {
            $this->assertFalse($request['options']['allow_redirects']);
        }
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack]);
        $this->assertSame($adapter, $client->getRequestAdapter());
    }

    public function testCaeChallengeStillReauthenticatesAndDecodesTheResponse(): void
    {
        $wire                                     = [];
        [$client, $adapter, $http, $stack, $auth] = $this->sdk([
            new Response(401, ['WWW-Authenticate' => 'Bearer claims="Zml4dHVyZS1jbGFpbXM="']),
            new Response(202),
        ], $wire);
        [$message] = $this->scenario('sendMail');
        $this->assertSame(4, (new Swift_Transport_Api_MicrosoftGraphTransport($client))->send($message));
        $this->assertCount(2, $wire);
        $this->assertSame([[], ['claims' => 'Zml4dHVyZS1jbGFpbXM=']], $auth->contexts);
        $this->assertSame($wire[0]['body'], $wire[1]['body']);
        foreach ($wire as $request) {
            $this->assertFalse($request['options']['allow_redirects']);
        }
    }

    public function testNormalGraphRetryAndErrorDecodingAreRetained(): void
    {
        $wire     = [];
        [$client] = $this->sdk([
            new Response(503, ['Retry-After' => '0']),
            new Response(202),
            $this->json(403, ['error' => ['code' => 'FixtureDenied', 'message' => 'fixture permission denied']]),
        ], $wire);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        [$message] = $this->scenario('sendMail');
        $this->assertSame(4, $transport->send($message));
        $this->assertCount(2, $wire);
        $failed = [];
        try {
            $transport->send($message, $failed);
            $this->fail('An ordinary SDK error must retain its normal transport failure.');
        } catch (Swift_TransportException $error) {
            $this->assertNull($error->getPrevious());
            $this->assertSame(403, $error->getCode());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(3, $wire);
        $this->assertCount(4, $failed);
    }

    public function testOriginalAdapterRetainsItsOwnRedirectBehaviorAfterASwiftFailure(): void
    {
        $wire     = [];
        [$client] = $this->sdk([
            new Response(307, ['Location' => 'https://other.safe.test/swift']),
            new Response(202),
            new Response(307, ['Location' => 'https://other.safe.test/caller']),
            new Response(202),
        ], $wire);
        [$message] = $this->scenario('sendMail');
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        try {
            $transport->send($message);
            $this->fail('Swift must reject the first redirect.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertSame(4, $transport->send($message), 'A new operation gets a fresh redirect latch.');
        $body = new Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody();
        $client->me()->sendMail()->post($body)->wait();
        $this->assertCount(4, $wire);
        $this->assertSame('other.safe.test', $wire[3]['request']->getUri()->getHost());
        $this->assertSame('/caller', $wire[3]['request']->getUri()->getPath());
        $this->assertFalse($wire[0]['options']['allow_redirects']);
        $this->assertIsArray($wire[2]['options']['allow_redirects']);
    }

    public function testReentrantFailureDoesNotPoisonTheOuterOperation(): void
    {
        $wire                                     = [];
        [$client, $adapter, $http, $stack, $auth] = $this->sdk([
            $this->json(201, ['id' => 'draft-id']),
            $this->json(201, ['id' => 'small-id']),
            $this->json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=fixture', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]),
            new Response(307, ['Location' => 'https://other.safe.test/nested']),
            new Response(201, ['Location' => 'https://outlook.office.com/attachments/fixture-id']),
            new Response(202),
        ], $wire);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        $transport->setLargeAttachmentThreshold(16);
        $observer       = $this->observe($transport);
        $entered        = false;
        $auth->callback = function () use (&$entered, $transport, $client, $auth): void {
            $this->assertSame($client, (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport));
            if ($entered || \count($auth->contexts) < 4) {
                return;
            }
            $entered  = true;
            [$nested] = $this->scenario('sendMail');
            $failed   = [];
            try {
                $transport->send($nested, $failed);
                $this->fail('The nested redirect must fail.');
            } catch (Swift_TransportException $error) {
                $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
                $this->assertCount(4, $failed);
            }
        };
        [$message] = $this->scenario('draft send');
        $this->assertSame(4, $transport->send($message));
        $this->assertCount(6, $wire);
        $this->assertSame(2, $observer->before);
        $this->assertSame(2, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
        $this->assertSame($adapter, $client->getRequestAdapter());
    }

    public function testNationalCloudBaseUrlAndFactoriesArePreserved(): void
    {
        $wire               = [];
        [$client, $adapter] = $this->sdk([new Response(202)], $wire);
        $adapter->setBaseUrl('https://graph.microsoft.us/v1.0');
        $scoped = Swift_Transport_Api_GraphRequestPolicy::scope($adapter);
        $this->assertNotSame($adapter, $scoped);
        $this->assertTrue(\class_exists(Swift\Transport\Api\GraphRequestPolicy::class));
        $this->assertSame($adapter->getSerializationWriterFactory(), $scoped->getSerializationWriterFactory());
        $this->assertSame($adapter->getParseNodeFactory(), $scoped->getParseNodeFactory());
        [$message] = $this->scenario('sendMail');
        $this->assertSame(4, (new Swift_Transport_Api_MicrosoftGraphTransport($client))->send($message));
        $this->assertSame('graph.microsoft.us', $wire[0]['request']->getUri()->getHost());
        $this->assertSame('https://graph.microsoft.us/v1.0', $adapter->getBaseUrl());
    }

    /** @dataProvider unsupportedAdapters */
    public function testUnsupportedAdaptersFailClosedBeforeRequests(string $kind): void
    {
        $adapter = match ($kind) {
            'other implementation'         => $this->createMock(Microsoft\Kiota\Abstractions\RequestAdapter::class),
            'uninitialized Guzzle adapter' => $this->getMockBuilder(Microsoft\Kiota\Http\GuzzleRequestAdapter::class)->disableOriginalConstructor()->getMock(),
            'non-cloneable'                => new class(new Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider()) extends Microsoft\Kiota\Http\GuzzleRequestAdapter {
                private function __clone()
                {
                }
            },
        };
        if ($adapter instanceof PHPUnit\Framework\MockObject\MockObject) {
            $adapter->expects($this->never())->method('sendAsync');
            $adapter->expects($this->never())->method('sendNoContentAsync');
        }
        $client    = GraphServiceClient::createWithRequestAdapter($adapter);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        $observer  = $this->observe($transport);
        [$message] = $this->scenario('sendMail');
        $failed    = [];
        try {
            $transport->send($message, $failed);
            $this->fail('An unsupported adapter cannot report accepted recipients.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertNull($error->getPrevious());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertCount(4, $failed);
            $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        }
    }

    public static function unsupportedAdapters(): iterable
    {
        yield ['other implementation'];
        yield ['uninitialized Guzzle adapter'];
        yield ['non-cloneable'];
    }

    public function testPingDoesNotAccessAnAdapterOrMakeARequest(): void
    {
        $client = $this->createMock(GraphServiceClient::class);
        $client->expects($this->never())->method('getRequestAdapter');
        $this->assertTrue((new Swift_Transport_Api_MicrosoftGraphTransport($client))->ping());
    }

    /** @dataProvider policies */
    public function testPoliciesAndMatchingEnvelopesRestoreRecipients(string $policy, bool $explicit, bool $redirect): void
    {
        $wire      = [];
        [$client]  = $this->sdk([$redirect ? new Response(307, ['Location' => '/other']) : new Response(202)], $wire);
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        $plugin    = match ($policy) {
            'allowlist' => new Swift_Plugins_AllowlistPlugin(['*@safe.test']),
            'catch-all' => new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catch@safe.test'),
            'redirect'  => new Swift_Plugins_RedirectingPlugin('sink@safe.test'),
        };
        $transport->registerPlugin($plugin);
        [$message] = $this->scenario('sendMail');
        $original  = $message->toString();
        $expected  = match ($policy) {
            'allowlist' => 2, 'catch-all' => 3, 'redirect' => 1
        };
        $failed = [];
        if ($redirect) {
            try {
                $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null);
                $this->fail('Policy changes must not allow a redirected send.');
            } catch (Swift_TransportException $error) {
                $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
                $this->assertCount($expected, $failed);
            }
        } else {
            $this->assertSame($expected, $transport->send($message, $failed, $explicit ? Swift_Envelope::fromMessage($message) : null));
            $this->assertSame([], $failed);
        }
        $this->assertCount(1, $wire);
        $this->assertStringNotContainsString('blocked@outside.test', $wire[0]['body']);
        $this->assertStringNotContainsString('blind@outside.test', $wire[0]['body']);
        $this->assertSame($original, $message->toString());
    }

    public static function policies(): iterable
    {
        foreach (['allowlist', 'catch-all', 'redirect'] as $policy) {
            foreach ([false, true] as $explicit) {
                foreach ([false, true] as $redirect) {
                    yield $policy.' / envelope '.(int) $explicit.' / redirect '.(int) $redirect => [$policy, $explicit, $redirect];
                }
            }
        }
    }

    /** @dataProvider redirectsWithoutLocation */
    public function testOther3xxWithoutLocationCannotBeDecodedAsSuccessfulMail(int $status): void
    {
        $wire      = [];
        [$client]  = $this->sdk([new Response($status)], $wire);
        [$message] = $this->scenario('sendMail');
        try {
            (new Swift_Transport_Api_MicrosoftGraphTransport($client))->send($message);
            $this->fail('A 3xx is not an acknowledgement, even without Location.');
        } catch (Swift_TransportException $error) {
            $this->assertSame($status, $error->getCode());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $wire);
    }

    public static function redirectsWithoutLocation(): iterable
    {
        foreach ([300, 304, 305, 306, 399] as $status) {
            yield [$status];
        }
    }

    public function testSendRetainsCallerRegisteredSerializationFactories(): void
    {
        $wire            = [];
        [$client]        = $this->sdk([new Response(202)], $wire);
        $writers         = Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactoryRegistry::getDefaultInstance();
        $parsers         = Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactoryRegistry::getDefaultInstance();
        $originalWriters = $writers->contentTypeAssociatedFactories;
        $originalParsers = $parsers->contentTypeAssociatedFactories;
        $spy             = new class($originalWriters['application/json']) implements Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactory {
            public int $calls = 0;

            public function __construct(private Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactory $delegate)
            {
            }

            public function getValidContentType(): string
            {
                return 'application/json';
            }

            public function getSerializationWriter(string $contentType): Microsoft\Kiota\Abstractions\Serialization\SerializationWriter
            {
                ++$this->calls;

                return $this->delegate->getSerializationWriter($contentType);
            }
        };
        $writers->contentTypeAssociatedFactories['application/json'] = $spy;
        try {
            [$message] = $this->scenario('sendMail');
            $this->assertSame(4, (new Swift_Transport_Api_MicrosoftGraphTransport($client))->send($message));
            $this->assertGreaterThan(0, $spy->calls, 'The caller registered writer must serialize the outgoing request.');
            $this->assertSame($spy, $writers->contentTypeAssociatedFactories['application/json']);
            $this->assertSame($originalParsers, $parsers->contentTypeAssociatedFactories);
        } finally {
            $writers->contentTypeAssociatedFactories = $originalWriters;
            $parsers->contentTypeAssociatedFactories = $originalParsers;
        }
    }

    /** @dataProvider unsupportedClients */
    public function testNonCooperatingGraphClientsFailBeforeWire(string $kind): void
    {
        $wire        = [];
        [, $adapter] = $this->sdk([new Response(202)], $wire);
        $arguments   = [new Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext('fixture-tenant', 'fixture-client', 'fixture-secret'), [], Microsoft\Graph\Core\NationalCloud::GLOBAL, $adapter];
        $client      = match ($kind) {
            'non-cloneable' => new class(...$arguments) extends GraphServiceClient {
                private function __clone()
                {
                }
            },
            'fixed adapter getter' => $this->getMockBuilder(GraphServiceClient::class)->setConstructorArgs($arguments)->onlyMethods(['getRequestAdapter'])->getMock(),
            'unscoped builder'     => new class(...$arguments) extends GraphServiceClient {
                public ?Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $unscoped = null;

                public function me(): Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder
                {
                    return $this->unscoped ?? parent::me();
                }
            },
        };
        if ('fixed adapter getter' === $kind) {
            $client->method('getRequestAdapter')->willReturn($adapter);
        }
        if ('unscoped builder' === $kind) {
            $client->unscoped = $client->me();
        }
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        [$message] = $this->scenario('sendMail');
        $failed    = [];
        try {
            $transport->send($message, $failed);
            $this->fail('The client must honor scoped adapters and builders.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertNull($error->getPrevious());
            $this->assertCount(4, $failed);
        }
        $this->assertSame([], $wire);
        $this->assertSame($adapter, $client->getRequestAdapter());
    }

    public static function unsupportedClients(): iterable
    {
        yield ['non-cloneable'];
        yield ['fixed adapter getter'];
        yield ['unscoped builder'];
    }

    public function testCooperatingClientOverrideUsesTheScopedAdapter(): void
    {
        $wire        = [];
        [, $adapter] = $this->sdk([new Response(202)], $wire);
        $client      = new class(new Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext('fixture-tenant', 'fixture-client', 'fixture-secret'), [], Microsoft\Graph\Core\NationalCloud::GLOBAL, $adapter) extends GraphServiceClient {
            public object $calls;

            public function me(): Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder
            {
                ++$this->calls->count;

                return parent::me();
            }
        };
        $client->calls = (object) ['count' => 0];
        [$message]     = $this->scenario('sendMail');
        $transport     = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        $this->assertSame(4, $transport->send($message));
        $this->assertSame(1, $client->calls->count);
        $this->assertSame($adapter, $client->getRequestAdapter());
        $this->assertCount(1, $wire);
        $this->assertFalse($wire[0]['options']['allow_redirects']);
    }

    private function scenario(string $stage): array
    {
        $message   = (new Swift_Message('PRIVATE-GRAPH-SUBJECT'))->setFrom('sender@safe.test')->setTo(['allowed@safe.test', 'blocked@outside.test'])->setCc('copy@safe.test')->setBcc('blind@outside.test')->setBody('PRIVATE-GRAPH-BODY');
        $responses = [];
        $calendar  = \str_starts_with($stage, 'event');
        if ($calendar) {
            $method = 'event cancel' === $stage ? 'CANCEL' : 'REQUEST';
            $ics    = \implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:'.$method, 'BEGIN:VEVENT', 'UID:fixture-graph-invite', 'DTSTART:20261102T090000Z', 'DTEND:20261102T100000Z', 'SUMMARY:PRIVATE-GRAPH-EVENT', 'ATTENDEE:mailto:allowed@safe.test', 'END:VEVENT', 'END:VCALENDAR', '']);
            $message->attach(new Swift_Attachment($ics, 'invite.ics', 'text/calendar'));
            if ('event lookup' !== $stage) {
                $responses[] = $this->json(200, ['value' => 'event create' === $stage ? [] : [['id' => 'event-id']]]);
            }
        } elseif ('sendMail' !== $stage) {
            $message->attach(new Swift_Attachment('PRIVATE-ATTACHMENT-BYTES', 'large.bin', 'application/octet-stream'));
            $message->attach(new Swift_Attachment('tiny', 'small.txt', 'text/plain'));
            if ('draft' !== $stage) {
                $responses[] = $this->json(201, ['id' => 'draft-id']);
            }
            if (\in_array($stage, ['upload session', 'upload chunk', 'draft send'], true)) {
                $responses[] = $this->json(201, ['id' => 'small-attachment-id']);
            }
            if (\in_array($stage, ['upload chunk', 'draft send'], true)) {
                $responses[] = $this->json(201, ['uploadUrl' => 'https://uploads.safe.test/session?token=fixture', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]);
            }
            if ('draft send' === $stage) {
                $responses[] = new Response(201, ['Location' => 'https://outlook.office.com/attachments/fixture-id']);
            }
        }

        return [$message, $responses, $calendar];
    }

    private function sdk(array $responses, array &$wire, bool $callerRetry = false): array
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = static function (RequestInterface $request, array $options) use (&$wire, $response) {
                $wire[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

                return $response;
            };
        }
        $stack = GraphClientFactory::getDefaultHandlerStack(new MockHandler($queue));
        if ($callerRetry) {
            $stack->push(Middleware::retry(static fn (int $retries, $request, $response, $error): bool => $retries < 1 && (null !== $response && $response->getStatusCode() >= 300 && $response->getStatusCode() < 400 || null !== $error), static fn (): int => 0), 'fixture_retry');
        }
        $http = new Client(['handler' => $stack, 'allow_redirects' => ['strict' => true, 'max' => 10], 'timeout' => 19]);
        $auth = new class implements AuthenticationProvider {
            public array $contexts = [];

            public $callback;

            public function authenticateRequest(RequestInformation $request, array $additionalAuthenticationContext = []): Promise
            {
                $this->contexts[] = $additionalAuthenticationContext;
                if (null !== $this->callback) {
                    ($this->callback)();
                }
                if ('graph.microsoft.com' === \parse_url($request->getUri(), \PHP_URL_HOST)) {
                    $request->getHeaders()->add('Authorization', 'Bearer fixture-access-token');
                }

                return new FulfilledPromise($request);
            }
        };
        $adapter = new GraphRequestAdapter($auth, $http);
        $client  = GraphServiceClient::createWithRequestAdapter($adapter);

        return [$client, $adapter, $http, $stack, $auth];
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class($this, $transport) implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public int $result = 0;

            public function __construct(private TestCase $test, private Swift_Transport $transport)
            {
            }

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->before;
                $this->test->assertSame($this->transport, $event->getTransport());
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->completed;
                $this->result = $event->getResult();
                $this->test->assertSame($this->transport, $event->getTransport());
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }

    private function json(int $status, array $payload): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], \json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
