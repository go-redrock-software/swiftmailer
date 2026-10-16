<?php

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Swift_Transport_Api_GraphAcceptanceTest extends TestCase
{
    /** @dataProvider ambiguousResponses */
    public function testAmbiguousAcceptanceCannotReportSuccessContinueOrReplay(string $stage, int $status, mixed $body, string $mode, string $wrapper): void
    {
        [$transport, $client, $http, $stack, $wire, $message, $prefix] = $this->scenario($stage, $status, $body, $mode);
        $snapshot                                                      = [$http->getConfig(), (string) $stack, $client->getRequestAdapter()];
        $observer                                                      = $this->observe($transport);
        $fallback                                                      = new Swift_Transport_NullTransport(new Swift_Events_SimpleEventDispatcher());
        $fallbackObserver                                              = $this->observe($fallback);
        $sender                                                        = $this->wrap($transport, $fallback, $wrapper);
        $original                                                      = $message->toString();
        $failed                                                        = [];
        try {
            $sender->send($message, $failed);
            $this->fail('Ambiguous Graph acceptance must fail permanently.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame($status, $error->getCode());
            $this->assertNull($error->getPrevious());
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $this->assertStringNotContainsString('PRIVATE-', $error->getMessage());
        }
        $this->assertCount($prefix + 1, $wire->requests);
        $this->assertSame(0, $fallbackObserver->before);
        $this->assertSame([1, 1, Swift_Events_SendEvent::RESULT_FAILED], [$observer->before, $observer->completed, $observer->result]);
        $this->assertEqualsCanonicalizing(\str_starts_with($stage, 'event') ? ['to@safe.test'] : ['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
        $this->assertSame($original, $message->toString());
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack, $client->getRequestAdapter()]);
    }

    public static function ambiguousResponses(): iterable
    {
        foreach (self::stages() as $stage) {
            $status = self::status($stage);
            $bodies = ['empty' => '', 'malformed JSON' => '{"PRIVATE-PROVIDER":', 'array' => '[]', 'null' => 'null'];
            if (!\in_array($stage, ['sendMail', 'draft send', 'event cancel', 'event delete'], true)) {
                $bodies['missing fields'] = '{}';
                $bodies['coercible id']   = '{"id":123,"value":[{"id":123}],"uploadUrl":123,"nextExpectedRanges":[]}';
            } else {
                unset($bodies['empty']);
                $bodies['unexpected JSON body'] = '{"PRIVATE-PROVIDER":"diagnostic"}';
            }
            foreach ($bodies as $label => $body) {
                foreach (['plain', 'retry', 'failover', 'roundrobin'] as $wrapper) {
                    yield $stage.' / '.$label.' / '.$wrapper => [$stage, $status, $body, 'normal', $wrapper];
                }
            }
            yield $stage.' / interrupted body' => [$stage, $status, '', 'interrupted', 'retry'];
            foreach (['sync rejected', 'async rejected'] as $mode) {
                yield $stage.' / '.$mode => [$stage, $status, '', $mode, 'retry'];
            }
            yield $stage.' / incorrect successful status' => [$stage, 'event delete' === $stage ? 202 : 204, '', 'normal', 'retry'];
        }
        foreach (['', ' ', "bad\nidentifier", 0, 123, false, null, []] as $id) {
            foreach (['draft', 'small attachment', 'event create', 'event update', 'event read', 'event lookup'] as $stage) {
                $data = 'event lookup' === $stage ? ['value' => [['id' => $id]]] : ['id' => $id];
                yield $stage.' / invalid typed id '.\json_encode($id) => [$stage, self::status($stage), \json_encode($data), 'normal', 'retry'];
            }
        }
        foreach (['draft', 'small attachment', 'upload session', 'event lookup', 'event create', 'event update', 'event read', 'upload chunk'] as $stage) {
            $body = match ($stage) {
                'upload session' => \json_encode(['uploadUrl' => 'https://uploads.safe.test/session?token=fixture', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']]),
                'event lookup'   => '{"value":[]}',
                'upload chunk'   => null,
                default          => '{"id":"event-id"}',
            };
            yield $stage.' / wrong status with otherwise valid evidence' => [$stage, 200 === self::status($stage) ? 201 : 200, $body, 'normal', 'retry'];
        }
        yield 'read event identity mismatch' => ['event read', 200, '{"id":"wrong-event-id"}', 'normal', 'retry'];
        yield 'updated event identity mismatch' => ['event update', 200, '{"id":"wrong-event-id"}', 'normal', 'retry'];
        foreach (['{}', '{"value":null}', '{"value":{}}', '{"value":[null]}', '{"value":[{}]}', '{"value":[{"id":"event-id"},null]}'] as $body) {
            yield 'lookup collection '.$body => ['event lookup', 200, $body, 'normal', 'retry'];
        }
    }

    /** @dataProvider validResponses */
    public function testDocumentedAcceptanceKeepsSdkParsingAndNonseekableBodies(string $stage, bool $nonseekable): void
    {
        [$transport, $client, $http, $stack, $wire, $message, $prefix] = $this->scenario($stage, self::status($stage), null, $nonseekable ? 'nonseekable' : 'normal');
        $snapshot                                                      = [$http->getConfig(), (string) $stack, $client->getRequestAdapter()];
        $failed                                                        = ['previous@safe.test'];
        $expected                                                      = \str_starts_with($stage, 'event') ? 1 : 3;
        $this->assertSame($expected, $transport->send($message, $failed));
        $this->assertSame([], $failed);
        $this->assertGreaterThanOrEqual($prefix + 1, \count($wire->requests));
        $this->assertSame($snapshot, [$http->getConfig(), (string) $stack, $client->getRequestAdapter()]);
    }

    public static function validResponses(): iterable
    {
        foreach (self::stages() as $stage) {
            foreach ([false, true] as $nonseekable) {
                yield $stage.' / nonseekable '.(int) $nonseekable => [$stage, $nonseekable];
            }
        }
    }

    /** @dataProvider partialNotificationFailures */
    public function testAcceptedInvitationCannotBeRepeatedAfterALaterFailure(string $wrapper, string $failure, string $stage): void
    {
        [$transport, , , , $wire, $message] = $this->scenario($stage, self::status($stage), null, 'normal');
        $message->attach(new Swift_Attachment('ordinary', 'small.txt', 'text/plain'));
        $wire->rewrite = static function ($request, $response, $stage) use ($failure) {
            if ('sendMail' !== $stage) {
                return $response;
            }
            if ('network' === $failure) {
                throw new GuzzleHttp\Exception\ConnectException('PRIVATE-LATE-NETWORK', $request);
            }

            return new Response(503, ['Retry-After' => '0']);
        };
        $fallback = new Swift_Transport_NullTransport(new Swift_Events_SimpleEventDispatcher());
        $observer = $this->observe($fallback);
        $sender   = $this->wrap($transport, $fallback, $wrapper);
        try {
            $sender->send($message, $failed);
            $this->fail('An accepted invitation cannot be replayed after a later mail failure.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame('network' === $failure ? 0 : 503, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $events = \array_filter($wire->requests, static fn ($entry) => 'POST' === $entry['request']->getMethod() && \str_ends_with($entry['request']->getUri()->getPath(), '/events'));
        $this->assertCount('event create' === $stage ? 1 : 0, $events);
        $this->assertSame(0, $observer->before);
    }

    public static function partialNotificationFailures(): iterable
    {
        foreach (['retry', 'failover', 'roundrobin'] as $wrapper) {
            foreach (['network', 'server'] as $failure) {
                foreach (['event create', 'event update', 'event cancel', 'event delete'] as $stage) {
                    yield $wrapper.' / '.$failure.' / '.$stage => [$wrapper, $failure, $stage];
                }
            }
        }
    }

    /** @dataProvider paginatedLookups */
    public function testLookupCompletesEmptyPagesBeforeCreatingOrUpdating(bool $found): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('event create', 201, null, 'normal');
        $wire->rewrite                      = static function ($request, $response, $stage) use ($found) {
            if ('event lookup' !== $stage) {
                return $response;
            }

            return !\str_contains($request->getUri()->getQuery(), 'skiptoken')
                ? Swift_GraphSdkFixture::json(200, ['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/events?skiptoken=fixture'])
                : Swift_GraphSdkFixture::json(200, ['value' => $found ? [['id' => 'existing-event']] : []]);
        };
        $this->assertSame(1, $transport->send($message));
        $this->assertCount($found ? 4 : 3, $wire->requests);
        $this->assertSame('GET', $wire->requests[1]['request']->getMethod());
        $this->assertSame('skiptoken=fixture', $wire->requests[1]['request']->getUri()->getQuery());
        $this->assertSame($found ? 'PATCH' : 'POST', $wire->requests[$found ? 3 : 2]['request']->getMethod());
        $this->assertSame($found ? '/v1.0/me/events/existing-event' : '/v1.0/me/events', $wire->requests[$found ? 3 : 2]['request']->getUri()->getPath());
    }

    public static function paginatedLookups(): iterable
    {
        yield 'found on later page' => [true];
        yield 'complete empty collection' => [false];
    }

    /** @dataProvider unsafeLookupPages */
    public function testIncompleteOrUnsafeLookupPageCannotCreateAnEvent(string $next): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('event lookup', 200, \json_encode(['value' => [], '@odata.nextLink' => $next]), 'normal');
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($message);
            $this->fail('Unresolved or unsafe pagination cannot mean no event matched.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $wire->requests);
    }

    public static function unsafeLookupPages(): iterable
    {
        foreach (['https://graph.microsoft.com/v1.0/me/events', 'https://other.safe.test/events', 'https://graph.microsoft.com/v1.0/users/other/events', 'http://graph.microsoft.com/v1.0/me/events', 'https://user@graph.microsoft.com/v1.0/me/events', 'https://graph.microsoft.com/v1.0/me/events#fragment'] as $url) {
            yield [$url];
        }
    }

    /** @dataProvider validUploadRanges */
    public function testDocumentedSequentialUploadProgressAndFinalLocation(string $next, bool $finalJsonHeader): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('upload chunk', 201, null, 'normal');
        $message->setChildren([new Swift_Attachment(\str_repeat('x', 4 * 1024 * 1024 + 2), 'large.bin', 'application/octet-stream')]);
        $wire->rewrite = static function ($request, $response, $stage) use ($next, $finalJsonHeader) {
            if ('upload chunk' !== $stage) {
                return $response;
            }
            if (\str_starts_with($request->getHeaderLine('Content-Range'), 'bytes 0-')) {
                return Swift_GraphSdkFixture::json(200, ['nextExpectedRanges' => [$next]]);
            }

            return $finalJsonHeader ? $response->withHeader('Content-Type', 'application/json') : $response;
        };
        $this->assertSame(3, $transport->send($message, $failed));
        $this->assertSame([], $failed);
        $chunks = \array_values(\array_filter($wire->requests, static fn ($entry) => 'PUT' === $entry['request']->getMethod()));
        $this->assertCount(2, $chunks);
        $this->assertSame('bytes 0-4194303/4194306', $chunks[0]['request']->getHeaderLine('Content-Range'));
        $this->assertSame('bytes 4194304-4194305/4194306', $chunks[1]['request']->getHeaderLine('Content-Range'));
        $this->assertSame(4 * 1024 * 1024 + 2, \strlen($chunks[0]['body'].$chunks[1]['body']));
    }

    public static function validUploadRanges(): iterable
    {
        foreach (['4194304', '4194304-', '4194304-4194305'] as $range) {
            foreach ([false, true] as $header) {
                yield $range.' / JSON header '.(int) $header => [$range, $header];
            }
        }
    }

    /** @dataProvider invalidUploadRanges */
    public function testInvalidProgressCannotSkipRepeatOrPrematurelyFinishChunks(int $status, array $data): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('upload chunk', 201, null, 'normal');
        $message->setChildren([new Swift_Attachment(\str_repeat('x', 4 * 1024 * 1024 + 2), 'large.bin', 'application/octet-stream')]);
        $wire->rewrite = static fn ($request, $response, $stage) => 'upload chunk' === $stage
            ? Swift_GraphSdkFixture::json($status, $data)->withHeader('Location', 'https://outlook.office.com/attachments/fixture') : $response;
        $registry = Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactoryRegistry::getDefaultInstance();
        $original = $registry->contentTypeAssociatedFactories;
        $parser   = new class extends Microsoft\Kiota\Serialization\Json\JsonParseNodeFactory {
            public int $calls = 0;

            public function getRootParseNode(string $contentType, Psr\Http\Message\StreamInterface $rawResponse): Microsoft\Kiota\Abstractions\Serialization\ParseNode
            {
                ++$this->calls;

                return parent::getRootParseNode($contentType, $rawResponse);
            }
        };
        $registry->contentTypeAssociatedFactories['application/json'] = $parser;
        try {
            try {
                (new Swift_Transport_RetryTransport($transport, 2, 0))->send($message);
                $this->fail('Upload progress needs exact forward byte-range evidence.');
            } catch (Swift_TransportException $error) {
                $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
                $this->assertSame($status, $error->getCode());
                $this->assertNull($error->getPrevious());
            }
            $this->assertCount(3, $wire->requests);
            $this->assertSame(2, $parser->calls, 'Only the confirmed draft and upload session reach the SDK parser.');
        } finally {
            $registry->contentTypeAssociatedFactories = $original;
        }
    }

    public static function invalidUploadRanges(): iterable
    {
        foreach (['missing' => [], 'empty' => ['nextExpectedRanges' => []], 'null' => ['nextExpectedRanges' => null], 'coercible' => ['nextExpectedRanges' => [4194304]], 'backward' => ['nextExpectedRanges' => ['0-']], 'skip' => ['nextExpectedRanges' => ['4194305-']], 'multiple' => ['nextExpectedRanges' => ['4194304-', '0-']], 'wrong end' => ['nextExpectedRanges' => ['4194304-4194310']]] as $label => $data) {
            yield $label => [200, $data];
        }
        yield 'early completion' => [201, []];
        yield 'undocumented 202 progress' => [202, ['nextExpectedRanges' => ['4194304-']]];
    }

    /** @dataProvider invalidUploadSessions */
    public function testUploadSessionNeedsUsableUncoercedMetadata(array $changes): void
    {
        $data                               = \array_replace(['uploadUrl' => 'https://uploads.safe.test/session?token=PRIVATE-UPLOAD', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']], $changes);
        [$transport, , , , $wire, $message] = $this->scenario('upload session', 201, \json_encode($data), 'normal');
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($message);
            $this->fail('Malformed session metadata must not reach an upload request or recreate the draft.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(201, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(3, $wire->requests);
    }

    public static function invalidUploadSessions(): iterable
    {
        foreach ([null, 0, false, '', 'http://uploads.safe.test/session', 'https://user:PRIVATE-PASSWORD@uploads.safe.test/session', 'https://uploads.safe.test/session#fragment', 'https://uploads.safe.test/with space'] as $url) {
            yield 'URL '.\json_encode($url) => [['uploadUrl' => $url]];
        }
        foreach ([null, 123, '', 'tomorrow', '2099-02-30T12:00:00Z', '2000-01-01T00:00:00Z'] as $expiry) {
            yield 'expiry '.\json_encode($expiry) => [['expirationDateTime' => $expiry]];
        }
        foreach ([null, [], [0], ['1-'], ['0-', '1-'], '0-'] as $ranges) {
            yield 'ranges '.\json_encode($ranges) => [['nextExpectedRanges' => $ranges]];
        }
    }

    /** @dataProvider invalidCompletionLocations */
    public function testFinalUploadNeedsAUsableLocation(mixed $location): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('upload chunk', 201, null, 'normal');
        $wire->rewrite                      = static fn ($request, $response, $stage) => 'upload chunk' === $stage ? $response->withHeader('Location', $location) : $response;
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($message);
            $this->fail('Unconfirmed final upload cannot send a draft or replay.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(201, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(4, $wire->requests);
    }

    public static function invalidCompletionLocations(): iterable
    {
        foreach (['', 'http://outlook.office.com/attachments/id', 'https://user@outlook.office.com/attachments/id', 'https://outlook.office.com/attachments/id#fragment', 'https://outlook.office.com/with space', ['https://outlook.office.com/attachments/a', 'https://outlook.office.com/attachments/b']] as $location) {
            yield [$location];
        }
    }

    /** @dataProvider modelStages */
    public function testSuccessfulHeadersWithUnparsedSdkModelArePermanent(string $stage): void
    {
        [$transport, , , , $wire, $message, $prefix] = $this->scenario($stage, self::status($stage), null, 'normal');
        $wire->rewrite                               = static fn ($request, $response, $current) => $current === $stage ? $response->withoutHeader('Content-Type') : $response;
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($message);
            $this->fail('Raw HTTP success must not replace the required parsed SDK result.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(self::status($stage), $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount($prefix + 1, $wire->requests);
    }

    public static function modelStages(): iterable
    {
        foreach (['draft', 'small attachment', 'upload session', 'event lookup', 'event create', 'event update', 'event read'] as $stage) {
            yield [$stage];
        }
    }

    public function testANewSendHasFreshAcceptanceStateAfterAnAmbiguousResponse(): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('sendMail', 202, '{}', 'normal');
        try {
            $transport->send($message);
            $this->fail('The first response is ambiguous.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(202, $error->getCode());
        }
        $wire->rewrite = static fn () => new Response(202);
        $this->assertSame(3, $transport->send($message, $failed));
        $this->assertSame([], $failed);
        $this->assertCount(2, $wire->requests);
    }

    public function testReentrantAmbiguousSendDoesNotPoisonTheOuterDraft(): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('draft', 201, null, 'normal');
        $nested                             = false;
        $wire->rewrite                      = function ($request, $response, $stage) use ($transport, &$nested) {
            if ('draft' === $stage && !$nested) {
                $nested = true;
                try {
                    $transport->send((new Swift_Message('nested', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test'));
                    $this->fail('The nested success body is ambiguous.');
                } catch (Swift_Transport_NonRetryableException $error) {
                    $this->assertSame(202, $error->getCode());
                }
            }

            return 'sendMail' === $stage ? new Response(202, ['Content-Type' => 'application/json'], '{}') : $response;
        };
        $this->assertSame(3, $transport->send($message, $failed));
        $this->assertTrue($nested);
        $this->assertSame([], $failed);
        $this->assertCount(6, $wire->requests);
    }

    /** @dataProvider parserFailures */
    public function testCallerParserCannotTurnSuccessfulHeadersIntoUnconfirmedDrafts(string $mode, string $stage): void
    {
        [$transport, , , , $wire, $message, $prefix]                  = $this->scenario($stage, self::status($stage), null, 'normal');
        $registry                                                     = Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactoryRegistry::getDefaultInstance();
        $original                                                     = $registry->contentTypeAssociatedFactories;
        $registry->contentTypeAssociatedFactories['application/json'] = new class($mode) extends Microsoft\Kiota\Serialization\Json\JsonParseNodeFactory {
            public function __construct(private string $mode)
            {
            }

            public function getRootParseNode(string $contentType, Psr\Http\Message\StreamInterface $rawResponse): Microsoft\Kiota\Abstractions\Serialization\ParseNode
            {
                $node = parent::getRootParseNode($contentType, $rawResponse);
                $mode = $this->mode;
                $node->setOnAfterAssignFieldValues(static function ($model) use ($mode) {
                    if ($model instanceof Microsoft\Graph\Generated\Models\UploadSession && 'changed expiry' === $mode) {
                        $model->setExpirationDateTime(new DateTime('2000-01-01T00:00:00Z'));
                    }
                    if ($model instanceof Microsoft\Graph\Generated\Models\Message && 'changed expiry' !== $mode) {
                        if ('throw' === $mode) {
                            throw new RuntimeException('PRIVATE-PARSER-DIAGNOSTIC');
                        }
                        $model->setId('different-id');
                    }
                });

                return $node;
            }
        };
        try {
            try {
                (new Swift_Transport_RetryTransport($transport, 2, 0))->send($message);
                $this->fail('A changed or failed parser result cannot confirm the draft.');
            } catch (Swift_TransportException $error) {
                $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
                $this->assertSame(201, $error->getCode());
                $this->assertNull($error->getPrevious());
                $this->assertCount($prefix + 1, $wire->requests);
            }
        } finally {
            $registry->contentTypeAssociatedFactories = $original;
        }
    }

    public static function parserFailures(): iterable
    {
        yield ['throw', 'draft'];
        yield ['changed model', 'draft'];
        yield ['changed expiry', 'upload session'];
    }

    public function testCallerMiddlewareCannotReplayAnAcknowledgedSend(): void
    {
        [$transport, , , $stack, $wire, $message] = $this->scenario('sendMail', 202, null, 'normal');
        $stack->push(Middleware::retry(static fn (int $retries) => $retries < 1, static fn () => 0), 'fixture_retry_every_response');
        try {
            $transport->send($message);
            $this->fail('A caller retry must not repeat acknowledged mail.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(202, $error->getCode());
        }
        $this->assertCount(1, $wire->requests);
    }

    /** @dataProvider validUploadSessions */
    public function testValidOpaqueUploadUrlsAndSessionMetadataRemainUsable(string $url, string $expiry, array $ranges): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('upload session', 201, \json_encode(['uploadUrl' => $url, 'expirationDateTime' => $expiry, 'nextExpectedRanges' => $ranges]), 'normal');
        $this->assertSame(3, $transport->send($message, $failed));
        $this->assertSame([], $failed);
        $this->assertSame((string) new GuzzleHttp\Psr7\Uri($url), (string) $wire->requests[3]['request']->getUri());
    }

    public static function validUploadSessions(): iterable
    {
        foreach (['https://uploads.safe.test/session?authtoken=0%2Bvalue', 'https://UPLOADS.safe.test:443/session?authtoken=0%2Bvalue'] as $url) {
            foreach (['2099-01-01T00:00:00Z', '2099-01-01T00:00:00.7671707Z', '2099-01-01T01:00:00+01:00'] as $expiry) {
                foreach ([['0-'], ['0']] as $ranges) {
                    yield $url.' / '.$expiry.' / '.$ranges[0] => [$url, $expiry, $ranges];
                }
            }
        }
    }

    public function testSuppressedPreflightFailureCannotBecomeAcceptedMail(): void
    {
        [, $client, , , $wire, $message] = $this->scenario('draft', 201, null, 'normal');
        $transport                       = $this->getMockBuilder(Swift_Transport_Api_MicrosoftGraphTransport::class)
            ->setConstructorArgs([$client])->onlyMethods(['attachmentByteSize'])->getMock();
        $transport->method('attachmentByteSize')->willReturn(Swift_Transport_Api_MicrosoftGraphTransport::MAX_ATTACHMENT_SIZE + 1);
        $transport->registerPlugin(new class implements Swift_Events_TransportExceptionListener {
            public function exceptionThrown(Swift_Events_TransportExceptionEvent $event): void
            {
                $event->cancelBubble();
            }
        });
        try {
            $transport->send($message, $failed);
            $this->fail('A suppressed preflight exception is not provider acceptance.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(0, $error->getCode());
        }
        $this->assertSame([], $wire->requests);
        $this->assertEqualsCanonicalizing(['to@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
    }

    public function testMultiPageLookupCyclesFailBeforeAnyEventMutation(): void
    {
        [$transport, , , , $wire, $message] = $this->scenario('event lookup', 200, null, 'normal');
        $pages                              = 0;
        $wire->rewrite                      = static function ($request, $response, $stage) use (&$pages) {
            if (++$pages > 4) {
                throw new RuntimeException('Fixture stopped a lookup cycle.');
            }

            return Swift_GraphSdkFixture::json(200, ['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/events?skiptoken='.(\str_contains($request->getUri()->getQuery(), 'skiptoken=A') ? 'B' : 'A')]);
        };
        try {
            $transport->send($message);
            $this->fail('Cyclic lookup pages cannot mean no event matched.');
        } catch (Swift_TransportException $error) {
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $error);
            $this->assertSame(200, $error->getCode());
        }
        $this->assertCount(3, $wire->requests);
    }

    /** @dataProvider preNotificationFailures */
    public function testOrdinaryFailureAfterConfirmedPreparatoryResponseKeepsNormalRetryClassification(string $stage): void
    {
        [$transport, , , , $wire, $message] = $this->scenario($stage, self::status($stage), null, 'normal');
        $wire->rewrite                      = static function ($request, $response, $current) use ($stage) {
            if ($stage === $current) {
                throw new GuzzleHttp\Exception\ConnectException('PRIVATE-PRE-NOTIFICATION-NETWORK', $request);
            }

            return $response;
        };
        try {
            $transport->send($message);
            $this->fail('The synthetic network failure must surface.');
        } catch (Swift_TransportException $error) {
            $this->assertSame(Swift_TransportException::class, $error::class);
            $this->assertSame(0, $error->getCode());
            $this->assertTrue((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(2, $wire->requests);
    }

    public static function preNotificationFailures(): iterable
    {
        yield ['event create'];
        yield ['small attachment'];
    }

    private static function stages(): array
    {
        return ['sendMail', 'draft', 'small attachment', 'upload session', 'upload chunk', 'draft send', 'event lookup', 'event create', 'event update', 'event cancel', 'event read', 'event delete'];
    }

    private static function status(string $stage): int
    {
        return match ($stage) {
            'sendMail', 'draft send', 'event cancel'     => 202,
            'event delete'                               => 204,
            'event lookup', 'event update', 'event read' => 200,
            default                                      => 201,
        };
    }

    private function scenario(string $stage, int $status, mixed $body, string $mode): array
    {
        $message  = (new Swift_Message('PRIVATE-SUBJECT', 'PRIVATE-BODY'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setCc('copy@safe.test')->setBcc('blind@safe.test');
        $calendar = \str_starts_with($stage, 'event');
        if ($calendar) {
            $ics = \implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:'.(\in_array($stage, ['event cancel', 'event delete'], true) ? 'CANCEL' : 'REQUEST'), 'BEGIN:VEVENT', 'UID:fixture-invite', 'DTSTART:20261102T090000Z', 'DTEND:20261102T100000Z', 'SUMMARY:PRIVATE-EVENT', 'ATTENDEE:mailto:to@safe.test', 'END:VEVENT', 'END:VCALENDAR', '']);
            $message->attach(new Swift_Attachment($ics, 'invite.ics', 'text/calendar'));
        } elseif ('sendMail' !== $stage) {
            $message->attach(new Swift_Attachment(\str_repeat('x', 32), 'large.bin', 'application/octet-stream'));
            $message->attach(new Swift_Attachment('tiny', 'small.txt', 'text/plain'));
        }
        $prefix = match ($stage) {
            'sendMail', 'draft', 'event lookup' => 0, 'small attachment', 'event create', 'event read' => 1, 'event update', 'event cancel', 'event delete' => 2, 'upload session' => 2, 'upload chunk' => 3, 'draft send' => 4
        };
        $wire    = (object) ['requests' => [], 'rewrite' => null];
        $handler = function (RequestInterface $request, array $options) use ($stage, $status, $body, $mode, $wire): mixed {
            $wire->requests[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];
            $path             = $request->getUri()->getPath();
            $current          = match (true) {
                'DELETE' === $request->getMethod()                                   => 'event delete',
                'PUT'    === $request->getMethod()                                      => 'upload chunk',
                'GET'    === $request->getMethod() && !\str_ends_with($path, '/events') => 'event read',
                'GET'    === $request->getMethod()                                      => 'event lookup',
                'PATCH'  === $request->getMethod()                                    => 'event update',
                \str_ends_with($path, '/sendMail')                                   => 'sendMail',
                \str_ends_with($path, '/createUploadSession')                        => 'upload session',
                \str_ends_with($path, '/attachments')                                => 'small attachment',
                \str_ends_with($path, '/messages')                                   => 'draft',
                \str_ends_with($path, '/send')                                       => 'draft send',
                \str_ends_with($path, '/cancel')                                     => 'event cancel',
                default                                                              => 'event create',
            };
            $data = match ($current) {
                'sendMail', 'draft send', 'event cancel', 'event delete', 'upload chunk' => null,
                'event lookup'                                                           => ['value' => \in_array($stage, ['event update', 'event cancel', 'event read', 'event delete'], true) ? [['id' => 'event-id']] : []],
                'upload session'                                                         => ['uploadUrl' => 'https://uploads.safe.test/session?token=PRIVATE-UPLOAD', 'expirationDateTime' => '2099-01-01T00:00:00Z', 'nextExpectedRanges' => ['0-']],
                'event update'                                                           => ['id' => \rawurldecode(\basename($path))],
                'event read'                                                             => ['id' => \rawurldecode(\basename($path)), 'attendees' => [['emailAddress' => ['address' => 'to@safe.test']]], 'type' => 'singleInstance', 'isOrganizer' => true, '@odata.etag' => 'W/"fixture"'],
                default                                                                  => ['id' => \str_replace(' ', '-', $current).'-id'],
            };
            $raw     = null === $data ? '' : \json_encode($data, JSON_THROW_ON_ERROR);
            $headers = null === $data ? [] : ['Content-Type' => 'application/json'];
            if ('upload chunk' === $current) {
                $headers['Location'] = 'https://outlook.office.com/api/v2.0/Users(\'fixture\')/Messages(\'draft\')/Attachments(\'attachment-id\')';
            }
            $responseStatus = self::status($current);
            if ($current === $stage) {
                $responseStatus = $status;
                if (null !== $body) {
                    $raw     = $body;
                    $headers = ['Content-Type' => 'application/json'];
                }
                if ('interrupted' === $mode) {
                    $raw = new PumpStream(static function () {
                        throw new RuntimeException('PRIVATE-TRANSFER-DIAGNOSTIC');
                    });
                } elseif ('nonseekable' === $mode) {
                    $raw = new NoSeekStream(Utils::streamFor($raw));
                }
            }
            $response = new Response($responseStatus, $headers, $raw);
            if (null !== $wire->rewrite) {
                $response = ($wire->rewrite)($request, $response, $current);
            }
            if ($current === $stage && \in_array($mode, ['sync rejected', 'async rejected'], true)) {
                $error = new GuzzleHttp\Exception\RequestException('PRIVATE-REJECTED-TRANSFER', $request, $response);
                if ('sync rejected' === $mode) {
                    throw $error;
                }

                return Create::rejectionFor($error);
            }

            return Create::promiseFor($response);
        };
        $stack = GraphClientFactory::getDefaultHandlerStack($handler);
        $stack->push(Middleware::retry(static fn (int $retries, $request, $response, $error): bool => $retries < 1 && null !== $error, static fn (): int => 0), 'fixture_retry_failure');
        $http    = new Client(['handler' => $stack, 'timeout' => 17]);
        $adapter = new GraphRequestAdapter(new AnonymousAuthenticationProvider(), $http);
        $client  = GraphServiceClient::createWithRequestAdapter($adapter);
        $this->assertSame($http, (new ReflectionProperty(Microsoft\Kiota\Http\GuzzleRequestAdapter::class, 'guzzleClient'))->getValue($adapter));
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport($client);
        $transport->setLargeAttachmentThreshold(16);
        if ($calendar) {
            $transport->enableCalendarEventConversion();
            if ('event delete' === $stage) {
                $transport->registerPlugin(new Swift_Plugins_AllowlistPlugin(['*@safe.test']));
            }
        }

        return [$transport, $client, $http, $stack, $wire, $message, $prefix];
    }

    private function wrap(Swift_Transport $transport, Swift_Transport $fallback, string $wrapper): Swift_Transport
    {
        if ('retry' === $wrapper) {
            return new Swift_Transport_RetryTransport($transport, 2, 0, new class implements Swift_Transport_RetryClassifier {
                public function isRetryable(Swift_TransportException $error): bool
                {
                    return true;
                }
            });
        }
        if ('plain' !== $wrapper) {
            $pool = 'failover' === $wrapper ? new Swift_Transport_FailoverTransport() : new Swift_Transport_LoadBalancedTransport();
            $pool->setTransports([$transport, $fallback]);

            return $pool;
        }

        return $transport;
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public int $result = 0;

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->completed;
                $this->result = $event->getResult();
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }
}
