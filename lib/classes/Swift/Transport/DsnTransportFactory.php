<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Nyholm\Dsn\DsnParser;

/**
 * Factory that creates Swift_Transport instances from DSN strings.
 *
 * Supports meta-transport wrappers:
 *   failover(dsn1 dsn2)   -> Swift_Transport_FailoverTransport
 *   roundrobin(dsn1 dsn2)  -> Swift_Transport_LoadBalancedTransport
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
    ];

    public function fromDsnString(string $dsnString): Swift_Transport
    {
        // Check for meta-transport wrappers
        if (\preg_match('/^(failover|roundrobin|retry)\((.+)\)$/', $dsnString, $matches)) {
            $wrapper   = $matches[1];
            $innerPart = \trim($matches[2]);

            if ('retry' === $wrapper) {
                $innerTransport = $this->fromDsnString($innerPart);

                return new Swift_Transport_RetryTransport($innerTransport);
            }

            $innerDsns = \preg_split('/\s+/', $innerPart);

            $transports = [];
            foreach ($innerDsns as $innerDsn) {
                $transports[] = $this->fromDsnString($innerDsn);
            }

            if ('failover' === $wrapper) {
                $transport = new Swift_Transport_FailoverTransport();
            } else {
                $transport = new Swift_Transport_LoadBalancedTransport();
            }
            $transport->setTransports($transports);

            return $transport;
        }

        return $this->createTransport($dsnString);
    }

    private function createTransport(string $dsnString): Swift_Transport
    {
        $dsn    = new Swift_Dsn(DsnParser::parseUrl($dsnString));
        $params = $dsn->getParameters();

        $this->validateDsnParameters($params);

        // Extract and validate retry parameters before creating transport
        $retries    = isset($params['retries']) ? (int) $params['retries'] : null;
        $retryDelay = isset($params['retry_delay']) ? (int) $params['retry_delay'] : 1000;

        if (null !== $retries && ($retries < 0 || $retries > 10)) {
            throw new \InvalidArgumentException(\sprintf('DSN parameter "retries" must be between 0 and 10, got %d.', $retries));
        }
        if ($retryDelay < 0 || $retryDelay > 30000) {
            throw new \InvalidArgumentException(\sprintf('DSN parameter "retry_delay" must be between 0 and 30000, got %d.', $retryDelay));
        }

        $class = $dsn->getTransportClass();

        // NullTransport needs an event dispatcher
        if (Swift_Transport_NullTransport::class === $class) {
            $transport = new Swift_Transport_NullTransport(
                new Swift_Events_SimpleEventDispatcher(),
            );
        } elseif (Swift_Transport_SendmailTransport::class === $class) {
            $command   = $dsn->getParameter('command') ?: '/usr/sbin/sendmail -bs';
            $transport = new Swift_SendmailTransport($command);
        } elseif (Swift_Transport_EsmtpTransport::class === $class) {
            // SMTP transports
            $transport = $this->createSmtpTransport($dsn);
        } else {
            // HTTP API transports: all extend AbstractHttpApiTransport(apiKey, ?httpClient, ?eventDispatcher)
            $apiKey     = $dsn->getUser() ?: $dsn->getPassword() ?: '';
            $dispatcher = new Swift_Events_SimpleEventDispatcher();
            $transport  = new $class($apiKey, null, $dispatcher);
        }

        // Wrap with retry if query params specify it
        if (null !== $retries && $retries > 0) {
            $transport = new Swift_Transport_RetryTransport($transport, $retries, $retryDelay);
        }

        return $transport;
    }

    private function createSmtpTransport(Swift_Dsn $dsn): Swift_Transport
    {
        $host       = $dsn->getHost() ?: 'localhost';
        $port       = $dsn->getPort() ?: ('smtp+ssl' === $dsn->getScheme() ? 465 : 587);
        $encryption = match ($dsn->getScheme()) {
            'smtp+ssl' => 'ssl',
            'smtp+tls' => 'tls',
            default    => null,
        };

        // Use Swift_SmtpTransport convenience class
        $transport = new Swift_SmtpTransport($host, $port, $encryption);

        if ($user = $dsn->getUser()) {
            $transport->setUsername($user);
        }
        if ($password = $dsn->getPassword()) {
            $transport->setPassword($password);
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
            // Disable SMTPUTF8: use plain IdnAddressEncoder instead of AutoAddressEncoder
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
