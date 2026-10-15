<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_NoRedirectClientTest extends TestCase
{
    /** @dataProvider paths */
    public function testEveryRequestPathRejectsRedirectAndLatchesTheOperation(string $path, bool $configuration): void
    {
        $wire    = [];
        $handler = new MockHandler([
            static function ($request, $options) use (&$wire) {
                $wire[] = [$request, $options];

                return new Response(307, ['Location' => 'https://other.safe.test/collect']);
            },
            new Response(200),
        ]);
        $stack       = HandlerStack::create($handler);
        $original    = new Client(['handler' => $stack, 'allow_redirects' => ['strict' => true], 'timeout' => 19]);
        $before      = $original->getConfig();
        $stackBefore = (string) $stack;
        $guard       = new Swift_Transport_Api_NoRedirectClient($original);
        $client      = $configuration ? new Client($guard->getConfig()) : $guard;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->invoke($client, $path);
                $this->fail('A redirect cannot be accepted or replayed.');
            } catch (Swift_Transport_NonRetryableException $error) {
                $this->assertSame(307, $error->getCode());
                $this->assertStringNotContainsString('other.safe.test', $error->getMessage());
            }
        }
        $this->assertCount(1, $wire);
        $this->assertFalse($wire[0][1]['allow_redirects']);
        $this->assertSame(19, $wire[0][1]['timeout']);
        $this->assertSame($before, $original->getConfig());
        $this->assertSame($stackBefore, (string) $stack);
        $this->assertFalse($guard->getConfig('allow_redirects'));
        $this->assertSame(19, $guard->getConfig('timeout'));
        $this->assertNotSame($stack, $guard->getConfig('handler'));
        $this->assertSame($guard->getConfig('handler'), $guard->getConfig()['handler']);
    }

    /** @dataProvider paths */
    public function testSuccessfulResponseAndOrdinaryErrorsRetainTheirIdentity(string $path, bool $configuration): void
    {
        $response = new Response(202, ['X-Fixture' => 'accepted']);
        $error    = new RuntimeException('fixture transport failure');
        $guard    = new Swift_Transport_Api_NoRedirectClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response, $error]))]));
        $client   = $configuration ? new Client($guard->getConfig()) : $guard;
        $this->assertSame($response, $this->invoke($client, $path));
        try {
            $this->invoke($client, $path);
            $this->fail('Ordinary HTTP failures must propagate.');
        } catch (RuntimeException $actual) {
            $this->assertSame($error, $actual);
        }
    }

    public static function paths(): iterable
    {
        foreach (['send', 'sendAsync', 'request', 'requestAsync'] as $path) {
            foreach ([false, true] as $configuration) {
                yield $path.' / copied config '.(int) $configuration => [$path, $configuration];
            }
        }
    }

    public function testCallableHandlerIsWrappedAndAliasesResolve(): void
    {
        $handler = new MockHandler([new Response(308, ['Location' => '/elsewhere'])]);
        $guard   = new Swift_Transport_Api_NoRedirectClient(new Client(['handler' => $handler]));
        $this->assertInstanceOf(Swift\Transport\Api\NoRedirectClient::class, $guard);
        $this->expectException(Swift_Transport_NonRetryableException::class);
        (new Client($guard->getConfig()))->request('POST', 'https://source.safe.test/send');
    }

    /** @dataProvider invalidConfigurations */
    public function testUnsupportedConfigurationFailsBeforeCreatingANetworkClient(mixed $configuration): void
    {
        $original = $this->createMock(ClientInterface::class);
        $original->method('getConfig')->willReturn($configuration);
        $original->expects($this->never())->method('send');
        $guard = new Swift_Transport_Api_NoRedirectClient($original);
        $this->expectException(Swift_Transport_NonRetryableException::class);
        $guard->getConfig();
    }

    public static function invalidConfigurations(): iterable
    {
        yield [null];
        yield [[]];
        yield [['handler' => 'not-a-callable']];
    }

    private function invoke(ClientInterface $client, string $path): Psr\Http\Message\ResponseInterface
    {
        $options = ['allow_redirects' => true];
        $result  = match ($path) {
            'send'         => $client->send(new Request('POST', 'https://source.safe.test/send'), $options),
            'sendAsync'    => $client->sendAsync(new Request('POST', 'https://source.safe.test/send'), $options),
            'request'      => $client->request('POST', 'https://source.safe.test/send', $options),
            'requestAsync' => $client->requestAsync('POST', 'https://source.safe.test/send', $options),
        };

        return $result instanceof GuzzleHttp\Promise\PromiseInterface ? $result->wait() : $result;
    }
}
