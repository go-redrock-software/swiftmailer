# Symfony Mailer Feature Parity — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Bring Swiftmailer to feature parity with Symfony Mailer without breaking existing APIs.

**Architecture:** Event-driven SentMessage access (no return type changes), header-based tagging/metadata, DSN factory for meta-transports, 5 new HTTP API transports. All new classes follow existing `Swift_` PSR-0 naming. Security hardening is a separate branch.

**Tech Stack:** PHP 8.1+, Guzzle 7, PHPUnit, Mockery, existing Swiftmailer event system.

**Design doc:** `docs/plans/2026-02-26-symfony-mailer-parity-design.md`

---

## Phase 1: Core Infrastructure

### Task 1: Swift_SentMessage Value Object

**Files:**
- Create: `lib/classes/Swift/SentMessage.php`
- Test: `tests/unit/Swift/SentMessageTest.php`

**Step 1: Write the failing test**

```php
<?php

class Swift_SentMessageTest extends \PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'message_id' => 'abc-123',
            'recipients' => 1,
            'debug' => ['status' => 200, 'response' => '{"ok":true}'],
        ]);

        $this->assertSame($message, $sentMessage->getOriginalMessage());
        $this->assertSame($transport, $sentMessage->getTransport());
        $this->assertEquals('abc-123', $sentMessage->getMessageId());
        $this->assertEquals(1, $sentMessage->getRecipientCount());
        $this->assertEquals(['status' => 200, 'response' => '{"ok":true}'], $sentMessage->getDebug());
        $this->assertEquals([], $sentMessage->getFailedRecipients());
    }

    public function testWithFailedRecipients()
    {
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A', 'c@d.com' => 'C']);
        $transport = $this->createMock(Swift_Transport::class);

        $sentMessage = new Swift_SentMessage($message, $transport, [
            'failed_recipients' => ['c@d.com'],
        ]);

        $this->assertEquals(['c@d.com'], $sentMessage->getFailedRecipients());
        $this->assertNull($sentMessage->getMessageId());
        $this->assertEquals(0, $sentMessage->getRecipientCount());
        $this->assertEquals([], $sentMessage->getDebug());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SentMessageTest.php --verbose`
Expected: FAIL — class `Swift_SentMessage` not found.

**Step 3: Write the implementation**

```php
<?php

class Swift_SentMessage
{
    private Swift_Mime_SimpleMessage $originalMessage;
    private Swift_Transport $transport;
    private ?string $messageId;
    private int $recipientCount;
    private array $debug;
    private array $failedRecipients;

    public function __construct(
        Swift_Mime_SimpleMessage $originalMessage,
        Swift_Transport $transport,
        array $result = [],
    ) {
        $this->originalMessage = $originalMessage;
        $this->transport = $transport;
        $this->messageId = $result['message_id'] ?? null;
        $this->recipientCount = $result['recipients'] ?? 0;
        $this->debug = $result['debug'] ?? [];
        $this->failedRecipients = $result['failed_recipients'] ?? [];
    }

    public function getOriginalMessage(): Swift_Mime_SimpleMessage
    {
        return $this->originalMessage;
    }

    public function getTransport(): Swift_Transport
    {
        return $this->transport;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function getDebug(): array
    {
        return $this->debug;
    }

    public function getFailedRecipients(): array
    {
        return $this->failedRecipients;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/SentMessageTest.php --verbose`
Expected: PASS (2 tests, multiple assertions).

**Step 5: Commit**

```
feat: add Swift_SentMessage value object
```

---

### Task 2: SentMessageEvent and FailedMessageEvent

**Files:**
- Create: `lib/classes/Swift/Events/SentMessageEvent.php`
- Create: `lib/classes/Swift/Events/FailedMessageEvent.php`
- Create: `lib/classes/Swift/Events/SentMessageListener.php`
- Create: `lib/classes/Swift/Events/FailedMessageListener.php`
- Modify: `lib/classes/Swift/Events/EventDispatcher.php`
- Modify: `lib/classes/Swift/Events/SimpleEventDispatcher.php`
- Test: `tests/unit/Swift/Events/SentMessageEventTest.php`
- Test: `tests/unit/Swift/Events/FailedMessageEventTest.php`

**Step 1: Write failing tests**

`tests/unit/Swift/Events/SentMessageEventTest.php`:
```php
<?php

class Swift_Events_SentMessageEventTest extends \PHPUnit\Framework\TestCase
{
    public function testGetSentMessage()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'x']);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);

        $this->assertSame($sentMessage, $event->getSentMessage());
        $this->assertSame($transport, $event->getSource());
    }
}
```

`tests/unit/Swift/Events/FailedMessageEventTest.php`:
```php
<?php

class Swift_Events_FailedMessageEventTest extends \PHPUnit\Framework\TestCase
{
    public function testGetters()
    {
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $exception = new Swift_TransportException('API error');

        $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception, ['a@b.com']);

        $this->assertSame($message, $event->getMessage());
        $this->assertSame($exception, $event->getException());
        $this->assertEquals(['a@b.com'], $event->getFailedRecipients());
        $this->assertSame($transport, $event->getSource());
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Events/SentMessageEventTest.php tests/unit/Swift/Events/FailedMessageEventTest.php --verbose`
Expected: FAIL — classes not found.

**Step 3: Write the event classes**

`lib/classes/Swift/Events/SentMessageEvent.php`:
```php
<?php

class Swift_Events_SentMessageEvent extends Swift_Events_EventObject
{
    private Swift_SentMessage $sentMessage;

    public function __construct(Swift_Transport $source, Swift_SentMessage $sentMessage)
    {
        parent::__construct($source);
        $this->sentMessage = $sentMessage;
    }

    public function getSentMessage(): Swift_SentMessage
    {
        return $this->sentMessage;
    }

    public function getTransport(): Swift_Transport
    {
        return $this->getSource();
    }
}
```

