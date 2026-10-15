<?php

use GuzzleHttp\Psr7\Uri;
use League\OAuth2\Client\Grant\AbstractGrant;
use League\OAuth2\Client\Grant\GrantFactory;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use Microsoft\Kiota\Authentication\Oauth\BaseCertificateContext;
use Microsoft\Kiota\Authentication\Oauth\TokenRequestContext;
use Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider;
use Psr\Http\Message\RequestInterface;

/** Repair native CAE inputs at the grant boundary and verify them before dispatch. */
class Swift_Transport_Api_GraphOAuthGrantPolicy
{
    private ?Swift_Transport_NonRetryableException $failure = null;

    private TokenRequestContext $expectedContext;

    private TokenRequestContext $baselineContext;

    private string $baselineTenant;

    private ?array $baselineParams = null;

    private array $endpoints = [];

    private array $expectedEndpoints = [];

    private array $expectedParams = [];

    private bool $dispatched = false;

    public function __construct(
        #[SensitiveParameter] private PhpLeagueAccessTokenProvider $source,
        #[SensitiveParameter] private PhpLeagueAccessTokenProvider $token,
        #[SensitiveParameter] private AbstractProvider $sourceOAuth,
        #[SensitiveParameter] private AbstractProvider $oauth,
        private ReflectionProperty $contextField,
    ) {
        $this->expectedContext = $source->getTokenRequestContext();
        $this->baselineContext = $token->getTokenRequestContext();
        $this->baselineTenant  = $this->baselineContext->getTenantId();
        if ($oauth instanceof GenericProvider) {
            foreach (['urlAccessToken', 'urlAuthorize'] as $name) {
                $reflection = new ReflectionClass(GenericProvider::class);
                if ($reflection->hasProperty($name)) {
                    $property = $reflection->getProperty($name);
                    if ($property->isInitialized($sourceOAuth) && $property->isInitialized($oauth)) {
                        $this->endpoints[$name]         = $property;
                        $this->expectedEndpoints[$name] = $property->getValue($sourceOAuth);
                    }
                }
            }
        }
        $factory = self::factory($oauth->getGrantFactory());
        if (!(new ReflectionObject($factory))->isCloneable()) {
            $this->reject('The Graph OAuth grant factory must support isolated ownership.');
        }
        $scoped = new Swift_Transport_Api_GraphOAuthGrantFactory(clone $factory, $this);
        $oauth->setGrantFactory($scoped);
        if ($oauth->getGrantFactory() !== $scoped) {
            $this->reject('The Graph OAuth provider must honor its scoped grant factory.');
        }
    }

