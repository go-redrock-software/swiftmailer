<?php

class Swift_Integration_RecipientPolicyEnvelopeTest extends PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider provideHeaderBasedProviderPolicies
     */
    public function testHeaderBasedProviderRemainsPolicySafe(bool $reject): void
    {
        $client = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->expects($this->exactly($reject ? 0 : 1))->method('request')->willReturnCallback(function ($method, $url, $options) {
            $payload = \json_decode($options['body'], true);
            $this->assertSame([['email' => 'dev@safe.test']], $payload['personalizations'][0]['to']);
            $this->assertStringNotContainsString('blocked@outside.test', $options['body']);

            return new GuzzleHttp\Psr7\Response(202);
        });
        $transport = new Swift_Transport_Api_SendgridTransport('test-key', $client, new Swift_Events_SimpleEventDispatcher());
        $transport->registerPlugin($reject ? new Swift_Plugins_AllowlistPlugin(['*@safe.test']) : new Swift_Plugins_RedirectingPlugin('dev@safe.test'));
        $message  = (new Swift_Message('Provider policy regression'))->setFrom('author@safe.test')->setTo($reject ? 'visible@safe.test' : 'blocked@outside.test')->setBody('Offline');
        $original = $message->getTo();
        $envelope = new Swift_Envelope('bounce@safe.test', ['blocked@outside.test']);
        $failed   = [];

        $this->assertSame($reject ? 0 : 1, $transport->send($message, $failed, $envelope));
        $this->assertSame($original, $message->getTo());
        $this->assertSame(['blocked@outside.test'], $envelope->getRecipients());
    }

    public static function provideHeaderBasedProviderPolicies(): iterable
    {
        yield 'reject blocked envelope despite allowed header' => [true];
        yield 'redirect blocked header and envelope' => [false];
    }

    /**
     * @dataProvider providePoliciesAndTransports
     */
    public function testPoliciesControlDeliveryAndRestoreMessage(string $protocol, string $policy, array $recipients, array $expected, ?string $visibleRecipient, bool $explicitEnvelope = true): void
    {
        $capture    = (object) ['writes' => [], 'requests' => []];
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $transport  = 'smtp' === $protocol
            ? $this->createSmtpTransport($capture, $dispatcher)
            : $this->createHttpTransport($capture, $dispatcher);
        foreach ($this->createPolicies($policy) as $plugin) {
            $transport->registerPlugin($plugin);
        }
        $message = (new Swift_Message('Recipient policy regression'))
            ->setFrom('author@safe.test')
            ->setBcc(['bcc@outside.test' => 'Private recipient'])
            ->setBody('Local recording transport only');
        if (null !== $visibleRecipient) {
            $message->setTo([$visibleRecipient => 'Visible recipient']);
        }
        $original = [$message->getTo(), $message->getCc(), $message->getBcc()];
        $envelope = $explicitEnvelope ? new Swift_Envelope('bounce@safe.test', $recipients) : null;
        $sender   = $explicitEnvelope ? 'bounce@safe.test' : 'author@safe.test';

        for ($send = 0; $send < 2; ++$send) {
            $capture->writes   = [];
            $capture->requests = [];
            $failed            = [];
            $this->assertSame(\count($expected), $transport->send($message, $failed, $envelope));
            $this->assertSame([], $failed);
            if (null !== $envelope) {
                $this->assertSame($recipients, $envelope->getRecipients());
                $this->assertSame('bounce@safe.test', $envelope->getSender());
            }
            $this->assertSame($original, [$message->getTo(), $message->getCc(), $message->getBcc()]);

            if ('smtp' === $protocol) {
                $commands = \array_values(\array_filter($capture->writes, static fn ($line) => \str_starts_with($line, 'RCPT TO:')));
                $this->assertSame(\array_map(static fn ($address) => 'RCPT TO:<'.$address.">\r\n", $expected), $commands);
                $wire = \implode('', $capture->writes);
                if ($expected) {
                    $this->assertStringContainsString('MAIL FROM:<'.$sender.">\r\n", $wire);
                } else {
                    $this->assertStringNotContainsString('MAIL FROM:', $wire);
                }
            } else {
                $this->assertCount($expected ? 1 : 0, $capture->requests);
                if ($expected) {
                    $this->assertSame($expected, $capture->requests[0]['json']['to']);
                    $this->assertSame($sender, $capture->requests[0]['json']['sender']);
                    $this->assertStringNotContainsString('hidden@safe.test', $capture->requests[0]['json']['raw']);
                }
                $wire = \json_encode($capture->requests);
            }
            $this->assertStringNotContainsString('blocked@outside.test', $wire);
            $this->assertStringNotContainsString('bcc@outside.test', $wire);
            $this->assertStringNotContainsString('X-Original-To:', $wire);
        }
        $transport->stop();
    }

    public static function providePoliciesAndTransports(): iterable
    {
        $cases = [
            'filter mixed'                            => ['filter', ['hidden@safe.test', 'blocked@outside.test'], ['hidden@safe.test'], 'visible@safe.test'],
            'filter rejects despite allowed header'   => ['filter', ['blocked@outside.test'], [], 'visible@safe.test'],
            'filter accepts despite blocked header'   => ['filter', ['hidden@safe.test'], ['hidden@safe.test'], 'blocked@outside.test'],
            'filter accepts without To header'        => ['filter', ['hidden@safe.test'], ['hidden@safe.test'], null],
            'catchall mixed'                          => ['catchall', ['hidden@safe.test', 'blocked@outside.test', 'another@outside.test'], ['hidden@safe.test', 'dev@safe.test'], 'visible@safe.test'],
            'catchall all blocked'                    => ['catchall', ['blocked@outside.test'], ['dev@safe.test'], null],
            'catchall already present'                => ['catchall', ['dev@safe.test', 'blocked@outside.test'], ['dev@safe.test'], 'visible@safe.test'],
            'catchall no blocked envelope recipients' => ['catchall', ['hidden@safe.test'], ['hidden@safe.test'], 'blocked@outside.test'],
            'redirect'                                => ['redirect', ['blocked@outside.test'], ['dev@safe.test'], 'visible@safe.test'],
            'redirect whitelist'                      => ['whitelist', ['trusted@trusted.test', 'blocked@outside.test', 'dev@safe.test'], ['trusted@trusted.test', 'dev@safe.test'], null],
            'redirect multiple targets'               => ['multiple', ['blocked@outside.test', 'dev@safe.test'], ['dev@safe.test', 'audit@safe.test'], 'visible@safe.test'],
            'allowlist first rejects before redirect' => ['filter-first', ['blocked@outside.test'], [], 'visible@safe.test'],
            'redirect first permits rewritten target' => ['redirect-first', ['blocked@outside.test'], ['dev@safe.test'], 'blocked@outside.test'],
            'allowlist first then redirect'           => ['filter-first', ['hidden@safe.test'], ['dev@safe.test'], 'visible@safe.test'],
        ];
        $cases += [
            'catchall outside allowlist is configured exception' => ['external-catchall', ['hidden@safe.test', 'blocked@outside.test'], ['hidden@safe.test', 'sink@dev.test'], null],
            'redirect with no targets rejects'                   => ['empty', ['blocked@outside.test'], [], 'visible@safe.test'],
            'redirect with no targets preserves whitelist'       => ['empty-whitelist', ['trusted@trusted.test', 'blocked@outside.test'], ['trusted@trusted.test'], null],
            'header-derived allowlist then redirect'             => ['filter-first', [], ['dev@safe.test'], 'visible@safe.test', false],
            'header-derived redirect then allowlist'             => ['redirect-first', [], ['dev@safe.test'], 'blocked@outside.test', false],
        ];
        foreach (['smtp', 'http'] as $protocol) {
            foreach ($cases as $name => $case) {
                yield $protocol.' '.$name => \array_merge([$protocol], $case);
            }
        }
    }

    private function createPolicies(string $policy): array
    {
        $filter   = new Swift_Plugins_AllowlistPlugin(['*@safe.test']);
        $redirect = new Swift_Plugins_RedirectingPlugin('dev@safe.test');

        return match ($policy) {
            'filter'            => [$filter],
            'catchall'          => [new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'dev@safe.test')],
            'external-catchall' => [new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'sink@dev.test')],
            'redirect'          => [$redirect],
            'whitelist'         => [new Swift_Plugins_RedirectingPlugin('dev@safe.test', ['/@trusted\.test$/'])],
            'multiple'          => [new Swift_Plugins_RedirectingPlugin(['dev@safe.test', 'audit@safe.test'])],
            'empty'             => [new Swift_Plugins_RedirectingPlugin([])],
            'empty-whitelist'   => [new Swift_Plugins_RedirectingPlugin([], ['/@trusted\.test$/'])],
            'filter-first'      => [$filter, $redirect],
            'redirect-first'    => [$redirect, $filter],
        };
    }

    private function createSmtpTransport(object $capture, Swift_Events_EventDispatcher $dispatcher): Swift_Transport
    {
        $buffer = $this->createMock(Swift_Transport_IoBuffer::class);
        $buffer->method('write')->willReturnCallback(static function ($bytes) use ($capture) {
            $capture->writes[] = $bytes;

            return \count($capture->writes);
        });
        $buffer->method('readLine')->willReturnCallback(static function ($sequence) use ($capture) {
            if (0 === $sequence) {
                return "220 recording.test\r\n";
            }

            return match ($capture->writes[$sequence - 1]) {
                "DATA\r\n" => "354 Continue\r\n",
                "QUIT\r\n" => "221 Bye\r\n",
                default    => "250 OK\r\n",
            };
        });

        return new Swift_Transport_EsmtpTransport($buffer, [], $dispatcher, 'recording.test', new Swift_AddressEncoder_IdnAddressEncoder());
    }

    private function createHttpTransport(object $capture, Swift_Events_EventDispatcher $dispatcher): Swift_Transport
    {
        $client = $this->createMock(GuzzleHttp\ClientInterface::class);
        $client->method('request')->willReturnCallback(static function ($method, $url, $options) use ($capture) {
            $capture->requests[] = $options;

            return new GuzzleHttp\Psr7\Response(202);
        });

        return new class('test-key', $client, $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
            {
                $recipients = $this->collectRecipients($message);
                $this->httpClient->request('POST', $this->getEndpoint(), ['json' => [
                    'to'     => $recipients,
                    'sender' => $this->getEnvelopeSender($message),
                    'raw'    => $message->toString(),
                ]]);

                return ['recipients' => \count($recipients)];
            }

            protected function getEndpoint(): string
            {
                return 'https://recording.test/send';
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
                return 'https://recording.test/ping';
            }
        };
    }
}
