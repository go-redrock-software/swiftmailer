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
 * Object representation of a DSN (mail connection)
 *
 */
class Swift_Dsn
{
    /**
     * @var string|null
     */
    private ?string $scheme;

    /**
     * @var string|null
     */
    private ?string $user;

    /**
     * @var string|null
     */
    private ?string $password;

    /**
     * @var string|null
     */
    private ?string $host;

    /**
     * @var int|null
     */
    private ?int $port;
    private array $parameters;

    /**
     * Swift_Dsn constructor.
     *
     * @param Url $dsn
     */
    public function __construct(Dsn $dsn)
    {
        //turn the Nyholm DSN object into ours (wrap)
        $this->scheme = $dsn->getScheme();
        $this->user = $dsn->getUser();
        $this->password = $dsn->getPassword();
        $this->host = $dsn->getHost();
        $this->port = $dsn->getPort();
        $this->parameters = $dsn->getParameters();
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
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


}
