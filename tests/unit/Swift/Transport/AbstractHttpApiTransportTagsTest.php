<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_AbstractHttpApiTransportTagsTest extends PHPUnit\Framework\TestCase
{
    private function createTransport(): Swift_Transport_AbstractHttpApiTransport
    {
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $httpClient = $this->createMock(ClientInterface::class);

        return new class('key', $httpClient, $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
            {
                return [];
            }

            protected function getEndpoint(): string
            {
                return '';
            }

            protected function getAuthHeaders(): array
            {
                return [];
            }

            protected function parseResponse(ResponseInterface $response): array
            {
                return [];
            }

            protected function getPingEndpoint(): string
            {
                return '';
            }

            public function testExtractTags(Swift_Mime_SimpleMessage $msg): array
            {
                return $this->extractTags($msg);
            }

            public function testExtractMetadata(Swift_Mime_SimpleMessage $msg): array
            {
                return $this->extractMetadata($msg);
            }
        };
    }

    public function testExtractTags(): void
    {
        $message = new Swift_Message();
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'password-reset');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'transactional');

        $transport = $this->createTransport();
        $tags      = $transport->testExtractTags($message);

        $this->assertEquals(['password-reset', 'transactional'], $tags);
        $this->assertNull($message->getHeaders()->get('X-Mailer-Tag'));
    }

    public function testExtractMetadata(): void
    {
        $message = new Swift_Message();
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-campaign', 'onboarding');

        $transport = $this->createTransport();
        $metadata  = $transport->testExtractMetadata($message);

        $this->assertEquals(['user_id' => '12345', 'campaign' => 'onboarding'], $metadata);
        $this->assertNull($message->getHeaders()->get('X-Mailer-Metadata-user_id'));
        $this->assertNull($message->getHeaders()->get('X-Mailer-Metadata-campaign'));
    }

    public function testExtractTagsReturnsEmptyWhenNone(): void
    {
        $message   = new Swift_Message();
        $transport = $this->createTransport();

        $this->assertEquals([], $transport->testExtractTags($message));
        $this->assertEquals([], $transport->testExtractMetadata($message));
    }
}
