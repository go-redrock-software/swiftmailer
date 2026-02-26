<?php

namespace Swift\Integration;

use PHPUnit\Framework\TestCase;

class SentMessageFlowTest extends TestCase
{
    public function testSentMessagePluginCapturesEventFromHttpApiTransport(): void
    {
        // Create a concrete test transport that always succeeds
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $transport = new class('test-key', $httpClient, $dispatcher) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'integration-test-id', 'recipients' => 1];
            }
            protected function getEndpoint(): string { return 'https://test.example.com/send'; }
            protected function getAuthHeaders(): array { return ['Authorization' => 'Bearer test']; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return 'https://test.example.com/ping'; }
        };

        // Register SentMessagePlugin
        $plugin = new \Swift_Plugins_SentMessagePlugin();
        $transport->registerPlugin($plugin);

        // Send a message
        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('Integration Test')
            ->setBody('Hello');

        $transport->start();
        $count = $transport->send($message);

        // Verify
        $this->assertEquals(1, $count);
        $this->assertNotNull($plugin->getLastSentMessage());
        $this->assertEquals('integration-test-id', $plugin->getLastSentMessage()->getMessageId());
        $this->assertEquals(1, $plugin->getLastSentMessage()->getRecipientCount());
        $this->assertSame($message, $plugin->getLastSentMessage()->getOriginalMessage());
    }

    public function testFailedMessageEventFiresOnError(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $transport = new class('test-key', $httpClient, $dispatcher) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                throw new \RuntimeException('API down');
            }
            protected function getEndpoint(): string { return 'https://test.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return 'https://test.example.com/ping'; }
        };

        // Register a FailedMessageListener using a shared holder object
        $holder = new \stdClass();
        $holder->event = null;
        $listener = new class($holder) implements \Swift_Events_FailedMessageListener {
            private \stdClass $holder;
            public function __construct(\stdClass $holder) { $this->holder = $holder; }
            public function failedMessage(\Swift_Events_FailedMessageEvent $evt): void
            {
                $this->holder->event = $evt;
            }
        };
        $dispatcher->bindEventListener($listener);

        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('Fail Test')
            ->setBody('Hello');

        $transport->start();

        try {
            $transport->send($message);
            $this->fail('Expected exception');
        } catch (\Swift_TransportException $e) {
            // Expected
        }

        $this->assertNotNull($holder->event);
        $this->assertInstanceOf(\Swift_TransportException::class, $holder->event->getException());
        $this->assertEquals(['to@test.com'], $holder->event->getFailedRecipients());
    }

    public function testTagsAreStrippedFromMessage(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $tagHolder = new \stdClass();
        $tagHolder->tags = null;
        $transport = new class('test-key', $httpClient, $dispatcher, $tagHolder) extends \Swift_Transport_AbstractHttpApiTransport {
            private \stdClass $tagHolder;
            public function __construct(string $apiKey, $httpClient, $dispatcher, \stdClass $tagHolder)
            {
                parent::__construct($apiKey, $httpClient, $dispatcher);
                $this->tagHolder = $tagHolder;
            }
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                $this->tagHolder->tags = $this->extractTags($message);
                return ['message_id' => 'tag-test', 'recipients' => 1];
            }
            protected function getEndpoint(): string { return 'https://test.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return 'https://test.example.com/ping'; }
        };

        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('Tag Test')
            ->setBody('Hello');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'campaign-1');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'transactional');

        $transport->start();
        $transport->send($message);

        // Tags were extracted
        $this->assertEquals(['campaign-1', 'transactional'], $tagHolder->tags);

        // Tags are stripped from the message (won't leak to recipients)
        $this->assertNull($message->getHeaders()->get('X-Mailer-Tag'));
    }

    public function testMetadataIsStrippedFromMessage(): void
    {
        $dispatcher = new \Swift_Events_SimpleEventDispatcher();
        $httpClient = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $metaHolder = new \stdClass();
        $metaHolder->meta = null;
        $transport = new class('test-key', $httpClient, $dispatcher, $metaHolder) extends \Swift_Transport_AbstractHttpApiTransport {
            private \stdClass $metaHolder;
            public function __construct(string $apiKey, $httpClient, $dispatcher, \stdClass $metaHolder)
            {
                parent::__construct($apiKey, $httpClient, $dispatcher);
                $this->metaHolder = $metaHolder;
            }
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                $this->metaHolder->meta = $this->extractMetadata($message);
                return ['message_id' => 'meta-test', 'recipients' => 1];
            }
            protected function getEndpoint(): string { return 'https://test.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return 'https://test.example.com/ping'; }
        };

        $message = (new \Swift_Message())
            ->setFrom(['from@test.com' => 'Sender'])
            ->setTo(['to@test.com' => 'Recipient'])
            ->setSubject('Metadata Test')
            ->setBody('Hello');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '42');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-env', 'prod');

        $transport->start();
        $transport->send($message);

        $this->assertEquals(['user_id' => '42', 'env' => 'prod'], $metaHolder->meta);
        $this->assertNull($message->getHeaders()->get('X-Mailer-Metadata-user_id'));
        $this->assertNull($message->getHeaders()->get('X-Mailer-Metadata-env'));
    }
}