    private static function factory(#[SensitiveParameter] mixed $factory): GrantFactory
    {
        if (!$factory instanceof GrantFactory) {
            throw new Swift_Transport_NonRetryableException('Graph OAuth requires a compatible League grant factory.');
        }

        return $factory;
    }

    public function begin(#[SensitiveParameter] array $additionalContext): void
    {
        $this->assertValid();
        $this->baselineContext = $this->token->getTokenRequestContext();
        $this->baselineTenant  = $this->baselineContext->getTenantId();
        $this->baselineParams  = ($additionalContext['claims'] ?? false) ? $this->baselineContext->getParams() : null;
        $this->expectedParams  = [];
        $this->dispatched      = false;
    }

    public function contextChanged(): bool
    {
        $this->assertValid();
        try {
            $context = $this->token->getTokenRequestContext();

            return $context !== $this->baselineContext || $context->getTenantId() !== $this->baselineTenant
                                                       || (null !== $this->baselineParams && $context->getParams() !== $this->baselineParams);
        } catch (Throwable $error) {
            $this->reject('Graph OAuth cannot inspect its current token context.', $error);
        }
    }

    public function needsPreparation(string $grant): bool
    {
        return $this->contextChanged() || ('refresh_token' === $grant && $this->token->getTokenRequestContext() instanceof BaseCertificateContext);
    }

    public function prepare(AbstractGrant $grant, #[SensitiveParameter] array $defaults, #[SensitiveParameter] array $options): array
    {
        $this->assertValid();
        try {
            if ('refresh_token' !== (string) $grant && $this->contextChanged()) {
                $fresh    = $this->freshParams();
                $old      = $this->baselineParams ?? $this->baselineContext->getParams();
                $remove   = $old + \array_fill_keys(['client_id', 'client_secret', 'client_assertion', 'client_assertion_type', 'code', 'redirect_uri', 'assertion', 'requested_token_use', 'grant_type', 'refresh_token'], true);
                $pkce     = $defaults['code_verifier'] ?? null;
                $defaults = \array_diff_key($defaults, $remove);
                $options  = \array_replace(\array_diff_key($options, $remove), $fresh);
                if ('authorization_code' !== $this->token->getTokenRequestContext()->getGrantType()) {
                    unset($defaults['code_verifier'], $options['code_verifier']);
                } elseif (null !== $pkce && !\array_key_exists('code_verifier', $old) && !\array_key_exists('code_verifier', $fresh)) {
                    $defaults['code_verifier'] = $pkce;
                }
                $this->alignEndpoints();
                $this->expectedParams = $fresh;
            }
            if ('refresh_token' === (string) $grant && 'urn:ietf:params:Oauth:client-assertion-type:jwt-bearer' === ($options['client_assertion_type'] ?? null)) {
                $options['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            }

            return $grant->prepareRequestParameters($defaults, $options);
        } catch (Throwable $error) {
            $this->reject('Graph OAuth could not prepare a consistent token grant.', $error);
        }
    }

    private function freshParams(): array
    {
        $context         = $this->token->getTokenRequestContext();
        $params          = $context->getParams();
        $params['scope'] = \implode(' ', $this->token->getScopes());
        if ($context->isCAEEnabled()) {
            $reflection = new ReflectionClass(PhpLeagueAccessTokenProvider::class);
            if (!$reflection->hasMethod('mergeClaims')) {
                $this->reject('The Graph SDK claims configuration is unsupported.');
            }
            $merge = $reflection->getMethod('mergeClaims');
            if ($merge->isStatic() || 2 !== $merge->getNumberOfParameters()) {
                $this->reject('The Graph SDK claims configuration is unsupported.');
            }
            $params = $merge->invoke($this->token, $params, PhpLeagueAccessTokenProvider::CP1_CLAIM);
            if (!\is_array($params)) {
                $this->reject('The Graph SDK returned unsupported claims parameters.');
            }
        }

        return $params;
    }

    private function alignEndpoints(): void
    {
        $tenant = $this->token->getTokenRequestContext()->getTenantId();
        if ($tenant === $this->baselineTenant) {
            return;
        }
        if (!$this->oauth instanceof GenericProvider || !\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $tenant)
                                                     || !\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $this->baselineTenant)) {
            $this->reject('Graph CAE tenant changes require compatible single-segment OAuth endpoints.');
        }
        foreach (['urlAccessToken' => 'token', 'urlAuthorize' => 'authorize'] as $name => $suffix) {
            $property = $this->endpoints[$name] ?? null;
            if (null === $property || $property->isReadOnly() || $property->isStatic()) {
                $this->reject('The Graph OAuth endpoint configuration cannot accept a tenant change.');
            }
            $url        = $property->getValue($this->oauth);
            $configured = 'token' === $suffix ? $this->oauth->getBaseAccessTokenUrl([]) : $this->oauth->getBaseAuthorizationUrl();
            if (!\is_string($url) || $configured !== $url) {
                $this->reject('The Graph OAuth provider must honor its configured endpoints.');
            }
            $uri  = new Uri($url);
            $tail = '/'.$this->baselineTenant.'/oauth2/v2.0/'.$suffix;
            if (!\str_ends_with($uri->getPath(), $tail) || '' === $uri->getHost()) {
                $this->reject('The custom Graph OAuth endpoint cannot be safely aligned with the returned tenant.');
            }
            $updated = (string) $uri->withPath(\substr($uri->getPath(), 0, -\strlen($tail)).'/'.$tenant.'/oauth2/v2.0/'.$suffix);
            $property->setValue($this->oauth, $updated);
            $configured = 'token' === $suffix ? $this->oauth->getBaseAccessTokenUrl([]) : $this->oauth->getBaseAuthorizationUrl();
            if ($configured !== $updated) {
                $this->reject('The Graph OAuth provider must honor its returned tenant endpoints.');
            }
        }
    }

    public function validateRequest(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] array $options = []): void
    {
        $this->assertValid();
        $changed     = $this->contextChanged();
        $certificate = $this->token->getTokenRequestContext() instanceof BaseCertificateContext;
        if (!$changed && !$certificate) {
            return;
        }
        try {
            $query = $this->formParams($request->getUri()->getQuery());
            $body  = $request->getBody();
            if (!$body->isSeekable()) {
                $this->reject('Graph OAuth must expose a verifiable token request body.');
            }
            $position = $body->tell();
            try {
                $body->rewind();
                $bytes = $body->getContents();
            } finally {
                $body->seek($position);
            }
            if (\str_contains(\strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
                $params = \json_decode($bytes, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($params)) {
                    $this->reject('Graph OAuth returned an unsupported token request body.');
                }
            } else {
                $params = $this->formParams($bytes);
            }
            $params += $query;
            if ($certificate && 'refresh_token' === ($params['grant_type'] ?? null)
                             && 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' !== ($params['client_assertion_type'] ?? null)) {
                $this->reject('Graph certificate refresh requires a canonical client assertion type.');
            }
            if (!$changed) {
                return;
            }
            if (!$this->expectedParams) {
                $this->reject('The Graph OAuth provider bypassed its current-context grant preparation.');
            }
            $basic = [];
            if (\preg_match('/\ABasic[ \t]+(.*)\z/iD', $request->getHeaderLine('Authorization'), $authorization)) {
                $credentials = \base64_decode($authorization[1], true);
                if (false === $credentials || !\str_contains($credentials, ':')) {
                    $this->reject('Graph OAuth returned malformed HTTP Basic credentials.');
                }
                [$basic['client_id'], $basic['client_secret']] = \explode(':', $credentials, 2);
            }
            foreach ($this->expectedParams as $key => $value) {
                $present = false;
                foreach ([$params, $query, $basic] as $location) {
                    if (\array_key_exists($key, $location)) {
                        $present = true;
                        if ($location[$key] !== $value) {
                            $this->reject('The Graph token request does not match its current context.');
                        }
                    }
                }
                if (!$present) {
                    $this->reject('The Graph token request omitted a current-context grant parameter.');
                }
            }
            foreach ($this->baselineParams ?? [] as $key => $value) {
                if (!\array_key_exists($key, $this->expectedParams) && (\array_key_exists($key, $params) || \array_key_exists($key, $basic))) {
                    $this->reject('The Graph token request retained an obsolete context parameter.');
                }
            }
            $expectedUrl = $this->oauth->getBaseAccessTokenUrl($params);
            if ($request->getUri()->getScheme()       !== (new Uri($expectedUrl))->getScheme()
                || $request->getUri()->getAuthority() !== (new Uri($expectedUrl))->getAuthority()
                || $request->getUri()->getPath()      !== (new Uri($expectedUrl))->getPath()) {
                $this->reject('The Graph token request does not match its current tenant endpoint.');
            }
            $this->dispatched = true;
        } catch (Throwable $error) {
            $this->reject('Graph OAuth cannot verify its current-context token request.', $error);
        }
    }

    /** Reject duplicates before parse_str can hide a stale first parameter. */
    private function formParams(#[SensitiveParameter] string $bytes): array
    {
        $seen = [];
        foreach (\explode('&', $bytes) as $pair) {
            if ('' === $pair) {
                continue;
            }
            \parse_str($pair, $entry);
            $key = \array_key_first($entry);
            if (null === $key) {
                $this->reject('Graph OAuth returned an invalid token parameter name.');
            }
            if (isset($seen[$key])) {
                $this->reject('Graph OAuth returned ambiguous token request parameters.');
            }
            $seen[$key] = true;
        }
        \parse_str($bytes, $params);

        return $params;
    }

    public function onFailure(Throwable $error): void
    {
        if (null === $this->failure && !$this->dispatched && $this->contextChanged()) {
            $this->reject('The returned Graph context could not prepare a token request.', $error);
        }
    }

    public function publish(): void
    {
        $this->assertValid();
        if ($this->contextField->getValue($this->source) !== $this->expectedContext || $this->source->getOauthProvider() !== $this->sourceOAuth) {
            return;
        }
        foreach ($this->endpoints as $name => $property) {
            if ($property->getValue($this->sourceOAuth) !== $this->expectedEndpoints[$name]) {
                return;
            }
        }
        foreach ($this->endpoints as $name => $property) {
            $value = $property->getValue($this->oauth);
            if ($value !== $this->expectedEndpoints[$name]) {
                $property->setValue($this->sourceOAuth, $value);
                $this->expectedEndpoints[$name] = $value;
            }
        }
        $context = $this->token->getTokenRequestContext();
        $this->contextField->setValue($this->source, $context);
        $this->expectedContext = $context;
    }

    public function assertValid(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    public function reject(string $message, #[SensitiveParameter] ?Throwable $previous = null): never
    {
        $this->failure ??= new Swift_Transport_NonRetryableException($message, 0, $previous);
        throw $this->failure;
    }
}