`lib/classes/Swift/Events/FailedMessageEvent.php`:
```php
<?php

class Swift_Events_FailedMessageEvent extends Swift_Events_EventObject
{
    private Swift_Mime_SimpleMessage $message;
    private Swift_TransportException $exception;
    private array $failedRecipients;

    public function __construct(
        Swift_Transport $source,
        Swift_Mime_SimpleMessage $message,
        Swift_TransportException $exception,
        array $failedRecipients = [],
    ) {
        parent::__construct($source);
        $this->message = $message;
        $this->exception = $exception;
        $this->failedRecipients = $failedRecipients;
    }

    public function getMessage(): Swift_Mime_SimpleMessage
    {
        return $this->message;
    }

    public function getException(): Swift_TransportException
    {
        return $this->exception;
    }

    public function getFailedRecipients(): array
    {
        return $this->failedRecipients;
    }

    public function getTransport(): Swift_Transport
    {
        return $this->getSource();
    }
}
```

`lib/classes/Swift/Events/SentMessageListener.php`:
```php
<?php

interface Swift_Events_SentMessageListener extends Swift_Events_EventListener
{
    public function sentMessage(Swift_Events_SentMessageEvent $evt);
}
```

`lib/classes/Swift/Events/FailedMessageListener.php`:
```php
<?php

interface Swift_Events_FailedMessageListener extends Swift_Events_EventListener
{
    public function failedMessage(Swift_Events_FailedMessageEvent $evt);
}
```

**Step 4: Wire into EventDispatcher**

Add to `Swift_Events_EventDispatcher` interface:
```php
public function createSentMessageEvent(Swift_Transport $source, Swift_SentMessage $sentMessage);
public function createFailedMessageEvent(Swift_Transport $source, Swift_Mime_SimpleMessage $message, Swift_TransportException $ex, array $failedRecipients = []);
```

Add to `Swift_Events_SimpleEventDispatcher`:
- Add to `$eventMap` in constructor:
  ```php
  'Swift_Events_SentMessageEvent' => 'Swift_Events_SentMessageListener',
  'Swift_Events_FailedMessageEvent' => 'Swift_Events_FailedMessageListener',
  ```
- Add factory methods:
  ```php
  public function createSentMessageEvent(Swift_Transport $source, Swift_SentMessage $sentMessage)
  {
      return new Swift_Events_SentMessageEvent($source, $sentMessage);
  }

  public function createFailedMessageEvent(Swift_Transport $source, Swift_Mime_SimpleMessage $message, Swift_TransportException $ex, array $failedRecipients = [])
  {
      return new Swift_Events_FailedMessageEvent($source, $message, $ex, $failedRecipients);
  }
  ```

**Step 5: Run tests to verify they pass**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Events/SentMessageEventTest.php tests/unit/Swift/Events/FailedMessageEventTest.php --verbose`
Expected: PASS.

**Step 6: Run full test suite for regressions**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All existing tests still pass.

**Step 7: Commit**

```
feat: add SentMessageEvent and FailedMessageEvent with listener interfaces
```

---

### Task 3: Wire New Events into AbstractHttpApiTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/AbstractHttpApiTransport.php`
- Test: `tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php`

**Step 1: Write failing test**

`tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php`:
```php
<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_AbstractHttpApiTransportTest extends \PHPUnit\Framework\TestCase
{
    public function testSendDispatchesSentMessageEvent()
    {
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = new class('test-key', $httpClient, $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test-id-123', 'recipients' => 1];
            }
            protected function getEndpoint(): string { return 'https://api.example.com/send'; }
            protected function getAuthHeaders(): array { return ['Authorization' => 'Bearer test-key']; }
            protected function parseResponse(ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return 'https://api.example.com/ping'; }
        };

        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        // Expect createSendEvent (existing)
        $sendEvent = $this->createMock(Swift_Events_SendEvent::class);
        $sendEvent->method('bubbleCancelled')->willReturn(false);

        $dispatcher->expects($this->once())
            ->method('createSendEvent')
            ->willReturn($sendEvent);

        // Expect createSentMessageEvent (new)
        $dispatcher->expects($this->once())
            ->method('createSentMessageEvent')
            ->with(
                $this->identicalTo($transport),
                $this->callback(function (Swift_SentMessage $sm) {
                    return $sm->getMessageId() === 'test-id-123'
                        && $sm->getRecipientCount() === 1;
                })
            )
            ->willReturn(new Swift_Events_SentMessageEvent(
                $transport,
                new Swift_SentMessage($message, $transport, ['message_id' => 'test-id-123'])
            ));

        $dispatcher->method('dispatchEvent')->willReturn(null);

        $transport->start();
        $count = $transport->send($message);

        $this->assertEquals(1, $count);
    }

    public function testSendDispatchesFailedMessageEventOnException()
    {
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $transport = new class('test-key', $httpClient, $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message): array
            {
                throw new \RuntimeException('API unavailable');
            }
            protected function getEndpoint(): string { return 'https://api.example.com/send'; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return 'https://api.example.com/ping'; }
        };

        $message = (new Swift_Message())
            ->setFrom(['from@example.com' => 'Sender'])
            ->setTo(['to@example.com' => 'Recipient'])
            ->setSubject('Test');

        $sendEvent = $this->createMock(Swift_Events_SendEvent::class);
        $sendEvent->method('bubbleCancelled')->willReturn(false);
        $dispatcher->method('createSendEvent')->willReturn($sendEvent);

        // Expect createFailedMessageEvent (new)
        $dispatcher->expects($this->once())
            ->method('createFailedMessageEvent')
            ->with(
                $this->identicalTo($transport),
                $this->identicalTo($message),
                $this->isInstanceOf(Swift_TransportException::class),
                $this->equalTo(['to@example.com'])
            );

        // throwException dispatches exceptionThrown which re-throws
        $exceptionEvent = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $exceptionEvent->method('bubbleCancelled')->willReturn(false);
        $dispatcher->method('createTransportExceptionEvent')->willReturn($exceptionEvent);
        $dispatcher->method('dispatchEvent')->willReturn(null);

        $transport->start();

        $this->expectException(Swift_TransportException::class);
        $transport->send($message);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php --verbose`
