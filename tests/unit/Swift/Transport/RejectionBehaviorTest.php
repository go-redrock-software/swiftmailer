<?php

class Swift_Transport_RejectionBehaviorTest extends PHPUnit\Framework\TestCase
{
    public function testHttpApiTransportReturnsZeroOnRejection()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();

        $transport = new class('test-key', new GuzzleHttp\Client(), $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
            {
                return ['message_id' => 'test', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.example.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return ['Authorization' => 'Bearer test-key'];
            }

            protected function parseResponse(Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register a plugin that rejects all messages
        $rejecter = new class implements Swift_Events_SendListener {
            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                $evt->reject('Test rejection');
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
            }
        };
        $transport->registerPlugin($rejecter);

        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test')
            ->setBody('Hello');

        $result = $transport->send($message);

        $this->assertSame(0, $result);
    }

    public function testRejectionReasonIsAccessibleInSendPerformed()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();

        $transport = new class('test-key', new GuzzleHttp\Client(), $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
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

            protected function parseResponse(Psr\Http\Message\ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.example.com/ping';
            }
        };

        // Register rejecting plugin
        $transport->registerPlugin(new class implements Swift_Events_SendListener {
            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
                $evt->reject('Suppression list match');
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
            }
        });

        // Register observer plugin that captures the sendPerformed event
        $capturedReason   = null;
        $capturedRejected = null;
        $transport->registerPlugin(new class($capturedReason, $capturedRejected) implements Swift_Events_SendListener {
            private mixed $reasonRef;

            private mixed $rejectedRef;

            public function __construct(&$reason, &$rejected)
            {
                $this->reasonRef   = &$reason;
                $this->rejectedRef = &$rejected;
            }

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                $this->rejectedRef = $evt->isRejected();
                $this->reasonRef   = $evt->getRejectionReason();
            }
        });

        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test')
            ->setBody('Hello');

        $transport->send($message);

        $this->assertTrue($capturedRejected);
        $this->assertSame('Suppression list match', $capturedReason);
    }
}
