<?php
/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use Nyholm\Dsn\DsnParser;
use Nyholm\Dsn\Exception\InvalidDsnException;

class Swift_Dsn_Configuration
{
    /**
     * @var array
     */
    private array $dsnConfigurations;
    private string $function_name;

    /**
     * Swift_Dsn_Configuration constructor.
     * @param string $dsnString
     */
    public function __construct(string $dsnString)
    {
        try {
            $dsn = DSNParser::parseFunc($dsnString);
        } catch (InvalidDsnException $e) {
            throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
        }

        //if $dsn->getName() is "dsn" we dont have a function (failover, roundrobin, etc.)
        if ($dsn->getName() === 'dsn') {
            //this is just one configuration, one DSN
            $this->dsnConfigurations[] = new Swift_Dsn($dsn->first());
        } else {
            //this is a function configuration, multiple DSNs
            foreach ($dsn->getArguments() as $argument) {
                $this->dsnConfigurations[] = new Swift_Dsn($argument);
            }
        }

        $this->function_name = $dsn->getName();

    }

    /**
     * Get a specific DSN by index
     *
     * @param int $index
     * @return Swift_Dsn|null
     */
    public function getDsn(int $index): ?Swift_Dsn
    {
        return $this->dsnConfigurations[$index] ?? throw new OutOfRangeException("Index {$index} does not exist");
    }

    /**
     * Get all DSNs
     *
     * @return array
     */
    public function getAllDsn(): array
    {
        return $this->dsnConfigurations;
    }

    /**
     * Count the configured DSNs
     *
     * @return int
     */
    public function countDsn(): int
    {
        return count($this->dsnConfigurations);
    }

    public function getFunction(): string
    {
        return $this->function_name;
    }

    public static function parse(string $dsnString): Swift_Dsn_Configuration
    {
        return new self($dsnString);
    }

    public function getFirst(): Swift_Dsn
    {
        return $this->dsnConfigurations[0];
    }

}