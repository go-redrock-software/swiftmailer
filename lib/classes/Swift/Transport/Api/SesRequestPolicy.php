<?php

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\NullProvider;
use AsyncAws\Core\Credentials\PsrCacheProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use AsyncAws\Core\Sts\StsClient;
use AsyncAws\Ses\SesClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Checked, operation-local ownership of AsyncAws HTTP and credential requests. */
class Swift_Transport_Api_SesRequestPolicy
{
    private ?Swift_Transport_NonRetryableException $failure = null;

    private ?int $sendStatus = null;

    private ?Swift_Transport_NonRetryableException $acceptanceFailure = null;

    private SplObjectStorage $providers;

    private SplObjectStorage $originalProviders;

    private SplObjectStorage $http;

    private SplObjectStorage $visiting;

    private array $states = [];

    private function __construct()
    {
        $this->providers         = new SplObjectStorage();
        $this->originalProviders = new SplObjectStorage();
        $this->http              = new SplObjectStorage();
        $this->visiting          = new SplObjectStorage();
    }

    public static function run(#[SensitiveParameter] mixed $client, callable $operation): mixed
    {
        $policy = new self();
        try {
            $result = $operation($policy->scopeClient($client));
            $policy->assertNoRedirect();
            $policy->assertAcceptance();

            return $result;
        } catch (Throwable $error) {
            $policy->assertNoRedirect();
            if (null !== $policy->sendStatus) {
                $policy->refuseAcceptance($policy->sendStatus);
            }
            $policy->assertAcceptance();
            throw $error;
        } finally {
            if (null === $policy->failure) {
                $policy->publishState();
            }
        }
    }

    /** Return a failure without retaining credential-bearing SDK/client graphs. */
    public static function detachedFailure(#[SensitiveParameter] Throwable $error, string $message): Swift_TransportException
    {
        $class  = Swift_Transport_RetryPolicy::isTerminalFailure($error) ? Swift_Transport_NonRetryableException::class : Swift_TransportException::class;
        $code   = $error->getCode();
        $detail = 500 === $code ? '; internal server error' : '';

        return new $class($message.' (SES status '.$code.$detail.').', $code);
    }

    public function observeSendStatus(int $status): void
    {
        if ($status >= 200 && $status < 300) {
            $this->sendStatus = $status;
        }
    }

    public function refuseAcceptance(int $status): never
    {
        $this->acceptanceFailure ??= new Swift_Transport_NonRetryableException('SES acceptance could not be confirmed.', $status);
        throw $this->acceptanceFailure;
    }

    public function assertAcceptance(): void
    {
        if (null !== $this->acceptanceFailure) {
            throw $this->acceptanceFailure;
        }
    }

    public function assertNoRedirect(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    public function getRedirectStatus(): ?int
    {
        return $this->failure?->getCode();
    }

    public function refuseRedirect(int $status): void
    {
        $this->failure ??= new Swift_Transport_NonRetryableException('SES HTTP redirects are not permitted.', $status);
    }

    private function scopeClient(#[SensitiveParameter] mixed $client): SesClient
    {
        if (!$client instanceof SesClient || SesClient::class !== $client::class) {
            throw new Swift_Transport_NonRetryableException('SES requires an AsyncAws SES v2 client for isolated HTTP requests.');
        }
        $copy        = self::copy($client);
        $http        = self::read(AbstractApi::class, 'httpClient', $client);
        $credentials = self::read(AbstractApi::class, 'credentialProvider', $client);
        if (!$http instanceof HttpClientInterface || !$credentials instanceof CredentialProvider) {
            throw new Swift_Transport_NonRetryableException('The SES client must have initialized HTTP and credential providers.');
        }
        self::replace(AbstractApi::class, 'httpClient', $copy, $this->scopeHttp($http));
        self::replace(AbstractApi::class, 'credentialProvider', $copy, $this->scopeProvider($credentials));

        return $copy;
    }

    public function scopeHttp(#[SensitiveParameter] HttpClientInterface $client): HttpClientInterface
    {
        if (!isset($this->http[$client])) {
            $this->http[$client] = new Swift_Transport_Api_SesHttpClient($client, $this);
        }

        return $this->http[$client];
    }

    private function scopeProvider(#[SensitiveParameter] CredentialProvider $provider): CredentialProvider
    {
        if (isset($this->visiting[$provider])) {
            throw new Swift_Transport_NonRetryableException('Circular SES credential-provider ownership is unsupported.');
        }
        if (isset($this->providers[$provider])) {
            return $this->providers[$provider];
        }
        $class = $provider::class;
        if (\in_array($class, [ConfigurationProvider::class, NullProvider::class, Credentials::class], true)) {
            return $provider;
        }
        if (!\in_array($class, [CacheProvider::class, ChainProvider::class, PsrCacheProvider::class, WebIdentityProvider::class, IniFileProvider::class, ContainerProvider::class, InstanceProvider::class], true)) {
            throw new Swift_Transport_NonRetryableException('The custom SES credential provider does not support isolated HTTP requests.');
        }
        $this->visiting[$provider]      = true;
        $copy                           = self::copy($provider);
        $this->providers[$provider]     = $copy;
        $this->originalProviders[$copy] = $provider;
        if (\in_array($class, [CacheProvider::class, PsrCacheProvider::class], true)) {
            $decorated = self::read($class, 'decorated', $provider);
            if (!$decorated instanceof CredentialProvider) {
                throw new Swift_Transport_NonRetryableException('The SES credential cache must own a compatible provider.');
            }
            $scoped = $this->scopeProvider($decorated);
            // PsrCacheProvider includes the decorated class in its persistent key.
            // Direct network providers return null (never credentials) when swallowing
            // a refusal; retain their class so existing cache entries remain usable.
            if (PsrCacheProvider::class === $class && $scoped instanceof Swift_Transport_Api_SesCredentialProvider) {
                $scoped = $scoped->getProvider();
            }
            self::replace($class, 'decorated', $copy, $scoped);
            if (CacheProvider::class === $class) {
                $this->rememberState($class, 'cache', $provider, $copy);
            }
        } elseif (ChainProvider::class === $class) {
            $providers = self::read($class, 'providers', $provider);
            if (!\is_array($providers)) {
                throw new Swift_Transport_NonRetryableException('SES credential chains must use a reusable provider array.');
            }
            foreach ($providers as &$item) {
                if (!$item instanceof CredentialProvider) {
                    throw new Swift_Transport_NonRetryableException('The SES credential chain contains an unsupported provider.');
                }
                $item = $this->scopeProvider($item);
            }
            unset($item);
            self::replace($class, 'providers', $copy, $providers);
            $this->rememberState($class, 'lastSuccessfulProvider', $provider, $copy);
            $winners = self::read($class, 'lastSuccessfulProvider', $provider);
            foreach ($winners as &$winner) {
                if (null !== $winner) {
                    if (!$winner instanceof CredentialProvider) {
                        throw new Swift_Transport_NonRetryableException('The SES credential chain has an unsupported cached provider.');
                    }
                    $winner = $this->scopeProvider($winner);
                }
            }
            unset($winner);
            self::replace($class, 'lastSuccessfulProvider', $copy, $winners);
        } else {
            $http = self::read($class, 'httpClient', $provider);
            if (null === $http && \in_array($class, [WebIdentityProvider::class, IniFileProvider::class], true)) {
                // Preserve AsyncAws's default AWS retry strategy for lazily created STS/SSO clients.
                $defaults = new StsClient([], new NullProvider(), null, self::read($class, 'logger', $provider));
                $http     = self::read(AbstractApi::class, 'httpClient', $defaults);
            }
            if (!$http instanceof HttpClientInterface) {
                throw new Swift_Transport_NonRetryableException('The SES credential provider must own a compatible HTTP client.');
            }
            self::replace($class, 'httpClient', $copy, $this->scopeHttp($http));
            $copy                           = new Swift_Transport_Api_SesCredentialProvider($copy, $this);
            $this->providers[$provider]     = $copy;
            $this->originalProviders[$copy] = $provider;
        }
        unset($this->visiting[$provider]);

        return $copy;
    }

    private function rememberState(string $class, string $name, object $source, object $copy): void
    {
        $expected = self::read($class, $name, $source);
        if (!\is_array($expected)) {
            throw new Swift_Transport_NonRetryableException('The SES credential cache state is unsupported.');
        }
        self::replace($class, $name, $copy, $expected);
        $this->states[] = [$class, $name, $source, $copy, $expected];
    }

    private function publishState(): void
    {
        foreach ($this->states as [$class, $name, $source, $copy, $expected]) {
            $current = self::read($class, $name, $source);
            $updated = self::read($class, $name, $copy);
            foreach ($updated as $key => $value) {
                if ($value instanceof CredentialProvider && isset($this->originalProviders[$value])) {
                    $value = $this->originalProviders[$value];
                }
                // A reentrant operation or caller callback owns any newer entry.
                if (\array_key_exists($key, $current) === \array_key_exists($key, $expected)
                    && (!\array_key_exists($key, $expected) || $current[$key] === $expected[$key])) {
                    $current[$key] = $value;
                }
            }
            self::replace($class, $name, $source, $current);
        }
    }

    public static function copy(#[SensitiveParameter] object $source): object
    {
        if (!(new ReflectionObject($source))->isCloneable()) {
            throw new Swift_Transport_NonRetryableException('SES request-policy ownership must support cloning.');
        }

        return clone $source;
    }

    private static function property(string $class, string $name, object $source): ReflectionProperty
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->hasProperty($name)) {
            throw new Swift_Transport_NonRetryableException('The SES SDK HTTP or credential configuration is unsupported.');
        }
        $property = $reflection->getProperty($name);
        if ($property->isStatic() || !$property->isInitialized($source)) {
            throw new Swift_Transport_NonRetryableException('The SES SDK HTTP or credential configuration must be initialized.');
        }

        return $property;
    }

    public static function read(string $class, string $name, #[SensitiveParameter] object $source): mixed
    {
        return self::property($class, $name, $source)->getValue($source);
    }

    public static function replace(string $class, string $name, #[SensitiveParameter] object $copy, #[SensitiveParameter] mixed $value): void
    {
        $property = self::property($class, $name, $copy);
        if ($property->isReadOnly()) {
            throw new Swift_Transport_NonRetryableException('The SES SDK HTTP or credential configuration must support isolated replacement.');
        }
        try {
            $property->setValue($copy, $value);
        } catch (TypeError $error) {
            throw new Swift_Transport_NonRetryableException('The SES SDK configuration cannot accept the isolated policy.', 0, $error);
        }
    }
}
