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
}
