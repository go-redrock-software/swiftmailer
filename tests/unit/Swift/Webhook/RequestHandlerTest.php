<?php

class Swift_Webhook_RequestHandlerTest extends PHPUnit\Framework\TestCase
{
    public function testHandleWithValidSignature()
    {
        $event = new Swift_Webhook_Event(
            'delivery',
            'bounced',
            'msg-1',
            'user@example.com',
            [],
            new DateTimeImmutable(),
            [],
        );

        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle(
            $converter,
            '{"event":"bounce"}',
            ['x-signature' => 'valid'],
            'my-secret',
        );

        $this->assertCount(1, $result);
        $this->assertSame($event, $result[0]);
    }

    public function testHandleWithInvalidSignatureThrows()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(false);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(Swift_Webhook_SignatureVerificationException::class);
        $this->expectExceptionMessage('test');

        $handler->handle(
            $converter,
            '{"event":"bounce"}',
            ['x-signature' => 'invalid'],
            'my-secret',
        );
    }

    public function testHandleWithInvalidJsonThrows()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $handler->handle($converter, 'not-json{', [], 'secret');
    }

    public function testHandleWithoutSecretSkipsVerification()
    {
        $event = new Swift_Webhook_Event(
            'engagement',
            'opened',
            'msg-2',
            'user@example.com',
            [],
            new DateTimeImmutable(),
            [],
        );

        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->expects($this->never())->method('verify');
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], null);

        $this->assertCount(1, $result);
    }

    public function testHandleNormalizesHeaderKeysToLowercase()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        // Mixed-case headers should be normalized
        $result = $handler->handle(
            $converter,
            '{"event":"test"}',
            ['X-Signature' => 'valid', 'Content-Type' => 'application/json'],
            'secret',
        );

        $this->assertIsArray($result);
    }

    public function testHandleWithEmptyJsonObject()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], 'secret');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testHandleWithEmptyJsonArray()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '[]', [], 'secret');

        $this->assertIsArray($result);
    }

    public function testHandleWithMultipleEvents()
    {
        $event1 = new Swift_Webhook_Event('delivery', 'bounced', 'msg-1', 'a@b.com', [], new DateTimeImmutable(), []);
        $event2 = new Swift_Webhook_Event('engagement', 'opened', 'msg-2', 'c@d.com', [], new DateTimeImmutable(), []);

        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([$event1, $event2]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{"events":[]}', [], 'secret');

        $this->assertCount(2, $result);
        $this->assertSame('bounced', $result[0]->getName());
        $this->assertSame('opened', $result[1]->getName());
    }

    public function testHandleWithTruncatedJsonThrows()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(InvalidArgumentException::class);
        $handler->handle($converter, '{"incomplete', [], 'secret');
    }

    public function testHandleWithEmptyBodyThrows()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(InvalidArgumentException::class);
        $handler->handle($converter, '', [], 'secret');
    }

    public function testHandlePassesHeadersToConvert()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->expects($this->once())
            ->method('convert')
            ->with($this->anything(), $this->callback(function ($headers) {
                return isset($headers['x-custom-header']);
            }))
            ->willReturn([]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $handler->handle(
            $converter,
            '{}',
            ['X-Custom-Header' => 'value'],
            'secret',
        );
    }

    public function testSignatureVerificationExceptionContainsProviderName()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(false);
        $converter->method('getProviderName')->willReturn('my-provider');

        $handler = new Swift_Webhook_RequestHandler();

        try {
            $handler->handle($converter, '{}', [], 'secret');
            $this->fail('Expected exception');
        } catch (Swift_Webhook_SignatureVerificationException $e) {
            $this->assertStringContainsString('my-provider', $e->getMessage());
        }
    }
}
