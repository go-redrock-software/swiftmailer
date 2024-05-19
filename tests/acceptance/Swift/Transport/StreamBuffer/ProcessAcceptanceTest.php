<?php

require_once __DIR__.'/AbstractStreamBufferAcceptanceTest.php';

class Swift_Transport_StreamBuffer_ProcessAcceptanceTest extends Swift_Transport_StreamBuffer_AbstractStreamBufferAcceptanceTest
{
    protected function setUp(): void
    {
        if (!\defined('SWIFT_SENDMAIL_PATH')) {
            $this->markTestSkipped(
                'Cannot run test without a path to sendmail (define '.
                'SWIFT_SENDMAIL_PATH in tests/acceptance.conf.php if you wish to run this test)',
            );
        }

        parent::setUp();
    }

    public function testReadLine()
    {
        // Initialize buffer with the required command
        $this->initializeBuffer();

        // Simulate writing a command to the sendmail process
        $this->buffer->write("EHLO localhost\r\n");

        // Read response from the sendmail process
        $line = $this->buffer->readLine(0);

        // Assert the line is not false or empty
        $this->assertNotEmpty($line);

        // Assert the line starts with a valid SMTP response code (e.g., "220")
        $this->assertMatchesRegularExpression('/^\d{3}/', $line);
    }

    public function testWrite()
    {
        // Initialize buffer with the required command
        $this->initializeBuffer();

        // Simulate writing a command to the sendmail process
        $bytes = "EHLO localhost\r\n";
        $this->buffer->write($bytes);

        // Read back the response from the sendmail process
        $response = $this->buffer->readLine(0);

        // Assert the response is not false or empty
        $this->assertNotEmpty($response);

        // Assert the response starts with a valid SMTP response code (e.g., "220")
        $this->assertMatchesRegularExpression('/^\d{3}/', $response);
    }

    protected function initializeBuffer()
    {
        /*
         * This is suppressed because this would be set in a file (acceptance.conf.php)
         * @noinspection PhpUndefinedConstantInspection
         */
        $this->buffer->initialize([
            'type'    => Swift_Transport_IoBuffer::TYPE_PROCESS,
            'command' => SWIFT_SENDMAIL_PATH.' -bs',
        ]);
    }
}
