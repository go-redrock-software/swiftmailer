<?php

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** The private HTTP boundary of a SES transport operation. */
class Swift_Transport_Api_SesHttpClient implements HttpClientInterface
{
    private HttpClientInterface $client;

    public function __construct(#[SensitiveParameter] HttpClientInterface $client, private Swift_Transport_Api_SesRequestPolicy $policy)
    {
        $this->client = $this->scope($client, new SplObjectStorage());
    }

    private function scope(#[SensitiveParameter] HttpClientInterface $client, SplObjectStorage $visiting): HttpClientInterface
    {
        $class = $client::class;
        if (\in_array($class, [
            Symfony\Component\HttpClient\CurlHttpClient::class,
            Symfony\Component\HttpClient\NativeHttpClient::class,
            Symfony\Component\HttpClient\AmpHttpClient::class,
            Symfony\Component\HttpClient\MockHttpClient::class,
        ], true)) {
            return $client;
        }
        if (!\in_array($class, [
            RetryableHttpClient::class,
            Symfony\Component\HttpClient\TraceableHttpClient::class,
            Symfony\Component\HttpClient\ScopingHttpClient::class,
        ], true) || isset($visiting[$client])) {
            throw new Swift_Transport_NonRetryableException('The custom SES HTTP client does not support isolated redirect handling.');
        }
        $visiting[$client] = true;
        $copy              = Swift_Transport_Api_SesRequestPolicy::copy($client);
        $inner             = Swift_Transport_Api_SesRequestPolicy::read($class, 'client', $client);
        if (!$inner instanceof HttpClientInterface) {
            throw new Swift_Transport_NonRetryableException('The SES HTTP decorator must own a compatible client.');
        }
        Swift_Transport_Api_SesRequestPolicy::replace($class, 'client', $copy, $this->scope($inner, $visiting));
        if (RetryableHttpClient::class === $class) {
            $strategy = Swift_Transport_Api_SesRequestPolicy::read($class, 'strategy', $client);
            if (!$strategy instanceof RetryStrategyInterface) {
                throw new Swift_Transport_NonRetryableException('The SES HTTP retry strategy is unsupported.');
            }
            // Instantiated only when Symfony's optional retry component is present.
            $guard = new class($strategy, $this->policy) implements RetryStrategyInterface {
                public function __construct(private RetryStrategyInterface $strategy, private Swift_Transport_Api_SesRequestPolicy $policy)
                {
                }

                public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
                {
                    $this->policy->assertNoRedirect();
                    $status = $context->getStatusCode();
                    if ($status >= 300 && $status < 400) {
                        $this->policy->refuseRedirect($status);

                        return false;
                    }

                    $path         = \parse_url($context->getInfo('url'), \PHP_URL_PATH);
                    $acceptedSend = $status >= 200 && $status < 300 && 'POST' === $context->getInfo('http_method') && \is_string($path) && \str_ends_with($path, '/v2/email/outbound-emails');
                    if ($acceptedSend) {
                        // Record success headers before caller code can throw or retry.
                        $this->policy->observeSendStatus($status);
                    }
                    $this->policy->assertAcceptance();
                    $retry = $this->strategy->shouldRetry($context, $responseContent, $exception);
                    if ($acceptedSend && true === $retry) {
                        $this->policy->refuseAcceptance($status);
                    }

                    return $retry;
                }

                public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
                {
                    $this->policy->assertNoRedirect();

                    return $this->strategy->getDelay($context, $responseContent, $exception);
                }
            };
            Swift_Transport_Api_SesRequestPolicy::replace($class, 'strategy', $copy, $guard);
        }
        unset($visiting[$client]);

        return $copy;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->policy->assertNoRedirect();
        $options['max_redirects'] = 0;

        $path = \parse_url($url, \PHP_URL_PATH);
        $send = 'POST' === $method && \is_string($path) && \str_ends_with($path, '/v2/email/outbound-emails');

        return new Swift_Transport_Api_SesHttpResponse($this->client->request($method, $url, $options), $this->policy, $send);
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->policy->assertNoRedirect();
        $map   = new SplObjectStorage();
        $inner = [];
        foreach ($responses instanceof ResponseInterface ? [$responses] : $responses as $response) {
            if (!$response instanceof Swift_Transport_Api_SesHttpResponse) {
                throw new Swift_Transport_NonRetryableException('SES must stream responses from its scoped HTTP client.');
            }
            $native       = $response->getResponse();
            $map[$native] = $response;
            $inner[]      = $native;
        }

        return new Symfony\Component\HttpClient\Response\ResponseStream((function () use ($map, $inner, $timeout) {
            foreach ($this->client->stream($inner, $timeout) as $response => $chunk) {
                $scoped = $map[$response];
                if ($chunk->isFirst()) {
                    $scoped->getStatusCode();
                }
                yield $scoped => $chunk;
            }
        })());
    }

    public function withOptions(array $options): static
    {
        if (!(new ReflectionObject($this->client))->hasMethod('withOptions')) {
            throw new Swift_Transport_NonRetryableException('This Symfony HTTP version does not support derived request defaults.');
        }

        $copy         = clone $this;
        $copy->client = $this->scope($this->client, new SplObjectStorage())->withOptions($options);

        return $copy;
    }
}
