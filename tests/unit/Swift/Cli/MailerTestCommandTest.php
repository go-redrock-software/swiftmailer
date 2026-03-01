<?php

/**
 * Integration test that exercises the CLI flow without actually invoking a process.
 * Uses the null:// transport so no real mail server is required.
 */
class Swift_Cli_MailerTestCommandTest extends PHPUnit\Framework\TestCase
{
    public function testFullFlowWithNullTransport()
    {
        // Parse
        $parser = new Swift_Cli_ArgumentParser();
        $args   = $parser->parse([
            'bin/swiftmailer-test',
            'null://null',
            '--to=test@example.com',
            '--from=sender@example.com',
            '--subject=Integration test',
            '--body=Hello from test',
        ]);

        // Create transport
        $factory   = new Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString($args->dsn);

        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);

        // Build message
        $message = (new Swift_Message($args->subject))
            ->setFrom($args->from)
            ->setTo($args->to)
            ->setBody($args->body, 'text/plain');

        // Send
        $mailer = new Swift_Mailer($transport);
        $failed = [];
        $sent   = $mailer->send($message, $failed);

        $this->assertSame(1, $sent);
        $this->assertEmpty($failed);
    }

    public function testBinScriptExitsZeroWithNullTransport()
    {
        $binPath = \realpath(__DIR__.'/../../../../bin/swiftmailer-test');
        if (!$binPath) {
            $this->markTestSkipped('bin/swiftmailer-test not found.');
        }

        $cmd    = \sprintf('php %s "null://null" --to=test@example.com 2>&1', \escapeshellarg($binPath));
        $output = [];
        $exit   = null;
        \exec($cmd, $output, $exit);

        $this->assertSame(0, $exit, 'Expected exit code 0. Output: '.\implode("\n", $output));
    }

    public function testBinScriptExitsOneOnMissingArgs()
    {
        $binPath = \realpath(__DIR__.'/../../../../bin/swiftmailer-test');
        if (!$binPath) {
            $this->markTestSkipped('bin/swiftmailer-test not found.');
        }

        $cmd    = \sprintf('php %s 2>&1', \escapeshellarg($binPath));
        $output = [];
        $exit   = null;
        \exec($cmd, $output, $exit);

        $this->assertSame(1, $exit);
    }

    public function testHelpFlagExitsZero()
    {
        $binPath = \realpath(__DIR__.'/../../../../bin/swiftmailer-test');
        if (!$binPath) {
            $this->markTestSkipped('bin/swiftmailer-test not found.');
        }

        $cmd    = \sprintf('php %s --help 2>&1', \escapeshellarg($binPath));
        $output = [];
        $exit   = null;
        \exec($cmd, $output, $exit);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Usage:', \implode("\n", $output));
    }
}
