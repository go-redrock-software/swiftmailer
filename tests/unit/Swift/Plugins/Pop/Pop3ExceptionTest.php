<?php

use PHPUnit\Framework\TestCase;

class Swift_Plugins_Pop_Pop3ExceptionTest extends TestCase
{
    public function testConstructorSetsMessage(): void
    {
        $exception = new Swift_Plugins_Pop_Pop3Exception('Connection failed');

        $this->assertSame('Connection failed', $exception->getMessage());
        $this->assertInstanceOf(Swift_IoException::class, $exception);
    }
}
