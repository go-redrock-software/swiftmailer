<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_SesMimeDeliveryTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            \unlink($file);
        }
    }

    /** @dataProvider mimeCases */
    public function testMultipartSendPreservesCompleteMimeAndRoutingAcrossRepeatedSends(string $kind, string $mimeKind, string $envelopeKind): void
    {
        $fixture                          = new SesSdkFixture();
        $transport                        = $this->transport($kind, $fixture);
        [$message, $attachments, $inline] = $this->message($mimeKind);
        $message->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', 'fixture-config');
        $message->getHeaders()->addTextHeader('X-SES-SOURCE-ARN', 'arn:aws:ses:us-east-1:123456789012:identity/example.test');
        $message->getHeaders()->addTextHeader('X-SES-LIST-MANAGEMENT-OPTIONS', 'contactListName=fixture-list; topicName=fixture-topic');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'fixture-tag');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-private', 'fixture-metadata');
        $message->getHeaders()->addMailboxHeader('Resent-Bcc', ['resent-blind@example.test']);
        $envelope = match ($envelopeKind) {
            'matching'  => Swift_Envelope::fromMessage($message),
            'different' => new Swift_Envelope('envelope-bounce@example.test', ['delivery@example.test', 'envelope-blind@example.test', 'delivery@example.test']),
            default     => null,
        };
        $snapshot = $this->stableMime($this->withoutHeaders($message->toString(), ['X-Mailer-Tag', 'X-SES-Message-ID']));
        for ($index = 0; $index < 2; ++$index) {
            $expected = $this->stableMime($this->withoutHeaders($message->toString(), ['Bcc', 'Resent-Bcc', 'X-Mailer-Tag', 'X-Mailer-Metadata-private']));
            $failed   = [];
            $this->assertSame('different' === $envelopeKind ? 2 : 3, $transport->send($message, $failed, $envelope));
            $this->assertSame([], $failed);
            $payload = $fixture->payload($index);
            $this->assertSame(['Raw'], \array_keys($payload['Content']));
            $raw = \base64_decode($payload['Content']['Raw']['Data'], true);
            $this->assertNotFalse($raw);
            $this->assertSame($expected, $this->stableMime($raw), 'Exactly one complete MIME serialization must reach SES.');
            $headers = \explode("\r\n\r\n", $raw, 2)[0];
            $this->assertDoesNotMatchRegularExpression('/^(?:Resent-)?Bcc:/mi', $headers);
            $this->assertStringContainsString('To: Visible <to@example.test>', $headers);
            $this->assertStringContainsString('Cc: Copy <cc@example.test>', $headers);
            $this->assertStringContainsString('Reply-To: Reply <reply@example.test>', $headers);
            $this->assertStringNotContainsString('fixture-metadata', $raw);
            $this->assertStringNotContainsString('X-Mailer-Tag:', $raw);
            $this->assertSame('Api' === $kind ? 'Author <author@example.test>' : 'author@example.test', $payload['FromEmailAddress']);
            $this->assertSame('fixture-config', $payload['ConfigurationSetName']);
            $this->assertSame('arn:aws:ses:us-east-1:123456789012:identity/example.test', $payload['FromEmailAddressIdentityArn']);
            $this->assertSame(['ContactListName' => 'fixture-list', 'TopicName' => 'fixture-topic'], $payload['ListManagementOptions']);
            if (0 === $index) {
                $this->assertSame([['Name' => 'tag', 'Value' => 'fixture-tag']], $payload['EmailTags']);
            }
            if (null === $envelope) {
                $this->assertSame('Api' === $kind ? ['Blind <blind@example.test>'] : ['blind@example.test'], $payload['Destination']['BccAddresses']);
                $this->assertSame('message-bounce@example.test', $payload['FeedbackForwardingEmailAddress']);
            } else {
                $this->assertSame(\array_values(\array_unique($envelope->getRecipients())), $payload['Destination']['ToAddresses']);
                $this->assertArrayNotHasKey('BccAddresses', $payload['Destination']);
                $this->assertSame($envelope->getSender(), $payload['FeedbackForwardingEmailAddress']);
            }
            $this->assertAttachmentBytes($raw, $attachments, $inline);
            $this->assertSame($snapshot, $this->stableMime($this->withoutHeaders($message->toString(), ['X-Mailer-Tag', 'X-SES-Message-ID'])));
            $this->assertSame(['blind@example.test' => 'Blind'], $message->getBcc());
            $this->assertCount(\count($attachments) + (\in_array($mimeKind, ['alternatives', 'parts-only'], true) ? 1 : ('inline' === $mimeKind ? 1 : ('nested' === $mimeKind ? 2 : 0))), $message->getChildren());
            if ('Http' === $kind) {
                $this->assertSame('fixture-id', $message->getHeaders()->get('X-SES-Message-ID')->getFieldBody());
            }
            $this->assertSame(0, $fixture->requests[$index]['options']['max_redirects']);
        }
        $this->assertCount(2, $fixture->requests);
    }

    public static function mimeCases(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach (['binary', 'stream', 'file', 'inline', 'alternatives', 'parts-only', 'nested', 'empty'] as $mimeKind) {
                foreach (['none', 'matching', 'different'] as $envelope) {
                    yield "$kind $mimeKind $envelope" => [$kind, $mimeKind, $envelope];
                }
            }
        }
    }

    /** @dataProvider policyCases */
    public function testMultipartDeliveryHonorsRecipientPoliciesAndEvents(string $kind, string $policy, bool $explicit): void
    {
        $fixture                          = new SesSdkFixture();
        $transport                        = $this->transport($kind, $fixture);
        [$message, $attachments, $inline] = $this->message('nested');
        $message->setTo(['kept@safe.test' => 'Kept', 'blocked@outside.test' => 'Blocked']);
        $message->setCc('copy@outside.test')->setBcc('blind@outside.test');
        $envelope = $explicit ? new Swift_Envelope('bounce@safe.test', ['kept@safe.test', 'blocked@outside.test']) : null;
        $transport->registerPlugin('allowlist' === $policy ? new Swift_Plugins_AllowlistPlugin(['*@safe.test']) : new Swift_Plugins_RedirectingPlugin('sink@safe.test'));
        $observer = $this->observe($transport);
        $before   = $message->getTo();
        $failed   = [];
        $this->assertSame(1, $transport->send($message, $failed, $envelope));
        $this->assertSame([], $failed);
        $payload      = $fixture->payload();
        $destinations = \array_merge(...\array_values($payload['Destination']));
        $expected     = 'allowlist' === $policy ? 'kept@safe.test' : 'sink@safe.test';
        $this->assertCount(1, $destinations);
        $this->assertStringContainsString($expected, $destinations[0]);
        $raw = \base64_decode($payload['Content']['Raw']['Data'], true);
        $this->assertAttachmentBytes($raw, $attachments, $inline);
        $this->assertDoesNotMatchRegularExpression('/^(?:Resent-)?Bcc:/mi', \explode("\r\n\r\n", $raw, 2)[0]);
        $this->assertSame($before, $message->getTo());
        $this->assertSame(['blind@outside.test' => null], $message->getBcc());
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_SUCCESS, $observer->result);
    }

    public static function policyCases(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach (['allowlist', 'redirect'] as $policy) {
                foreach ([false, true] as $explicit) {
                    yield "$kind $policy explicit ".(int) $explicit => [$kind, $policy, $explicit];
                }
            }
        }
    }

    /** @dataProvider kinds */
    public function testMultipartFailureAndRedirectRetainPermanentPolicyAndCompletion(string $kind): void
    {
        $fixture                          = new SesSdkFixture([new MockResponse('{"MessageId":"not-accepted"}', ['http_code' => 307, 'response_headers' => ['Location: https://other.example.test/collect']])], true);
        $transport                        = $this->transport($kind, $fixture);
        $observer                         = $this->observe($transport);
        [$message, $attachments, $inline] = $this->message('nested');
        $failed                           = [];
        try {
            (new Swift_Transport_RetryTransport($transport, maxRetries: 2, baseDelayMs: 0))->send($message, $failed);
            $this->fail('A multipart send must not follow, retry or accept a redirect.');
        } catch (Swift_TransportException $error) {
            $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($error));
        }
        $this->assertCount(1, $fixture->requests);
        $this->assertAttachmentBytes(\base64_decode($fixture->payload()['Content']['Raw']['Data'], true), $attachments, $inline);
        $this->assertSame(['to@example.test', 'cc@example.test', 'blind@example.test'], $failed);
        $this->assertSame(1, $observer->before);
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertFalse($message->getHeaders()->has('X-SES-Message-ID'));
    }

    /** @dataProvider signerCases */
    public function testDkimMultipartSerializationRunsOnce(string $kind, string $envelopeKind): void
    {
        $key = \openssl_pkey_new(['private_key_bits' => 2048]);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));
        $counter = (object) ['calls' => 0];
        $signer  = new class($privateKey, $counter) extends Swift_Signers_DKIMSigner {
            public function __construct(string $key, private object $counter)
            {
                parent::__construct($key, 'example.test', 'fixture');
            }

            public function startBody()
            {
                ++$this->counter->calls;
                parent::startBody();
            }
        };
        [$message, $attachments, $inline] = $this->message('binary');
        $message->attachSigner($signer);
        $fixture  = new SesSdkFixture();
        $envelope = match ($envelopeKind) {
            'matching'  => Swift_Envelope::fromMessage($message),
            'different' => new Swift_Envelope('bounce@example.test', ['delivery@example.test']),
            default     => null,
        };
        $this->assertSame('different' === $envelopeKind ? 1 : 3, $this->transport($kind, $fixture)->send($message, $failed, $envelope));
        $this->assertSame(1, $counter->calls);
        $raw = \base64_decode($fixture->payload()['Content']['Raw']['Data'], true);
        $this->assertStringContainsString('DKIM-Signature:', $raw);
        $this->assertAttachmentBytes($raw, $attachments, $inline);
    }

    public static function signerCases(): iterable
    {
        foreach (['Api', 'Http'] as $kind) {
            foreach (['none', 'matching', 'different'] as $envelope) {
                yield "$kind $envelope" => [$kind, $envelope];
            }
        }
    }

    public static function kinds(): iterable
    {
        yield ['Api'];
        yield ['Http'];
    }

    private function transport(string $kind, SesSdkFixture $fixture): Swift_Transport_AbstractApiTransport
    {
        $class = 'Swift_Transport_Api_AmazonSes'.$kind.'Transport';

        return new $class($fixture->client, new Swift_Events_SimpleEventDispatcher());
    }

    private function message(string $kind): array
    {
        $message = (new Swift_Message('MIME fixture', 'PLAIN MIME fixture π'))->setFrom(['author@example.test' => 'Author'])
            ->setTo(['to@example.test' => 'Visible'])->setCc(['cc@example.test' => 'Copy'])->setBcc(['blind@example.test' => 'Blind'])
            ->setReplyTo(['reply@example.test' => 'Reply'])->setReturnPath('message-bounce@example.test');
        $bytes       = "attachment\0\xff\r\nfixture\r\n";
        $attachments = [];
        $inline      = null;
        if (\in_array($kind, ['binary', 'empty', 'stream', 'file'], true)) {
            $attachments['fixture.bin'] = 'empty'  === $kind ? '' : $bytes;
            $body                       = 'stream' === $kind ? new Swift_ByteStream_ArrayByteStream($bytes) : $attachments['fixture.bin'];
            if ('file' === $kind) {
                $file          = \tempnam(\sys_get_temp_dir(), 'swiftmailer-64-');
                $this->files[] = $file;
                \file_put_contents($file, $bytes);
                $message->attach(Swift_Attachment::fromPath($file, 'application/octet-stream')->setFilename('fixture.bin'));
            } else {
                $message->attach(new Swift_Attachment($body, 'fixture.bin', 'application/octet-stream'));
            }
        } elseif ('parts-only' === $kind) {
            $message->setBody(null);
            $message->addPart('ONLY child MIME body', 'text/plain');
        } elseif ('alternatives' === $kind) {
            $message->addPart('<p>HTML MIME fixture π</p>', 'text/html');
        } else {
            $image  = (new Swift_Image($bytes, 'inline.png', 'image/png'))->setId('fixture-inline@example.test');
            $inline = ['id' => 'fixture-inline@example.test', 'bytes' => $bytes];
            $cid    = $message->embed($image);
            $message->setBody('<p>HTML MIME fixture π<img src="'.$cid.'"></p>', 'text/html');
            if ('nested' === $kind) {
                $message->addPart('PLAIN alternative fixture', 'text/plain');
                $message->attach(new Swift_Attachment($bytes, 'first.bin', 'application/octet-stream'));
                $message->attach(new Swift_Attachment('second attachment', 'second.txt', 'text/plain'));
                $attachments = ['first.bin' => $bytes, 'second.txt' => 'second attachment'];
            }
        }

        return [$message, $attachments, $inline];
    }

    private function assertAttachmentBytes(string $raw, array $attachments, ?array $inline): void
    {
        $this->assertSame(\count($attachments), \preg_match_all('/^Content-Disposition: attachment;/m', $raw));
        foreach ($attachments as $filename => $bytes) {
            $this->assertStringContainsString('filename='.$filename, $raw);
        }
        \preg_match_all('/Content-Transfer-Encoding: base64\r\n(?:[^\r\n]+\r\n)*\r\n([A-Za-z0-9+\/=\r\n]*)/', $raw, $matches);
        $decoded = \array_map(static fn (string $body) => \base64_decode($body, true), $matches[1]);
        foreach ($attachments as $bytes) {
            $this->assertContains($bytes, $decoded);
        }
        if (null !== $inline) {
            $this->assertSame(1, \substr_count($raw, 'Content-ID: <'.$inline['id'].'>'));
            $this->assertStringContainsString('cid:'.$inline['id'], \quoted_printable_decode($raw));
            $this->assertContains($inline['bytes'], $decoded);
        }
    }

    private function stableMime(string $mime): string
    {
        $boundaries = [];

        return \preg_replace_callback('/_=_swift_[A-Za-z0-9_]+_=_/', static function (array $match) use (&$boundaries): string {
            return $boundaries[$match[0]] ??= 'fixture-boundary-'.\count($boundaries);
        }, $mime);
    }

    private function withoutHeaders(string $mime, array $names): string
    {
        [$headers, $body] = \explode("\r\n\r\n", $mime, 2);
        foreach ($names as $name) {
            $headers = \preg_replace('/^'.\preg_quote($name, '/').':[^\r\n]*(?:\r\n[ \t][^\r\n]*)*(?:\r\n|$)/mi', '', $headers);
        }

        return \rtrim($headers, "\r\n")."\r\n\r\n".$body;
    }

    private function observe(Swift_Transport $transport): object
    {
        $observer = new class implements Swift_Events_SendListener {
            public int $before = 0;

            public int $completed = 0;

            public ?int $result = null;

            public function beforeSendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->before;
            }

            public function sendPerformed(Swift_Events_SendEvent $event): void
            {
                ++$this->completed;
                $this->result = $event->getResult();
            }
        };
        $transport->registerPlugin($observer);

        return $observer;
    }
}
