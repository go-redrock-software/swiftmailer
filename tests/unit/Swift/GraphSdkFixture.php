<?php

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider;
use Microsoft\Kiota\Serialization\Json\JsonParseNodeFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Real SDK serialization and middleware with a synthetic in-memory Graph endpoint. */
class Swift_GraphSdkFixture
{
    public GraphServiceClient $client;

    public array $requests = [];

    public function __construct(?callable $respond = null)
    {
        $handler = function (RequestInterface $request, array $options) use ($respond) {
            $this->requests[] = ['request' => $request, 'body' => (string) $request->getBody(), 'options' => $options];

            return Create::promiseFor($respond ? $respond($request) : $this->respond($request));
        };
        $http         = new Client(['handler' => GraphClientFactory::getDefaultHandlerStack($handler)]);
        $this->client = GraphServiceClient::createWithRequestAdapter(new GraphRequestAdapter(new AnonymousAuthenticationProvider(), $http));
    }

    public function paths(): array
    {
        return \array_map(static fn (array $entry): string => $entry['request']->getMethod().' '.\rawurldecode($entry['request']->getUri()->getPath()), $this->requests);
    }

    public function payload(int $index = -1): array
    {
        return \json_decode($this->requests[$index < 0 ? \count($this->requests) + $index : $index]['body'], true, 512, \JSON_THROW_ON_ERROR);
    }

    public function mailBody(): SendMailPostRequestBody
    {
        foreach (\array_reverse($this->requests) as $entry) {
            if (\str_ends_with($entry['request']->getUri()->getPath(), '/sendMail')) {
                return (new JsonParseNodeFactory())->getRootParseNode('application/json', Utils::streamFor($entry['body']))->getObjectValue([SendMailPostRequestBody::class, 'createFromDiscriminatorValue']);
            }
        }
        throw new RuntimeException('No sendMail request was recorded.');
    }

    public static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], \json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function respond(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ('GET' === $request->getMethod() && \str_ends_with($path, '/events')) {
            return self::json(200, ['value' => []]);
        }
        if ('POST' === $request->getMethod() && \str_ends_with($path, '/events')) {
            return self::json(201, ['id' => 'fixture-event']);
        }
        if ('POST' === $request->getMethod() && \preg_match('~/(sendMail|send|cancel)$~', $path)) {
            return new Response(202);
        }
        if ('PATCH' === $request->getMethod() && \str_contains($path, '/events/')) {
            return self::json(200, ['id' => 'fixture-event']);
        }

        return self::json(400, ['error' => ['code' => 'UnexpectedFixtureRequest', 'message' => $request->getMethod().' '.$path]]);
    }
}
