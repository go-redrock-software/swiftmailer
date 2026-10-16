<?php

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Ses\SesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

require_once \dirname(__DIR__).'/SesSdkFixture.php';

class Swift_Transport_DsnSesTest extends TestCase
{
    /** @dataProvider configurations */
    public function testDsnCredentialsRegionAndSessionReachSignedRequests(string $scheme, string $userinfo, string $query, string $host, string $endpoint, string $region, string $key, string $secret, ?string $token): void
    {
        $fixture = new SesSdkFixture([
            new MockResponse('{"MessageId":"dsn-id"}', ['http_code' => 200]),
            new MockResponse('{"message":"not suppressed"}', ['http_code' => 404, 'response_headers' => ['x-amzn-errortype: NotFoundException']]),
        ]);
        $factory   = new Swift_Transport_DsnTransportFactory($fixture->http);
        $transport = $factory->fromDsnString($scheme.'://'.$userinfo.'@'.$host.$query);
        $this->assertInstanceOf('Swift_Transport_Api_AmazonSes'.('amazon+api' === $scheme ? 'Api' : 'Http').'Transport', $transport);
        $client = $this->client($transport);
        $this->assertSame(SesClient::class, $client::class);
        $this->assertSame($fixture->http, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client));
        $provider = Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'credentialProvider', $client);
        $this->assertSame(Credentials::class, $provider::class);
        $this->assertSame($key, $provider->getAccessKeyId());
        $this->assertSame($secret, $provider->getSecretKey());
        $this->assertSame($token, $provider->getSessionToken());
        $this->assertSame($region, $client->getConfiguration()->get('region'));
        $this->assertSame($endpoint, $client->getConfiguration()->get('endpoint'));
        $this->assertSame('false', $client->getConfiguration()->get('debug'));
        $this->assertSame([], $fixture->requests, 'Construction must not make credential or API requests.');
        $listener = $this->createMock(Swift_Events_SendListener::class);
        $listener->expects($this->once())->method('beforeSendPerformed');
        $listener->expects($this->once())->method('sendPerformed');
        $transport->registerPlugin($listener);
        $message    = $this->message();
        $recipients = [$message->getTo(), $message->getCc(), $message->getBcc()];
        $this->assertSame(3, $transport->send($message));
        $this->assertSame([$message->getTo(), $message->getCc(), $message->getBcc()], $recipients);
        $this->assertCount(1, $fixture->requests);
        $this->assertSame($endpoint.'/v2/email/outbound-emails', $fixture->requests[0]['url']);
        $this->assertSigned($fixture->requests[0], $key, $secret, $region, $token);
        $this->assertSame(['to@example.test'], $fixture->payload()['Destination']['ToAddresses']);
        $this->assertSame(['cc@example.test'], $fixture->payload()['Destination']['CcAddresses']);
        $this->assertSame(['blind@example.test'], $fixture->payload()['Destination']['BccAddresses']);
        $this->assertSame('body', $fixture->payload()['Content']['Simple']['Body']['Text']['Data']);
        $this->assertTrue($transport->ping());
        $this->assertCount(2, $fixture->requests);
        $this->assertSame($endpoint.'/v2/email/suppression/addresses/ping%40swiftmailer.invalid', $fixture->requests[1]['url']);
        $this->assertSame(0, $fixture->requests[1]['options']['max_redirects']);
        $this->assertSame($fixture->http, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client));
    }

    public static function configurations(): iterable
    {
        foreach (['amazon+api', 'amazon+http'] as $scheme) {
            foreach ([
                ['key:secret', '', 'default', 'https://email.us-east-1.amazonaws.com', 'us-east-1', 'key', 'secret', null],
                ['key:secret', '?region=eu-west-1', 'default', 'https://email.eu-west-1.amazonaws.com', 'eu-west-1', 'key', 'secret', null],
                ['key:secret', '?region=us-gov-west-1', 'default', 'https://email.us-gov-west-1.amazonaws.com', 'us-gov-west-1', 'key', 'secret', null],
                ['key:secret', '?region=eusc-de-east-1', 'default', 'https://email.eusc-de-east-1.amazonaws.eu', 'eusc-de-east-1', 'key', 'secret', null],
                ['key:secret', '?region=cn-north-1', 'default', 'https://email.cn-north-1.amazonaws.com.cn', 'cn-north-1', 'key', 'secret', null],
                ['key:secret', '?region=eu-west-2', '8.8.8.8:8443/prefix/', 'https://8.8.8.8:8443/prefix', 'eu-west-2', 'key', 'secret', null],
                ['key:secret', '?session_token=TEMP%2B%2F%3D', 'default', 'https://email.us-east-1.amazonaws.com', 'us-east-1', 'key', 'secret', 'TEMP+/='],
                ['key:secret', '?session_token=TEMP%3Bsession_token%3DSECOND', 'default', 'https://email.us-east-1.amazonaws.com', 'us-east-1', 'key', 'secret', 'TEMP;session_token=SECOND'],
                ['0:0', '?session_token=0', 'default', 'https://email.us-east-1.amazonaws.com', 'us-east-1', '0', '0', '0'],
                ['%30:%30', '', 'default', 'https://email.us-east-1.amazonaws.com', 'us-east-1', '0', '0', null],
                ['API%40ID:SECRET%2B%2F%3D', '', 'default', 'https://email.us-east-1.amazonaws.com', 'us-east-1', 'API@ID', 'SECRET+/=', null],
            ] as $case) {
                yield [$scheme, ...$case];
            }
        }
    }

    /** @dataProvider invalidDsns */
    public function testInvalidDsnsRejectBeforeAnyRequestWithoutExposingFactoryInputs(string $dsn): void
    {
        $fixture  = new SesSdkFixture();
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new Swift_Transport_DsnTransportFactory($fixture->http))->fromDsnString($dsn);
                $this->fail('Invalid SES configuration must reject.');
            } catch (InvalidArgumentException $error) {
                $frames = \array_filter($error->getTrace(), static fn (array $frame): bool => Swift_Transport_DsnTransportFactory::class === ($frame['class'] ?? null));
                $this->assertStringNotContainsString('PRIVATE', $error->getMessage().\print_r($frames, true));
                $this->assertNull($error->getPrevious());
            }
            $this->assertSame([], $fixture->requests);
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function invalidDsns(): iterable
    {
        foreach (['amazon+api', 'amazon+http'] as $scheme) {
            foreach (['PRIVATE-KEY', ':PRIVATE-SECRET', 'PRIVATE-KEY:', 'PRIVATE%0AKEY:PRIVATE-SECRET', 'PRIVATE-KEY:PRIVATE%0DSECRET'] as $userinfo) {
                yield [$scheme.'://'.$userinfo.'@default'];
            }
            foreach (['region=', 'region=PRIVATE-REGION', 'region=eu-west-0', 'region=EU-WEST-1', 'region=fips-us-east-1', 'region=eu-west-1.example.com', 'region=us-east-1%2FPRIVATE-PATH', 'region[]=eu-west-1', 'region=us-east-1&region=eu-west-1', 'region=us-east-1;region=eu-west-1', 'session_token=', 'session_token=PRIVATE%20TOKEN', 'session_token=PRIVATE%0ATOKEN', 'session_token=PRIVATE%7fTOKEN', 'session_token[]=PRIVATE-TOKEN', 'session.token=PRIVATE-TOKEN', 'session_token=PRIVATE-A&session_token=PRIVATE-B', 'session_token%00=PRIVATE-TOKEN'] as $query) {
                yield [$scheme.'://PRIVATE-KEY:PRIVATE-SECRET@default?'.$query];
            }
            if (\str_contains(\ini_get('arg_separator.input'), ';')) {
                yield [$scheme.'://PRIVATE-KEY:PRIVATE-SECRET@default?session_token=PRIVATE-A;session_token=PRIVATE-B'];
            }
            yield [$scheme.'://PRIVATE-KEY@default?session_token=PRIVATE-VALID'];
            yield [$scheme.'://PRIVATE-KEY:PRIVATE-SECRET@default:8443?session_token=PRIVATE-VALID'];
            foreach (['localhost', '127.0.0.1', '10.0.0.1', '[::1]', 'default:8443', 'default/path', 'default/#PRIVATE-FRAGMENT', '8.8.8.8/../PRIVATE-PATH'] as $host) {
                yield [$scheme.'://PRIVATE-KEY:PRIVATE-SECRET@'.$host];
            }
        }
        foreach (['sendgrid', 'mailgun', 'scaleway', 'gmail+api', 'microsoft-graph', 'amazon+smtp', 'null'] as $scheme) {
            yield [$scheme.'://PRIVATE-KEY:PRIVATE-SECRET@default?session_token=PRIVATE-TOKEN'];
        }
    }

    /** @dataProvider schemes */
    public function testAmbientAwsConfigurationCannotChangeDsnSigningOrEndpoint(string $scheme): void
    {
        $values   = ['AWS_ACCESS_KEY_ID' => 'AMBIENT-KEY', 'AWS_SECRET_ACCESS_KEY' => 'AMBIENT-SECRET', 'AWS_SESSION_TOKEN' => 'AMBIENT-TOKEN', 'AWS_REGION' => 'ap-south-1', 'AWS_DEFAULT_REGION' => 'eu-north-1', 'AWS_ENDPOINT_URL' => 'http://127.0.0.1/ambient', 'AWS_ROLE_ARN' => 'AMBIENT-ROLE', 'AWS_WEB_IDENTITY_TOKEN_FILE' => '/missing/ambient-token', 'AWS_CONTAINER_CREDENTIALS_FULL_URI' => 'http://127.0.0.1/credentials'];
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = \getenv($name);
            \putenv($name.'='.$value);
        }
        try {
            $fixture   = new SesSdkFixture();
            $transport = (new Swift_Transport_DsnTransportFactory($fixture->http))->fromDsnString($scheme.'://key:secret@default');
            $this->assertCallerClient($transport, $fixture->http);
            $client = $this->client($transport);
            $this->assertSame(Credentials::class, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'credentialProvider', $client)::class);
            $this->assertSame('key', $client->getConfiguration()->get('accessKeyId'));
            $this->assertSame('secret', $client->getConfiguration()->get('accessKeySecret'));
            $this->assertNull($client->getConfiguration()->get('sessionToken'));
            $this->assertSame([], $fixture->requests);
            $this->assertSame(3, $transport->send($this->message()));
            $this->assertCount(1, $fixture->requests);
            $this->assertSame('https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', $fixture->requests[0]['url']);
            $this->assertSigned($fixture->requests[0], 'key', 'secret', 'us-east-1', null);
            foreach ($values as $name => $value) {
                $this->assertSame($value, \getenv($name));
            }
        } finally {
            foreach ($original as $name => $value) {
                \putenv(false === $value ? $name : $name.'='.$value);
            }
        }
    }

    /** @dataProvider wrappers */
    public function testWrappersAndPublicMailerHelperKeepTheConfiguredClient(string $scheme, string $wrapper): void
    {
        $fixture = new SesSdkFixture();
        $factory = new Swift_Transport_DsnTransportFactory($fixture->http);
        $leaf    = $scheme.'://key:secret@default?region=eu-west-1&session_token=TEMP';
        $dsn     = match ($wrapper) {
            'leaf-retry' => $leaf.'&retries=1&retry_delay=0',
            'retry'      => 'retry('.$leaf.')',
            default      => $wrapper.'('.$leaf.' null://default)',
        };
        $transport = $factory->fromDsnString($dsn);
        $this->assertCallerClient($transport, $fixture->http);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertSigned($fixture->requests[0], 'key', 'secret', 'eu-west-1', 'TEMP');
        // The legacy helper uses default SDK HTTP construction; construction itself is network-free.
        $mailer = Swift::createTransportFromDsn($scheme.'://key:secret@default?region=eu-west-1');
        $this->assertInstanceOf(SesClient::class, $this->client($mailer->getTransport()));
        $this->assertSame('eu-west-1', $this->client($mailer->getTransport())->getConfiguration()->get('region'));
    }

    public static function wrappers(): iterable
    {
        foreach (self::schemes() as [$scheme]) {
            foreach (['leaf-retry', 'retry', 'failover', 'roundrobin'] as $wrapper) {
                yield [$scheme, $wrapper];
            }
        }
    }

    /** @dataProvider mimeModes */
    public function testConstructedTransportRetainsMimeAndExplicitEnvelopePolicy(string $scheme, bool $explicit): void
    {
        $fixture   = new SesSdkFixture();
        $transport = (new Swift_Transport_DsnTransportFactory($fixture->http))->fromDsnString($scheme.'://key:secret@default');
        $this->assertCallerClient($transport, $fixture->http);
        $message  = $this->message()->attach(new Swift_Attachment("binary\x00\xff", 'dsn.bin', 'application/octet-stream'));
        $failed   = [];
        $envelope = $explicit ? new Swift_Envelope('bounce@example.test', ['hidden@example.test']) : null;
        $this->assertSame($explicit ? 1 : 3, $transport->send($message, $failed, $envelope));
        $payload = $fixture->payload();
        $raw     = \base64_decode($payload['Content']['Raw']['Data'], true);
        $this->assertStringContainsString('filename=dsn.bin', $raw);
        $this->assertStringContainsString(\base64_encode("binary\x00\xff"), $raw);
        $this->assertStringNotContainsString('Bcc:', $raw);
        $this->assertSame($explicit ? ['ToAddresses' => ['hidden@example.test']] : ['ToAddresses' => ['to@example.test'], 'CcAddresses' => ['cc@example.test'], 'BccAddresses' => ['blind@example.test']], $payload['Destination']);
        if ($explicit) {
            $this->assertSame('bounce@example.test', $payload['FeedbackForwardingEmailAddress']);
        }
        $this->assertSame(['blind@example.test' => null], $message->getBcc());
        $this->assertCount(1, $message->getChildren());
        $this->assertSame([], $failed);
    }

    public static function mimeModes(): iterable
    {
        foreach (self::schemes() as [$scheme]) {
            yield [$scheme, false];
            yield [$scheme, true];
        }
    }

    /** @dataProvider redirects */
    public function testDsnRequestsRetainPermanentRedirectRefusal(string $scheme, int $status): void
    {
        $fixture   = new SesSdkFixture([new MockResponse('{"MessageId":"must-not-accept"}', ['http_code' => $status, 'response_headers' => ['Location: https://other.example.test']])]);
        $http      = new RetryableHttpClient($fixture->http, new GenericRetryStrategy([$status], 0), 2);
        $transport = (new Swift_Transport_DsnTransportFactory($http))->fromDsnString($scheme.'://key:secret@default');
        $this->assertCallerClient($transport, $http);
        try {
            (new Swift_Transport_RetryTransport($transport, 2, 0))->send($this->message());
            $this->fail('A redirect cannot be accepted or retried.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $fixture->requests);
        $this->assertSame(0, $fixture->requests[0]['options']['max_redirects']);
        $this->assertSame($http, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $this->client($transport)));
    }

    public static function redirects(): iterable
    {
        foreach (self::schemes() as [$scheme]) {
            foreach ([301, 302, 303, 307, 308] as $status) {
                yield [$scheme, $status];
            }
        }
    }

    /** @dataProvider schemes */
    public function testInjectedOrdinaryHttpRetryIsPreserved(string $scheme): void
    {
        $fixture   = new SesSdkFixture([new MockResponse('{"message":"busy"}', ['http_code' => 503])]);
        $http      = new RetryableHttpClient($fixture->http, new GenericRetryStrategy([503], 0), 2);
        $transport = (new Swift_Transport_DsnTransportFactory($http))->fromDsnString($scheme.'://key:secret@default');
        $this->assertCallerClient($transport, $http);
        $this->assertSame(3, $transport->send($this->message()));
        $this->assertCount(2, $fixture->requests);
        foreach ($fixture->requests as $request) {
            $this->assertSigned($request, 'key', 'secret', 'us-east-1', null);
        }
    }

    public static function schemes(): iterable
    {
        yield ['amazon+api'];
        yield ['amazon+http'];
    }

    public function testIncorrectHttpClientTypeDoesNotExposeItsArgument(): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                new Swift_Transport_DsnTransportFactory('PRIVATE-HTTP-CLIENT');
                $this->fail('An invalid HTTP client type must reject.');
            } catch (TypeError $error) {
                $frames = \array_filter($error->getTrace(), static fn (array $frame): bool => Swift_Transport_DsnTransportFactory::class === ($frame['class'] ?? null));
                $this->assertStringNotContainsString('PRIVATE-HTTP-CLIENT', $error->getMessage().\print_r($frames, true));
            }
        } finally {
            \ini_set('zend.exception_ignore_args', $previous);
        }
    }

    private function assertCallerClient(Swift_Transport $transport, Symfony\Contracts\HttpClient\HttpClientInterface $http): void
    {
        if ($transport instanceof Swift_Transport_RetryTransport) {
            $this->assertCallerClient($transport->getInnerTransport(), $http);
        } elseif ($transport instanceof Swift_Transport_LoadBalancedTransport) {
            foreach ($transport->getTransports() as $leaf) {
                if (!$leaf instanceof Swift_Transport_NullTransport) {
                    $this->assertCallerClient($leaf, $http);
                }
            }
        } else {
            $client = $this->client($transport);
            $this->assertSame($http, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'httpClient', $client));
            $this->assertSame(Credentials::class, Swift_Transport_Api_SesRequestPolicy::read(AbstractApi::class, 'credentialProvider', $client)::class);
        }
    }

    private function client(Swift_Transport $transport): SesClient
    {
        return (new ReflectionMethod($transport, 'getApiConnection'))->invoke($transport);
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('SES DSN'))->setFrom('sender@example.test')->setTo('to@example.test')->setCc('cc@example.test')->setBcc('blind@example.test')->setBody('body');
    }

    /** Independently recompute the AWS documented SigV4 HMAC, using recorded HTTP bytes. */
    private function assertSigned(array $request, string $key, string $secret, string $region, ?string $token): void
    {
        $headers = [];
        foreach ($request['options']['headers'] as $line) {
            [$name, $value]              = \explode(':', $line, 2);
            $headers[\strtolower($name)] = \trim($value);
        }
        $this->assertSame($token, $headers['x-amz-security-token'] ?? null);
        $this->assertSame(0, $request['options']['max_redirects']);
        $this->assertSame(1, \preg_match('/Credential=(.+?)\/([0-9]{8})\/([^\/]+)\/ses\/aws4_request,\s*SignedHeaders=([^,]+),\s*Signature=([0-9a-f]{64})$/', $headers['authorization'], $matches));
        $this->assertSame($key, $matches[1]);
        $this->assertSame($region, $matches[3]);
        $canonicalHeaders = '';
        foreach (\explode(';', $matches[4]) as $name) {
            $canonicalHeaders .= $name.':'.\preg_replace('/\s+/', ' ', $headers[$name])."\n";
        }
        $path      = \parse_url($request['url'], PHP_URL_PATH);
        $canonical = $request['method']."\n".$path."\n\n".$canonicalHeaders."\n".$matches[4]."\n".\hash('sha256', $request['body']);
        $scope     = $matches[2].'/'.$region.'/ses/aws4_request';
        $toSign    = "AWS4-HMAC-SHA256\n".$headers['x-amz-date']."\n".$scope."\n".\hash('sha256', $canonical);
        $derived   = \hash_hmac('sha256', $matches[2], 'AWS4'.$secret, true);
        foreach ([$region, 'ses', 'aws4_request'] as $part) {
            $derived = \hash_hmac('sha256', $part, $derived, true);
        }
        $this->assertSame(\hash_hmac('sha256', $toSign, $derived), $matches[5]);
    }
}
