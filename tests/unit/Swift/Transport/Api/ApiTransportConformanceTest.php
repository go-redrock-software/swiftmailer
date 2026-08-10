<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Uniform contract suite run against every HTTP API transport (those extending
 * Swift_Transport_AbstractHttpApiTransport).
 *
 * Per-provider tests verify each provider's bespoke payload/response handling.
 * This guarantees the shared behavioural floor that no provider should be
 * allowed to break and that every *future* provider inherits for free: it
 * implements Swift_Transport, reports its started state correctly, routes
 * plugins to the dispatcher, exposes https endpoints, and returns a boolean
 * from ping() (including failing closed when the HTTP client throws).
 *
 * The four SDK-based transports (Amazon SES api/http, Google, Microsoft Graph)
 * are intentionally excluded: they do not use a Guzzle ClientInterface and
 * already carry their own explicit lifecycle/interface coverage.
 */
class Swift_Transport_Api_ApiTransportConformanceTest extends TestCase
{
    /**
     * @dataProvider httpTransportProvider
     */
    public function testImplementsSwiftTransport(string $key): void
    {
        $this->assertInstanceOf(Swift_Transport::class, $this->makeTransport($key, $this->benignClient()));
    }

    /**
     * @dataProvider httpTransportProvider
     */
    public function testExtendsAbstractHttpApiTransport(string $key): void
    {
        $this->assertInstanceOf(
            Swift_Transport_AbstractHttpApiTransport::class,
            $this->makeTransport($key, $this->benignClient()),
        );
    }

    /**
     * @dataProvider httpTransportProvider
     */
    public function testIsNotStartedByDefault(string $key): void
    {
        $this->assertFalse($this->makeTransport($key, $this->benignClient())->isStarted());
    }

    /**
     * @dataProvider httpTransportProvider
     */
    public function testStartMarksTransportStarted(string $key): void
    {
        $transport = $this->makeTransport($key, $this->benignClient());
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    /**
     * @dataProvider httpTransportProvider
     */
    public function testRegisterPluginDelegatesToDispatcher(string $key): void
    {
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $plugin     = $this->createMock(Swift_Events_EventListener::class);

        $dispatcher->expects($this->once())
            ->method('bindEventListener')
            ->with($plugin);

        $this->makeTransport($key, $this->benignClient(), $dispatcher)->registerPlugin($plugin);
    }

    /**
     * @dataProvider httpTransportProvider
     */
    public function testSendAndPingEndpointsAreHttpsUrls(string $key): void
    {
        $transport = $this->makeTransport($key, $this->benignClient());

        foreach (['getEndpoint', 'getPingEndpoint'] as $method) {
            $endpoint = $this->callProtected($transport, $method);
            $this->assertIsString($endpoint, \sprintf('%s::%s() must return a string.', $key, $method));
            $this->assertStringStartsWith(
                'https://',
                $endpoint,
                \sprintf('%s::%s() must return an https URL, got "%s".', $key, $method, $endpoint),
            );
        }
    }

    /**
     * @dataProvider httpTransportProvider
     */
    public function testPingReturnsBool(string $key): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn(new Response(200, [], '{}'));

        $this->assertIsBool($this->makeTransport($key, $client)->ping());
    }

    /**
     * Every transport with a real health check must fail closed (return false)
     * when the HTTP client throws, rather than leaking the exception.
     *
     * @dataProvider httpTransportWithHealthCheckProvider
     */
    public function testPingReturnsFalseWhenHttpClientThrows(string $key): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willThrowException(new RuntimeException('network down'));

