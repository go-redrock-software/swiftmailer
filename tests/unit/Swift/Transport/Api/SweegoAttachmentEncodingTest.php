<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SweegoAttachmentEncodingTest extends TestCase
{
    /** @dataProvider attachmentBodies */
    public function testAttachmentsPreserveExactBytesAcrossRepeatedSends(string $mode, string $bytes, string $filename, string $encoded): void
    {
        $path = null;
        try {
            if ('file' === $mode) {
                $path = \tempnam(\sys_get_temp_dir(), 'swiftmailer-49-');
                $this->assertIsString($path);
                \file_put_contents($path, $bytes);
                $attachment = \Swift_Attachment::fromPath($path, 'application/octet-stream')->setFilename($filename);
            } else {
                $attachment = new \Swift_Attachment('stream' === $mode ? new \Swift_ByteStream_ArrayByteStream($bytes) : $bytes, $filename, 'application/octet-stream');
            }
            $attachment->setId('file@safe.test');
            $message     = $this->message()->attach($attachment);
            $original    = $message->toString();
            $submissions = $this->sendAndCapture($message, 2);

            foreach ($submissions as $payload) {
                $this->assertSame([[
                    'content'     => $encoded,
                    'filename'    => $filename,
                    'disposition' => 'attachment',
                    'content_id'  => 'file@safe.test',
                ]], $payload['attachments']);
                $this->assertSame($bytes, \base64_decode($payload['attachments'][0]['content'], true));
            }
            $this->assertSame($bytes, $attachment->getBody());
            $this->assertSame($original, $message->toString());
        } finally {
            unset($message, $attachment);
            if (null !== $path && \is_file($path)) {
                \unlink($path);
            }
        }
    }

    public static function attachmentBodies(): iterable
    {
        $vectors = [
            'text'                      => ["Hello\n", 'letter.txt', 'SGVsbG8K'],
            'zero content and filename' => ['0', '0', 'MA=='],
            'empty'                     => ['', 'empty.txt', ''],
            'arbitrary binary'          => ["\x00\x01\xfe\xff", 'binary.bin', 'AAH+/w=='],
            'base64-looking raw bytes'  => ['SGVsbG8=', 'encoded-looking.txt', 'U0dWc2JHOD0='],
            'multiple read chunks'      => [\str_repeat("\x00\xffA", 4000), 'large.bin', \str_repeat('AP9B', 4000)],
        ];
        foreach (['string', 'stream', 'file'] as $mode) {
            foreach ($vectors as $label => [$bytes, $filename, $encoded]) {
                yield $mode.' / '.$label => [$mode, $bytes, $filename, $encoded];
            }
        }
    }

    public function testAnUnsetBodyIsEncodedAsAnEmptyFileWithoutChangingTheAttachment(): void
    {
        $attachment  = new \Swift_Attachment(null, 'empty.txt');
        $message     = $this->message()->attach($attachment);
        $submissions = $this->sendAndCapture($message, 2);

        foreach ($submissions as $payload) {
            $this->assertArrayHasKey('content', $payload['attachments'][0]);
            $this->assertSame('', $payload['attachments'][0]['content']);
            $this->assertSame('empty.txt', $payload['attachments'][0]['filename']);
        }
        $this->assertNull($attachment->getBody());
    }

    /** @dataProvider inlineBodies */
    public function testInlineImagesKeepTheirContentIdAndDisposition(bool $stream): void
    {
        $bytes   = "\x00\x01\xfe\xff";
        $image   = (new \Swift_Image($stream ? new \Swift_ByteStream_ArrayByteStream($bytes) : $bytes, '0', 'image/png'))->setId('logo@safe.test');
        $message = $this->message();
        $html    = '<img src="'.$message->embed($image).'">';
        $message->setBody($html, 'text/html');
        $original = $message->toString();

        foreach ($this->sendAndCapture($message, 2) as $payload) {
            $this->assertSame($html, $payload['message-html']);
            $this->assertSame([[
                'content'     => 'AAH+/w==',
                'filename'    => '0',
                'disposition' => 'inline',
                'content_id'  => 'logo@safe.test',
            ]], $payload['attachments']);
        }
        $this->assertSame($original, $message->toString());
    }

    public static function inlineBodies(): iterable
    {
        yield 'string' => [false];
        yield 'stream' => [true];
    }

    public function testAbsentOptionalDispositionDoesNotRemoveRequiredEmptyContent(): void
    {
        $attachment = (new \Swift_Attachment('', '0'))->setId('empty@safe.test')->setDisposition(null);

        foreach ($this->sendAndCapture($this->message()->attach($attachment)) as $payload) {
            $this->assertSame([[
                'content'    => '',
                'filename'   => '0',
                'content_id' => 'empty@safe.test',
            ]], $payload['attachments']);
        }
    }

    private function message(): \Swift_Message
    {
        return (new \Swift_Message('Attachment fixture'))->setFrom('sender@safe.test')->setTo('to@safe.test')->setBcc('blind@safe.test')->setBody('Body');
    }

    private function sendAndCapture(\Swift_Message $message, int $times = 1): array
    {
        $client      = $this->createMock(ClientInterface::class);
        $submissions = [];
        $client->expects($this->exactly($times))->method('request')->willReturnCallback(static function ($method, $url, $options) use (&$submissions) {
            $submissions[] = $options['json'];

            return new Response(200, [], '{"transaction_id":"attachment-id"}');
        });
        $transport = new \Swift_Transport_Api_SweegoTransport('fixture-key', $client);
        $failed    = [];
        for ($i = 0; $i < $times; ++$i) {
            $this->assertSame(2, $transport->send($message, $failed));
        }
        $this->assertSame([], $failed);
        foreach ($submissions as $payload) {
            $this->assertSame([['email' => 'to@safe.test']], $payload['recipients']);
            $this->assertSame([['email' => 'blind@safe.test']], $payload['bcc']);
            $this->assertIsString(\json_encode($payload, \JSON_THROW_ON_ERROR));
        }

        return $submissions;
    }
}
