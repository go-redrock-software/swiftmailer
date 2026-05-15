<?php

use PHPUnit\Framework\TestCase;

class Swift_SpoolTransportTest extends TestCase
{
    public function testConstructorSetsSpool(): void
    {
        $spool = new Swift_MemorySpool();
        $transport = new Swift_SpoolTransport($spool);

        $this->assertInstanceOf(Swift_SpoolTransport::class, $transport);
        $this->assertSame($spool, $transport->getSpool());
    }

    public function testIsAlwaysStarted(): void
    {
        $spool = new Swift_MemorySpool();
        $transport = new Swift_SpoolTransport($spool);

        $this->assertTrue($transport->isStarted());
    }
}
