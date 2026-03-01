<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use Nyholm\Dsn\Configuration\Dsn;
use Nyholm\Dsn\Configuration\Url;

/**
 * Object representation of a DSN (mail connection).
 */
readonly class Swift_Dsn
{
    private const array TRANSPORT_CLASS_MAP = [
        'null'            => Swift_Transport_NullTransport::class,
        'smtp'            => Swift_Transport_EsmtpTransport::class,
        'smtp+tls'        => Swift_Transport_EsmtpTransport::class,
        'smtp+ssl'        => Swift_Transport_EsmtpTransport::class,
        'microsoft-graph' => Swift_Transport_Api_MicrosoftGraphTransport::class,
        'gmail+smtp'      => Swift_Transport_EsmtpTransport::class,
        'gmail+api'       => Swift_Transport_Api_GoogleTransport::class,
        'amazon+api'      => Swift_Transport_Api_AmazonSesApiTransport::class,
        'amazon+http'     => Swift_Transport_Api_AmazonSesHttpTransport::class,
        'azure'           => Swift_Transport_Api_AzureTransport::class,
        'brevo'           => Swift_Transport_Api_BrevoTransport::class,
        'infobip'         => Swift_Transport_Api_InfoBipTransport::class,
        'mailpace'        => Swift_Transport_Api_MailPaceTransport::class,
        'mailchimp'       => Swift_Transport_Api_MailChimpTransport::class,
        'mailersend'      => Swift_Transport_Api_MailerSendTransport::class,
        'mailgun'         => Swift_Transport_Api_MailGunTransport::class,
        'mailjet'         => Swift_Transport_Api_MailJetTransport::class,
        'postmark'        => Swift_Transport_Api_PostMarkTransport::class,
        'resend'          => Swift_Transport_Api_ResendTransport::class,
        'scaleway'        => Swift_Transport_Api_ScalewayTransport::class,
        'sendgrid'        => Swift_Transport_Api_SendgridTransport::class,
        'ahasend'         => Swift_Transport_Api_AhaSendTransport::class,
        'mailomat'        => Swift_Transport_Api_MailomatTransport::class,
        'mailtrap'        => Swift_Transport_Api_MailtrapTransport::class,
        'postal'          => Swift_Transport_Api_PostalTransport::class,
        'sweego'          => Swift_Transport_Api_SweegoTransport::class,
    ];

    private ?string $scheme;

    private ?string $user;

    private ?string $password;

    private ?string $host;

    private ?int $port;

    private array $parameters;

    /**
     * Swift_Dsn constructor.
     *
     * @param Url $dsn
     */
    public function __construct(Dsn $dsn)
    {
        // turn the Nyholm DSN object into ours (wrap)
        $this->scheme     = $dsn->getScheme();
        $this->user       = $dsn->getUser();
        $this->password   = $dsn->getPassword();
        $this->host       = $dsn->getHost();
        $this->port       = $dsn->getPort();
        $this->parameters = $dsn->getParameters();
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUser(): ?string
    {
        return $this->user;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getParameter(string $parameter): ?string
    {
        return $this->parameters[$parameter] ?? null;
    }

    public function getTransportClass(): string
    {
        if (!isset(self::TRANSPORT_CLASS_MAP[$this->scheme])) {
            throw new InvalidArgumentException(\sprintf('Unsupported DSN scheme "%s". Supported: %s', $this->scheme, \implode(', ', \array_keys(self::TRANSPORT_CLASS_MAP))));
        }

        return self::TRANSPORT_CLASS_MAP[$this->scheme];
    }
}
