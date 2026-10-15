<?php

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Ses\SesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_SesPolicyCompatibilityTest extends TestCase
{
    /** @dataProvider decorators */
    public function testNestedRetryDecoratorsCannotHideRedirectAndCallerRetainsStrategy(string $kind, bool $redirect): void
    {
        $requests = [];
        $raw      = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $redirect): MockResponse {
            $requests[] = $options;

            return 1 === \count($requests) ? new MockResponse('{}', ['http_code' => $redirect ? 307 : 503, 'response_headers' => ['Location: /elsewhere']]) : new MockResponse('{"MessageId":"id"}');
        });
        $http = new RetryableHttpClient($raw, new GenericRetryStrategy([307, 503], 0), 2);
        $http = match ($kind) {
            'nested retry' => new RetryableHttpClient($http, new GenericRetryStrategy([307, 503], 0), 2),
            'traceable'    => new TraceableHttpClient($http),
            'scoping'      => ScopingHttpClient::forBaseUri($http, 'https://ses.example.test', ['max_redirects' => 12]),
        };
        $client    = new SesClient(['region' => 'us-east-1'], new Credentials('fixture-key', 'fixture-secret'), $http);
        $transport = new Swift_Transport_Api_AmazonSesApiTransport($client);
        try {
            $count = $transport->send($this->message());
            $this->assertFalse($redirect);
            $this->assertSame(1, $count);
        } catch (Swift_TransportException $error) {
            $this->assertTrue($redirect);
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount($redirect ? 1 : 2, $requests);
        foreach ($requests as $options) {
            $this->assertSame(0, $options['max_redirects']);
        }
        if ('traceable' === $kind) {
            $this->assertCount(1, $http->getTracedRequests());
        }
        $requests = [];
        $this->assertSame(200, $http->request('GET', 'https://ses.example.test', ['max_redirects' => 7])->getStatusCode());
        $this->assertCount(2, $requests);
        $this->assertSame(7, $requests[0]['max_redirects']);
        $this->assertSame($http, (new ReflectionProperty(AbstractApi::class, 'httpClient'))->getValue($client));
    }

    public static function decorators(): iterable
    {
        foreach (['nested retry', 'traceable', 'scoping'] as $kind) {
            foreach ([false, true] as $redirect) {
                yield "$kind redirect ".(int) $redirect => [$kind, $redirect];
            }
        }
    }

    public function testWithOptionsAndStreamingRetainOptionsAndOperationLatch(): void
    {
        $fixture = new SesSdkFixture([new MockResponse('first'), new MockResponse('second'), new MockResponse('{}', ['http_code' => 307, 'response_headers' => ['Location: /elsewhere']])], true);
        Swift_Transport_Api_SesRequestPolicy::run($fixture->client, function (SesClient $client) use ($fixture): void {
            $http     = (new ReflectionProperty(AbstractApi::class, 'httpClient'))->getValue($client);
            $derived  = $http->withOptions(['headers' => ['X-Fixture' => 'derived'], 'max_redirects' => 9]);
            $response = $derived->request('GET', 'https://ses.example.test/stream');
            $contents = '';
            foreach ($derived->stream($response) as $key => $chunk) {
                $this->assertSame($response, $key);
                $contents .= $chunk->getContent();
            }
            $this->assertSame('first', $contents);
            $this->assertSame('second', $http->request('GET', 'https://ses.example.test/original')->getContent());
            $this->assertStringContainsString('X-Fixture: derived', \implode("\n", $fixture->requests[0]['options']['headers']));
            $this->assertStringNotContainsString('X-Fixture:', \implode("\n", $fixture->requests[1]['options']['headers']));
            try {
                $response = $derived->request('GET', 'https://ses.example.test/redirect');
                $this->assertInstanceOf(Swift_Transport_Api_SesHttpResponse::class, $response);
                $response->getContent();
                $this->fail('Redirect must latch across derived clients.');
            } catch (Swift_Transport_NonRetryableException $error) {
                $this->assertSame(307, $error->getCode());
            }
            try {
                $http->request('GET', 'https://ses.example.test/fallback');
                $this->fail('The original scoped HTTP boundary must retain a derived-client refusal.');
            } catch (Swift_Transport_NonRetryableException $error) {
                $this->assertSame(307, $error->getCode());
            }
            $this->assertCount(3, $fixture->requests);
            // The outer operation must still refuse after a callback catches the inner refusal.
            $this->expectException(Swift_Transport_NonRetryableException::class);
        });
    }

    /** @dataProvider timeoutResponses */
    public function testSdkTimedResolvePreservesStreamingAndStableRefusal(int $status, bool $retry): void
    {
        $fixture = new SesSdkFixture([new MockResponse('{"MessageId":"id"}', ['http_code' => $status, 'response_headers' => ['Location: /elsewhere']])], $retry);
        try {
            $id = Swift_Transport_Api_SesRequestPolicy::run($fixture->client, function (SesClient $client): ?string {
                $result = $client->sendEmail([
                    'FromEmailAddress' => 'from@example.test',
                    'Destination'      => ['ToAddresses' => ['to@example.test']],
                    'Content'          => ['Simple' => ['Subject' => ['Data' => 'fixture'], 'Body' => ['Text' => ['Data' => 'body']]]],
                ]);
                $this->assertTrue($result->resolve(1.0));

                return $result->getMessageId();
            });
            $this->assertSame(200, $status);
            $this->assertSame('id', $id);
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(307, $status);
            $this->assertSame(307, $error->getCode());
        }
        $this->assertCount(1, $fixture->requests);
        unset($fixture);
        \gc_collect_cycles();
    }

    public static function timeoutResponses(): iterable
    {
        foreach ([200, 307] as $status) {
            foreach ([false, true] as $retry) {
                yield "$status retry ".(int) $retry => [$status, $retry];
            }
        }
    }

    /** @dataProvider unsupported */
    public function testUnsupportedOwnershipFailsBeforeNetwork(string $kind, bool $warm): void
    {
        $fixture = new SesSdkFixture();
        $client  = $fixture->client;
        $called  = (object) ['count' => 0];
        if ('sdk' === $kind) {
            $client = new class {
                public function sendEmail(): never
                {
                    throw new LogicException('Opaque SDK must not run.');
                }
            };
        } elseif ('subclass' === $kind) {
            $client = new class extends SesClient {
            };
        } elseif ('uninitialized' === $kind) {
            $client = (new ReflectionClass(SesClient::class))->newInstanceWithoutConstructor();
        } elseif ('http' === $kind) {
            $http = new class extends MockHttpClient {
            };
            (new ReflectionProperty(AbstractApi::class, 'httpClient'))->setValue($client, $http);
        } else {
            $provider = new class($called) implements CredentialProvider {
                public function __construct(private object $called)
                {
                }

                public function getCredentials(Configuration $configuration): ?Credentials
                {
                    ++$this->called->count;

                    return new Credentials('custom-key', 'custom-secret');
                }
            };
            if ('iterator' === $kind) {
                $provider = new ChainProvider((static function () use ($called, $provider) {
                    ++$called->count;
                    yield $provider;
                })());
            }
            $provider = new CacheProvider($provider);
            if ($warm) {
                $key = \sha1(\serialize($client->getConfiguration()));
                (new ReflectionProperty(CacheProvider::class, 'cache'))->setValue($provider, [$key => new Credentials('cached-key', 'cached-secret')]);
            }
            (new ReflectionProperty(AbstractApi::class, 'credentialProvider'))->setValue($client, $provider);
        }
        $transport = new Swift_Transport_Api_AmazonSesHttpTransport($client);
        $this->assertFalse($transport->ping());
        try {
            $transport->send($this->message());
            $this->fail('Unsupported ownership must fail closed.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertSame([], $fixture->requests);
        $this->assertSame(0, $called->count);
    }

    public static function unsupported(): iterable
    {
        foreach (['sdk', 'subclass', 'uninitialized', 'http', 'provider', 'iterator'] as $kind) {
            foreach ([false, true] as $warm) {
                yield "$kind warm ".(int) $warm => [$kind, $warm];
            }
        }
    }

    /** @dataProvider nullableProviders */
    public function testStandaloneProviderGetsIsolatedDefaultHttpWithoutMutatingNull(string $class): void
    {
        $provider = new $class();
        $fixture  = new SesSdkFixture([], false, $provider);
        Swift_Transport_Api_SesRequestPolicy::run($fixture->client, function (SesClient $client) use ($provider, $class): void {
            $wrapper = (new ReflectionProperty(AbstractApi::class, 'credentialProvider'))->getValue($client);
            $copy    = $wrapper->getProvider();
            $this->assertNotSame($provider, $copy);
            $this->assertInstanceOf(Swift_Transport_Api_SesHttpClient::class, (new ReflectionProperty($class, 'httpClient'))->getValue($copy));
            $this->assertNull((new ReflectionProperty($class, 'httpClient'))->getValue($provider));
        });
        $this->assertSame([], $fixture->requests);
    }

    public static function nullableProviders(): iterable
    {
        yield [AsyncAws\Core\Credentials\WebIdentityProvider::class];
        yield [AsyncAws\Core\Credentials\IniFileProvider::class];
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'body'))->setFrom('from@example.test')->setTo('to@example.test');
    }
}
