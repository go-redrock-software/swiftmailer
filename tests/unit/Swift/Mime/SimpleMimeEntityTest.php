<?php

class Swift_Mime_SimpleMimeEntityTest extends Swift_Mime_AbstractMimeEntityTest
{
    protected function createEntity($headerFactory, $encoder, $cache)
    {
        $idGenerator = new Swift_Mime_IdGenerator('example.com');

        return new Swift_Mime_SimpleMimeEntity($headerFactory, $encoder, $cache, $idGenerator);
    }

    public function testToStringMagicMethodReturnsString()
    {
        $headers = $this->createHeaderSet([], false);
        $headers->shouldReceive('toString')
            ->zeroOrMoreTimes()
            ->andReturn("Content-Type: text/plain\r\n");

        $entity = $this->createEntity(
            $headers,
            $this->createEncoder(),
            $this->createCache(),
        );
        $result = (string) $entity;
        $this->assertIsString($result);
        $this->assertStringContainsString('Content-Type', $result);
    }

    public function testSetBoundaryWithInvalidCharsThrowsException()
    {
        $entity = $this->createEntity(
            $this->createHeaderSet(),
            $this->createEncoder(),
            $this->createCache(),
        );

        $this->expectException(Swift_RfcComplianceException::class);
        $entity->setBoundary('invalid boundary with @#$!');
    }
}
