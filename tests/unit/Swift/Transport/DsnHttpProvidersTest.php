<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_DsnHttpProvidersTest extends TestCase
{
    /** @dataProvider providers */
    public function testDsnConfigurationReachesTheWire(string $dsn, string $class, string $endpoint, string $header, string $auth): void
    {
        $transport = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
        $this->assertInstanceOf($class, $transport);
        $listener = $this->createMock(Swift_Events_SendListener::class);
        $listener->expects($this->once())->method('beforeSendPerformed');
        $listener->expects($this->once())->method('sendPerformed');
        $transport->registerPlugin($listener);
        $history               = [];
        $transport->httpClient = $this->client($history);
        $message               = $this->message();
        $original              = $message->toString();
        $this->assertSame(3, $transport->send($message));
        $this->assertSame($endpoint, (string) $history[0]['request']->getUri());
        $this->assertSame($auth, $history[0]['request']->getHeaderLine($header));
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertFalse($history[0]['options']['http_errors']);
        $this->assertSame($original, $message->toString());
        $this->assertNull($transport->activeEnvelope);
        if ($transport instanceof Swift_Transport_Api_ScalewayTransport) {
            $this->assertSame(\str_contains($dsn, '://0:') ? '0' : 'project', \json_decode((string) $history[0]['request']->getBody(), true)['project_id']);
        }
        $this->assertTrue($transport->ping());
        $this->assertSame($auth, $history[1]['request']->getHeaderLine($header));
        $this->assertFalse($history[1]['options']['allow_redirects']);
    }

    public static function providers(): iterable
    {
        yield ['mailgun://key:domain.test@default', Swift_Transport_Api_MailGunTransport::class, 'https://api.mailgun.net/v3/domain.test/messages', 'Authorization', 'Basic '.\base64_encode('api:key')];
        yield ['mailgun://key:domain.test@default?region=eu', Swift_Transport_Api_MailGunTransport::class, 'https://api.eu.mailgun.net/v3/domain.test/messages', 'Authorization', 'Basic '.\base64_encode('api:key')];
        yield ['mailgun://key:domain.test@default?region=us', Swift_Transport_Api_MailGunTransport::class, 'https://api.mailgun.net/v3/domain.test/messages', 'Authorization', 'Basic '.\base64_encode('api:key')];
        yield ['mailgun://key:domain%2Ftest@8.8.8.8:8443/prefix/', Swift_Transport_Api_MailGunTransport::class, 'https://8.8.8.8:8443/prefix/v3/domain%2Ftest/messages', 'Authorization', 'Basic '.\base64_encode('api:key')];
        yield ['mailjet://public:private@default', Swift_Transport_Api_MailJetTransport::class, 'https://api.mailjet.com/v3.1/send', 'Authorization', 'Basic '.\base64_encode('public:private')];
        yield ['scaleway://project:key@default', Swift_Transport_Api_ScalewayTransport::class, 'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails', 'X-Auth-Token', 'key'];
        yield ['scaleway://project:key@default?region=fr-par', Swift_Transport_Api_ScalewayTransport::class, 'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails', 'X-Auth-Token', 'key'];
        yield ['infobip://key@8.8.8.8:8443/prefix/', Swift_Transport_Api_InfoBipTransport::class, 'https://8.8.8.8:8443/prefix/email/3/send', 'Authorization', 'App key'];
        yield ['postal://key@8.8.8.8:8443/prefix/', Swift_Transport_Api_PostalTransport::class, 'https://8.8.8.8:8443/prefix/api/v1/send/message', 'X-Server-API-Key', 'key'];
        yield ['mailtrap://key@default', Swift_Transport_Api_MailtrapTransport::class, 'https://send.api.mailtrap.io/api/send', 'Authorization', 'Bearer key'];
        yield ['mailtrap+sandbox://key@12345', Swift_Transport_Api_MailtrapTransport::class, 'https://sandbox.api.mailtrap.io/api/send/12345', 'Authorization', 'Bearer key'];
        yield ['mailtrap+sandbox://key@default?inbox_id=12345', Swift_Transport_Api_MailtrapTransport::class, 'https://sandbox.api.mailtrap.io/api/send/12345', 'Authorization', 'Bearer key'];
        yield ['mailtrap+sandbox://key@0012345?inbox_id=12345', Swift_Transport_Api_MailtrapTransport::class, 'https://sandbox.api.mailtrap.io/api/send/12345', 'Authorization', 'Bearer key'];
        yield ['mailgun://0:0@default', Swift_Transport_Api_MailGunTransport::class, 'https://api.mailgun.net/v3/0/messages', 'Authorization', 'Basic '.\base64_encode('api:0')];
        yield ['mailjet://0:0@default', Swift_Transport_Api_MailJetTransport::class, 'https://api.mailjet.com/v3.1/send', 'Authorization', 'Basic '.\base64_encode('0:0')];
        yield ['scaleway://0:0@default', Swift_Transport_Api_ScalewayTransport::class, 'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails', 'X-Auth-Token', '0'];
        yield ['infobip://%30@8.8.8.8', Swift_Transport_Api_InfoBipTransport::class, 'https://8.8.8.8/email/3/send', 'Authorization', 'App 0'];
        yield ['postal://0@8.8.8.8', Swift_Transport_Api_PostalTransport::class, 'https://8.8.8.8/api/v1/send/message', 'X-Server-API-Key', '0'];
        yield ['mailtrap://:0@default', Swift_Transport_Api_MailtrapTransport::class, 'https://send.api.mailtrap.io/api/send', 'Authorization', 'Bearer 0'];
        yield ['sendgrid://0@default', Swift_Transport_Api_SendgridTransport::class, 'https://api.sendgrid.com/v3/mail/send', 'Authorization', 'Bearer 0'];
        yield ['sendgrid://:%30@ignored.example', Swift_Transport_Api_SendgridTransport::class, 'https://api.sendgrid.com/v3/mail/send', 'Authorization', 'Bearer 0'];
        yield ['mailjet://p%40blic:pr%3Aivate@default/', Swift_Transport_Api_MailJetTransport::class, 'https://api.mailjet.com/v3.1/send', 'Authorization', 'Basic '.\base64_encode('p@blic:pr:ivate')];
    }

    /** @dataProvider invalidDsns */
    public function testInvalidConfigurationRejectsWithoutCredentialTraces(string $dsn): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new Swift_Transport_DsnTransportFactory())->fromDsnString(\str_replace('KEY', 'PRIVATE-HTTP-KEY', $dsn));
                $this->fail('Invalid DSN must be rejected.');
            } catch (InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
                $frames = \array_filter($error->getTrace(), static fn (array $frame): bool => Swift_Transport_DsnTransportFactory::class === ($frame['class'] ?? null));
                $this->assertStringNotContainsString('PRIVATE-HTTP-KEY', $error->getMessage().\print_r($frames, true));
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function invalidDsns(): iterable
    {
        foreach (['microsoft-graph', 'amazon+api', 'amazon+http'] as $scheme) {
            yield [$scheme.'://KEY@default'];
            yield ['retry('.$scheme.'://KEY@default)'];
        }
        foreach (['mailgun://KEY@default', 'mailgun://:KEY@default', 'mailjet://KEY@default', 'mailjet://:KEY@default', 'scaleway://KEY@default', 'scaleway://:KEY@default', 'infobip://KEY@default', 'postal://KEY@default', 'mailtrap://default', 'mailtrap+sandbox://KEY@default', 'mailtrap+sandbox://KEY@0', 'mailtrap+sandbox://KEY@-1', 'mailtrap+sandbox://KEY@1?inbox_id=2', 'mailtrap+sandbox://KEY@default?inbox_id=', 'mailtrap+sandbox://KEY@default?inbox_id=1%2F2', 'mailtrap+sandbox://KEY@default?inbox_id=1.5'] as $dsn) {
            yield [$dsn];
        }
        foreach (['infobip', 'postal', 'mailgun'] as $scheme) {
            foreach (['localhost', '127.0.0.1', '10.0.0.1', '[::1]'] as $host) {
                yield [$scheme.'://KEY:domain@'.$host];
            }
            yield [$scheme.'://KEY:domain@8.8.8.8/#KEY'];
            yield [$scheme.'://KEY:domain@8.8.8.8/%0D%0AKEY'];
            yield [$scheme.'://KEY:domain@8.8.8.8/a/../KEY'];
            yield [$scheme.'://KEY:domain@8.8.8.8:0'];
            yield [$scheme.'://KEY:domain@bad%40host'];
        }
        foreach (['mailjet', 'scaleway', 'mailtrap', 'mailtrap+sandbox'] as $scheme) {
            yield [$scheme.'://KEY:private@other.example'];
            yield [$scheme.'://KEY:private@default:8443'];
            yield [$scheme.'://KEY:private@default/path'];
            yield [$scheme.'://KEY:private@default/#KEY'];
        }
        foreach (['region=KEY', 'region[]=eu', 'region=eu&region=us', 'region=eu;region=us', 'region=eu&%72egion=us', 'region[x]=eu', 'region%00=eu', '%20region=eu'] as $query) {
            yield ['mailgun://KEY:domain@default?'.$query];
        }
        yield ['mailgun://KEY:domain@8.8.8.8?region=eu'];
        yield ['scaleway://project:KEY@default?region=nl-ams'];
        yield ['sendgrid://KEY@default?region=eu'];
        yield ['smtp://KEY:private@example.test?region=eu'];
        yield ['null://default?region=eu'];
        foreach (['inbox_id[]=1', 'inbox.id=1', 'inbox_id=1&inbox_id=2', 'inbox_id=1;inbox_id=2', 'inbox_id=1&inbox%5fid=2', 'inbox_id%00=1', 'inbox_id[x]=1'] as $query) {
            yield ['mailtrap+sandbox://KEY@default?'.$query];
        }
        yield ['mailtrap://KEY@default?inbox_id=1'];
        yield ['sendgrid://KEY@default?inbox_id=1'];
    }

    /** @dataProvider wrappers */
    public function testPublicHelperAndWrappersUseProviderConstruction(string $wrapper): void
    {
        $leaf = 'mailgun://key:domain.test@default?region=eu';
        $dsn  = match ($wrapper) {
            'helper'     => $leaf,
            'leaf-retry' => $leaf.'&retries=1&retry_delay=0',
            'retry'      => 'retry('.$leaf.')',
            default      => $wrapper.'('.$leaf.' null://default)',
        };
        $transport           = 'helper' === $wrapper ? Swift::createTransportFromDsn($dsn)->getTransport() : (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
        $backend             = $transport instanceof Swift_Transport_RetryTransport ? $transport->getInnerTransport() : ($transport instanceof Swift_Transport_LoadBalancedTransport ? $transport->getTransports()[0] : $transport);
        $history             = [];
        $backend->httpClient = $this->client($history);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSame('https://api.eu.mailgun.net/v3/domain.test/messages', (string) $history[0]['request']->getUri());
    }

    public static function wrappers(): iterable
    {
        foreach (['helper', 'leaf-retry', 'retry', 'failover', 'roundrobin'] as $wrapper) {
            yield [$wrapper];
        }
    }

    /** @dataProvider rawProviders */
    public function testExplicitEnvelopeUsesRawDeliveryAndPreservesTheMessage(string $scheme, string $suffix): void
    {
        $transport             = (new Swift_Transport_DsnTransportFactory())->fromDsnString($scheme.'://key:domain@8.8.8.8:8443/prefix');
        $history               = [];
        $transport->httpClient = $this->client($history);
        $message               = $this->message();
        $original              = $message->toString();
        $failed                = [];
        $this->assertSame(1, $transport->send($message, $failed, new Swift_Envelope('sender@example.com', ['hidden@example.com'])));
        $this->assertSame('https://8.8.8.8:8443/prefix'.$suffix, (string) $history[0]['request']->getUri());
        $this->assertSame($original, $message->toString());
        $this->assertSame([], $failed);
        $this->assertNull($transport->activeEnvelope);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $body = (string) $history[0]['request']->getBody();
        $this->assertStringContainsString('hidden@example.com', $body);
        $this->assertStringNotContainsString('Bcc:', $body);
        if ('mailgun' !== $scheme) {
            $payload = \json_decode($body, true);
            $raw     = \base64_decode('postal' === $scheme ? $payload['data'] : $payload['mimeMessage']);
            $this->assertStringNotContainsString('Bcc:', $raw);
        }
    }

    public static function rawProviders(): iterable
    {
        yield ['mailgun', '/v3/domain/messages.mime'];
        yield ['infobip', '/email/4/mime'];
        yield ['postal', '/api/v1/send/raw'];
    }

    /** @dataProvider fixedProviders */
    public function testFixedProvidersRetainEnvelopePolicy(string $dsn): void
    {
        $transport             = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
        $history               = [];
        $transport->httpClient = $this->client($history);
        $failed                = [];
        try {
            $transport->send($this->message(), $failed, new Swift_Envelope('sender@example.com', ['hidden@example.com']));
            $this->fail('Recipient policy must reject an unsupported override.');
        } catch (Swift_TransportException $error) {
            $this->assertStringContainsString('does not support envelope recipients', $error->getMessage());
            $this->assertSame([], $history);
            $this->assertNull($transport->activeEnvelope);
        }
    }

    public static function fixedProviders(): iterable
    {
        yield ['mailjet://public:private@default'];
        yield ['scaleway://project:key@default'];
        yield ['mailtrap://key@default'];
        yield ['mailtrap+sandbox://key@12345'];
    }

    /** @dataProvider failureProviders */
    public function testRedirectResponsesDoNotForwardProviderCredentials(string $dsn): void
    {
        $transport = (new Swift_Transport_DsnTransportFactory())->fromDsnString($dsn);
        $history   = [];
        $stack     = HandlerStack::create(new MockHandler([new Response(302, ['Location' => 'https://other.example.test'], '{}')]));
        $stack->push(Middleware::history($history));
        $transport->httpClient = new Client(['handler' => $stack]);
        try {
            $transport->send($this->message());
            $this->fail('Redirects must not report acceptance.');
        } catch (Swift_TransportException) {
            $this->assertCount(1, $history);
            $this->assertFalse($history[0]['options']['allow_redirects']);
            $this->assertNull($transport->activeEnvelope);
        }
    }

    public static function failureProviders(): iterable
    {
        foreach (self::fixedProviders() as $case) {
            yield $case;
        }
        yield ['mailgun://key:domain@default'];
        yield ['infobip://key@8.8.8.8'];
        yield ['postal://key@8.8.8.8'];
    }

    public function testMetadataRetainsAnEndpointBasePath(): void
    {
        $dsn = new Swift_Dsn(Nyholm\Dsn\DsnParser::parseUrl('postal://key@8.8.8.8:8443/prefix'));
        $this->assertSame('/prefix', $dsn->getPath());
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('DSN request'))->setFrom('sender@example.com')->setTo('to@example.com')->setCc('cc@example.com')->setBcc('bcc@example.com')->setBody('fixture');
    }

    private function client(array &$history): Client
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '{"status":"success","Messages":[{"Status":"success"}],"messages":[{"status":{"groupName":"PENDING"}}],"emails":[]}');
        $stack    = HandlerStack::create(new MockHandler([$response, $response]));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }
}
