<?php

class Swift_Cli_ArgumentParserTest extends PHPUnit\Framework\TestCase
{
    public function testParsesRequiredDsnAndTo()
    {
        $parser = new Swift_Cli_ArgumentParser();
        $args   = $parser->parse([
            'bin/swiftmailer-test',
            'smtp://user:pass@smtp.example.com:587',
            '--to=test@example.com',
        ]);

        $this->assertSame('smtp://user:pass@smtp.example.com:587', $args->dsn);
        $this->assertSame('test@example.com', $args->to);
        $this->assertSame('swiftmailer-test@localhost', $args->from);
        $this->assertSame('SwiftMailer Test Email', $args->subject);
        $this->assertStringContainsString('test email', \strtolower($args->body));
    }

    public function testParsesAllOptionalFlags()
    {
        $parser = new Swift_Cli_ArgumentParser();
        $args   = $parser->parse([
            'bin/swiftmailer-test',
            'sendgrid://apikey',
            '--to=a@b.com',
            '--from=me@b.com',
            '--subject=Hello',
            '--body=Custom body',
        ]);

        $this->assertSame('sendgrid://apikey', $args->dsn);
        $this->assertSame('a@b.com', $args->to);
        $this->assertSame('me@b.com', $args->from);
        $this->assertSame('Hello', $args->subject);
        $this->assertSame('Custom body', $args->body);
    }

    public function testThrowsWhenDsnMissing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DSN');

        $parser = new Swift_Cli_ArgumentParser();
        $parser->parse(['bin/swiftmailer-test', '--to=a@b.com']);
    }

    public function testThrowsWhenToMissing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--to');

        $parser = new Swift_Cli_ArgumentParser();
        $parser->parse(['bin/swiftmailer-test', 'smtp://localhost']);
    }

    public function testPrintsUsageOnHelp()
    {
        $parser = new Swift_Cli_ArgumentParser();

        $this->expectException(Swift_Cli_HelpRequestedException::class);

        $parser->parse(['bin/swiftmailer-test', '--help']);
    }

    public function testThrowsWhenOptionLacksValue()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a value');

        $parser = new Swift_Cli_ArgumentParser();
        $parser->parse(['bin/swiftmailer-test', 'smtp://localhost', '--verbose']);
    }
}
