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
     * @covers \Swift_Dsn_Configuration::__construct
     * @covers \Swift_Dsn_Configuration::getAllDsn
     * @covers \Swift_Dsn_Configuration::getFunction
     */
    public function testConstruct(): void
    {
        $dsnConf = new Swift_Dsn_Configuration('dsn://user:pwd@host:123/path');

        $this->assertInstanceOf(Swift_Dsn_Configuration::class, $dsnConf);
        $this->assertCount(1, $dsnConf->getAllDsn());
        $this->assertEquals('dsn', $dsnConf->getFunction());
    }

    /**
     * @covers \Swift_Dsn_Configuration::parse
     * @covers \Swift_Dsn_Configuration::getAllDsn
     * @covers \Swift_Dsn_Configuration::getFunction
     */
    public function testParse(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse(
            'failover(dsn://user1:pwd1@host1:123/path1,dsn://user2:pwd2@host2:123/path2)',
        );

        $this->assertCount(2, $dsnConf->getAllDsn());
        $this->assertEquals('failover', $dsnConf->getFunction());
    }

    /**
     * @covers \Swift_Dsn_Configuration::getAllDsn
     */
    public function testGetAllDsn(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertContainsOnlyInstancesOf(Swift_Dsn::class, $dsnConf->getAllDsn());
    }

    /**
     * @covers \Swift_Dsn_Configuration::getDsn
     */
    public function testGetDsn(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');
        $dsn     = $dsnConf->getDsn(0);

        $this->assertInstanceOf(Swift_Dsn::class, $dsn);
    }

    /**
     * @covers \Swift_Dsn_Configuration::countDsn
     */
    public function testCountDsn(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertEquals(1, $dsnConf->countDsn());
    }

    /**
     * @covers \Swift_Dsn_Configuration::getFunction
     */
    public function testGetFunction(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertEquals('dsn', $dsnConf->getFunction());
    }

    /**
     * @covers \Swift_Dsn_Configuration::getFirst
     * @covers \Swift_Dsn_Configuration::getDsn
     */
    public function testGetFirst(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->assertInstanceOf(Swift_Dsn::class, $dsnConf->getFirst());
        $this->assertInstanceOf(Swift_Dsn::class, $dsnConf->getDsn(0));
    }

    /**
     * @covers \Swift_Dsn_Configuration::__construct
     */
    public function testConstructWithInvalidArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Swift_Dsn_Configuration('thisisbad');
    }

    /**
     * @covers \Swift_Dsn_Configuration::__construct
     */
    public function testConstructWithInvalidArgumentType(): void
    {
        $this->expectException(TypeError::class);

        new Swift_Dsn_Configuration(null);
    }

    /**
     * @covers \Swift_Dsn_Configuration::__construct
     */
    public function testConstructWithEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Swift_Dsn_Configuration('');
    }

    /**
     * @covers \Swift_Dsn_Configuration::parse
     */
    public function testParseWithInvalidArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Swift_Dsn_Configuration::parse('thisisbad');
    }

    /**
     * @covers \Swift_Dsn_Configuration::parse
     */
    public function testParseWithInvalidArgumentType(): void
    {
        $this->expectException(TypeError::class);

        Swift_Dsn_Configuration::parse(null);
    }

    /**
     * @covers \Swift_Dsn_Configuration::parse
     */
    public function testParseWithEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Swift_Dsn_Configuration::parse('');
    }

    /**
     * @covers \Swift_Dsn_Configuration
     */
    public function testFunctionDsnWithMultipleArguments(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('failover(smtp://host1:25 smtp://host2:25)');

        $this->assertEquals('failover', $dsnConf->getFunction());
        $this->assertEquals(2, $dsnConf->countDsn());
    }

    /**
     * @covers \Swift_Dsn_Configuration::getDsn
     */
    public function testGetDsnWithInvalidIndex(): void
    {
        $dsnConf = Swift_Dsn_Configuration::parse('dsn://user:pwd@host:123/path');

        $this->expectException(OutOfRangeException::class);

        $dsnConf->getDsn(100);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