        $this->assertFalse($this->makeTransport($key, $client)->ping());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function httpTransportProvider(): array
    {
        $cases = [];
        foreach (self::HTTP_TRANSPORT_KEYS as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    /**
     * MailPace has no dedicated health endpoint -- its ping() unconditionally
     * returns true -- so it is excluded from the fail-closed assertion.
     *
     * @return array<string, array{0: string}>
     */
    public static function httpTransportWithHealthCheckProvider(): array
    {
        $cases = [];
        foreach (self::HTTP_TRANSPORT_KEYS as $key) {
            if ('mailpace' === $key) {
                continue;
            }
            $cases[$key] = [$key];
        }

        return $cases;
    }

    private const array HTTP_TRANSPORT_KEYS = [
        'ahasend',
        'azure',
        'brevo',
        'infobip',
        'mailchimp',
        'mailgun',
        'mailjet',
        'mailpace',
        'mailersend',
        'mailomat',
        'mailtrap',
        'postmark',
        'postal',
        'resend',
        'scaleway',
        'sendgrid',
        'sweego',
    ];

    private function makeTransport(string $key, ClientInterface $client, ?Swift_Events_EventDispatcher $dispatcher = null): Swift_Transport_AbstractHttpApiTransport
    {
        // A real dispatcher is always injected: start()/stop() (the latter runs
        // from __destruct on a started transport) call createTransportChangeEvent()
        // on the dispatcher without null-safety, so a null dispatcher would fatal.
        $dispatcher ??= $this->stubDispatcher();

        return match ($key) {
            'ahasend'    => new Swift_Transport_Api_AhaSendTransport('api-key', $client, $dispatcher),
            'azure'      => new Swift_Transport_Api_AzureTransport('endpoint=https://test.communication.azure.com/;accesskey='.\base64_encode('secret'), $client, $dispatcher),
            'brevo'      => new Swift_Transport_Api_BrevoTransport('api-key', $client, $dispatcher),
            'infobip'    => new Swift_Transport_Api_InfoBipTransport('api-key', 'xyz.api.infobip.com', $client, $dispatcher),
            'mailchimp'  => new Swift_Transport_Api_MailChimpTransport('api-key', $client, $dispatcher),
            'mailgun'    => new Swift_Transport_Api_MailGunTransport('api-key', 'mail.example.com', 'https://api.mailgun.net', $client, $dispatcher),
            'mailjet'    => new Swift_Transport_Api_MailJetTransport('public-key', 'private-key', $client, $dispatcher),
            'mailpace'   => new Swift_Transport_Api_MailPaceTransport('api-key', $client, $dispatcher),
            'mailersend' => new Swift_Transport_Api_MailerSendTransport('api-key', $client, $dispatcher),
            'mailomat'   => new Swift_Transport_Api_MailomatTransport('api-key', $client, $dispatcher),
            'mailtrap'   => new Swift_Transport_Api_MailtrapTransport('api-key', false, null, $client, $dispatcher),
            'postmark'   => new Swift_Transport_Api_PostMarkTransport('api-key', $client, $dispatcher),
            'postal'     => new Swift_Transport_Api_PostalTransport('api-key', 'postal.example.com', $client, $dispatcher),
            'resend'     => new Swift_Transport_Api_ResendTransport('api-key', $client, $dispatcher),
            'scaleway'   => new Swift_Transport_Api_ScalewayTransport('api-key', 'project-id', 'fr-par', $client, $dispatcher),
            'sendgrid'   => new Swift_Transport_Api_SendgridTransport('api-key', $client, $dispatcher),
            'sweego'     => new Swift_Transport_Api_SweegoTransport('api-key', $client, $dispatcher),
            default      => throw new InvalidArgumentException(\sprintf('Unknown transport key "%s".', $key)),
        };
    }

    private function benignClient(): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn(new Response(200, [], '{}'));

        return $client;
    }

    private function stubDispatcher(): Swift_Events_EventDispatcher
    {
        $event = $this->createMock(Swift_Events_TransportChangeEvent::class);
        $event->method('bubbleCancelled')->willReturn(false);

        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($event);

        return $dispatcher;
    }

    private function callProtected(object $object, string $method): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invoke($object);
    }
}
