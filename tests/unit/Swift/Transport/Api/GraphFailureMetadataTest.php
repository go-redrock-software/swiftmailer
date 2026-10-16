<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_GraphFailureMetadataTest extends TestCase
{
    /** @dataProvider failures */
    public function testResponseMetadataTakesPrecedenceWithoutRetainingCauses(Throwable $source, int $code, bool $terminal, bool $retryable): void
    {
        try {
            $error = Swift_Transport_Api_GraphRequestPolicy::detachedFailure($source);
        } catch (Throwable $unexpected) {
            $this->fail('Failure metadata inspection must return a safe Swift failure, got '.$unexpected::class);
        }
        $this->assertInstanceOf(Swift_TransportException::class, $error);
        $this->assertSame($code, $error->getCode());
        $this->assertSame($terminal, Swift_Transport_RetryPolicy::isTerminalFailure($error));
        $this->assertSame($retryable, (new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        $this->assertNull($error->getPrevious());
        $this->assertStringNotContainsString('TRACE-', $error->getMessage());
    }

    public static function failures(): iterable
    {
        $api = static function (?int $status, int $code = 0): ApiException {
            $error = new ApiException('TRACE-unsafe remote diagnostic: try again forbidden', $code);
            if (null !== $status) {
                $error->setResponseStatusCode($status);
            }

            return $error;
        };
        yield 'Kiota authorization' => [$api(403), 403, false, false];
        yield 'Kiota status outranks exception code' => [$api(403, 503), 403, false, false];
        yield 'HTTP 500 remains transient' => [$api(500), 500, false, true];
        yield 'nested HTTP outranks outer application code' => [new RuntimeException('TRACE-wrapper', 17, $api(429)), 429, false, true];
        yield 'nested Guzzle response' => [new RuntimeException('TRACE-wrapper', 17, new RequestException('TRACE-http', new Request('POST', 'https://graph.safe.test/'), new Response(503))), 503, false, true];
        yield 'redirect marker precedes nested status' => [new Swift_Transport_NonRetryableException('TRACE-redirect', 307, $api(403)), 307, true, false];
        yield 'PHP Error remains terminal' => [new RuntimeException('TRACE-wrapper', 17, new TypeError('TRACE-error')), 17, true, false];
        yield 'missing response status' => [$api(null, 42), 42, false, false];
        yield 'invalid response status is not HTTP metadata' => [$api(999, 42), 42, false, false];
        yield 'nested numeric fallback' => [new RuntimeException('TRACE-wrapper', 0, new RuntimeException('TRACE-inner', 42)), 42, false, false];
        foreach (['invalid_request', 'invalid_client', 'invalid_grant', 'unauthorized_client', 'unsupported_grant_type', 'invalid_scope'] as $reason) {
            foreach ([0, 400] as $status) {
                yield 'OAuth '.$reason.' code '.$status => [new League\OAuth2\Client\Provider\Exception\IdentityProviderException('TRACE-untrusted OAuth diagnostic', $status, ['error' => $reason]), $status, false, false];
            }
        }
        foreach (['server_error', 'temporarily_unavailable'] as $reason) {
            yield 'OAuth transient '.$reason => [new League\OAuth2\Client\Provider\Exception\IdentityProviderException('TRACE-transient', 0, ['error' => $reason]), 0, false, true];
        }
        yield 'OAuth server status remains authoritative' => [new League\OAuth2\Client\Provider\Exception\IdentityProviderException('TRACE-conflicting', 500, ['error' => 'invalid_client']), 500, false, true];
        yield 'OAuth metadata failure is terminal' => [new class('TRACE-metadata', 0, []) extends League\OAuth2\Client\Provider\Exception\IdentityProviderException {
            public function getResponseBody()
            {
                throw new TypeError('TRACE-broken OAuth metadata');
            }
        }, 0, true, false];
        yield 'network with no code' => [new RuntimeException('TRACE-network'), 0, false, true];
        yield 'metadata getter failure is terminal' => [new class('TRACE-metadata') extends ApiException {
            public function getResponseStatusCode(): ?int
            {
                throw new TypeError('TRACE-broken metadata');
            }
        }, 0, true, false];
    }

    /** @dataProvider transientResponses */
    public function testDefaultSwiftRetryStillDeliversAfterTransientGraphErrors(int $status, bool $httpErrors): void
    {
        $wire  = [];
        $queue = [];
        foreach ([new Response($status, ['Content-Type' => 'application/json'], '{"error":{"code":"FixtureFailure","message":"fixture"}}'), new Response(202)] as $response) {
            $queue[] = static function ($request) use (&$wire, $response) {
                $wire[] = $request;

                return $response;
            };
        }
        $http    = new Client(['http_errors' => $httpErrors, 'handler' => HandlerStack::create(new MockHandler($queue))]);
        $adapter = new GraphRequestAdapter(new AnonymousAuthenticationProvider(), $http);
        $client  = GraphServiceClient::createWithRequestAdapter($adapter);
        $this->assertSame($http, (new ReflectionProperty(Microsoft\Kiota\Http\GuzzleRequestAdapter::class, 'guzzleClient'))->getValue($adapter));
        $transport = new Swift_Transport_RetryTransport(new Swift_Transport_Api_MicrosoftGraphTransport($client), 2, 0);
        $this->assertSame(1, $transport->send((new Swift_Message('fixture', 'body'))->setFrom('sender@safe.test')->setTo('to@safe.test'), $failed));
        $this->assertSame([], $failed);
        $this->assertCount(2, $wire);
    }

    public function testHttpStatusIsClearedBeforeANetworkFailureAndByExplicitReset(): void
    {
        $request = new Request('GET', 'https://fixture.safe.test/');
        $http    = new Client(['http_errors' => false, 'handler' => HandlerStack::create(new MockHandler([new Response(401), new Response(403), new GuzzleHttp\Exception\ConnectException('fixture network failure', $request)]))]);
        $guard   = new Swift_Transport_Api_NoRedirectClient($http);
        $guard->send($request);
        $this->assertSame(401, $guard->getLastResponseStatus());
        $guard->resetResponseStatus();
        $this->assertNull($guard->getLastResponseStatus());
        $guard->send($request);
        $this->assertSame(403, $guard->getLastResponseStatus());
        try {
            $guard->send($request);
            $this->fail('The final network attempt must reject.');
        } catch (GuzzleHttp\Exception\ConnectException) {
            $this->assertNull($guard->getLastResponseStatus());
        }
    }

    public static function transientResponses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            foreach ([false, true] as $errors) {
                yield [$status, $errors];
            }
        }
    }
}
