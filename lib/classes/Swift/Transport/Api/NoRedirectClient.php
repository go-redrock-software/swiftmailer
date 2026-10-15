<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle policy for one SDK operation, without changing the supplied client.
 *
 * Google authentication creates clients from getConfig(), so both delegated
 * calls and an isolated handler configuration must enforce the same policy.
 */
class Swift_Transport_Api_NoRedirectClient implements ClientInterface
{
    private ?array $configuration = null;

    private ?Swift_Transport_NonRetryableException $failure = null;

    public function __construct(#[SensitiveParameter] private ClientInterface $client)
    {
    }

    #[Override]
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $this->assertUsable();

        return $this->validateResponse($this->client->send($request, self::options($options)));
    }

    #[Override]
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        $this->assertUsable();

        return $this->client->sendAsync($request, self::options($options))->then($this->validateResponse(...));
    }

    #[Override]
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        $this->assertUsable();

        return $this->validateResponse($this->client->request($method, $uri, self::options($options)));
    }

    #[Override]
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        $this->assertUsable();

        return $this->client->requestAsync($method, $uri, self::options($options))->then($this->validateResponse(...));
    }

    #[Override]
    public function getConfig(?string $option = null): mixed
    {
        if ('allow_redirects' === $option) {
            return false;
        }
        if (null !== $option && 'handler' !== $option) {
            return $this->client->getConfig($option);
        }
        if (null === $this->configuration) {
            $configuration = $this->client->getConfig();
            if (!\is_array($configuration)) {
                throw new Swift_Transport_NonRetryableException('The Google HTTP client must expose a Guzzle-compatible configuration.');
            }
            $handler = $configuration['handler'] ?? null;
            if ($handler instanceof HandlerStack) {
                $stack = clone $handler;
            } elseif (\is_callable($handler)) {
                $stack = HandlerStack::create($handler);
            } else {
                throw new Swift_Transport_NonRetryableException('The Google HTTP client must expose a callable handler for scoped authentication.');
            }
            // SDK authentication may create another client with this configuration.
            $stack->unshift(static function (callable $next): callable {
                return static fn (RequestInterface $request, array $options): PromiseInterface => $next($request, self::options($options));
            }, 'swift_no_redirects');
            // Reject before existing retry middleware or the SDK sees a 3xx result.
            $stack->push(function (callable $next): callable {
                return function (RequestInterface $request, array $options) use ($next): PromiseInterface {
                    $this->assertUsable();

                    return $next($request, $options)->then($this->validateResponse(...));
                };
            }, 'swift_redirect_response');
            $configuration['handler']         = $stack;
            $configuration['allow_redirects'] = false;
            $this->configuration              = $configuration;
        }

        return null === $option ? $this->configuration : $this->configuration['handler'];
    }

    private static function options(array $options): array
    {
        $options['allow_redirects'] = false;

        return $options;
    }

    private function assertUsable(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    private function validateResponse(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            $this->failure = new Swift_Transport_NonRetryableException(\sprintf('Refusing an HTTP redirect (HTTP %d); automatic retry is disabled.', $status), $status);
            throw $this->failure;
        }

        return $response;
    }
}
