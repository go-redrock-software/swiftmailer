<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Ses\SesClient;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ProviderFactory;
use Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider;
use Microsoft\Kiota\Authentication\PhpLeagueAuthenticationProvider;
use Nyholm\Dsn\Configuration\Url;
use Nyholm\Dsn\DsnParser;
use Nyholm\Dsn\Exception\InvalidDsnException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Factory that creates Swift_Transport instances from DSN strings.
 *
 * Supports meta-transport wrappers:
 *   failover(dsn1 dsn2)    -> Swift_Transport_FailoverTransport
 *   roundrobin(dsn1 dsn2)  -> Swift_Transport_LoadBalancedTransport
 *   retry(dsn)             -> Swift_Transport_RetryTransport
 *
 * Retry can also be requested via query parameters on a plain DSN:
 *   null://default?retries=5&retry_delay=2000
 */
class Swift_Transport_DsnTransportFactory
{
    private const ALLOWED_DSN_PARAMETERS = [
        'verify_peer',
        'peer_fingerprint',
        'source_ip',
        'smtputf8',
        'command',
        'retries',
        'retry_delay',
        'click_tracking',
        'click_tracking_text',
        'open_tracking',
        'timeout',
        'auth_mode',
        'local_domain',
        'region',
        'inbox_id',
        'session_token',
        'refresh_token',
        'tenant_id',
        'user_id',
    ];

    private const array SENDGRID_TRACKING_PARAMETERS = ['click_tracking', 'click_tracking_text', 'open_tracking'];

    private const array SMTP_CONNECTION_PARAMETERS = ['timeout', 'auth_mode', 'local_domain'];

    private const array SMTP_HOST_MAP = [
        'gmail+smtp'      => ['host' => 'smtp.gmail.com',                     'port' => 465, 'encryption' => 'ssl'],
        'amazon+smtp'     => ['host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls'],
        'brevo+smtp'      => ['host' => 'smtp-relay.brevo.com',               'port' => 587, 'encryption' => 'tls'],
        'infobip+smtp'    => ['host' => 'smtp-api.infobip.com',               'port' => 587, 'encryption' => 'tls'],
        'mandrill+smtp'   => ['host' => 'smtp.mandrillapp.com',               'port' => 587, 'encryption' => 'tls'],
        'mailersend+smtp' => ['host' => 'smtp.mailersend.net',                'port' => 587, 'encryption' => 'tls'],
        'mailgun+smtp'    => ['host' => 'smtp.mailgun.org',                   'port' => 587, 'encryption' => 'tls'],
        'mailjet+smtp'    => ['host' => 'in-v3.mailjet.com',                  'port' => 587, 'encryption' => 'tls'],
        'postmark+smtp'   => ['host' => 'smtp.postmarkapp.com',               'port' => 587, 'encryption' => 'tls'],
        'resend+smtp'     => ['host' => 'smtp.resend.com',                    'port' => 465, 'encryption' => 'ssl'],
        'scaleway+smtp'   => ['host' => 'smtp.tem.scw.cloud',                 'port' => 587, 'encryption' => 'tls'],
        'sendgrid+smtp'   => ['host' => 'smtp.sendgrid.net',                  'port' => 587, 'encryption' => 'tls'],
        'ahasend+smtp'    => ['host' => 'smtp.ahasend.com',                   'port' => 587, 'encryption' => 'tls'],
        'mailomat+smtp'   => ['host' => 'smtp.mailomat.at',                   'port' => 587, 'encryption' => 'tls'],
        'mailtrap+smtp'   => ['host' => 'live.smtp.mailtrap.io',              'port' => 587, 'encryption' => 'tls'],
        'sweego+smtp'     => ['host' => 'smtp.sweego.io',                     'port' => 587, 'encryption' => 'tls'],
    ];

    private ?HttpClientInterface $sesHttpClient = null;

    private ?GuzzleClientInterface $gmailHttpClient = null;

    private ?GuzzleClientInterface $graphHttpClient = null;

    private ?GuzzleClientInterface $graphTokenHttpClient = null;

