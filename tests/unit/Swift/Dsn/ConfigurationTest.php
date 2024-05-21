<?php
/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 *
 */

use PHPUnit\Framework\TestCase;

class Swift_Dsn_ConfigurationTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testConstruct(): void
    {
        $dsnConf = new \Swift_Dsn_Configuration('dsn://user:pwd@host:123/path');

        $this->assertInstanceOf(Swift_Dsn_Configuration::class, $dsnConf);
        $this->assertCount(1, $dsnConf->getAllDsn());
        $this->assertEquals('dsn', $dsnConf->getFunction());
    }

    public function testParse(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('failover(dsn://user1:pwd1@host1:123/path1,dsn://user2:pwd2@host2:123/path2)');

        $this->assertCount(2, $dsnConf->getAllDsn());
        $this->assertEquals('failover', $dsnConf->getFunction());
    }

    public function testGetAllDsn(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertContainsOnlyInstancesOf(Swift_Dsn::class, $dsnConf->getAllDsn());
    }

    public function testGetDsn(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');
        $dsn = $dsnConf->getDsn(0);

        $this->assertInstanceOf(Swift_Dsn::class, $dsn);
    }

    public function testCountDsn(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertEquals(1, $dsnConf->countDsn());
    }

    public function testGetFunction(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertEquals('dsn', $dsnConf->getFunction());
    }

    public function testGetFirst(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertInstanceOf(Swift_Dsn::class, $dsnConf->getFirst());
        $this->assertInstanceOf(Swift_Dsn::class, $dsnConf->getDsn(0));
    }

    public function testConstructWithInvalidArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Swift_Dsn_Configuration('thisisbad');
    }

    public function testConstructWithInvalidArgumentType(): void
    {
        $this->expectException(\TypeError::class);

        new \Swift_Dsn_Configuration(null);
    }

    public function testConstructWithEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Swift_Dsn_Configuration('');
    }

    public function testParseWithInvalidArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Swift_Dsn_Configuration::parse('thisisbad');
    }

    public function testParseWithInvalidArgumentType(): void
    {
        $this->expectException(\TypeError::class);

        Swift_Dsn_Configuration::parse(null);
    }

    public function testParseWithEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Swift_Dsn_Configuration::parse('');
    }

    public function testGetDsnWithInvalidIndex(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->expectException(\OutOfRangeException::class);

        $dsnConf->getDsn(100);
    }
}