Expected: FAIL — `createSentMessageEvent` never called / method doesn't exist on mock.

**Step 3: Modify `AbstractHttpApiTransport::send()`**

In the `try` block, after setting `RESULT_SUCCESS`, add:
```php
$sentMessage = new Swift_SentMessage($message, $this, [
    'message_id' => $result['message_id'] ?? null,
    'recipients' => $recipientCount,
    'debug' => $result,
]);

if ($sentEvt = $this->eventDispatcher?->createSentMessageEvent($this, $sentMessage)) {
    $this->eventDispatcher->dispatchEvent($sentEvt, 'sentMessage');
}
```

In the `catch` block, before calling `throwException`, add:
```php
$transportException = new Swift_TransportException(
    'Failed to send email via ' . static::class . ': ' . $e->getMessage(),
    0,
    $e,
);

if ($failedEvt = $this->eventDispatcher?->createFailedMessageEvent($this, $message, $transportException, $failedRecipients)) {
    $this->eventDispatcher->dispatchEvent($failedEvt, 'failedMessage');
}
```

(Also refactor to reuse the `$transportException` in the existing `throwException()` call instead of creating it twice.)

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php --verbose`
Expected: PASS.

**Step 5: Run full unit suite**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All pass.

**Step 6: Commit**

```
feat: dispatch SentMessageEvent and FailedMessageEvent from HTTP API transports
```

---

### Task 4: SentMessagePlugin (Convenience Accessor)

**Files:**
- Create: `lib/classes/Swift/Plugins/SentMessagePlugin.php`
- Test: `tests/unit/Swift/Plugins/SentMessagePluginTest.php`

**Step 1: Write failing test**

```php
<?php

