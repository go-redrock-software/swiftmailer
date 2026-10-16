<?php

namespace Swift\Transport\Api;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SweegoHttpContractTest extends TestCase
{
    /** @dataProvider dispatchersAndEnvelopes */
    public function testRequestOptionsAndPublicSentMetadata(bool $injected, bool $explicit): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://api.sweego.io/send', $this->callback(function (array $options): bool {
            $this->assertSame([
                'Api-Key'      => 'fixture-api-key',
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ], $options['headers']);
            $this->assertFalse($options['http_errors']);
            $this->assertSame([['email' => 'first@safe.test'], ['email' => 'second@safe.test']], $options['json']['recipients']);
            $this->assertSame([['email' => 'copy@safe.test']], $options['json']['cc']);
            $this->assertSame([['email' => 'blind@safe.test']], $options['json']['bcc']);

            return true;
        }))->willReturn(new Response(202, [], '{"transaction_id":"contract-id"}'));
        $dispatcher = $injected ? new \Swift_Events_SimpleEventDispatcher() : null;
        $transport  = new \Swift_Transport_Api_SweegoTransport('fixture-api-key', $client, $dispatcher);
        $observer   = $this->observe($transport);
        $message    = $this->message();
        $original   = $message->toString();
        $failed     = ['earlier@safe.test'];
        $this->assertSame(4, $transport->send($message, $failed, $explicit ? \Swift_Envelope::fromMessage($message) : null));
        $this->assertSame(['earlier@safe.test'], $failed);
        $this->assertCount(1, $observer->messages);
        $sent = $observer->messages[0];
        $this->assertSame('contract-id', $sent->getMessageId());
        $this->assertSame(4, $sent->getRecipientCount());
        $this->assertSame(['message_id' => 'contract-id', 'recipients' => 4], $sent->getDebug());
        $this->assertSame([], $sent->getFailedRecipients());
        $this->assertSame($message, $sent->getOriginalMessage());
        $this->assertSame($transport, $sent->getTransport());
        $this->assertSame($original, $message->toString());
        if ($injected) {
            $this->assertSame($dispatcher, $transport->eventDispatcher);
        }
    }

    public static function dispatchersAndEnvelopes(): iterable
    {
        foreach ([false, true] as $injected) {
            foreach ([false, true] as $explicit) {
                yield 'dispatcher '.(int) $injected.' / envelope '.(int) $explicit => [$injected, $explicit];
            }
        }
    }

    /** @dataProvider providerErrors */
    public function testRealGuzzleMiddlewarePreservesErrorDetails(int $status, array $body, string $detail): void
    {
        $history   = [];
        $client    = $this->client(new Response($status, [], \json_encode($body, \JSON_THROW_ON_ERROR)), $history);
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-api-key', $client);
        $observer  = $this->observe($transport);
        $message   = $this->message();
        $original  = $message->toString();
        $failed    = [];
        try {
            $transport->send($message, $failed);
            $this->fail('A provider rejection must reach normal transport failure handling.');
        } catch (\Swift_TransportException $error) {
            $this->assertStringContainsString('Sweego API error ('.$status.'): '.$detail, $error->getMessage());
            $this->assertInstanceOf(\Swift_TransportException::class, $error->getPrevious());
        }
        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
        $this->assertSame('fixture-api-key', $history[0]['request']->getHeaderLine('Api-Key'));
        $this->assertFalse($history[0]['options']['http_errors']);
        $this->assertSame(['first@safe.test', 'second@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
        $this->assertSame([], $observer->messages);
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);
    }

    public static function providerErrors(): iterable
    {
        foreach ([422, 503] as $status) {
            foreach ([
                'message wins'             => [['message' => 'primary detail', 'error' => 'secondary detail'], 'primary detail'],
                'message only'             => [['message' => 'only detail'], 'only detail'],
                'error only'               => [['error' => 'fallback detail'], 'fallback detail'],
                'null message'             => [['message' => null, 'error' => 'fallback detail'], 'fallback detail'],
                'absent details'           => [[], 'Unknown error'],
                'empty message is present' => [['message' => '', 'error' => 'secondary detail'], ''],
                'zero message is present'  => [['message' => '0', 'error' => 'secondary detail'], '0'],
            ] as $label => [$body, $detail]) {
                yield $status.' / '.$label => [$status, $body, $detail];
            }
        }
    }

    /** @dataProvider statusBoundaries */
    public function testRealGuzzleReturnedStatusBoundaries(int $status, bool $success): void
    {
        $history = [];
        // No Location header: this checks the returned status, not redirect policy (#53).
        $client    = $this->client(new Response($status, [], '{"transaction_id":"status-id"}'), $history);
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-api-key', $client);
        $observer  = $this->observe($transport);
        $failed    = [];
        try {
            $count = $transport->send($this->message(), $failed);
            $this->assertTrue($success, 'A non-success HTTP status must fail.');
            $this->assertSame(4, $count);
            $this->assertCount(1, $observer->messages);
            $this->assertSame('status-id', $observer->messages[0]->getMessageId());
            $this->assertSame([], $failed);
        } catch (\Swift_TransportException $error) {
            $this->assertFalse($success, $error->getMessage());
            $this->assertSame([], $observer->messages);
            $this->assertSame(['first@safe.test', 'second@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
            if ($status < 400) {
                $this->assertInstanceOf(\Swift_Transport_NonRetryableException::class, $error->getPrevious());
            }
        }
        $this->assertCount(1, $history);
    }

    public static function statusBoundaries(): iterable
    {
        foreach ([199 => false, 200 => true, 299 => true, 300 => false, 399 => false, 400 => false] as $status => $success) {
            yield (string) $status => [$status, $success];
        }
    }

    /** @dataProvider parserOverrideStatuses */
    public function testHttpStatusValidationIsIndependentOfAParserOverride(int $status, bool $success): void
    {
        $history = [];
        $client  = $this->client(new Response($status, [
            'X-Provider-Id'    => 'custom-parser-id',
            'X-Provider-Error' => 'custom rejection',
        ]), $history);
        $transport = new class('fixture-api-key', $client) extends \Swift_Transport_Api_SweegoTransport {
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [
                    'transaction_id' => $response->getHeaderLine('X-Provider-Id'),
                    'message'        => $response->getHeaderLine('X-Provider-Error'),
                ];
            }
        };
        $observer = $this->observe($transport);
        $failed   = [];
        try {
            $count = $transport->send($this->message(), $failed);
            $this->assertTrue($success, 'A custom parser must not bypass the inherited HTTP status check.');
            $this->assertSame(4, $count);
            $this->assertCount(1, $observer->messages);
            $this->assertSame('custom-parser-id', $observer->messages[0]->getMessageId());
            $this->assertSame([], $failed);
        } catch (\Swift_TransportException $error) {
            $this->assertFalse($success, $error->getMessage());
            $this->assertStringContainsString('Sweego API error ('.$status.'): custom rejection', $error->getMessage());
            $this->assertSame([], $observer->messages);
            $this->assertSame(['first@safe.test', 'second@safe.test', 'copy@safe.test', 'blind@safe.test'], $failed);
        }
        $this->assertCount(1, $history);
    }

    public static function parserOverrideStatuses(): iterable
    {
        foreach ([199 => false, 200 => true, 299 => true, 300 => false, 400 => false] as $status => $success) {
            yield (string) $status => [$status, $success];
        }
    }

    /** @dataProvider senderNames */
    public function testSenderNamesPreserveEveryPresentString(?string $name, bool $injected): void
    {
        $history    = [];
        $client     = $this->client(new Response(200, [], '{"transaction_id":"name-id"}'), $history);
        $transport  = new \Swift_Transport_Api_SweegoTransport('fixture-api-key', $client, $injected ? new \Swift_Events_SimpleEventDispatcher() : null);
        $message    = $this->message()->setFrom(['sender@safe.test' => $name]);
        $original   = $message->toString();
        $nativeFrom = $message->getFrom();
        $this->assertSame(4, $transport->send($message));
        $this->assertCount(1, $history);
        $payload  = \json_decode((string) $history[0]['request']->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        $expected = ['email' => 'sender@safe.test'];
        if (null !== $name && '' !== $name) {
            $expected['name'] = $name;
        }
        $this->assertSame($expected, $payload['from']);
        $this->assertSame($nativeFrom, $message->getFrom());
        $this->assertSame($original, $message->toString());
    }

    public static function senderNames(): iterable
    {
        foreach ([null, '', '0', '00', 'Sender Name', '零', ' '] as $index => $name) {
            foreach ([false, true] as $injected) {
                yield 'name '.$index.' / dispatcher '.(int) $injected => [$name, $injected];
            }
        }
    }

    private function client(Response $response, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function observe(\Swift_Transport $transport): object
    {
        $observer = new class implements \Swift_Events_SentMessageListener {
            public array $messages = [];

            public function sentMessage(\Swift_Events_SentMessageEvent $evt): void
            {
                $this->messages[] = $evt->getSentMessage();
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }

    private function message(): \Swift_Message
    {
        return (new \Swift_Message('HTTP contract'))
            ->setFrom('sender@safe.test')
            ->setTo(['first@safe.test' => null, 'second@safe.test' => null])
            ->setCc('copy@safe.test')
            ->setBcc('blind@safe.test')
            ->setBody('Contract body');
    }
}
