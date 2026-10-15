<?php

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_SesCredentialPolicyTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            \unlink($file);
        }
    }

    /** @dataProvider redirects */
    public function testCredentialRedirectCannotFallBackForwardOrReachSes(string $kind, string $provider, int $stage, int $status, string $destination, bool $retry): void
    {
        $fixture = $this->fixture($provider, $stage, $status, $destination, $retry);
        $class   = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $failed  = [];
        try {
            (new Swift_Transport_RetryTransport(new $class($fixture->ses->client), maxRetries: 2, baseDelayMs: 0))->send($this->message(), $failed);
            $this->fail('Credential redirects cannot become successful SES sends.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            $cause = $error;
            while (null !== $cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }
            $this->assertInstanceOf(Swift_Transport_NonRetryableException::class, $cause);
            $this->assertSame($status, $cause->getCode());
        }
        $this->assertCount($stage + 1, $fixture->requests);
        $this->assertSame([], $fixture->ses->requests);
        $this->assertSame(['to@example.test'], $failed);
        foreach ($fixture->requests as $request) {
            $this->assertSame(0, $request['options']['max_redirects']);
            $this->assertStringNotContainsString('other.example.test', $request['url']);
        }
        $this->assertSame([], (new ReflectionProperty(CacheProvider::class, 'cache'))->getValue($fixture->cache));
        $this->assertSame([], (new ReflectionProperty(ChainProvider::class, 'lastSuccessfulProvider'))->getValue($fixture->chain));
    }

    public static function redirects(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach (['container' => [0], 'instance' => [0, 1, 2], 'web identity' => [0], 'ini role' => [0]] as $provider => $stages) {
                foreach ($stages as $stage) {
                    foreach ([301, 302, 303, 307, 308] as $status) {
                        foreach (['/same-origin', 'https://other.example.test/credentials', 'http://other.example.test/credentials', '//other.example.test/credentials'] as $destination) {
                            foreach ([false, true] as $retry) {
                                yield "$kind $provider $stage $status $destination retry ".(int) $retry => [$kind, $provider, $stage, $status, $destination, $retry];
                            }
                        }
                    }
                }
            }
        }
    }

    /** @dataProvider providers */
    public function testNormalCredentialsAndWinnerCacheRemainReusableByCaller(string $kind, string $provider, bool $retry): void
    {
        $fixture   = $this->fixture($provider, -1, 200, '', $retry);
        $class     = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $transport = new $class($fixture->ses->client);
        $this->assertSame(1, $transport->send($this->message()));
        $count = 'instance' === $provider ? 3 : 1;
        $this->assertCount($count, $fixture->requests);
        $this->assertSame(1, $transport->send($this->message()));
        $credentials = $fixture->cache->getCredentials($fixture->ses->client->getConfiguration());
        $this->assertSame('credential-key', $credentials->getAccessKeyId());
        $this->assertCount($count, $fixture->requests, 'The original credential cache must retain the scoped refresh.');
        $winners = (new ReflectionProperty(ChainProvider::class, 'lastSuccessfulProvider'))->getValue($fixture->chain);
        $this->assertSame([$fixture->provider], \array_values($winners), 'Never publish a scoped provider into the caller chain.');
        foreach ($fixture->ses->requests as $request) {
            $this->assertStringContainsString('credential-key/', \implode("\n", $request['options']['headers']));
            $this->assertStringNotContainsString('fixture-key/', \implode("\n", $request['options']['headers']));
        }
        $this->assertSame($fixture->http, (new ReflectionProperty($fixture->provider::class, 'httpClient'))->getValue($fixture->provider));
        $this->assertSame($fixture->cache, (new ReflectionProperty(AbstractApi::class, 'credentialProvider'))->getValue($fixture->ses->client));
    }

    public static function providers(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach (['container', 'instance', 'web identity', 'ini role'] as $provider) {
                foreach ([false, true] as $retry) {
                    yield "$kind $provider retry ".(int) $retry => [$kind, $provider, $retry];
                }
            }
        }
    }

    /** @dataProvider providers */
    public function testExpiredCredentialsRefreshWithoutReplacingCallerOwnership(string $kind, string $provider, bool $retry): void
    {
        $fixture       = $this->fixture($provider, -1, 200, '', $retry);
        $configuration = $fixture->ses->client->getConfiguration();
        $key           = \sha1(\serialize($configuration));
        $expired       = new AsyncAws\Core\Credentials\Credentials('expired-key', 'expired-secret', null, new DateTimeImmutable('2000-01-01T00:00:00Z'));
        (new ReflectionProperty(CacheProvider::class, 'cache'))->setValue($fixture->cache, [$key => $expired]);
        (new ReflectionProperty(ChainProvider::class, 'lastSuccessfulProvider'))->setValue($fixture->chain, [$key => $fixture->provider]);
        $class = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';
        $this->assertSame(1, (new $class($fixture->ses->client))->send($this->message()));
        $this->assertSame('credential-key', $fixture->cache->getCredentials($configuration)->getAccessKeyId());
        $this->assertCount('instance' === $provider ? 3 : 1, $fixture->requests);
        $this->assertStringNotContainsString('expired-key', \implode("\n", $fixture->ses->requests[0]['options']['headers']));
    }

    public function testReentrantCredentialRefreshKeepsNewerCallerCache(): void
    {
        $fixture                 = $this->fixture('container', -1, 200, '', false);
        $transport               = new Swift_Transport_Api_AmazonSesApiTransport($fixture->ses->client);
        $newer                   = new AsyncAws\Core\Credentials\Credentials('newer-key', 'newer-secret');
        $fixture->ses->onRequest = function () use ($fixture, $newer): void {
            $key = \sha1(\serialize($fixture->ses->client->getConfiguration()));
            (new ReflectionProperty(CacheProvider::class, 'cache'))->setValue($fixture->cache, [$key => $newer]);
        };
        $this->assertSame(1, $transport->send($this->message()));
        $this->assertSame($newer, $fixture->cache->getCredentials($fixture->ses->client->getConfiguration()));
    }

    /** @dataProvider persistentCaches */
    public function testPsrCacheRetainsNativeKeysAndNeverSavesRedirectFallback(string $provider, bool $chain, bool $redirect): void
    {
        $fixture   = $this->fixture($provider, $redirect ? 0 : -1, 307, 'https://other.example.test/credentials', false);
        $decorated = $chain ? $fixture->chain : $fixture->provider;
        $state     = (object) ['value' => null, 'saved' => false, 'keys' => []];
        $item      = $this->createMock(Psr\Cache\CacheItemInterface::class);
        $item->method('isHit')->willReturnCallback(static fn () => $state->saved);
        $item->method('get')->willReturnCallback(static fn () => $state->value);
        $item->method('set')->willReturnCallback(static function ($value) use ($state, $item) {
            $state->value = $value;

            return $item;
        });
        $item->method('expiresAt')->willReturnSelf();
        $pool = $this->createMock(Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(static function (string $key) use ($state, $item) {
            $state->keys[] = $key;

            return $item;
        });
        $pool->method('save')->willReturnCallback(static function () use ($state): bool {
            $state->saved = true;

            return true;
        });
        $cache = new AsyncAws\Core\Credentials\PsrCacheProvider($decorated, $pool);
        $ses   = new SesSdkFixture([], false, $cache, [
            'podIdentityCredentialsFullUri' => $fixture->ses->client->getConfiguration()->get('podIdentityCredentialsFullUri'),
            'roleArn'                       => $fixture->ses->client->getConfiguration()->get('roleArn'),
            'webIdentityTokenFile'          => $fixture->ses->client->getConfiguration()->get('webIdentityTokenFile'),
            'roleSessionName'               => $fixture->ses->client->getConfiguration()->get('roleSessionName'),
            'sharedCredentialsFile'         => $fixture->ses->client->getConfiguration()->get('sharedCredentialsFile'),
            'sharedConfigFile'              => $fixture->ses->client->getConfiguration()->get('sharedConfigFile'),
            'profile'                       => $fixture->ses->client->getConfiguration()->get('profile'),
        ]);
        $transport = new Swift_Transport_Api_AmazonSesApiTransport($ses->client);
        if ($redirect) {
            try {
                $transport->send($this->message());
                $this->fail('A persistent cache must not save fallback credentials after a redirect.');
            } catch (Swift_TransportException $error) {
                $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
            }
            $this->assertFalse($state->saved);
            $this->assertSame([], $ses->requests);
        } else {
            $this->assertSame(1, $transport->send($this->message()));
            $this->assertSame(1, $transport->send($this->message()));
            $this->assertSame('credential-key', $cache->getCredentials($ses->client->getConfiguration())->getAccessKeyId());
            $this->assertCount(1, $fixture->requests);
        }
        $key = 'AsyncAws.Credentials.'.\sha1(\serialize([$ses->client->getConfiguration(), $decorated::class]));
        $this->assertSame([$key], \array_values(\array_unique($state->keys)));
    }

    public static function persistentCaches(): iterable
    {
        foreach (['container', 'web identity', 'ini role'] as $provider) {
            foreach ([false, true] as $chain) {
                foreach ([false, true] as $redirect) {
                    yield "$provider chain ".(int) $chain.' redirect '.(int) $redirect => [$provider, $chain, $redirect];
                }
            }
        }
    }

    private function fixture(string $kind, int $redirectStage, int $status, string $destination, bool $retry): object
    {
        $fixture = (object) ['requests' => []];
        $raw     = new MockHttpClient(function (string $method, string $url, array $options) use ($fixture, $kind, $redirectStage, $status, $destination): MockResponse {
            $stage               = \count($fixture->requests);
            $fixture->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if ($stage === $redirectStage) {
                return new MockResponse('credential-secret', ['http_code' => $status, 'response_headers' => ['Location: '.$destination]]);
            }
            $credentials = ['Code' => 'Success', 'AccessKeyId' => 'credential-key', 'SecretAccessKey' => 'credential-secret', 'Token' => 'credential-token', 'Expiration' => '2099-01-01T00:00:00Z'];
            $body        = \json_encode($credentials);
            if ('instance' === $kind && 0 === $stage % 3) {
                $body = 'fixture-metadata-token';
            } elseif ('instance' === $kind && 1 === $stage % 3) {
                $body = 'fixture-role';
            } elseif (\in_array($kind, ['web identity', 'ini role'], true)) {
                $operation = 'web identity' === $kind ? 'AssumeRoleWithWebIdentity' : 'AssumeRole';
                $body      = '<'.$operation.'Response xmlns="https://sts.amazonaws.com/doc/2011-06-15/"><'.$operation.'Result><Credentials><AccessKeyId>credential-key</AccessKeyId><SecretAccessKey>credential-secret</SecretAccessKey><SessionToken>credential-token</SessionToken><Expiration>2099-01-01T00:00:00Z</Expiration></Credentials></'.$operation.'Result></'.$operation.'Response>';
            }

            return new MockResponse($body, ['http_code' => 200]);
        });
        $fixture->http = $retry ? new RetryableHttpClient($raw, new GenericRetryStrategy([301, 302, 303, 307, 308, 503], 0), 2) : $raw;
        $configuration = ['podIdentityCredentialsFullUri' => 'http://127.0.0.1:11859/credentials'];
        if ('container' === $kind) {
            $fixture->provider = new ContainerProvider($fixture->http);
        } elseif ('instance' === $kind) {
            $fixture->provider = new InstanceProvider($fixture->http);
        } elseif ('web identity' === $kind) {
            $configuration += ['roleArn' => 'arn:aws:iam::123456789012:role/fixture', 'webIdentityTokenFile' => $this->file('fixture-web-token'), 'roleSessionName' => 'fixture-session'];
            $fixture->provider = new WebIdentityProvider(null, null, $fixture->http);
        } else {
            $configuration += ['sharedCredentialsFile' => $this->file("[fixture]\nrole_arn=arn:aws:iam::123456789012:role/fixture\nsource_profile=source\nrole_session_name=fixture-session\n[source]\naws_access_key_id=source-key\naws_secret_access_key=source-secret\nregion=us-east-1\n"), 'sharedConfigFile' => $this->file(''), 'profile' => 'fixture'];
            $fixture->provider = new IniFileProvider(null, null, $fixture->http);
        }
        // A swallowed credential error must not permit fallback credentials to send mail.
        $fixture->chain = new ChainProvider([$fixture->provider, new ConfigurationProvider()]);
        $fixture->cache = new CacheProvider($fixture->chain);
        $fixture->ses   = new SesSdkFixture([], false, $fixture->cache, $configuration);

        return $fixture;
    }

    private function file(string $contents): string
    {
        $file          = \tempnam(\sys_get_temp_dir(), 'swiftmailer-59-');
        $this->files[] = $file;
        \file_put_contents($file, $contents);

        return $file;
    }

    private function message(): Swift_Message
    {
        return (new Swift_Message('fixture', 'fixture-body'))->setFrom('from@example.test')->setTo('to@example.test');
    }
}
