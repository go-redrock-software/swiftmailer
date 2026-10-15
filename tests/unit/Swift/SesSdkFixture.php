<?php

use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Ses\SesClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

/** Real AsyncAws signing/serialization with an entirely synthetic HTTP boundary. */
final class SesSdkFixture
{
    public SesClient $client;

    public MockHttpClient $http;

    public array $requests = [];

    public $onRequest;

    public function __construct(array $responses = [], bool $retry = false, ?CredentialProvider $credentials = null, array $configuration = [])
    {
        $this->http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = $options['body'] ?? '';
            if (\is_resource($body)) {
                $body = \stream_get_contents($body);
            } elseif (\is_callable($body)) {
                $parts = '';
                while ('' !== $part = $body(16384)) {
                    $parts .= $part;
                }
                $body = $parts;
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options, 'body' => $body];

            if (null !== $this->onRequest) {
                ($this->onRequest)($this->requests[\array_key_last($this->requests)]);
            }

            return \array_shift($responses) ?? new MockResponse('{"MessageId":"fixture-id"}', ['http_code' => 200]);
        });
        $http         = $retry ? new RetryableHttpClient($this->http, new GenericRetryStrategy([301, 302, 303, 307, 308, 503], 0), 2) : $this->http;
        $this->client = new SesClient($configuration + [
            'region'      => 'us-east-1', 'endpoint' => 'https://ses.example.test',
            'accessKeyId' => 'fixture-key', 'accessKeySecret' => 'fixture-secret',
        ], $credentials, $http);
    }

    public function input(): AsyncAws\Ses\Input\SendEmailRequest
    {
        $payload = $this->payload();
        if (isset($payload['Content']['Raw']['Data'])) {
            $payload['Content']['Raw']['Data'] = \base64_decode($payload['Content']['Raw']['Data'], true);
        }

        return AsyncAws\Ses\Input\SendEmailRequest::create($payload);
    }

    public function payload(int $index = 0): array
    {
        return \json_decode($this->requests[$index]['body'], true, 512, \JSON_THROW_ON_ERROR);
    }
}