    /** Optional caller-owned HTTP clients for factory-created SDK clients. */
    public function __construct(#[SensitiveParameter] ?HttpClientInterface $sesHttpClient = null, #[SensitiveParameter] ?GuzzleClientInterface $gmailHttpClient = null, #[SensitiveParameter] ?GuzzleClientInterface $graphHttpClient = null, #[SensitiveParameter] ?GuzzleClientInterface $graphTokenHttpClient = null)
    {
        $this->sesHttpClient        = $sesHttpClient;
        $this->gmailHttpClient      = $gmailHttpClient;
        $this->graphHttpClient      = $graphHttpClient;
        $this->graphTokenHttpClient = $graphTokenHttpClient;
    }

    public function fromDsnString(#[SensitiveParameter] string $dsnString): Swift_Transport
    {
        // retry(...) wrapper -- decorate the inner transport (which may itself be a
        // failover/roundrobin wrapper or a plain DSN) with retry-on-failure.
        if (\preg_match('/\Aretry\((.+)\)\z/D', $dsnString, $matches)) {
            return new Swift_Transport_RetryTransport($this->fromDsnString(\trim($matches[1])));
        }

        // Check for meta-transport wrappers
        if (\preg_match('/\A(failover|roundrobin)\((.+?)\)(?:\?(.*))?\z/D', $dsnString, $matches)) {
            $retryPeriod = $this->parsePoolRetryPeriod($matches[3] ?? '');
            $wrapper     = $matches[1];
            if (\substr_count($matches[2], ')') > \substr_count($matches[2], '(')) {
                throw new InvalidArgumentException('Malformed pool DSN; configure retry_period after the closing parenthesis.');
            }
            $innerDsns = \preg_split('/\s+/', \trim($matches[2]));

            $transports = [];
            foreach ($innerDsns as $innerDsn) {
                $transports[] = $this->fromDsnString($innerDsn);
            }

            if ('failover' === $wrapper) {
                $transport = new Swift_Transport_FailoverTransport($retryPeriod);
            } else {
                $transport = new Swift_Transport_LoadBalancedTransport($retryPeriod);
            }
            $transport->setTransports($transports);

            return $transport;
        }

        if (\preg_match('/\A(?:failover|roundrobin|retry)\(/D', $dsnString)) {
            throw new InvalidArgumentException('Malformed transport wrapper DSN; retry_period belongs after a pool wrapper.');
        }
        $transport = $this->createTransport($dsnString);

        // A positive ?retries= query parameter decorates the transport with retry.
        return $this->maybeWrapWithRetry($transport, $dsnString);
    }

    /** Validate pool options before constructing any credential-bearing inner DSN. */
    private function parsePoolRetryPeriod(#[SensitiveParameter] string $query): int
    {
        if ('' === $query) {
            return 60;
        }
        $pairs = \explode('&', $query);
        $pair  = \explode('=', $pairs[0], 2);
        if (1 !== \count($pairs) || 2 !== \count($pair) || 'retry_period' !== \urldecode($pair[0])) {
            throw new InvalidArgumentException('Pool options must contain only one retry_period value.');
        }
        $value = \urldecode($pair[1]);
        if (!\preg_match('/\A[0-9]+\z/D', $value)) {
            throw new InvalidArgumentException('retry_period must be a non-negative integer number of seconds.');
        }
        $value   = \ltrim($value, '0');
        $value   = '' === $value ? '0' : $value;
        $maximum = (string) \PHP_INT_MAX;
        if (\strlen($value) > \strlen($maximum) || (\strlen($value) === \strlen($maximum) && \strcmp($value, $maximum) > 0)) {
            throw new InvalidArgumentException('retry_period is outside the supported integer range.');
        }

        return (int) $value;
    }

    private function parseDsnUrl(#[SensitiveParameter] string $dsnString): Url
    {
        try {
            $parsed = DsnParser::parseUrl($dsnString);
        } catch (InvalidDsnException) {
            // Parser errors retain raw DSNs in messages, properties and trace arguments.
            throw new InvalidArgumentException('Invalid DSN syntax; check the scheme, host, port and URL-encoded credentials.');
        }
        // Nyholm 2.0.1 uses empty() on raw userinfo and loses the literal string '0'.
        // Restore only that value, retaining the validated parser result and query data.
        if (\preg_match('#\A[a-zA-Z0-9+.-]+:(?://)?([^/?\#@]*)@#D', $dsnString, $matches)) {
            $authentication = \explode(':', $matches[1], 2);
            if ('0' === $authentication[0]) {
                $parsed = $parsed->withUser('0');
            }
            if ('0' === ($authentication[1] ?? null)) {
                $parsed = $parsed->withPassword('0');
            }
        }

        return $parsed;
    }

    /**
     * Wraps a transport in a RetryTransport when the DSN carries a positive
     * "retries" query parameter (with an optional "retry_delay" in milliseconds).
     */
    private function maybeWrapWithRetry(Swift_Transport $transport, #[SensitiveParameter] string $dsnString): Swift_Transport
    {
        $dsn     = new Swift_Dsn($this->parseDsnUrl($dsnString));
        $retries = (int) ($dsn->getParameter('retries') ?? 0);

        if ($retries <= 0) {
            return $transport;
        }

        $delay = (int) ($dsn->getParameter('retry_delay') ?? 1000);

        return new Swift_Transport_RetryTransport($transport, $retries, $delay);
    }

    private function createTransport(#[SensitiveParameter] string $dsnString): Swift_Transport
    {
        $nyholmDsn  = $this->parseDsnUrl($dsnString);
        $dsn        = new Swift_Dsn($nyholmDsn);
        $sdkOptions = null;
        if ('gmail+api' === $dsn->getScheme()) {
            $sdkOptions = $this->parseGmailOptions($dsnString, $dsn);
        } elseif ('microsoft-graph' === $dsn->getScheme()) {
            $sdkOptions = $this->parseGraphOptions($dsnString, $dsn);
        } else {
            $this->validateDsnParameters($dsn->getParameters());
        }
        $trackingOptions = $this->parseSendgridTrackingOptions($dsnString, $dsn);

        $class           = $dsn->getTransportClass();
        $smtpOptions     = $this->parseSmtpOptions($dsnString, $dsn, $class);
        $providerOptions = $sdkOptions ?? $this->parseHttpProviderOptions($dsnString, $dsn);

        // NullTransport needs an event dispatcher
        if (Swift_Transport_NullTransport::class === $class) {
            return new Swift_Transport_NullTransport(
                new Swift_Events_SimpleEventDispatcher(),
            );
        }

        // Sendmail / native transports
        if (Swift_Transport_SendmailTransport::class === $class) {
            if ('native' === $dsn->getScheme()) {
                $command = \ini_get('sendmail_path') ?: '/usr/sbin/sendmail -bs';
            } else {
                $command = $dsn->getParameter('command') ?: '/usr/sbin/sendmail -bs';
            }

            $binary          = \explode(' ', $command)[0];
            $allowedBinaries = [
                '/usr/sbin/sendmail',
                '/usr/lib/sendmail',
                '/usr/bin/sendmail',
                '/usr/local/sbin/sendmail',
                '/usr/local/bin/sendmail',
            ];
            if (!\in_array($binary, $allowedBinaries, true)) {
                throw new InvalidArgumentException(\sprintf('DSN sendmail binary "%s" is not in the allowlist.', $binary));
            }

            return new Swift_SendmailTransport($command);
        }

        // SMTP transports
        if (Swift_Transport_EsmtpTransport::class === $class) {
            return $this->createSmtpTransport($dsn, $smtpOptions);
        }

        $transport = $this->createHttpTransport($dsnString, $dsn, $class, $providerOptions);
        if ($transport instanceof Swift_Transport_Api_SendgridTransport) {
            $transport->setClickTracking($trackingOptions['click_tracking'] ?? null)
                ->setClickTrackingText($trackingOptions['click_tracking_text'] ?? null)
                ->setOpenTracking($trackingOptions['open_tracking'] ?? null);
        }

        return $transport;
    }

    /** @return array<string, string> */
    private function parseHttpProviderOptions(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn): array
    {
        $options = $this->canonicalOptions($dsnString, $dsn->getParameters(), ['region', 'inbox_id', 'session_token', 'refresh_token', 'tenant_id', 'user_id'], 'HTTP provider');
        if (isset($options['refresh_token'])) {
            throw new InvalidArgumentException('refresh_token requires the Gmail API scheme.');
        }
        if (isset($options['tenant_id']) || isset($options['user_id'])) {
            throw new InvalidArgumentException('tenant_id and user_id require the Microsoft Graph scheme.');
        }
        if (isset($options['region'])) {
            if (\in_array($dsn->getScheme(), ['amazon+api', 'amazon+http'], true)) {
                $this->sesRegion($options['region']);
            } else {
                $regions = match ($dsn->getScheme()) {
                    'mailgun'  => ['us', 'eu'],
                    'scaleway' => ['fr-par'],
                    default    => [],
                };
                if (!\in_array($options['region'], $regions, true)) {
                    throw new InvalidArgumentException('HTTP provider region is unsupported for this scheme.');
                }
            }
            if ('mailgun' === $dsn->getScheme() && 'default' !== $dsn->getHost()) {
                throw new InvalidArgumentException('Mailgun region requires the default endpoint host.');
            }
        }
        if (isset($options['session_token'])) {
            if (!\in_array($dsn->getScheme(), ['amazon+api', 'amazon+http'], true)) {
                throw new InvalidArgumentException('session_token requires an Amazon SES SDK scheme.');
            }
            $this->sesCredential($options['session_token']);
        }
        if (isset($options['inbox_id']) && 'mailtrap+sandbox' !== $dsn->getScheme()) {
            throw new InvalidArgumentException('inbox_id requires the mailtrap+sandbox scheme.');
        }

        return $options;
    }

    /** @param array<string, string> $options */
    private function createHttpTransport(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, string $class, #[SensitiveParameter] array $options): Swift_Transport
    {
        $scheme = $dsn->getScheme();
        if (\in_array($scheme, ['amazon+api', 'amazon+http'], true)) {
            return $this->createSesTransport($dsnString, $dsn, $class, $options);
        }
        if ('gmail+api' === $scheme) {
            return $this->createGmailTransport($dsnString, $dsn, $options);
        }
        if ('microsoft-graph' === $scheme) {
            return $this->createGraphTransport($dsnString, $dsn, $options);
        }
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $apiKey     = $dsn->getUser() ?? $dsn->getPassword() ?? '';
        switch ($scheme) {
            case 'mailgun':
                $key         = $this->requiredHttpCredential($dsn->getUser());
                $domain      = $this->requiredHttpCredential($dsn->getPassword());
                $defaultHost = 'eu' === ($options['region'] ?? 'us') ? 'api.eu.mailgun.net' : 'api.mailgun.net';
                $endpoint    = $this->httpEndpoint($dsnString, $dsn, $defaultHost);

                return new Swift_Transport_Api_MailGunTransport($key, $domain, $endpoint, null, $dispatcher);
            case 'mailjet':
                $this->requireFixedHttpEndpoint($dsnString, $dsn);

                return new Swift_Transport_Api_MailJetTransport($this->requiredHttpCredential($dsn->getUser()), $this->requiredHttpCredential($dsn->getPassword()), null, $dispatcher);
            case 'scaleway':
                $this->requireFixedHttpEndpoint($dsnString, $dsn);

                return new Swift_Transport_Api_ScalewayTransport($this->requiredHttpCredential($dsn->getPassword()), $this->requiredHttpCredential($dsn->getUser()), $options['region'] ?? 'fr-par', null, $dispatcher);
            case 'infobip':
                return new Swift_Transport_Api_InfoBipTransport($this->requiredHttpCredential($apiKey), \substr($this->httpEndpoint($dsnString, $dsn), 8), null, $dispatcher);
            case 'postal':
                return new Swift_Transport_Api_PostalTransport($this->requiredHttpCredential($apiKey), \substr($this->httpEndpoint($dsnString, $dsn), 8), null, $dispatcher);
            case 'mailtrap':
                $this->requireFixedHttpEndpoint($dsnString, $dsn);

                return new Swift_Transport_Api_MailtrapTransport($this->requiredHttpCredential($apiKey), false, null, null, $dispatcher);
            case 'mailtrap+sandbox':
                $this->requireFixedHttpEndpoint($dsnString, $dsn, true);
                $hostId  = 'default' === $dsn->getHost() ? null : $this->mailtrapInboxId($dsn->getHost() ?? '');
                $inboxId = isset($options['inbox_id']) ? $this->mailtrapInboxId($options['inbox_id']) : $hostId;
                if (null === $inboxId || (null !== $hostId && $hostId !== $inboxId)) {
                    throw new InvalidArgumentException('Mailtrap sandbox requires one unambiguous positive inbox ID.');
                }

                return new Swift_Transport_Api_MailtrapTransport($this->requiredHttpCredential($apiKey), true, $inboxId, null, $dispatcher);
        }

        // These remaining HTTP providers share the base constructor contract.
        return new $class($apiKey, null, $dispatcher);
    }

    /** @return array<string, string> */
    private function parseGraphOptions(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn): array
    {
        $names = ['tenant_id', 'user_id', 'retries', 'retry_delay'];
        if (\array_diff(\array_keys($dsn->getParameters()), $names)) {
            throw new InvalidArgumentException('Graph DSN options may contain only tenant_id, user_id, retries and retry_delay.');
        }

        return $this->canonicalOptions($dsnString, $dsn->getParameters(), $names, 'Graph');
    }

    /** @param array<string, string> $options */
    private function createGraphTransport(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, #[SensitiveParameter] array $options): Swift_Transport
    {
        $this->requireFixedHttpEndpoint($dsnString, $dsn);
        $user     = $this->graphCredential($dsn->getUser());
        $mailbox  = isset($options['user_id']) ? $this->graphCredential($options['user_id']) : null;
        $password = $dsn->getPassword();
        $tenant   = $options['tenant_id'] ?? null;
        if (null !== \parse_url($dsnString, \PHP_URL_PASS)) {
            $password = $this->graphCredential($password, true);
            if (null === $tenant || null === $mailbox || !\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $tenant)) {
                throw new InvalidArgumentException('Graph application DSNs require a safe tenant_id and explicit user_id mailbox.');
            }
        } elseif (null !== $tenant || !\preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $user)) {
            throw new InvalidArgumentException('Graph bearer DSNs require one valid token without a password or tenant_id.');
        }
        try {
            if (null === $password) {
                $authentication = new Swift_Transport_Api_GraphBearerAuthenticationProvider($user);
            } else {
                $context = new Swift_Transport_Api_GraphClientCredentialContext($tenant, $user, $password);
                $oauth   = ProviderFactory::create($context, [], 'https://login.microsoftonline.com', 'https://graph.microsoft.com');
                if (null !== $this->graphTokenHttpClient) {
                    $oauth->setHttpClient($this->graphTokenHttpClient);
                }
                $token          = new PhpLeagueAccessTokenProvider($context, ['https://graph.microsoft.com/.default'], ['graph.microsoft.com'], $oauth);
                $authentication = PhpLeagueAuthenticationProvider::createWithAccessTokenProvider($token);
            }
            $adapter = new GraphRequestAdapter($authentication, $this->createGraphHttpClient(null === $mailbox));
            $adapter->setBaseUrl('https://graph.microsoft.com/v1.0');
            $client = GraphServiceClient::createWithRequestAdapter($adapter);

            return new Swift_Transport_Api_MicrosoftGraphTransport($client, $mailbox, new Swift_Events_SimpleEventDispatcher());
        } catch (Throwable) {
            throw new InvalidArgumentException('Unable to construct the Graph SDK client from explicit DSN authentication.');
        }
    }

    /** Add the SDK's /me URL translation to an isolated caller configuration. */
    private function createGraphHttpClient(bool $useMe): ?GuzzleHttp\Client
    {
        if (null === $this->graphHttpClient) {
            return null;
        }
        $config = $this->graphHttpClient->getConfig();
        if (!\is_array($config)) {
            throw new InvalidArgumentException('Graph HTTP clients must expose compatible configuration.');
        }
        $handler = $config['handler'] ?? null;
        if ($handler instanceof GuzzleHttp\HandlerStack) {
            $stack = clone $handler;
        } elseif (\is_callable($handler)) {
            $stack = GuzzleHttp\HandlerStack::create($handler);
        } else {
            throw new InvalidArgumentException('Graph HTTP clients must expose a callable handler.');
        }
        if ($useMe) {
            $stack->unshift(Microsoft\Graph\Core\Middleware\GraphMiddleware::urlReplace(new Microsoft\Kiota\Http\Middleware\Options\UrlReplaceOption(true, ['/users/me-token-to-replace' => '/me'])), 'swift_graph_me_url');
        }
        $config['handler']     = $stack;
        $config['http_errors'] = false;
        unset($config['auth']);
        if (isset($config['headers'])) {
            if (!\is_array($config['headers'])) {
                throw new InvalidArgumentException('Graph HTTP clients must expose compatible headers.');
            }
            foreach (\array_keys($config['headers']) as $name) {
                if (0 === \strcasecmp((string) $name, 'Authorization')) {
                    unset($config['headers'][$name]);
                }
            }
        }

        return new GuzzleHttp\Client($config);
    }

    private function graphCredential(#[SensitiveParameter] ?string $value, bool $allowSpace = false): string
    {
        $pattern = $allowSpace ? '/[^\x20-\x7e]/' : '/[^\x21-\x7e]/';
        if (null === $value || '' === $value || \preg_match($pattern, $value)) {
            throw new InvalidArgumentException('Graph credentials must be nonempty printable ASCII strings; identifiers cannot contain spaces.');
        }

        return $value;
    }

    /** @return array<string, string> */
    private function parseGmailOptions(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn): array
    {
        $names = ['refresh_token', 'retries', 'retry_delay'];
        if (\array_diff(\array_keys($dsn->getParameters()), $names)) {
            throw new InvalidArgumentException('Gmail DSN options may contain only refresh_token, retries and retry_delay.');
        }
        $options = $this->canonicalOptions($dsnString, $dsn->getParameters(), $names, 'Gmail');
        if (isset($options['refresh_token'])) {
            $this->gmailCredential($options['refresh_token']);
        }

        return $options;
    }

    /** @param array<string, string> $options */
    private function createGmailTransport(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, #[SensitiveParameter] array $options): Swift_Transport
    {
        $this->requireFixedHttpEndpoint($dsnString, $dsn);
        $user   = $this->gmailCredential($dsn->getUser());
        $secret = null;
        if (isset($options['refresh_token'])) {
            $secret = $this->gmailCredential($dsn->getPassword());
        } elseif (null !== \parse_url($dsnString, \PHP_URL_PASS) || !\preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $user)) {
            throw new InvalidArgumentException('Gmail bearer DSNs require one valid bearer token without a password.');
        }
        try {
            $credentials = null === $secret ? null : new Swift_Transport_Api_GmailRefreshCredentials([Gmail::GMAIL_SEND], [
                'client_id' => $user, 'client_secret' => $secret, 'refresh_token' => $options['refresh_token'],
            ]);
            $client = new GoogleClient([
                'credentials'                         => $credentials,
                'scopes'                              => [Gmail::GMAIL_SEND],
                'use_application_default_credentials' => false,
                'universe_domain'                     => 'googleapis.com',
            ]);
            if (null === $credentials) {
                $client->setAccessToken(['access_token' => $user]);
            }
            if (null !== $this->gmailHttpClient) {
                $client->setHttpClient($this->gmailHttpClient);
            }

            return new Swift_Transport_Api_GoogleTransport($client, new Swift_Events_SimpleEventDispatcher());
        } catch (Throwable) {
            throw new InvalidArgumentException('Unable to construct the Gmail SDK client from explicit DSN authentication.');
        }
    }

    private function gmailCredential(#[SensitiveParameter] ?string $value): string
    {
        if (null === $value || '' === $value || \preg_match('/[^\x21-\x7e]/', $value)) {
            throw new InvalidArgumentException('Gmail authentication fields must be nonempty visible ASCII strings.');
        }

        return $value;
    }

    /** @param array<string, string> $options */
    private function createSesTransport(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, string $class, #[SensitiveParameter] array $options): Swift_Transport
    {
        $key    = $this->sesCredential($dsn->getUser());
        $secret = $this->sesCredential($dsn->getPassword());
        $token  = $options['session_token'] ?? null;
        $region = $this->sesRegion($options['region'] ?? 'us-east-1');
        if ('default' === $dsn->getHost()) {
            $this->requireFixedHttpEndpoint($dsnString, $dsn);
            $domain = match (true) {
                \str_starts_with($region, 'cn-')   => 'amazonaws.com.cn',
                \str_starts_with($region, 'eusc-') => 'amazonaws.eu',
                default                            => 'amazonaws.com',
            };
            $endpoint = 'https://email.'.$region.'.'.$domain;
        } else {
            $endpoint = $this->httpEndpoint($dsnString, $dsn);
        }
        // Explicit configuration blocks ambient region/endpoint/session-token overrides.
        // A concrete Credentials provider also excludes role, profile and metadata exchanges.
        $client = new SesClient([
            'region'          => $region,
            'endpoint'        => $endpoint,
            'accessKeyId'     => $key,
            'accessKeySecret' => $secret,
            'sessionToken'    => $token,
            'debug'           => 'false',
        ], new Credentials($key, $secret, $token), $this->sesHttpClient);

        return new $class($client, new Swift_Events_SimpleEventDispatcher());
    }

    private function sesCredential(#[SensitiveParameter] ?string $value): string
    {
        $value = $this->requiredHttpCredential($value);
        if (\preg_match('/[^\x21-\x7e]/', $value)) {
            throw new InvalidArgumentException('SES credentials and session_token must contain only visible ASCII characters.');
        }

        return $value;
    }

    private function sesRegion(#[SensitiveParameter] string $value): string
    {
        if (\strlen($value) > 63 || !\preg_match('/\A(?:[a-z]{2}|eusc)(?:-[a-z]+){1,3}-[1-9][0-9]*\z/D', $value)) {
            throw new InvalidArgumentException('SES region must use an AWS region-code spelling.');
        }

        return $value;
    }

    private function requiredHttpCredential(#[SensitiveParameter] ?string $value): string
    {
        if (null === $value || '' === $value) {
            throw new InvalidArgumentException('HTTP provider DSN is missing a required credential or identifier.');
        }

        return $value;
    }

    private function mailtrapInboxId(#[SensitiveParameter] string $value): string
    {
        if (!\preg_match('/\A[0-9]+\z/D', $value) || '' === ($value = \ltrim($value, '0'))) {
            throw new InvalidArgumentException('Mailtrap inbox_id must be a positive decimal identifier.');
        }

        return $value;
    }

    private function requireFixedHttpEndpoint(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, bool $sandbox = false): void
    {
        if ((!$sandbox && 'default' !== $dsn->getHost()) || null !== $dsn->getPort() || !\in_array($dsn->getPath(), [null, '', '/'], true) || null !== \parse_url($dsnString, \PHP_URL_FRAGMENT)) {
            throw new InvalidArgumentException('This HTTP provider DSN requires its fixed endpoint without a port, base path or fragment.');
        }
    }

    private function httpEndpoint(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, ?string $defaultHost = null): string
    {
        $host        = 'default' === $dsn->getHost() ? $defaultHost : $dsn->getHost();
        $path        = $dsn->getPath() ?? '';
        $decodedPath = \rawurldecode($path);
        if (null === $host || '' === $host || null !== \parse_url($dsnString, \PHP_URL_FRAGMENT) || \preg_match('/[\x00-\x20\x7f?#\\\\]/', $decodedPath) || \preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decodedPath)) {
            throw new InvalidArgumentException('HTTP provider DSN requires a valid public HTTPS endpoint and base path without a fragment.');
        }
        $normalizedHost = \trim($host, '[]');
        if ((!\filter_var($normalizedHost, \FILTER_VALIDATE_IP) && !\filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)) || (null !== $dsn->getPort() && $dsn->getPort() < 1)) {
            throw new InvalidArgumentException('HTTP provider endpoint host or port is invalid.');
        }
        $endpoint = 'https://'.$host.(null !== $dsn->getPort() ? ':'.$dsn->getPort() : '').\rtrim($path, '/');
        try {
            Swift_Transport_UrlValidator::validate($endpoint);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('HTTP provider endpoint must be a public HTTPS address.');
        }

        return $endpoint;
    }

    /** @return array<string, bool> */
    private function parseSendgridTrackingOptions(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn): array
    {
        $parameters = $this->canonicalOptions($dsnString, $dsn->getParameters(), self::SENDGRID_TRACKING_PARAMETERS, 'SendGrid tracking');
        if (!$parameters) {
            return [];
        }
        if ('sendgrid' !== $dsn->getScheme()) {
            throw new InvalidArgumentException('SendGrid tracking options require the sendgrid HTTP API scheme.');
        }
        $options = [];
        foreach ($parameters as $name => $value) {
            if (!\in_array(\strtolower($value), ['true', 'false', '1', '0'], true)) {
                throw new InvalidArgumentException('Each SendGrid tracking option must be true, false, 1 or 0.');
            }
            $options[$name] = \in_array(\strtolower($value), ['true', '1'], true);
        }

        return $options;
    }

    /** @return array{timeout?: int, auth_mode?: string, local_domain?: string} */
    private function parseSmtpOptions(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] Swift_Dsn $dsn, string $class): array
    {
        $parameters = $this->canonicalOptions($dsnString, $dsn->getParameters(), self::SMTP_CONNECTION_PARAMETERS, 'SMTP connection');
        if (!$parameters) {
            return [];
        }
        if (Swift_Transport_EsmtpTransport::class !== $class) {
            throw new InvalidArgumentException('SMTP connection options require an SMTP scheme.');
        }
        $options = [];
        if (isset($parameters['timeout'])) {
            $value = $parameters['timeout'];
            if (!\preg_match('/\A[0-9]+\z/D', $value)) {
                throw new InvalidArgumentException('SMTP timeout must be a positive whole number of seconds.');
            }
            $value   = \ltrim($value, '0');
            $maximum = (string) \PHP_INT_MAX;
            if ('' === $value || \strlen($value) > \strlen($maximum) || (\strlen($value) === \strlen($maximum) && \strcmp($value, $maximum) > 0)) {
                throw new InvalidArgumentException('SMTP timeout must be positive and within the supported integer range.');
            }
            $options['timeout'] = (int) $value;
        }
        if (isset($parameters['auth_mode'])) {
            if (!\preg_match('/\A[A-Za-z0-9_-]+\z/D', $parameters['auth_mode'])) {
                throw new InvalidArgumentException('SMTP auth_mode must name a configured authenticator.');
            }
            $options['auth_mode'] = \strtoupper($parameters['auth_mode']);
        }
        if (isset($parameters['local_domain'])) {
            $options['local_domain'] = $this->smtpLocalDomain($parameters['local_domain']);
        }

        return $options;
    }

    private function smtpLocalDomain(#[SensitiveParameter] string $value): string
    {
        if (\preg_match('/\A\[IPv6:(.+)\]\z/iD', $value, $matches)) {
            if (false !== \filter_var($matches[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return $matches[1];
            }
        } elseif (\preg_match('/\A\[(.+)\]\z/D', $value, $matches)) {
            if (false !== \filter_var($matches[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                return $matches[1];
            }
        } elseif (false !== \filter_var($value, \FILTER_VALIDATE_IP) || false !== \filter_var($value, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)) {
            return $value;
        }

        throw new InvalidArgumentException('SMTP local_domain must be an ASCII hostname or a valid IPv4/IPv6 address.');
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function canonicalOptions(#[SensitiveParameter] string $dsnString, #[SensitiveParameter] array $parameters, array $names, string $label): array
    {
        $selected = \array_intersect_key($parameters, \array_flip($names));
        if (!$selected) {
            return [];
        }
        $query = \parse_url($dsnString, \PHP_URL_QUERY);
        if (!\is_string($query)) {
            throw new InvalidArgumentException($label.' query is invalid.');
        }
        $occurrences      = [];
        $separatorPattern = '/['.\preg_quote(\ini_get('arg_separator.input') ?: '&', '/').']/';
        foreach (\preg_split($separatorPattern, $query) as $pair) {
            $name = \urldecode(\explode('=', $pair, 2)[0]);
            $base = \explode('[', \explode("\0", $name, 2)[0], 2)[0];
            $base = \str_replace([' ', '.'], '_', \ltrim($base));
            if (!\in_array($base, $names, true)) {
                continue;
            }
            if ($name !== $base) {
                throw new InvalidArgumentException($label.' option names must use their canonical scalar spelling.');
            }
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
        }
        $validated = [];
        foreach ($selected as $name => $value) {
            if (1 !== ($occurrences[$name] ?? 0) || !\is_string($value)) {
                throw new InvalidArgumentException($label.' options must be scalar values and occur exactly once.');
            }
            $validated[$name] = $value;
        }

        return $validated;
    }

    /** @param array{timeout?: int, auth_mode?: string, local_domain?: string} $options */
    private function createSmtpTransport(#[SensitiveParameter] Swift_Dsn $dsn, #[SensitiveParameter] array $options = []): Swift_Transport
    {
        $providerDefaults = self::SMTP_HOST_MAP[$dsn->getScheme()] ?? null;

        $host = $dsn->getHost() && 'default' !== $dsn->getHost()
            ? $dsn->getHost()
            : ($providerDefaults['host'] ?? 'localhost');
        $port = $dsn->getPort()
            ?: ($providerDefaults['port'] ?? ('smtp+ssl' === $dsn->getScheme() ? 465 : 587));
        $encryption = match (true) {
            'smtp+ssl' === $dsn->getScheme() => 'ssl',
            'smtp+tls' === $dsn->getScheme() => 'tls',
            null !== $providerDefaults       => $providerDefaults['encryption'],
            default                          => null,
        };

        // Use Swift_SmtpTransport convenience class
        $transport = new Swift_SmtpTransport($host, $port, $encryption);

        if (null !== ($user = $dsn->getUser())) {
            $transport->setUsername($user);
        }
        if (null !== ($password = $dsn->getPassword())) {
            $transport->setPassword($password);
        }

        if (isset($options['timeout'])) {
            $transport->setTimeout($options['timeout']);
        }
        if (isset($options['local_domain'])) {
            $transport->setLocalDomain($options['local_domain']);
        }
        if (isset($options['auth_mode'])) {
            $knownMode = false;
            foreach ($transport->getExtensionHandlers() as $handler) {
                if ($handler instanceof Swift_Transport_Esmtp_AuthHandler) {
                    foreach ($handler->getAuthenticators() as $authenticator) {
                        if (0 === \strcasecmp($options['auth_mode'], $authenticator->getAuthKeyword())) {
                            $knownMode = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$knownMode) {
                throw new InvalidArgumentException('SMTP auth_mode must name a configured authenticator.');
            }
            $transport->setAuthMode($options['auth_mode']);
        }

        // TLS DSN parameters
        $params        = $dsn->getParameters();
        $streamOptions = [];

        if (isset($params['verify_peer'])) {
            $val = \filter_var($params['verify_peer'], FILTER_VALIDATE_BOOLEAN);
            if (!$val) {
                @\trigger_error('Swiftmailer: verify_peer=false disables TLS certificate verification. This is insecure and should only be used for local development.', \E_USER_WARNING);
            }
            $streamOptions['ssl']['verify_peer']      = $val;
            $streamOptions['ssl']['verify_peer_name'] = $val;
        }
        if (isset($params['peer_fingerprint'])) {
            $streamOptions['ssl']['peer_fingerprint'] = $params['peer_fingerprint'];
        }
        if (isset($params['source_ip'])) {
            $transport->setSourceIp($params['source_ip']);
        }

        if (!empty($streamOptions)) {
            $transport->setStreamOptions($streamOptions);
        }

        if (isset($params['smtputf8']) && !\filter_var($params['smtputf8'], FILTER_VALIDATE_BOOLEAN)) {
            $transport->setAddressEncoder(new Swift_AddressEncoder_IdnAddressEncoder());
        }

        return $transport;
    }

    private function validateDsnParameters(array $params): void
    {
        $unknown = \array_diff(\array_keys($params), self::ALLOWED_DSN_PARAMETERS);
        foreach ($unknown as $param) {
            @\trigger_error(\sprintf('Swiftmailer: unknown DSN parameter "%s" will be ignored. Allowed parameters: %s.', $param, \implode(', ', self::ALLOWED_DSN_PARAMETERS)), \E_USER_WARNING);
        }
    }
}
