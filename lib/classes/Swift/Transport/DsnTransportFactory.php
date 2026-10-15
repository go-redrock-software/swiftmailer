<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Nyholm\Dsn\Configuration\Url;
use Nyholm\Dsn\DsnParser;

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
        $parsed = DsnParser::parseUrl($dsnString);
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
        $nyholmDsn = $this->parseDsnUrl($dsnString);
        $dsn       = new Swift_Dsn($nyholmDsn);
        $this->validateDsnParameters($dsn->getParameters());
        $trackingOptions = $this->parseSendgridTrackingOptions($dsnString, $dsn);

        $class       = $dsn->getTransportClass();
        $smtpOptions = $this->parseSmtpOptions($dsnString, $dsn, $class);

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

        // Mailtrap sandbox mode
        if ('mailtrap+sandbox' === $dsn->getScheme()) {
            $apiKey     = $dsn->getUser() ?: $dsn->getPassword() ?: '';
            $inboxId    = $dsn->getParameter('inbox_id') ?: $dsn->getHost();
            $dispatcher = new Swift_Events_SimpleEventDispatcher();

            return new Swift_Transport_Api_MailtrapTransport($apiKey, true, $inboxId, null, $dispatcher);
        }

        // HTTP API transports: all extend AbstractHttpApiTransport(apiKey, ?httpClient, ?eventDispatcher)
        $apiKey     = $dsn->getUser() ?: $dsn->getPassword() ?: '';
        $dispatcher = new Swift_Events_SimpleEventDispatcher();

        $transport = new $class($apiKey, null, $dispatcher);
        if ($transport instanceof Swift_Transport_Api_SendgridTransport) {
            $transport->setClickTracking($trackingOptions['click_tracking'] ?? null)
                ->setClickTrackingText($trackingOptions['click_tracking_text'] ?? null)
                ->setOpenTracking($trackingOptions['open_tracking'] ?? null);
        }

        return $transport;
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
