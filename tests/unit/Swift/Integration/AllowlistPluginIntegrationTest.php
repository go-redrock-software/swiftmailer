<?php

namespace Swift\Integration;

use PHPUnit\Framework\TestCase;

class AllowlistPluginIntegrationTest extends TestCase
{
    public function testAllowlistBlocksNonAllowedRecipients(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $sent       = false;

        $transport = new class('test-key', $httpClient, $dispatcher, $sent) extends \Swift_Transport_AbstractHttpApiTransport {
            private bool $sentRef;

            public function __construct(string $apiKey, $httpClient, $dispatcher, bool &$sent)
            {
                parent::__construct($apiKey, $httpClient, $dispatcher);
                $this->sentRef = &$sent;
            }

            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                $this->sentRef = true;

                return ['message_id' => 'test', 'recipients' => \count($message->getTo())];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register allowlist plugin
        $plugin = new \Swift_Plugins_AllowlistPlugin(['*@safe.com']);
        $transport->registerPlugin($plugin);

        // Send to non-allowed recipient
        $message = (new \Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['blocked@external.com'])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        $this->assertSame(0, $result);
        $this->assertFalse($sent);

        // Original recipients should be restored
        $this->assertArrayHasKey('blocked@external.com', $message->getTo());
    }

    public function testAllowlistAllowsMatchingRecipients(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $sentTo     = null;

        $transport = new class('test-key', $httpClient, $dispatcher, $sentTo) extends \Swift_Transport_AbstractHttpApiTransport {
            private mixed $sentToRef;

            public function __construct(string $apiKey, $httpClient, $dispatcher, &$sentTo)
            {
                parent::__construct($apiKey, $httpClient, $dispatcher);
                $this->sentToRef = &$sentTo;
            }

            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                $this->sentToRef = $message->getTo();

                return ['message_id' => 'test', 'recipients' => \count($message->getTo())];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        $plugin = new \Swift_Plugins_AllowlistPlugin(['dev@safe.com', '*@internal.corp']);
        $transport->registerPlugin($plugin);

        $message = (new \Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo([
                'dev@safe.com'         => 'Dev',
                'anyone@internal.corp' => 'Internal',
                'blocked@external.com' => 'Blocked',
            ])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        // Only 2 allowed recipients
        $this->assertCount(2, $sentTo);
        $this->assertArrayHasKey('dev@safe.com', $sentTo);
        $this->assertArrayHasKey('anyone@internal.corp', $sentTo);

        // After send, all 3 original recipients should be restored
        $this->assertCount(3, $message->getTo());
    }

    public function testLoggerCapturesAllowlistRejectionReason(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $transport = new class('test-key', $httpClient, $dispatcher) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register AllowlistPlugin
        $allowlist = new \Swift_Plugins_AllowlistPlugin(['*@safe.com']);
        $transport->registerPlugin($allowlist);

        // Register LoggerPlugin with an ArrayLogger to capture output
        $logger       = new \Swift_Plugins_Loggers_ArrayLogger();
        $loggerPlugin = new \Swift_Plugins_LoggerPlugin($logger);
        $transport->registerPlugin($loggerPlugin);

        // Send to a non-allowed recipient
        $message = (new \Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['blocked@external.com' => 'Blocked User'])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        $this->assertSame(0, $result);

        // LoggerPlugin should have captured the rejection reason from AllowlistPlugin
        $log = $logger->dump();
        $this->assertStringContainsString('rejected', \strtolower($log));
        $this->assertStringContainsString('blocked@external.com', $log);
        $this->assertStringContainsString('allowlist', \strtolower($log));
    }

    public function testLoggerNotTriggeredWhenRecipientsAllowed(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $transport = new class('test-key', $httpClient, $dispatcher) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        $allowlist = new \Swift_Plugins_AllowlistPlugin(['*@safe.com']);
        $transport->registerPlugin($allowlist);

        $logger       = new \Swift_Plugins_Loggers_ArrayLogger();
        $loggerPlugin = new \Swift_Plugins_LoggerPlugin($logger);
        $transport->registerPlugin($loggerPlugin);

        $message = (new \Swift_Message())
            ->setFrom(['sender@safe.com'])
            ->setTo(['allowed@safe.com' => 'Safe User'])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        $this->assertGreaterThan(0, $result);

        // Log should NOT contain rejection messages
        $log = $logger->dump();
        $this->assertStringNotContainsString('rejected', \strtolower($log));
    }
}
