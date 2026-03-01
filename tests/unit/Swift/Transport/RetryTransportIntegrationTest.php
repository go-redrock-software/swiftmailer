<?php

class Swift_Transport_RetryTransportIntegrationTest extends PHPUnit\Framework\TestCase
{
    public function testRetryWithFlakyTransportEventuallySucceeds()
    {
        $dispatcher  = new Swift_Events_SimpleEventDispatcher();
        $failCount   = 0;
        $maxFailures = 2;

        // Anonymous transport that fails N times then succeeds
        $flakyTransport = new class($dispatcher, $failCount, $maxFailures) extends Swift_Transport_AbstractHttpApiTransport {
            private int $callCount = 0;

            private int $failRef;

            private int $maxFail;

            public function __construct(Swift_Events_EventDispatcher $d, int &$failCount, int $maxFailures)
            {
                parent::__construct('test-key', new GuzzleHttp\Client(), $d);
                $this->failRef = &$failCount;
                $this->maxFail = $maxFailures;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                ++$this->callCount;
                if ($this->callCount <= $this->maxFail) {
                    ++$this->failRef;
                    throw new Exception('Service temporarily unavailable');
                }

                return ['message_id' => 'retry-test-'.\uniqid(), 'recipients' => \count($message->getTo())];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(Psr\Http\Message\ResponseInterface $r): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        $retry = new Swift_Transport_RetryTransport($flakyTransport, maxRetries: 3, baseDelayMs: 0);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Retry integration test')
            ->setBody('Hello');

        $result = $retry->send($message);

        $this->assertSame(1, $result);
        $this->assertSame(2, $failCount);
    }

    public function testRetryWithPermanentFailureDoesNotRetry()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $callCount  = 0;

        $permanentFailTransport = new class($dispatcher, $callCount) extends Swift_Transport_AbstractHttpApiTransport {
            private int $countRef;

            public function __construct(Swift_Events_EventDispatcher $d, int &$callCount)
            {
                parent::__construct('bad-key', new GuzzleHttp\Client(), $d);
                $this->countRef = &$callCount;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                ++$this->countRef;
                throw new Exception('Invalid API key provided');
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(Psr\Http\Message\ResponseInterface $r): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        $retry = new Swift_Transport_RetryTransport($permanentFailTransport, maxRetries: 3, baseDelayMs: 0);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('Auth fail test')
            ->setBody('Hello');

        try {
            $retry->send($message);
            $this->fail('Expected Swift_TransportException');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
        }

        // AbstractHttpApiTransport wraps the exception, so the retry transport sees code 0
        // but the "Invalid API key" message triggers permanent classification
        $this->assertSame(1, $callCount);
    }

    public function testRetryTransportWithPlugins()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $sendCount  = 0;

        $flakyTransport = new class($dispatcher, $sendCount) extends Swift_Transport_AbstractHttpApiTransport {
            private int $calls = 0;

            private int $countRef;

            public function __construct(Swift_Events_EventDispatcher $d, int &$sendCount)
            {
                parent::__construct('test-key', new GuzzleHttp\Client(), $d);
                $this->countRef = &$sendCount;
            }

            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                ++$this->calls;
                ++$this->countRef;
                if (1 === $this->calls) {
                    throw new Exception('Connection timed out');
                }

                return ['message_id' => 'test-id', 'recipients' => \count($message->getTo())];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(Psr\Http\Message\ResponseInterface $r): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register a logger plugin to verify events still flow through
        $logger       = new Swift_Plugins_Loggers_ArrayLogger();
        $loggerPlugin = new Swift_Plugins_LoggerPlugin($logger);

        $retry = new Swift_Transport_RetryTransport($flakyTransport, maxRetries: 3, baseDelayMs: 0);
        $retry->registerPlugin($loggerPlugin);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Plugin test')
            ->setBody('Hello');

        $result = $retry->send($message);

        $this->assertSame(1, $result);
        $this->assertSame(2, $sendCount);

        // Logger should have captured events
        $log = $logger->dump();
        $this->assertNotEmpty($log);
    }

    public function testRetryTransportWithDsnFactory()
    {
        $factory = new Swift_Transport_DsnTransportFactory();

        // Test retry() wrapper syntax
        $transport = $factory->fromDsnString('retry(null://default)');
        $this->assertInstanceOf(Swift_Transport_RetryTransport::class, $transport);

        $message = (new Swift_Message())
            ->setFrom(['sender@example.com'])
            ->setTo(['to@example.com'])
            ->setSubject('DSN test')
            ->setBody('Hello');

        // NullTransport always returns recipient count
        $result = $transport->send($message);
        $this->assertSame(1, $result);
    }
}
