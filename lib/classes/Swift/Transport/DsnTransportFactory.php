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
        $nyholmDsn = DsnParser::parseUrl($dsnString);
        $dsn       = new Swift_Dsn($nyholmDsn);
        $params    = $dsn->getParameters();

        // Extract retry parameters before creating transport
        $retries    = isset($params['retries']) ? (int) $params['retries'] : null;
        $retryDelay = isset($params['retry_delay']) ? (int) $params['retry_delay'] : 1000;

        $class = $dsn->getTransportClass();

        // NullTransport needs an event dispatcher
        if (Swift_Transport_NullTransport::class === $class) {
            $transport = new Swift_Transport_NullTransport(
                new Swift_Events_SimpleEventDispatcher(),
            );
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
            $val                                      = \filter_var($params['verify_peer'], FILTER_VALIDATE_BOOLEAN);
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

        return $transport;
    }
}
