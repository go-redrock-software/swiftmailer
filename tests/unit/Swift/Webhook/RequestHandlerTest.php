<?php

/**
 * Combined interface for testing timestamp-aware converters.
 */
interface TimestampAwareTestConverter extends Swift_Webhook_PayloadConverterInterface, Swift_Webhook_TimestampExtractorInterface
{
}

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

    public function testHandleWithEmptySecretThrowsInvalidArgument()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook signing secret must not be empty');

        $handler->handle($converter, '{}', [], '');
    }

    public function testHandleAlwaysCallsVerify()
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
        $converter->expects($this->once())->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], 'any-secret');

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

    // ── Timestamp validation tests ────────────────────────────────

    public function testHandleRejectsExpiredTimestamp()
    {
        $converter = $this->createMock(TimestampAwareTestConverter::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('extractTimestamp')->willReturn(\time() - 600);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(Swift_Webhook_SignatureVerificationException::class);

        $handler->handle($converter, '{}', [], 'secret', 300);
    }

    public function testHandleAcceptsFreshTimestamp()
    {
        $event = new Swift_Webhook_Event('delivery', 'delivered', 'msg-t1', 'a@b.com', [], new DateTimeImmutable(), []);

        $converter = $this->createMock(TimestampAwareTestConverter::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('extractTimestamp')->willReturn(\time() - 10);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], 'secret', 300);

        $this->assertCount(1, $result);
    }

    public function testHandleSkipsTimestampWhenConverterDoesNotSupportIt()
    {
        $event = new Swift_Webhook_Event('delivery', 'delivered', 'msg-t2', 'a@b.com', [], new DateTimeImmutable(), []);

        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], 'secret', 300);

        $this->assertCount(1, $result);
    }

    public function testHandleSkipsTimestampWhenExtractorReturnsNull()
    {
        $event = new Swift_Webhook_Event('delivery', 'delivered', 'msg-t3', 'a@b.com', [], new DateTimeImmutable(), []);

        $converter = $this->createMock(TimestampAwareTestConverter::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('extractTimestamp')->willReturn(null);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], 'secret', 300);

        $this->assertCount(1, $result);
    }

    public function testHandleUsesCustomMaxAge()
    {
        $converter = $this->createMock(TimestampAwareTestConverter::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('extractTimestamp')->willReturn(\time() - 120);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        // 120 seconds old — passes with default 300s, fails with 60s
        $this->expectException(Swift_Webhook_SignatureVerificationException::class);

        $handler->handle($converter, '{}', [], 'secret', 60);
    }

    public function testHandleWithZeroMaxAgeDisablesTimestampValidation()
    {
        $event = new Swift_Webhook_Event('delivery', 'delivered', 'msg-t4', 'a@b.com', [], new DateTimeImmutable(), []);

        $converter = $this->createMock(TimestampAwareTestConverter::class);
        $converter->method('verify')->willReturn(true);
        $converter->method('extractTimestamp')->willReturn(\time() - 99999);
        $converter->method('convert')->willReturn([$event]);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();
        $result  = $handler->handle($converter, '{}', [], 'secret', 0);

        $this->assertCount(1, $result);
    }

    // ── Other tests ─────────────────────────────────────────────

    public function testWebhookIpAllowlistRejectsUnknownIp()
    {
        $converter = $this->createMock(Swift_Webhook_PayloadConverterInterface::class);
        $converter->method('getProviderName')->willReturn('test');

        $handler = new Swift_Webhook_RequestHandler();

        $this->expectException(Swift_Webhook_SignatureVerificationException::class);
        $this->expectExceptionMessage('remote IP not in allowlist');

        $handler->handle($converter, '{"e":1}', [], 'secret', 300, ['10.0.0.1'], '192.168.1.1');
    }

    public function testWebhookIpAllowlistAcceptsKnownIp()
    {
        $event = new Swift_Webhook_Event(
            'delivery',
            'delivered',
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
        $result  = $handler->handle($converter, '{"e":1}', [], 'secret', 300, ['10.0.0.1'], '10.0.0.1');

        $this->assertCount(1, $result);
    }

    public function testWebhookNullAllowlistAcceptsAll()
    {
        $event = new Swift_Webhook_Event(
            'delivery',
            'delivered',
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
        $result  = $handler->handle($converter, '{"e":1}', [], 'secret', 300, null, '192.168.1.1');

        $this->assertCount(1, $result);
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
