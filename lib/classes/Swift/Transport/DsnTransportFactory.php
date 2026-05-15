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

    public function fromDsnString(string $dsnString): Swift_Transport
    {
        // Check for meta-transport wrappers
        if (\preg_match('/^(failover|roundrobin)\((.+)\)$/', $dsnString, $matches)) {
            $wrapper   = $matches[1];
            $innerDsns = \preg_split('/\s+/', \trim($matches[2]));

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
        $this->validateDsnParameters($dsn->getParameters());

        $this->validateDsnParameters($dsn->getParameters());

        $class = $dsn->getTransportClass();

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

            return new Swift_SendmailTransport($command);
        }

        // SMTP transports
        if (Swift_Transport_EsmtpTransport::class === $class) {
            return $this->createSmtpTransport($dsn);
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

        return new $class($apiKey, null, $dispatcher);
    }

    private function createSmtpTransport(Swift_Dsn $dsn): Swift_Transport
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