class Swift_Plugins_SentMessagePluginTest extends \PHPUnit\Framework\TestCase
{
    public function testCapturesLastSentMessage()
    {
        $plugin = new Swift_Plugins_SentMessagePlugin();

        $this->assertNull($plugin->getLastSentMessage());
        $this->assertEquals([], $plugin->getSentMessages());

        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'id-1']);

        $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
        $plugin->sentMessage($event);

        $this->assertSame($sentMessage, $plugin->getLastSentMessage());
        $this->assertCount(1, $plugin->getSentMessages());

        // Second message
        $sentMessage2 = new Swift_SentMessage($message, $transport, ['message_id' => 'id-2']);
        $event2 = new Swift_Events_SentMessageEvent($transport, $sentMessage2);
        $plugin->sentMessage($event2);

        $this->assertSame($sentMessage2, $plugin->getLastSentMessage());
        $this->assertCount(2, $plugin->getSentMessages());
    }

    public function testReset()
    {
        $plugin = new Swift_Plugins_SentMessagePlugin();
        $transport = $this->createMock(Swift_Transport::class);
        $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
        $sentMessage = new Swift_SentMessage($message, $transport);

        $plugin->sentMessage(new Swift_Events_SentMessageEvent($transport, $sentMessage));
        $plugin->reset();

        $this->assertNull($plugin->getLastSentMessage());
        $this->assertEquals([], $plugin->getSentMessages());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/SentMessagePluginTest.php --verbose`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

class Swift_Plugins_SentMessagePlugin implements Swift_Events_SentMessageListener
{
    /** @var Swift_SentMessage[] */
    private array $sentMessages = [];

    public function sentMessage(Swift_Events_SentMessageEvent $evt): void
    {
        $this->sentMessages[] = $evt->getSentMessage();
    }

    public function getLastSentMessage(): ?Swift_SentMessage
    {
        if (empty($this->sentMessages)) {
            return null;
        }

        return $this->sentMessages[array_key_last($this->sentMessages)];
    }

    /** @return Swift_SentMessage[] */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function reset(): void
    {
        $this->sentMessages = [];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/SentMessagePluginTest.php --verbose`
Expected: PASS.

**Step 5: Commit**

```
feat: add SentMessagePlugin for convenient SentMessage access
```

---

### Task 5: Tag & Metadata Extraction Helpers

**Files:**
- Modify: `lib/classes/Swift/Transport/AbstractHttpApiTransport.php`
- Test: `tests/unit/Swift/Transport/AbstractHttpApiTransportTagsTest.php`

**Step 1: Write failing test**

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_AbstractHttpApiTransportTagsTest extends \PHPUnit\Framework\TestCase
{
    private function createTransport(): Swift_Transport_AbstractHttpApiTransport
    {
        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $httpClient = $this->createMock(ClientInterface::class);

        return new class('key', $httpClient, $dispatcher) extends Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(Swift_Mime_SimpleMessage $message): array { return []; }
            protected function getEndpoint(): string { return ''; }
            protected function getAuthHeaders(): array { return []; }
            protected function parseResponse(ResponseInterface $response): array { return []; }
            protected function getPingEndpoint(): string { return ''; }

            // Expose protected methods for testing
            public function testExtractTags(Swift_Mime_SimpleMessage $msg): array
            {
                return $this->extractTags($msg);
            }
            public function testExtractMetadata(Swift_Mime_SimpleMessage $msg): array
            {
                return $this->extractMetadata($msg);
            }
        };
    }

    public function testExtractTags()
    {
        $message = new Swift_Message();
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'password-reset');
        $message->getHeaders()->addTextHeader('X-Mailer-Tag', 'transactional');

        $transport = $this->createTransport();
        $tags = $transport->testExtractTags($message);

        $this->assertEquals(['password-reset', 'transactional'], $tags);

        // Headers should be removed from message
        $this->assertNull($message->getHeaders()->get('X-Mailer-Tag'));
    }

    public function testExtractMetadata()
    {
        $message = new Swift_Message();
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
        $message->getHeaders()->addTextHeader('X-Mailer-Metadata-campaign', 'onboarding');

        $transport = $this->createTransport();
        $metadata = $transport->testExtractMetadata($message);

        $this->assertEquals(['user_id' => '12345', 'campaign' => 'onboarding'], $metadata);

        // Headers should be removed
        $this->assertNull($message->getHeaders()->get('X-Mailer-Metadata-user_id'));
        $this->assertNull($message->getHeaders()->get('X-Mailer-Metadata-campaign'));
    }

    public function testExtractTagsReturnsEmptyWhenNone()
    {
        $message = new Swift_Message();
        $transport = $this->createTransport();

        $this->assertEquals([], $transport->testExtractTags($message));
        $this->assertEquals([], $transport->testExtractMetadata($message));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/AbstractHttpApiTransportTagsTest.php --verbose`
Expected: FAIL — method `extractTags` not found.

**Step 3: Add methods to `AbstractHttpApiTransport`**

Add to `Swift_Transport_AbstractHttpApiTransport`:

```php
/**
 * Extract X-Mailer-Tag headers from message and remove them.
 *
 * @return string[]
 */
protected function extractTags(Swift_Mime_SimpleMessage $message): array
{
    $tags = [];
    $headers = $message->getHeaders();

    while ($header = $headers->get('X-Mailer-Tag')) {
        $tags[] = $header->getFieldBody();
        $headers->remove('X-Mailer-Tag');
    }

    return $tags;
}

/**
 * Extract X-Mailer-Metadata-* headers from message and remove them.
 *
 * @return array<string, string>
 */
protected function extractMetadata(Swift_Mime_SimpleMessage $message): array
{
    $metadata = [];
    $headers = $message->getHeaders();
    $prefix = 'X-Mailer-Metadata-';

    $toRemove = [];
    foreach ($headers->getAll() as $header) {
        $name = $header->getFieldName();
        if (str_starts_with($name, $prefix)) {
            $key = substr($name, strlen($prefix));
            $metadata[$key] = $header->getFieldBody();
            $toRemove[] = $name;
        }
    }

    foreach ($toRemove as $name) {
        $headers->removeAll($name);
    }

    return $metadata;
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/AbstractHttpApiTransportTagsTest.php --verbose`
Expected: PASS.

**Step 5: Run full unit suite**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All pass.

**Step 6: Commit**

```
feat: add tag and metadata extraction helpers to AbstractHttpApiTransport
```

---

### Task 6: Update LoggerPlugin for New Events

**Files:**
- Modify: `lib/classes/Swift/Plugins/LoggerPlugin.php`
- Test: `tests/unit/Swift/Plugins/LoggerPluginTest.php` (add new test methods)

**Step 1: Write failing test**

Add to existing test file (or create new file if structure requires):

```php
public function testSentMessageLogged()
{
    $logger = $this->createMock(Swift_Plugins_Logger::class);
    $logger->expects($this->once())
        ->method('add')
        ->with($this->stringContains('Message sent via'));

    $plugin = new Swift_Plugins_LoggerPlugin($logger);

    $transport = $this->createMock(Swift_Transport::class);
    $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
    $sentMessage = new Swift_SentMessage($message, $transport, ['message_id' => 'xyz']);

    $event = new Swift_Events_SentMessageEvent($transport, $sentMessage);
    $plugin->sentMessage($event);
}

public function testFailedMessageLogged()
{
    $logger = $this->createMock(Swift_Plugins_Logger::class);
    $logger->expects($this->once())
        ->method('add')
        ->with($this->stringContains('Message failed'));

    $plugin = new Swift_Plugins_LoggerPlugin($logger);

    $transport = $this->createMock(Swift_Transport::class);
    $message = (new Swift_Message())->setTo(['a@b.com' => 'A']);
    $exception = new Swift_TransportException('Timeout');

    $event = new Swift_Events_FailedMessageEvent($transport, $message, $exception, ['a@b.com']);
    $plugin->failedMessage($event);
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/LoggerPluginTest.php --verbose`
Expected: FAIL — method `sentMessage` not found on LoggerPlugin.

**Step 3: Update LoggerPlugin**

Add `Swift_Events_SentMessageListener` and `Swift_Events_FailedMessageListener` to the `implements` list.

Add methods:
```php
public function sentMessage(Swift_Events_SentMessageEvent $evt): void
{
    $sm = $evt->getSentMessage();
    $id = $sm->getMessageId() ?? '(no id)';
    $this->logger->add(sprintf(
        '== Message sent via %s (id: %s, recipients: %d)',
        get_class($evt->getSource()),
        $id,
        $sm->getRecipientCount(),
    ));
}

public function failedMessage(Swift_Events_FailedMessageEvent $evt): void
{
    $this->logger->add(sprintf(
        '!! Message failed via %s: %s (failed recipients: %s)',
        get_class($evt->getSource()),
        $evt->getException()->getMessage(),
        implode(', ', $evt->getFailedRecipients()),
    ));
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/LoggerPluginTest.php --verbose`
Expected: PASS.

**Step 5: Commit**

```
feat: update LoggerPlugin to log SentMessage and FailedMessage events
```

---

## Phase 2: DSN Enhancements

### Task 7: NullTransport and SMTP Schemes in DSN Map

**Files:**
- Modify: `lib/classes/Swift/Dsn.php`
- Modify: `tests/unit/Swift/DsnTest.php` (or create if missing)

**Step 1: Write failing test**

```php
public function testNullScheme()
{
    // Create a Nyholm DSN with scheme 'null'
    $nyholmDsn = new \Nyholm\Dsn\Configuration\Url('null', null, null, 'default');
    $dsn = new Swift_Dsn($nyholmDsn);

    $this->assertEquals(Swift_Transport_NullTransport::class, $dsn->getTransportClass());
}

public function testSmtpScheme()
{
    $nyholmDsn = new \Nyholm\Dsn\Configuration\Url('smtp', null, null, 'localhost', 587);
    $dsn = new Swift_Dsn($nyholmDsn);

    $this->assertEquals(Swift_Transport_EsmtpTransport::class, $dsn->getTransportClass());
}

public function testUnknownSchemeThrows()
{
    $nyholmDsn = new \Nyholm\Dsn\Configuration\Url('unknown', null, null, 'default');
    $dsn = new Swift_Dsn($nyholmDsn);

    $this->expectException(\InvalidArgumentException::class);
    $dsn->getTransportClass();
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/DsnTest.php --verbose`
Expected: FAIL — undefined index for 'null' in transport_class_map, no exception for unknown.

**Step 3: Update `Swift_Dsn`**

Add to `$transport_class_map`:
```php
'null'     => Swift_Transport_NullTransport::class,
'smtp'     => Swift_Transport_EsmtpTransport::class,
'smtp+tls' => Swift_Transport_EsmtpTransport::class,
'smtp+ssl' => Swift_Transport_EsmtpTransport::class,
```

Update `getTransportClass()` to throw on unknown schemes:
```php
public function getTransportClass(): string
{
    if (!isset(static::$transport_class_map[$this->scheme])) {
        throw new \InvalidArgumentException(sprintf('Unsupported DSN scheme "%s". Supported: %s', $this->scheme, implode(', ', array_keys(static::$transport_class_map))));
    }

    return static::$transport_class_map[$this->scheme];
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/DsnTest.php --verbose`
Expected: PASS.

**Step 5: Commit**

```
feat: add null, smtp, smtp+tls, smtp+ssl schemes to DSN map
```

---

### Task 8: DsnTransportFactory

**Files:**
- Create: `lib/classes/Swift/Transport/DsnTransportFactory.php`
- Test: `tests/unit/Swift/Transport/DsnTransportFactoryTest.php`

**Step 1: Write failing tests**

```php
<?php

class Swift_Transport_DsnTransportFactoryTest extends \PHPUnit\Framework\TestCase
{
    public function testCreateSimpleTransport()
    {
        $factory = new Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString('null://default');

        $this->assertInstanceOf(Swift_Transport_NullTransport::class, $transport);
    }

    public function testCreateFailoverTransport()
    {
        $factory = new Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString('failover(null://default null://default)');

        $this->assertInstanceOf(Swift_Transport_FailoverTransport::class, $transport);
    }

    public function testCreateRoundRobinTransport()
    {
        $factory = new Swift_Transport_DsnTransportFactory();
        $transport = $factory->fromDsnString('roundrobin(null://default null://default)');

        $this->assertInstanceOf(Swift_Transport_LoadBalancedTransport::class, $transport);
    }

    public function testInvalidDsnThrows()
    {
        $factory = new Swift_Transport_DsnTransportFactory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->fromDsnString('unknown://default');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryTest.php --verbose`
Expected: FAIL — class not found.

**Step 3: Write the factory**

```php
<?php

use Nyholm\Dsn\DsnParser;

class Swift_Transport_DsnTransportFactory
{
    public function fromDsnString(string $dsnString): Swift_Transport
    {
        // Check for meta-transport wrappers
        if (preg_match('/^(failover|roundrobin)\((.+)\)$/', $dsnString, $matches)) {
            $wrapper = $matches[1];
            $innerDsns = preg_split('/\s+/', trim($matches[2]));

            $transports = [];
            foreach ($innerDsns as $innerDsn) {
                $transports[] = $this->fromDsnString($innerDsn);
            }

            if ('failover' === $wrapper) {
                $transport = new Swift_Transport_FailoverTransport();
            } else {
                $transport = new Swift_Transport_LoadBalancedTransport();
            }
            $transport->setTransports($transports);

            return $transport;
        }

        return $this->createTransport($dsnString);
    }

    private function createTransport(string $dsnString): Swift_Transport
    {
        $nyholmDsn = DsnParser::parseUrl($dsnString);
        $dsn = new Swift_Dsn($nyholmDsn);
        $class = $dsn->getTransportClass();

        // NullTransport needs an event dispatcher
        if (Swift_Transport_NullTransport::class === $class) {
            return new Swift_Transport_NullTransport(
                Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher')
            );
        }

        // SMTP transports
        if (Swift_Transport_EsmtpTransport::class === $class) {
            return $this->createSmtpTransport($dsn);
        }

        // HTTP API transports: all take (apiKey, ?httpClient, ?eventDispatcher)
        $apiKey = $dsn->getUser();
        $dispatcher = Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher');

        return new $class($apiKey, null, $dispatcher);
    }

    private function createSmtpTransport(Swift_Dsn $dsn): Swift_Transport_EsmtpTransport
    {
        $host = $dsn->getHost() ?: 'localhost';
        $port = $dsn->getPort() ?: ('smtp+ssl' === $dsn->getScheme() ? 465 : 587);
        $encryption = match ($dsn->getScheme()) {
            'smtp+ssl' => 'ssl',
            'smtp+tls' => 'tls',
            default => null,
        };

        $transport = new Swift_SmtpTransport($host, $port, $encryption);

        if ($user = $dsn->getUser()) {
            $transport->setUsername($user);
        }
        if ($password = $dsn->getPassword()) {
            $transport->setPassword($password);
        }

        // TLS DSN parameters
        $params = $dsn->getParameters();
        $streamOptions = [];

        if (isset($params['verify_peer'])) {
            $streamOptions['ssl']['verify_peer'] = filter_var($params['verify_peer'], FILTER_VALIDATE_BOOLEAN);
            $streamOptions['ssl']['verify_peer_name'] = filter_var($params['verify_peer'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($params['peer_fingerprint'])) {
            $streamOptions['ssl']['peer_fingerprint'] = $params['peer_fingerprint'];
        }
        if (isset($params['source_ip'])) {
            $transport->setSourceIp($params['source_ip']);
        }

        if (!empty($streamOptions)) {
            $transport->setStreamOptions($streamOptions);
        }

        return $transport;
    }
}
```

Note: The exact constructor signatures for SMTP transport need to be verified against the existing codebase during implementation. The `Swift_SmtpTransport` convenience class may wrap `EsmtpTransport`. Adjust accordingly.

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/DsnTransportFactoryTest.php --verbose`
Expected: PASS.

**Step 5: Run full suite**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All pass.

**Step 6: Commit**

```
feat: add DsnTransportFactory with failover/roundrobin DSN support
```

---

## Phase 3: New Transport Providers

Each transport follows the identical pattern. Implementation is mechanical — extend `AbstractHttpApiTransport`, implement 5 abstract methods, add DSN scheme, write unit test.

### Task 9: AhaSend Transport

**Files:**
- Create: `lib/classes/Swift/Transport/Api/AhaSendTransport.php`
- Test: `tests/unit/Swift/Transport/Api/AhaSendTransportTest.php`
- Modify: `lib/classes/Swift/Dsn.php` (add `'ahasend'` to map)

**Step 1: Write failing test**

```php
<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

class Swift_Transport_Api_AhaSendTransportTest extends \PHPUnit\Framework\TestCase
{
    private ClientInterface $httpClient;
    private Swift_Events_EventDispatcher $dispatcher;
    private Swift_Transport_Api_AhaSendTransport $transport;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $this->transport = new Swift_Transport_Api_AhaSendTransport(
            'test-api-key',
            $this->httpClient,
            $this->dispatcher,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['recipient@example.com' => 'Recipient'])
            ->setSubject('Test Subject')
            ->setBody('Hello world');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.ahasend.com/v1/email/send', $this->callback(function (array $options): bool {
                $this->assertEquals('test-api-key', $options['headers']['X-Api-Key']);
                $this->assertEquals('application/json', $options['headers']['Content-Type']);

                $body = $options['json'];
                $this->assertEquals('sender@example.com', $body['from']['email']);
                $this->assertEquals('Sender', $body['from']['name']);
                $this->assertCount(1, $body['recipients']);
                $this->assertEquals('recipient@example.com', $body['recipients'][0]['email']);
                $this->assertEquals('Test Subject', $body['subject']);

                return true;
            }))
            ->willReturn(new Response(200, [], json_encode([
                'object' => 'list',
                'data' => [['object' => 'message', 'id' => 'msg-uuid', 'status' => 'queued']],
            ])));

        $this->transport->start();
        $sent = $this->transport->send($message);
        $this->assertEquals(1, $sent);
    }

    public function testSendWithAttachment(): void
    {
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['recipient@example.com' => 'Recipient'])
            ->setSubject('Test')
            ->setBody('Hello')
            ->attach(new Swift_Attachment('file-content', 'doc.pdf', 'application/pdf'));

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options): bool {
                $body = $options['json'];
                $this->assertNotEmpty($body['attachments']);
                $this->assertEquals('doc.pdf', $body['attachments'][0]['file_name']);

                return true;
            }))
            ->willReturn(new Response(200, [], json_encode([
                'object' => 'list',
                'data' => [['object' => 'message', 'id' => 'msg-uuid']],
            ])));

        $this->transport->start();
        $this->transport->send($message);
    }

    public function testSendFailureThrowsException(): void
    {
        $message = (new Swift_Message())
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['recipient@example.com' => 'Recipient'])
            ->setSubject('Test')
            ->setBody('Hello');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn(new Response(401, [], json_encode([
                'error' => ['type' => 'authentication_error', 'message' => 'Invalid API key'],
            ])));

        $exceptionEvent = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $exceptionEvent->method('bubbleCancelled')->willReturn(false);
        $this->dispatcher->method('createTransportExceptionEvent')->willReturn($exceptionEvent);

        $this->transport->start();
        $this->expectException(Swift_TransportException::class);
        $this->transport->send($message);
    }

    public function testPing(): void
    {
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('ahasend.com'), $this->anything())
            ->willReturn(new Response(200));

        $this->transport->start();
        $this->assertTrue($this->transport->ping());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/AhaSendTransportTest.php --verbose`
Expected: FAIL — class not found.

**Step 3: Write the transport**

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_AhaSendTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $body = $this->getMessageBody($message);
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);
        $fromName = $from[$fromEmail] ?? null;

        $recipients = [];
        foreach ($message->getTo() ?? [] as $email => $name) {
            $recipients[] = array_filter(['email' => $email, 'name' => $name]);
        }

        $payload = array_filter([
            'from' => array_filter(['email' => $fromEmail, 'name' => $fromName]),
            'recipients' => $recipients,
            'subject' => $message->getSubject(),
            'content' => array_filter([
                'text_body' => $body['text'],
                'html_body' => $body['html'],
            ]),
        ]);

        // CC/BCC as additional recipients
        foreach (['getCc', 'getBcc'] as $method) {
            foreach ($message->$method() ?? [] as $email => $name) {
                $payload['recipients'][] = array_filter(['email' => $email, 'name' => $name]);
            }
        }

        // Attachments
        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = array_map(fn($a) => [
                'file_name' => $a['filename'],
                'content_type' => $a['contentType'],
                'data' => base64_encode($a['content']),
                'base64' => true,
                'content_id' => 'inline' === $a['disposition'] ? $a['contentId'] : null,
            ], $attachments);
        }

        // Tags and metadata
        $tags = $this->extractTags($message);
        if (!empty($tags)) {
            $payload['headers']['X-Tag'] = $tags[0]; // AhaSend supports custom headers
        }

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            'json' => $payload,
        ]);

        $result = $this->parseResponse($response);

        if ($response->getStatusCode() >= 400) {
            throw new Swift_TransportException(
                'AhaSend API error: ' . ($result['error']['message'] ?? 'Unknown error'),
            );
        }

        $messageId = $result['data'][0]['id'] ?? null;

        return [
            'message_id' => $messageId,
            'recipients' => $this->countRecipients($message),
        ];
    }

    protected function getEndpoint(): string
    {
        return 'https://api.ahasend.com/v1/email/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['X-Api-Key' => $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.ahasend.com/v1/email/send'; // Lightweight auth check
    }
}
```

**Step 4: Add DSN scheme**

In `Swift_Dsn::$transport_class_map`, add:
```php
'ahasend' => Swift_Transport_Api_AhaSendTransport::class,
```

**Step 5: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/AhaSendTransportTest.php --verbose`
Expected: PASS.

**Step 6: Commit**

```
feat: add AhaSend HTTP API transport
```

---

### Task 10: Mailomat Transport

**Files:**
- Create: `lib/classes/Swift/Transport/Api/MailomatTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailomatTransportTest.php`
- Modify: `lib/classes/Swift/Dsn.php` (add `'mailomat'`)

Follow the same test/implement/commit pattern as Task 9. Key differences:

- **Endpoint:** `POST https://api.mailomat.swiss/message`
- **Auth:** `Authorization: Bearer {apiKey}`
- **Payload keys:** `from`, `to`, `cc`, `bcc`, `replyTo`, `subject`, `text`, `html`, `attachments` (uses `contentBase64` not `data`)
- **Ping endpoint:** `GET https://api.mailomat.swiss/events`
- **Success response:** 202 with `{"messageUuid": "uuid"}`
- **No tag/metadata support** — `extractTags()`/`extractMetadata()` results ignored

**Commit:**
```
feat: add Mailomat HTTP API transport
```

---

### Task 11: Mailtrap Transport

**Files:**
- Create: `lib/classes/Swift/Transport/Api/MailtrapTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailtrapTransportTest.php`
- Modify: `lib/classes/Swift/Dsn.php` (add `'mailtrap'` and `'mailtrap+sandbox'`)

Key differences from other transports:

- **Constructor takes extra param:** `bool $sandbox = false` (or determined by DSN scheme)
- **Live endpoint:** `POST https://send.api.mailtrap.io/api/send`
- **Sandbox endpoint:** `POST https://sandbox.api.mailtrap.io/api/send/{inbox_id}` — inbox_id from DSN host or parameter
- **Auth:** `Authorization: Bearer {apiKey}`
- **Tag support:** Single `category` field in payload (use first tag from `extractTags()`)
- **Metadata support:** `custom_variables` object in payload (from `extractMetadata()`)
- **Response:** `{"success": true, "message_ids": ["uuid"]}`

Test both sandbox and live modes.

**Commit:**
```
feat: add Mailtrap HTTP API transport with sandbox support
```

---

### Task 12: Postal Transport

**Files:**
- Create: `lib/classes/Swift/Transport/Api/PostalTransport.php`
- Test: `tests/unit/Swift/Transport/Api/PostalTransportTest.php`
- Modify: `lib/classes/Swift/Dsn.php` (add `'postal'`)

Key differences:

- **Constructor takes host from DSN** — self-hosted, endpoint is `https://{host}/api/v1/send/message`
- **Auth:** `X-Server-API-Key: {apiKey}`
- **Payload:** `to`/`cc`/`bcc` are plain string arrays (not objects), `from` is a plain string, `plain_body`/`html_body`, attachments use `name`/`content_type`/`data`
- **Tag support:** Single `tag` field in payload
- **Metadata:** Via `headers` hash

**Commit:**
```
feat: add Postal HTTP API transport (self-hosted)
```

---

### Task 13: Sweego Transport

**Files:**
- Create: `lib/classes/Swift/Transport/Api/SweegoTransport.php`
- Test: `tests/unit/Swift/Transport/Api/SweegoTransportTest.php`
- Modify: `lib/classes/Swift/Dsn.php` (add `'sweego'`)

Key differences:

- **Endpoint:** `POST https://api.sweego.io/send`
- **Auth:** `Api-Key: {apiKey}`
- **Required fields:** `channel: "email"`, `campaign-type: "transac"` — always sent
- **Payload keys:** `from`, `recipients` (objects), `subject`, `message-txt`, `message-html`
- **Attachments:** `content` is raw body (not base64), `filename`, `disposition`, `content_id`
- **Response:** `{"transaction_id": "uuid"}`

**Commit:**
```
feat: add Sweego HTTP API transport
```

---

## Phase 4: CSS Inliner Plugin

### Task 14: CSS Inliner Plugin

**Files:**
- Create: `lib/classes/Swift/Plugins/CssInlinerPlugin.php`
- Test: `tests/unit/Swift/Plugins/CssInlinerPluginTest.php`
- Modify: `composer.json` (add `tijsverkoyen/css-to-inline-styles` to `suggest`)

**Step 1: Write failing test**

```php
<?php

class Swift_Plugins_CssInlinerPluginTest extends \PHPUnit\Framework\TestCase
{
    public function testInlinesCssFromStyleBlock()
    {
        if (!class_exists(\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            $this->markTestSkipped('tijsverkoyen/css-to-inline-styles not installed');
        }

        $plugin = new Swift_Plugins_CssInlinerPlugin();

        $html = '<html><head><style>p { color: red; }</style></head><body><p>Hello</p></body></html>';
        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C'])
            ->setSubject('Test')
            ->setBody($html, 'text/html');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $body = $message->getBody();
        $this->assertStringContainsString('style=', $body);
        $this->assertStringContainsString('color:', $body);
    }

    public function testSkipsPlainTextMessages()
    {
        if (!class_exists(\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            $this->markTestSkipped('tijsverkoyen/css-to-inline-styles not installed');
        }

        $plugin = new Swift_Plugins_CssInlinerPlugin();

        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C'])
            ->setSubject('Test')
            ->setBody('Just plain text');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        $this->assertEquals('Just plain text', $message->getBody());
    }

    public function testInlinesHtmlMimePart()
    {
        if (!class_exists(\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            $this->markTestSkipped('tijsverkoyen/css-to-inline-styles not installed');
        }

        $plugin = new Swift_Plugins_CssInlinerPlugin();
        $html = '<html><head><style>h1 { font-size: 20px; }</style></head><body><h1>Hi</h1></body></html>';

        $message = (new Swift_Message())
            ->setFrom(['a@b.com' => 'A'])
            ->setTo(['c@d.com' => 'C'])
            ->setSubject('Test')
            ->setBody('Plain text version')
            ->addPart($html, 'text/html');

        $transport = $this->createMock(Swift_Transport::class);
        $event = new Swift_Events_SendEvent($transport, $message);

        $plugin->beforeSendPerformed($event);

        // Check the HTML child part was inlined
        $found = false;
        foreach ($message->getChildren() as $child) {
            if ('text/html' === $child->getContentType()) {
                $this->assertStringContainsString('style=', $child->getBody());
                $found = true;
            }
        }
        $this->assertTrue($found, 'HTML part should have inlined styles');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/CssInlinerPluginTest.php --verbose`
Expected: FAIL — class not found.

**Step 3: Write the plugin**

```php
<?php

class Swift_Plugins_CssInlinerPlugin implements Swift_Events_SendListener
{
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        if (!class_exists(\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
            return;
        }

        $message = $evt->getMessage();

        // Inline the main body if HTML
        if ('text/html' === $message->getBodyContentType()) {
            $message->setBody($this->inlineCss($message->getBody()), 'text/html');
        }

        // Inline any HTML child parts
        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_MimePart && 'text/html' === $child->getContentType()) {
                $child->setBody($this->inlineCss($child->getBody()), 'text/html');
            }
        }
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        // No-op — required by interface
    }

    private function inlineCss(string $html): string
    {
        $inliner = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();

        return $inliner->convert($html);
    }
}
```

**Step 4: Update `composer.json`**

Add to `suggest`:
```json
"tijsverkoyen/css-to-inline-styles": "For automatic CSS inlining in HTML emails (Swift_Plugins_CssInlinerPlugin)"
```

**Step 5: Install the suggested package for testing**

Run: `composer require --dev tijsverkoyen/css-to-inline-styles`

**Step 6: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Plugins/CssInlinerPluginTest.php --verbose`
Expected: PASS.

**Step 7: Commit**

```
feat: add CssInlinerPlugin for automatic CSS inlining in HTML emails
```

---

## Phase 5: Update Existing Transports with Tag/Metadata Support

### Task 15: Add Tag/Metadata to Existing HTTP API Transports

Each existing transport in `lib/classes/Swift/Transport/Api/` needs its `doSend()` updated to call `extractTags()` and `extractMetadata()` and map them to the provider's native format.

**Provider-specific mappings:**

| Transport | Tag field | Metadata field |
|-|-|-|
| SendGrid | `categories` (array) | `custom_args` (object) |
| Mailgun | `o:tag` (multiple headers) | `v:key` (variable params) |
| PostMark | `Tag` (single string) | `Metadata` (object) |
| Brevo | `tags` (array) | `headers` (custom X- headers) |
| Resend | `tags` (array of `{name,value}`) | `headers` |
| MailPace | `tags` (object/array) | `metadata` (object) |
| MailerSend | `tags` (array) | `personalization[].data` |
| Mailjet | `CustomCampaign` (single) | `Properties` (object) |
| Infobip | — (not supported) | — |
| Amazon SES API | `Tags` (array of `{Name,Value}`) | — |
| Amazon SES HTTP | `Tags` (array of `{Name,Value}`) | — |
| Azure | — (not supported) | — |
| Google | `X-Gm-Message-State` (limited) | — |
| Microsoft Graph | `internetMessageHeaders` | `internetMessageHeaders` |
| MailChimp/Mandrill | `tags` (array) | `metadata` (object) |
| Scaleway | `tags` (string[]) | additional `headers` |

**Approach per transport:**
1. Write a test asserting tags/metadata appear in the API payload
2. Update `doSend()` to call `extractTags()`/`extractMetadata()` and map to provider format
3. Run test

Do this as a batch — one commit per 2-3 transports to keep diffs manageable.

**Commits:**
```
feat: add tag/metadata support to SendGrid, Mailgun, PostMark transports
feat: add tag/metadata support to Brevo, Resend, MailPace transports
feat: add tag/metadata support to MailerSend, Mailjet, Mandrill transports
feat: add tag/metadata support to Amazon SES, Scaleway, Microsoft Graph transports
```

---

## Phase 6: Final Integration

### Task 16: Integration Smoke Tests

**Files:**
- Create: `tests/unit/Swift/Integration/DsnTransportFactoryIntegrationTest.php`
- Create: `tests/unit/Swift/Integration/SentMessageFlowTest.php`

Write end-to-end tests using `NullTransport` and mocked HTTP clients to verify:
1. DSN string → Transport construction → send → SentMessageEvent fires → SentMessagePlugin captures it
2. Failover DSN with one failing transport falls over correctly and fires FailedMessageEvent then SentMessageEvent
3. Tag/metadata headers are stripped from the final message (don't leak to recipients)

**Commit:**
```
test: add integration tests for DSN factory, SentMessage flow, and tag stripping
```

---

### Task 17: Full Test Suite Pass

Run: `vendor/bin/simple-phpunit --verbose`

Fix any regressions. Ensure all four suites (unit, acceptance, bug, smoke) pass.

**Commit (if needed):**
```
fix: resolve test regressions from feature parity changes
```

---

## Summary

| Phase | Tasks | What |
|-|-|-|
| 1 | 1–6 | Core infrastructure: SentMessage, events, tags, logger |
| 2 | 7–8 | DSN: null/smtp schemes, DsnTransportFactory, failover/roundrobin DSN |
| 3 | 9–13 | 5 new transports: AhaSend, Mailomat, Mailtrap, Postal, Sweego |
| 4 | 14 | CSS Inliner Plugin |
| 5 | 15 | Tag/metadata support in all 16 existing transports |
| 6 | 16–17 | Integration tests, full suite pass |

Total: 17 tasks, ~30-40 commits on the feature branch.

Security hardening is a separate branch — not covered in this plan.
