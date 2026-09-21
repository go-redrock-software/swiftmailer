<?php

class Swift_Transport_StreamBufferTest extends PHPUnit\Framework\TestCase
{
    public function testSettingWriteTranslationsCreatesFilters()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createFilter')
            ->with('a', 'b')
            ->willReturnCallback([$this, 'createFilter']);

        $buffer = $this->createBuffer($factory);
        $buffer->setWriteTranslations(['a' => 'b']);
    }

    public function testOverridingTranslationsOnlyAddsNeededFilters()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createFilter')
            ->willReturnCallback([$this, 'createFilter']);

        $buffer = $this->createBuffer($factory);
        $buffer->setWriteTranslations(['a' => 'b']);
        $buffer->setWriteTranslations(['x' => 'y', 'a' => 'b']);
    }

    public function testStartTlsSurfacesHandshakeFailureAsTransportException()
    {
        // Reproduces TRACCLOUD-1C2Z: a relay that will not complete the TLS handshake
        // must fail as a catchable Swift_TransportException. Previously the raw
        // stream_socket_enable_crypto() warning escaped and -- under a host error handler
        // that promotes warnings -- became an unhandled ErrorException that bypassed the
        // caller's transport-error path and stranded queued mail.
        $buffer = $this->createBuffer($this->createFactory());

        // A connected, plain (non-TLS) socket pair: enabling client crypto cannot succeed.
        [$local, $remote] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        \stream_set_blocking($local, false);

        $stream = new ReflectionProperty(Swift_Transport_StreamBuffer::class, 'stream');
        $stream->setValue($buffer, $local);

        // Simulate a host handler (e.g. TracCloud's) that turns warnings into exceptions.
        \set_error_handler(static function ($type, $message) {
            throw new ErrorException($message, 0, $type);
        });

        try {
            $this->expectException(Swift_TransportException::class);
            $buffer->startTLS();
        } finally {
            \restore_error_handler();
            \fclose($local);
            \fclose($remote);
        }
    }

    private function createBuffer($replacementFactory)
    {
        return new Swift_Transport_StreamBuffer($replacementFactory);
    }

    private function createFactory()
    {
        return $this->getMockBuilder('Swift_ReplacementFilterFactory')->getMock();
    }

    public function createFilter()
    {
        return $this->getMockBuilder('Swift_StreamFilter')->getMock();
    }
}
