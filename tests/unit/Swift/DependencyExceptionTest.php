<?php
use PHPUnit\Framework\TestCase;

final class Swift_DependencyExceptionTest extends TestCase
{
    public function testConstruct()
    {
        $message = 'Test message';
        $exception = new Swift_DependencyException($message);
        $this->assertInstanceOf(Swift_DependencyException::class, $exception);
        $this->assertEquals($message, $exception->getMessage());
    }
}