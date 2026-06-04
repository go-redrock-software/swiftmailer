# HTTP API Transports Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement all 13 stubbed API transports using direct HTTP calls (no provider SDKs), wire them into the DSN system, and add unit tests.

**Architecture:** A new `Swift_Transport_AbstractHttpApiTransport` base class handles Guzzle client management, start/stop/ping lifecycle with event dispatching, and the common send() flow (auto-start, pre-send events, delegate to abstract `doSend()`, post-send events, error handling). Each concrete transport only implements provider-specific payload building, endpoint/auth configuration, and response parsing.

**Tech Stack:** PHP 8.1+, GuzzleHttp 7.x (HTTP client), existing Swift event system, PSR-0 autoloading with `Swift_` prefix.

---

## Naming Issues to Fix

Before starting, note two pre-existing naming problems:

1. **MailGunTransport**: Class is `Swift_Transport_ApiMailGunTransport` (missing underscore before `Api`). PSR-0 expects `Swift_Transport_Api_MailGunTransport`. The file is correctly at `Api/MailGunTransport.php`. Fix: rename the class.

2. **AmazonSesApiTransport.php vs AmazonSesSmtpTransport.php**: `AmazonSesApiTransport.php` contains class `Swift_Transport_Api_AmazonSesSmtpTransport` (fully implemented). `AmazonSesSmtpTransport.php` is a stub with the same class name. The stub shadows the real implementation. Fix: delete the stub, rename the implemented file's class to match its filename, or consolidate.

---

## Task 1: Add Guzzle Dependency

**Files:**
- Modify: `composer.json`

**Step 1: Add guzzlehttp/guzzle to require**

Add `"guzzlehttp/guzzle": "^7.0"` to the `require` section of `composer.json`. Guzzle is already a transitive dependency (via microsoft/microsoft-graph), this makes it explicit.

**Step 2: Run composer update**

Run: `composer require guzzlehttp/guzzle:^7.0`
Expected: Resolves successfully (already installed transitively).

**Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat: add explicit guzzle dependency for HTTP API transports"
```

---

## Task 2: Create AbstractHttpApiTransport

**Files:**
- Create: `lib/classes/Swift/Transport/AbstractHttpApiTransport.php`
- Test: `tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Swift\Transport;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;

class Swift_Transport_AbstractHttpApiTransportTest extends TestCase
{
    private $httpClientMock;
    private $eventDispatcherMock;
    private $transport;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new class(
            'test-api-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        ) extends \Swift_Transport_AbstractHttpApiTransport {
            protected function doSend(\Swift_Mime_SimpleMessage $message): array
            {
                return ['message_id' => 'test-123', 'recipients' => 1];
            }

            protected function getEndpoint(): string
            {
                return 'https://api.test.com/send';
            }

            protected function getAuthHeaders(): array
            {
                return ['Authorization' => 'Bearer ' . $this->apiKey];
            }

            protected function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
            {
                return json_decode($response->getBody()->getContents(), true);
            }

            protected function getPingEndpoint(): string
            {
                return 'https://api.test.com/ping';
            }
        };
    }

    public function testIsNotStartedByDefault(): void
    {
        $this->assertFalse($this->transport->isStarted());
    }

    public function testStartSetsStartedState(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);
        $this->eventDispatcherMock->expects($this->exactly(2))->method('dispatchEvent');

        $this->transport->start();
        $this->assertTrue($this->transport->isStarted());
    }

    public function testStartDoesNothingIfAlreadyStarted(): void
    {
        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->transport->start();
        $this->transport->start(); // second call should be no-op
        $this->assertTrue($this->transport->isStarted());
    }

    public function testPingReturnsTrueOnSuccessfulResponse(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->method('request')->willReturn($response);

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertTrue($this->transport->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException('fail', new \GuzzleHttp\Psr7\Request('GET', '/')));

        $evt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $this->eventDispatcherMock->method('createTransportChangeEvent')->willReturn($evt);

        $this->assertFalse($this->transport->ping());
    }

    public function testGetApiConnectionReturnsHttpClient(): void
    {
        $reflection = new \ReflectionMethod($this->transport, 'getApiConnection');
        $reflection->setAccessible(true);
        $this->assertSame($this->httpClientMock, $reflection->invoke($this->transport));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php -v`
Expected: FAIL — class `Swift_Transport_AbstractHttpApiTransport` not found.

**Step 3: Write the implementation**

```php
<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Abstract base class for HTTP API-based transports.
 *
 * Provides common lifecycle management (start/stop/ping), event dispatching,
 * and HTTP client handling. Concrete transports implement provider-specific
 * payload building, endpoint configuration, and response parsing.
 */
abstract class Swift_Transport_AbstractHttpApiTransport extends Swift_Transport_AbstractApiTransport
{
    protected string $apiKey;

    protected ClientInterface $httpClient;

    public function __construct(
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        $this->apiKey = $apiKey;
        $this->httpClient = $httpClient ?? new Client();
        $this->eventDispatcher = $eventDispatcher;
    }

    public function start(): void
    {
        if (!$this->started) {
            if ($evt = $this->eventDispatcher?->createTransportChangeEvent($this)) {
                $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStarted');
                if ($evt->bubbleCancelled()) {
                    return;
                }
            }

            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
            }

            $this->started = true;
        }
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $response = $this->httpClient->request('GET', $this->getPingEndpoint(), [
                'headers' => $this->getAuthHeaders(),
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        if (null === $failedRecipients) {
            $failedRecipients = [];
        }

        if (!$this->isStarted()) {
            $this->start();
        }

        if ($evt = $this->eventDispatcher?->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        try {
            $result = $this->doSend($message);

            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            }

            $recipientCount = $result['recipients'] ?? $this->countRecipients($message);

            return $recipientCount;
        } catch (\Exception $e) {
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->setFailedRecipients($this->collectRecipients($message));
            }

            $failedRecipients = array_merge($failedRecipients, $this->collectRecipients($message));

            $this->throwException(new Swift_TransportException(
                'Failed to send email via ' . static::class . ': ' . $e->getMessage(),
                0,
                $e,
            ));

            return 0;
        } finally {
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }
        }
    }

    protected function getApiConnection(): ClientInterface
    {
        return $this->httpClient;
    }

    /**
     * Send the message via the provider's HTTP API.
     *
     * @return array{message_id?: string, recipients?: int} Result data
     */
    abstract protected function doSend(Swift_Mime_SimpleMessage $message): array;

    /**
     * Get the API endpoint URL for sending email.
     */
    abstract protected function getEndpoint(): string;

    /**
     * Get the authentication headers for API requests.
     */
    abstract protected function getAuthHeaders(): array;

    /**
     * Parse the API response.
     */
    abstract protected function parseResponse(ResponseInterface $response): array;

    /**
     * Get the API endpoint URL for ping/health check.
     */
    abstract protected function getPingEndpoint(): string;

    /**
     * Count total recipients on a message.
     */
    protected function countRecipients(Swift_Mime_SimpleMessage $message): int
    {
        return count($message->getTo() ?? [])
            + count($message->getCc() ?? [])
            + count($message->getBcc() ?? []);
    }

    /**
     * Collect all recipient addresses from a message.
     */
    protected function collectRecipients(Swift_Mime_SimpleMessage $message): array
    {
        $recipients = [];
        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            foreach ($message->$method() ?? [] as $address => $name) {
                $recipients[] = $address;
            }
        }

        return $recipients;
    }

    /**
     * Format a SwiftMailer address array entry as "Name <email>" or just "email".
     */
    protected function formatAddress(string $email, ?string $name = null): string
    {
        if ($name) {
            return sprintf('%s <%s>', $name, $email);
        }

        return $email;
    }

    /**
     * Format all addresses from a SwiftMailer address array.
     */
    protected function formatAddresses(array $addresses): array
    {
        $formatted = [];
        foreach ($addresses as $email => $name) {
            $formatted[] = $this->formatAddress($email, $name);
        }

        return $formatted;
    }

    /**
     * Get attachments from the message as an array of arrays with keys:
     * 'filename', 'content', 'contentType', 'disposition', 'contentId'.
     */
    protected function getMessageAttachments(Swift_Mime_SimpleMessage $message): array
    {
        $attachments = [];
        foreach ($message->getChildren() ?? [] as $child) {
            if ($child instanceof Swift_Attachment || $child instanceof Swift_Image) {
                $attachments[] = [
                    'filename' => $child->getFilename(),
                    'content' => $child->getBody(),
                    'contentType' => $child->getContentType(),
                    'disposition' => $child->getDisposition(),
                    'contentId' => $child->getId(),
                ];
            }
        }

        return $attachments;
    }

    /**
     * Get text and HTML parts from a message.
     *
     * @return array{text: ?string, html: ?string}
     */
    protected function getMessageBody(Swift_Mime_SimpleMessage $message): array
    {
        $body = $message->getBody();
        $contentType = $message->getBodyContentType();
        $text = null;
        $html = null;

        if ('text/html' === $contentType) {
            $html = $body;
        } else {
            $text = $body;
        }

        // Check children for alternative parts
        foreach ($message->getChildren() ?? [] as $child) {
            if ($child instanceof Swift_MimePart) {
                if ('text/html' === $child->getContentType()) {
                    $html = $child->getBody();
                } elseif ('text/plain' === $child->getContentType()) {
                    $text = $child->getBody();
                }
            }
        }

        return ['text' => $text, 'html' => $html];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/AbstractHttpApiTransport.php tests/unit/Swift/Transport/AbstractHttpApiTransportTest.php
git commit -m "feat: add AbstractHttpApiTransport base class for HTTP-based transports"
```

---

## Task 3: Fix MailGun Class Name

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MailGunTransport.php`

**Step 1: Rename class from `Swift_Transport_ApiMailGunTransport` to `Swift_Transport_Api_MailGunTransport`**

Just fix the class declaration line. The file path is already correct.

**Step 2: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MailGunTransport.php
git commit -m "fix: correct MailGun transport class name to match PSR-0 convention"
```

---

## Task 4: Fix AmazonSes Naming Conflict

**Files:**
- Delete: `lib/classes/Swift/Transport/Api/AmazonSesSmtpTransport.php` (the stub)
- Modify: `lib/classes/Swift/Transport/Api/AmazonSesApiTransport.php` (rename class to match file)

**Step 1: Delete the stub file**

The stub `AmazonSesSmtpTransport.php` shadows the real implementation in `AmazonSesApiTransport.php` (which incorrectly declares class `Swift_Transport_Api_AmazonSesSmtpTransport`).

Delete `lib/classes/Swift/Transport/Api/AmazonSesSmtpTransport.php`.

**Step 2: Rename class in AmazonSesApiTransport.php**

Change `class Swift_Transport_Api_AmazonSesSmtpTransport` to `class Swift_Transport_Api_AmazonSesApiTransport` in `AmazonSesApiTransport.php`.

**Step 3: Commit**

```bash
git rm lib/classes/Swift/Transport/Api/AmazonSesSmtpTransport.php
git add lib/classes/Swift/Transport/Api/AmazonSesApiTransport.php
git commit -m "fix: resolve AmazonSes transport naming conflict, align class name with file"
```

---

## Task 5: Implement SendgridTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/SendgridTransport.php`
- Test: `tests/unit/Swift/Transport/Api/SendgridTransportTest.php`

**Step 1: Write the test**

```php
<?php

namespace Swift\Transport\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Transport_Api_SendgridTransportTest extends TestCase
{
    private $httpClientMock;
    private $eventDispatcherMock;
    private $transport;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->eventDispatcherMock = $this->createMock(\Swift_Events_EventDispatcher::class);

        $this->transport = new \Swift_Transport_Api_SendgridTransport(
            'test-sendgrid-key',
            $this->httpClientMock,
            $this->eventDispatcherMock,
        );
    }

    public function testSendBasicMessage(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('Hello', 'text/plain');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.sendgrid.com/v3/mail/send',
                $this->callback(function ($options) {
                    $payload = json_decode($options['body'], true);
                    return $payload['from']['email'] === 'from@example.com'
                        && $payload['personalizations'][0]['to'][0]['email'] === 'to@example.com'
                        && $payload['subject'] === 'Test'
                        && $payload['content'][0]['value'] === 'Hello';
                }),
            )
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message);
        $this->assertEquals(1, $result);
    }

    public function testSendWithCcBcc(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setCc(['cc@example.com' => 'CC']);
        $message->setBcc(['bcc@example.com' => 'BCC']);
        $message->setSubject('Test');
        $message->setBody('Hello');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = json_decode($options['body'], true);
                return isset($payload['personalizations'][0]['cc'])
                    && isset($payload['personalizations'][0]['bcc']);
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $result = $this->transport->send($message);
        $this->assertEquals(3, $result);
    }

    public function testSendWithHtmlBody(): void
    {
        $message = $this->createSwiftMessage();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Test');
        $message->setBody('<p>Hello</p>', 'text/html');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                $payload = json_decode($options['body'], true);
                $types = array_column($payload['content'], 'type');
                return in_array('text/html', $types);
            }))
            ->willReturn(new Response(202));

        $evt = $this->createMock(\Swift_Events_SendEvent::class);
        $this->eventDispatcherMock->method('createSendEvent')->willReturn($evt);
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->transport->send($message);
    }

    public function testPingSuccess(): void
    {
        $this->httpClientMock->method('request')->willReturn(new Response(200, [], '{"scopes":[]}'));
        $this->eventDispatcherMock->method('createTransportChangeEvent')
            ->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $this->assertTrue($this->transport->ping());
    }

    private function createSwiftMessage(): \Swift_Mime_SimpleMessage
    {
        return new \Swift_Mime_SimpleMessage(
            new \Swift_Mime_SimpleHeaderSet(
                new \Swift_Mime_SimpleHeaderFactory(
                    new \Swift_Mime_HeaderEncoder_Base64HeaderEncoder(),
                    new \Swift_Mime_ContentEncoder_Base64ContentEncoder(),
                    new \Egulias\EmailValidator\EmailValidator(),
                ),
            ),
            new \Swift_Mime_ContentEncoder_Base64ContentEncoder(),
            new \Swift_KeyCache_ArrayKeyCache(new \Swift_KeyCache_SimpleKeyCacheInputStream()),
            new \Swift_Mime_IdGenerator('example.com'),
        );
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/SendgridTransportTest.php -v`
Expected: FAIL

**Step 3: Write the implementation**

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_SendgridTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.sendgrid.com';

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $body = json_decode($response->getBody()->getContents(), true);
            $errorMsg = $body['errors'][0]['message'] ?? 'Unknown SendGrid error';
            throw new Swift_TransportException('SendGrid API error: ' . $errorMsg);
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/v3/mail/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/v3/scopes';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);
        $fromName = $from[$fromEmail];

        $personalization = [];

        // To
        $personalization['to'] = [];
        foreach ($message->getTo() as $email => $name) {
            $personalization['to'][] = array_filter(['email' => $email, 'name' => $name]);
        }

        // CC
        if ($cc = $message->getCc()) {
            $personalization['cc'] = [];
            foreach ($cc as $email => $name) {
                $personalization['cc'][] = array_filter(['email' => $email, 'name' => $name]);
            }
        }

        // BCC
        if ($bcc = $message->getBcc()) {
            $personalization['bcc'] = [];
            foreach ($bcc as $email => $name) {
                $personalization['bcc'][] = array_filter(['email' => $email, 'name' => $name]);
            }
        }

        $payload = [
            'personalizations' => [$personalization],
            'from' => array_filter(['email' => $fromEmail, 'name' => $fromName]),
            'subject' => $message->getSubject(),
        ];

        // Reply-To
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $payload['reply_to'] = array_filter(['email' => $replyEmail, 'name' => $replyTo[$replyEmail]]);
        }

        // Content (text and/or html)
        $body = $this->getMessageBody($message);
        $payload['content'] = [];
        if ($body['text']) {
            $payload['content'][] = ['type' => 'text/plain', 'value' => $body['text']];
        }
        if ($body['html']) {
            $payload['content'][] = ['type' => 'text/html', 'value' => $body['html']];
        }

        // Attachments
        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = array_filter([
                    'content' => base64_encode($att['content']),
                    'type' => $att['contentType'],
                    'filename' => $att['filename'],
                    'disposition' => $att['disposition'],
                    'content_id' => 'inline' === $att['disposition'] ? $att['contentId'] : null,
                ]);
            }
        }

        return $payload;
    }
}
```

**Step 4: Run test**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/SendgridTransportTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/Api/SendgridTransport.php tests/unit/Swift/Transport/Api/SendgridTransportTest.php
git commit -m "feat: implement SendGrid HTTP API transport"
```

---

## Task 6: Implement PostMarkTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/PostMarkTransport.php`
- Test: `tests/unit/Swift/Transport/Api/PostMarkTransportTest.php`

**Step 1: Write test** (follows same pattern as SendGrid — assert payload structure, assert auth header)

Test should verify:
- Auth header is `X-Postmark-Server-Token`
- Payload fields: `From`, `To`, `Subject`, `TextBody`, `HtmlBody`, `Cc`, `Bcc`, `ReplyTo`, `Attachments`
- Endpoint: `https://api.postmarkapp.com/email`
- Ping endpoint: `https://api.postmarkapp.com/server`
- Success on HTTP 200

**Step 2: Write the implementation**

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_PostMarkTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.postmarkapp.com';

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $result = $this->parseResponse($response);
        if (($result['ErrorCode'] ?? -1) !== 0) {
            throw new Swift_TransportException('Postmark API error: ' . ($result['Message'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/email';
    }

    protected function getAuthHeaders(): array
    {
        return ['X-Postmark-Server-Token' => $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/server';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'From' => $this->formatAddress($fromEmail, $from[$fromEmail]),
            'To' => implode(', ', $this->formatAddresses($message->getTo())),
            'Subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['Cc'] = implode(', ', $this->formatAddresses($cc));
        }

        if ($bcc = $message->getBcc()) {
            $payload['Bcc'] = implode(', ', $this->formatAddresses($bcc));
        }

        if ($replyTo = $message->getReplyTo()) {
            $payload['ReplyTo'] = $this->formatAddress(array_key_first($replyTo), $replyTo[array_key_first($replyTo)]);
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['TextBody'] = $body['text'];
        }
        if ($body['html']) {
            $payload['HtmlBody'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['Attachments'] = [];
            foreach ($attachments as $att) {
                $entry = [
                    'Name' => $att['filename'],
                    'Content' => base64_encode($att['content']),
                    'ContentType' => $att['contentType'],
                ];
                if ('inline' === $att['disposition']) {
                    $entry['ContentID'] = 'cid:' . $att['contentId'];
                }
                $payload['Attachments'][] = $entry;
            }
        }

        return $payload;
    }
}
```

**Step 3: Run tests, commit**

```bash
git add lib/classes/Swift/Transport/Api/PostMarkTransport.php tests/unit/Swift/Transport/Api/PostMarkTransportTest.php
git commit -m "feat: implement Postmark HTTP API transport"
```

---

## Task 7: Implement BrevoTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/BrevoTransport.php`
- Test: `tests/unit/Swift/Transport/Api/BrevoTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://api.brevo.com/v3/smtp/email`
- Auth: `api-key: {KEY}`
- Ping: `GET https://api.brevo.com/v3/account`
- Success: HTTP 201
- Payload keys: `sender` (object), `to`/`cc`/`bcc` (arrays of objects), `subject`, `htmlContent`, `textContent`, `replyTo` (object), `attachment` (array)

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_BrevoTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.brevo.com';

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('Brevo API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/v3/smtp/email';
    }

    protected function getAuthHeaders(): array
    {
        return ['api-key' => $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/v3/account';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'sender' => array_filter(['email' => $fromEmail, 'name' => $from[$fromEmail]]),
            'to' => $this->mapAddresses($message->getTo()),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->mapAddresses($cc);
        }
        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->mapAddresses($bcc);
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $payload['replyTo'] = array_filter(['email' => $replyEmail, 'name' => $replyTo[$replyEmail]]);
        }

        $body = $this->getMessageBody($message);
        if ($body['html']) {
            $payload['htmlContent'] = $body['html'];
        }
        if ($body['text']) {
            $payload['textContent'] = $body['text'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachment'] = [];
            foreach ($attachments as $att) {
                $payload['attachment'][] = [
                    'name' => $att['filename'],
                    'content' => base64_encode($att['content']),
                ];
            }
        }

        return $payload;
    }

    private function mapAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = array_filter(['email' => $email, 'name' => $name]);
        }
        return $mapped;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/BrevoTransport.php tests/unit/Swift/Transport/Api/BrevoTransportTest.php
git commit -m "feat: implement Brevo HTTP API transport"
```

---

## Task 8: Implement ResendTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/ResendTransport.php`
- Test: `tests/unit/Swift/Transport/Api/ResendTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://api.resend.com/emails`
- Auth: `Authorization: Bearer {KEY}`
- Ping: `GET https://api.resend.com/api-keys`
- Success: HTTP 200 with `{"id": "..."}`
- Payload keys: `from` (string "Name <email>"), `to`/`cc`/`bcc` (arrays of strings), `subject`, `html`, `text`, `reply_to` (string), `attachments` (array)

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_ResendTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.resend.com';

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('Resend API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/emails';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/api-keys';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'from' => $this->formatAddress($fromEmail, $from[$fromEmail]),
            'to' => $this->formatAddresses($message->getTo()),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->formatAddresses($cc);
        }
        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->formatAddresses($bcc);
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $payload['reply_to'] = $this->formatAddress($replyEmail, $replyTo[$replyEmail]);
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['text'] = $body['text'];
        }
        if ($body['html']) {
            $payload['html'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = [
                    'filename' => $att['filename'],
                    'content' => base64_encode($att['content']),
                ];
            }
        }

        return $payload;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/ResendTransport.php tests/unit/Swift/Transport/Api/ResendTransportTest.php
git commit -m "feat: implement Resend HTTP API transport"
```

---

## Task 9: Implement MailerSendTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MailerSendTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailerSendTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://api.mailersend.com/v1/email`
- Auth: `Authorization: Bearer {KEY}`
- Ping: `GET https://api.mailersend.com/v1/api-quota`
- Success: HTTP 202 (empty body, message ID in `x-message-id` header)
- Payload uses object format for addresses: `{"email": "...", "name": "..."}`

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_MailerSendTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.mailersend.com';

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('MailerSend API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/v1/email';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        $body = $response->getBody()->getContents();
        return $body ? (json_decode($body, true) ?? []) : [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/v1/api-quota';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'from' => array_filter(['email' => $fromEmail, 'name' => $from[$fromEmail]]),
            'to' => $this->mapAddresses($message->getTo()),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = $this->mapAddresses($cc);
        }
        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = $this->mapAddresses($bcc);
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $payload['reply_to'] = array_filter(['email' => $replyEmail, 'name' => $replyTo[$replyEmail]]);
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['text'] = $body['text'];
        }
        if ($body['html']) {
            $payload['html'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = [
                    'content' => base64_encode($att['content']),
                    'filename' => $att['filename'],
                    'disposition' => $att['disposition'] ?? 'attachment',
                ];
            }
        }

        return $payload;
    }

    private function mapAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = array_filter(['email' => $email, 'name' => $name]);
        }
        return $mapped;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/MailerSendTransport.php tests/unit/Swift/Transport/Api/MailerSendTransportTest.php
git commit -m "feat: implement MailerSend HTTP API transport"
```

---

## Task 10: Implement MailPaceTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MailPaceTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailPaceTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://app.mailpace.com/api/v1/send`
- Auth: `MailPace-Server-Token: {TOKEN}`
- Ping: send a request to the endpoint; no dedicated health endpoint. Override `ping()` to return true.
- Success: HTTP 200 with `{"id": ..., "status": "pending"}`
- Payload uses string addresses, comma-separated. Fields: `from`, `to`, `cc`, `bcc`, `subject`, `textbody`, `htmlbody`, `replyto`, `attachments`

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_MailPaceTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://app.mailpace.com';

    public function ping(): bool
    {
        // MailPace has no dedicated health endpoint
        if (!$this->isStarted()) {
            $this->start();
        }
        return true;
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('MailPace API error: ' . ($result['error'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/api/v1/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['MailPace-Server-Token' => $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        // Not used — ping() is overridden
        return self::HOST . '/api/v1/send';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'from' => $this->formatAddress($fromEmail, $from[$fromEmail]),
            'to' => implode(', ', $this->formatAddresses($message->getTo())),
            'subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['cc'] = implode(', ', $this->formatAddresses($cc));
        }
        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = implode(', ', $this->formatAddresses($bcc));
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $payload['replyto'] = $this->formatAddress($replyEmail, $replyTo[$replyEmail]);
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['textbody'] = $body['text'];
        }
        if ($body['html']) {
            $payload['htmlbody'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = [
                    'name' => $att['filename'],
                    'content_type' => $att['contentType'],
                    'content' => base64_encode($att['content']),
                ];
            }
        }

        return $payload;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/MailPaceTransport.php tests/unit/Swift/Transport/Api/MailPaceTransportTest.php
git commit -m "feat: implement MailPace HTTP API transport"
```

---

## Task 11: Implement MailGunTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MailGunTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailGunTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://api.mailgun.net/v3/{domain}/messages`
- Auth: HTTP Basic Auth — `Authorization: Basic base64("api:{key}")`
- Ping: `GET https://api.mailgun.net/v3/domains/{domain}`
- Success: HTTP 200 with `{"id": "...", "message": "Queued."}`
- **Uses multipart/form-data**, not JSON
- Constructor takes `apiKey` and `domain` parameters
- EU region support via optional `host` parameter

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_MailGunTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.mailgun.net';

    private string $domain;
    private string $host;

    public function __construct(
        string $apiKey,
        string $domain,
        string $host = self::HOST,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        $this->domain = $domain;
        $this->host = $host;
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $formData = $this->getFormData($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => $this->getAuthHeaders(),
            'multipart' => $formData,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('Mailgun API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return $this->host . '/v3/' . $this->domain . '/messages';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('api:' . $this->apiKey)];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return $this->host . '/v3/domains/' . $this->domain;
    }

    private function getFormData(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $fields = [
            ['name' => 'from', 'contents' => $this->formatAddress($fromEmail, $from[$fromEmail])],
            ['name' => 'to', 'contents' => implode(', ', $this->formatAddresses($message->getTo()))],
            ['name' => 'subject', 'contents' => $message->getSubject()],
        ];

        if ($cc = $message->getCc()) {
            $fields[] = ['name' => 'cc', 'contents' => implode(', ', $this->formatAddresses($cc))];
        }
        if ($bcc = $message->getBcc()) {
            $fields[] = ['name' => 'bcc', 'contents' => implode(', ', $this->formatAddresses($bcc))];
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $fields[] = ['name' => 'h:Reply-To', 'contents' => $this->formatAddress($replyEmail, $replyTo[$replyEmail])];
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $fields[] = ['name' => 'text', 'contents' => $body['text']];
        }
        if ($body['html']) {
            $fields[] = ['name' => 'html', 'contents' => $body['html']];
        }

        foreach ($this->getMessageAttachments($message) as $att) {
            $fields[] = [
                'name' => 'inline' === $att['disposition'] ? 'inline' : 'attachment',
                'contents' => $att['content'],
                'filename' => $att['filename'],
                'headers' => ['Content-Type' => $att['contentType']],
            ];
        }

        return $fields;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/MailGunTransport.php tests/unit/Swift/Transport/Api/MailGunTransportTest.php
git commit -m "feat: implement Mailgun HTTP API transport"
```

---

## Task 12: Implement MailJetTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MailJetTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailJetTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://api.mailjet.com/v3.1/send`
- Auth: HTTP Basic Auth — `Authorization: Basic base64("{publicKey}:{privateKey}")`
- Constructor takes both `publicKey` and `privateKey`
- Ping: `GET https://api.mailjet.com/v3/REST/apikey`
- Success: HTTP 200 with `Messages[].Status === "success"`
- Payload wraps in `{"Messages": [...]}`; address fields: `Email`, `Name`; body: `TextPart`, `HTMLPart`

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_MailJetTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.mailjet.com';

    private string $privateKey;

    public function __construct(
        string $publicKey,
        string $privateKey,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($publicKey, $httpClient, $eventDispatcher);
        $this->privateKey = $privateKey;
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = ['Messages' => [$this->getPayload($message)]];

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $result = $this->parseResponse($response);
        $status = $result['Messages'][0]['Status'] ?? 'error';
        if ('success' !== $status) {
            $errors = $result['Messages'][0]['Errors'] ?? [];
            $errorMsg = !empty($errors) ? $errors[0]['ErrorMessage'] : 'Unknown Mailjet error';
            throw new Swift_TransportException('Mailjet API error: ' . $errorMsg);
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/v3.1/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($this->apiKey . ':' . $this->privateKey)];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/v3/REST/apikey';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'From' => array_filter(['Email' => $fromEmail, 'Name' => $from[$fromEmail]]),
            'To' => $this->mapAddresses($message->getTo()),
            'Subject' => $message->getSubject(),
        ];

        if ($cc = $message->getCc()) {
            $payload['Cc'] = $this->mapAddresses($cc);
        }
        if ($bcc = $message->getBcc()) {
            $payload['Bcc'] = $this->mapAddresses($bcc);
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $payload['ReplyTo'] = array_filter(['Email' => $replyEmail, 'Name' => $replyTo[$replyEmail]]);
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['TextPart'] = $body['text'];
        }
        if ($body['html']) {
            $payload['HTMLPart'] = $body['html'];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['Attachments'] = [];
            foreach ($attachments as $att) {
                $entry = [
                    'ContentType' => $att['contentType'],
                    'Filename' => $att['filename'],
                    'Base64Content' => base64_encode($att['content']),
                ];
                if ('inline' === $att['disposition']) {
                    $entry['ContentID'] = $att['contentId'];
                }
                $payload['Attachments'][] = $entry;
            }
        }

        return $payload;
    }

    private function mapAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = array_filter(['Email' => $email, 'Name' => $name]);
        }
        return $mapped;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/MailJetTransport.php tests/unit/Swift/Transport/Api/MailJetTransportTest.php
git commit -m "feat: implement Mailjet HTTP API transport"
```

---

## Task 13: Implement ScalewayTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/ScalewayTransport.php`
- Test: `tests/unit/Swift/Transport/Api/ScalewayTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://api.scaleway.com/transactional-email/v1alpha1/regions/{region}/emails`
- Auth: `X-Auth-Token: {SECRET_KEY}`
- Constructor takes `apiKey`, `region` (default "fr-par"), and `projectId`
- Ping: `GET .../domains`
- Success: HTTP 200
- Payload: `from` (object), `to` (array of objects), `subject`, `text`, `html`, `project_id`, `attachments`, `additional_headers` (for cc, reply-to)

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_ScalewayTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.scaleway.com';

    private string $region;
    private string $projectId;

    public function __construct(
        string $apiKey,
        string $projectId,
        string $region = 'fr-par',
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        $this->projectId = $projectId;
        $this->region = $region;
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'body' => json_encode($payload),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('Scaleway API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/transactional-email/v1alpha1/regions/' . $this->region . '/emails';
    }

    protected function getAuthHeaders(): array
    {
        return ['X-Auth-Token' => $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST . '/transactional-email/v1alpha1/regions/' . $this->region . '/domains';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $allTo = [];
        foreach ($message->getTo() as $email => $name) {
            $allTo[] = array_filter(['email' => $email, 'name' => $name]);
        }

        $payload = [
            'from' => array_filter(['email' => $fromEmail, 'name' => $from[$fromEmail]]),
            'to' => $allTo,
            'subject' => $message->getSubject(),
            'project_id' => $this->projectId,
        ];

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['text'] = $body['text'];
        }
        if ($body['html']) {
            $payload['html'] = $body['html'];
        }

        $additionalHeaders = [];

        if ($cc = $message->getCc()) {
            $additionalHeaders[] = ['key' => 'Cc', 'value' => implode(', ', $this->formatAddresses($cc))];
            // Also add CC recipients to the to array for delivery
            foreach ($cc as $email => $name) {
                $allTo[] = array_filter(['email' => $email, 'name' => $name]);
            }
            $payload['to'] = $allTo;
        }

        if ($bcc = $message->getBcc()) {
            // BCC recipients added to delivery list only, no header
            foreach ($bcc as $email => $name) {
                $allTo[] = array_filter(['email' => $email, 'name' => $name]);
            }
            $payload['to'] = $allTo;
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $additionalHeaders[] = [
                'key' => 'Reply-To',
                'value' => $this->formatAddress($replyEmail, $replyTo[$replyEmail]),
            ];
        }

        if (!empty($additionalHeaders)) {
            $payload['additional_headers'] = $additionalHeaders;
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = [
                    'name' => $att['filename'],
                    'type' => $att['contentType'],
                    'content' => base64_encode($att['content']),
                ];
            }
        }

        return $payload;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/ScalewayTransport.php tests/unit/Swift/Transport/Api/ScalewayTransportTest.php
git commit -m "feat: implement Scaleway HTTP API transport"
```

---

## Task 14: Implement InfoBipTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/InfoBipTransport.php`
- Test: `tests/unit/Swift/Transport/Api/InfoBipTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://{baseUrl}/email/3/send`
- Auth: `Authorization: App {KEY}`
- Constructor takes `apiKey` and `baseUrl` (account-specific, e.g. `xxxxx.api.infobip.com`)
- Ping: `GET https://{baseUrl}/email/1/domains`
- Success: HTTP 200, check `messages[0].status.groupName === "PENDING"`
- **Uses multipart/form-data**

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_InfoBipTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private string $baseUrl;

    public function __construct(
        string $apiKey,
        string $baseUrl,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        parent::__construct($apiKey, $httpClient, $eventDispatcher);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $formData = $this->getFormData($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => $this->getAuthHeaders(),
            'multipart' => $formData,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            throw new Swift_TransportException('Infobip API error: ' . json_encode($result));
        }

        $result = $this->parseResponse($response);
        $groupName = $result['messages'][0]['status']['groupName'] ?? '';
        if ('PENDING' !== $groupName) {
            throw new Swift_TransportException('Infobip API error: message status is ' . $groupName);
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return 'https://' . $this->baseUrl . '/email/3/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'App ' . $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://' . $this->baseUrl . '/email/1/domains';
    }

    private function getFormData(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $fields = [
            ['name' => 'from', 'contents' => $this->formatAddress($fromEmail, $from[$fromEmail])],
            ['name' => 'subject', 'contents' => $message->getSubject()],
        ];

        foreach ($message->getTo() as $email => $name) {
            $fields[] = ['name' => 'to', 'contents' => $this->formatAddress($email, $name)];
        }

        if ($cc = $message->getCc()) {
            foreach ($cc as $email => $name) {
                $fields[] = ['name' => 'cc', 'contents' => $this->formatAddress($email, $name)];
            }
        }
        if ($bcc = $message->getBcc()) {
            foreach ($bcc as $email => $name) {
                $fields[] = ['name' => 'bcc', 'contents' => $this->formatAddress($email, $name)];
            }
        }
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $fields[] = ['name' => 'replyTo', 'contents' => $this->formatAddress($replyEmail, $replyTo[$replyEmail])];
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $fields[] = ['name' => 'text', 'contents' => $body['text']];
        }
        if ($body['html']) {
            $fields[] = ['name' => 'html', 'contents' => $body['html']];
        }

        foreach ($this->getMessageAttachments($message) as $att) {
            $fields[] = [
                'name' => 'inline' === $att['disposition'] ? 'inlineImage' : 'attachment',
                'contents' => $att['content'],
                'filename' => $att['filename'],
                'headers' => ['Content-Type' => $att['contentType']],
            ];
        }

        return $fields;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/InfoBipTransport.php tests/unit/Swift/Transport/Api/InfoBipTransportTest.php
git commit -m "feat: implement Infobip HTTP API transport"
```

---

## Task 15: Implement MailChimpTransport (Mandrill)

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MailChimpTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MailChimpTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://mandrillapp.com/api/1.0/messages/send`
- Auth: API key in the JSON body (`"key"` field) — no auth headers needed
- Ping: `POST https://mandrillapp.com/api/1.0/users/ping` with body `{"key": "..."}` — returns `"PONG!"` on success
- Success: HTTP 200, check `[0].status` is `"sent"` or `"queued"`
- `to` array uses `type` field for cc/bcc
- Attachments go in `message.attachments`

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_MailChimpTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://mandrillapp.com';

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $response = $this->httpClient->request('POST', self::HOST . '/api/1.0/users/ping', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['key' => $this->apiKey]),
            ]);

            return 'PONG!' === trim($response->getBody()->getContents(), "\" \n\r\t");
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload),
        ]);

        $result = $this->parseResponse($response);

        if (!is_array($result) || empty($result)) {
            throw new Swift_TransportException('Mandrill API error: invalid response');
        }

        // Check for API-level errors
        if (isset($result['status']) && 'error' === $result['status']) {
            throw new Swift_TransportException('Mandrill API error: ' . ($result['message'] ?? 'Unknown'));
        }

        // Count successful sends
        $sent = 0;
        foreach ($result as $recipient) {
            if (in_array($recipient['status'] ?? '', ['sent', 'queued'])) {
                ++$sent;
            }
        }

        return ['recipients' => $sent];
    }

    protected function getEndpoint(): string
    {
        return self::HOST . '/api/1.0/messages/send';
    }

    protected function getAuthHeaders(): array
    {
        // Mandrill uses key in body, not headers
        return [];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        // Not used — ping() is overridden
        return self::HOST . '/api/1.0/users/ping';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $to = [];
        foreach ($message->getTo() as $email => $name) {
            $to[] = array_filter(['email' => $email, 'name' => $name, 'type' => 'to']);
        }
        if ($cc = $message->getCc()) {
            foreach ($cc as $email => $name) {
                $to[] = array_filter(['email' => $email, 'name' => $name, 'type' => 'cc']);
            }
        }
        if ($bcc = $message->getBcc()) {
            foreach ($bcc as $email => $name) {
                $to[] = array_filter(['email' => $email, 'name' => $name, 'type' => 'bcc']);
            }
        }

        $msg = [
            'from_email' => $fromEmail,
            'to' => $to,
            'subject' => $message->getSubject(),
        ];

        if ($from[$fromEmail]) {
            $msg['from_name'] = $from[$fromEmail];
        }

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $msg['text'] = $body['text'];
        }
        if ($body['html']) {
            $msg['html'] = $body['html'];
        }

        if ($replyTo = $message->getReplyTo()) {
            $replyEmail = array_key_first($replyTo);
            $msg['headers'] = ['Reply-To' => $this->formatAddress($replyEmail, $replyTo[$replyEmail])];
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $msg['attachments'] = [];
            foreach ($attachments as $att) {
                if ('inline' === $att['disposition']) {
                    $msg['images'][] = [
                        'type' => $att['contentType'],
                        'name' => $att['contentId'],
                        'content' => base64_encode($att['content']),
                    ];
                } else {
                    $msg['attachments'][] = [
                        'type' => $att['contentType'],
                        'name' => $att['filename'],
                        'content' => base64_encode($att['content']),
                    ];
                }
            }
        }

        return [
            'key' => $this->apiKey,
            'message' => $msg,
        ];
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/MailChimpTransport.php tests/unit/Swift/Transport/Api/MailChimpTransportTest.php
git commit -m "feat: implement Mandrill/MailChimp HTTP API transport"
```

---

## Task 16: Implement AzureTransport

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/AzureTransport.php`
- Test: `tests/unit/Swift/Transport/Api/AzureTransportTest.php`

**Implementation details:**
- Endpoint: `POST https://{resource}.communication.azure.com/emails:send?api-version=2024-07-01-preview`
- Auth: HMAC-SHA256 signing (connection string parsed for key, endpoint)
- Constructor takes `connectionString` (format: `endpoint=https://xxx.communication.azure.com/;accesskey=BASE64KEY`)
- Ping: GET operation status with known UUID; 401=bad creds, 404=good creds
- Success: HTTP 202
- Payload: `senderAddress`, `content`, `recipients`, `attachments`, `replyTo`

```php
<?php

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_AzureTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const API_VERSION = '2024-07-01-preview';

    private string $endpoint;
    private string $accessKey;

    public function __construct(
        string $connectionString,
        ?ClientInterface $httpClient = null,
        ?Swift_Events_EventDispatcher $eventDispatcher = null,
    ) {
        $parsed = $this->parseConnectionString($connectionString);
        $this->endpoint = rtrim($parsed['endpoint'], '/');
        $this->accessKey = $parsed['accesskey'];

        parent::__construct($this->accessKey, $httpClient, $eventDispatcher);
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $url = $this->endpoint . '/emails/operations/00000000-0000-0000-0000-000000000000?api-version=' . self::API_VERSION;
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->signRequest('GET', $url, ''),
                'http_errors' => false,
            ]);

            // 404 = authenticated OK (operation doesn't exist); 401 = bad creds
            return $response->getStatusCode() !== 401;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = json_encode($this->getPayload($message));
        $url = $this->getEndpoint();

        $response = $this->httpClient->request('POST', $url, [
            'headers' => array_merge(
                $this->signRequest('POST', $url, $payload),
                ['Content-Type' => 'application/json'],
            ),
            'body' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $result = $this->parseResponse($response);
            $errorMsg = $result['error']['message'] ?? 'Unknown Azure error';
            throw new Swift_TransportException('Azure Communication Services error: ' . $errorMsg);
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return $this->endpoint . '/emails:send?api-version=' . self::API_VERSION;
    }

    protected function getAuthHeaders(): array
    {
        // Not used directly — Azure uses per-request HMAC signing
        return [];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return $this->endpoint . '/emails/operations/00000000-0000-0000-0000-000000000000?api-version=' . self::API_VERSION;
    }

    /**
     * Sign a request using Azure HMAC-SHA256.
     */
    private function signRequest(string $method, string $url, string $body): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $pathAndQuery = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        $contentHash = base64_encode(hash('sha256', $body, true));
        $date = gmdate('D, d M Y H:i:s T');

        $stringToSign = implode("\n", [
            $method,
            $pathAndQuery,
            $date . ';' . $host . ';' . $contentHash,
        ]);

        $signature = base64_encode(
            hash_hmac('sha256', $stringToSign, base64_decode($this->accessKey), true),
        );

        return [
            'x-ms-date' => $date,
            'x-ms-content-sha256' => $contentHash,
            'host' => $host,
            'Authorization' => sprintf(
                'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=%s',
                $signature,
            ),
        ];
    }

    private function parseConnectionString(string $connectionString): array
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);
            if ('' === $segment) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $parts[strtolower($key)] = $value;
        }

        if (!isset($parts['endpoint'], $parts['accesskey'])) {
            throw new InvalidArgumentException(
                'Azure connection string must contain "endpoint" and "accesskey" components.',
            );
        }

        return $parts;
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $from = $message->getFrom();
        $fromEmail = array_key_first($from);

        $payload = [
            'senderAddress' => $fromEmail,
            'content' => [
                'subject' => $message->getSubject(),
            ],
            'recipients' => [
                'to' => $this->mapAzureAddresses($message->getTo()),
            ],
        ];

        $body = $this->getMessageBody($message);
        if ($body['text']) {
            $payload['content']['plainText'] = $body['text'];
        }
        if ($body['html']) {
            $payload['content']['html'] = $body['html'];
        }

        if ($cc = $message->getCc()) {
            $payload['recipients']['cc'] = $this->mapAzureAddresses($cc);
        }
        if ($bcc = $message->getBcc()) {
            $payload['recipients']['bcc'] = $this->mapAzureAddresses($bcc);
        }
        if ($replyTo = $message->getReplyTo()) {
            $payload['replyTo'] = $this->mapAzureAddresses($replyTo);
        }

        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = [
                    'name' => $att['filename'],
                    'contentType' => $att['contentType'],
                    'contentInBase64' => base64_encode($att['content']),
                ];
            }
        }

        return $payload;
    }

    private function mapAzureAddresses(array $addresses): array
    {
        $mapped = [];
        foreach ($addresses as $email => $name) {
            $mapped[] = array_filter(['address' => $email, 'displayName' => $name]);
        }
        return $mapped;
    }
}
```

**Commit:**
```bash
git add lib/classes/Swift/Transport/Api/AzureTransport.php tests/unit/Swift/Transport/Api/AzureTransportTest.php
git commit -m "feat: implement Azure Communication Services HTTP API transport"
```

---

## Task 17: Wire Up DSN Mappings

**Files:**
- Modify: `lib/classes/Swift/Dsn.php`
- Test: `tests/unit/Swift/DsnTest.php`

**Step 1: Write test**

Test that each scheme resolves to the correct transport class:

```php
<?php

use PHPUnit\Framework\TestCase;

class Swift_DsnTest extends TestCase
{
    /**
     * @dataProvider schemeProvider
     */
    public function testGetTransportClass(string $scheme, string $expectedClass): void
    {
        $nyholmDsn = \Nyholm\Dsn\DsnParser::parse($scheme . '://user:pass@host');
        $dsn = new \Swift_Dsn($nyholmDsn);
        $this->assertEquals($expectedClass, $dsn->getTransportClass());
    }

    public function schemeProvider(): array
    {
        return [
            ['microsoft-graph', \Swift_Transport_Api_MicrosoftGraphTransport::class],
            ['gmail+api', \Swift_Transport_Api_GoogleTransport::class],
            ['gmail+smtp', \Swift_Transport_EsmtpTransport::class],
            ['sendgrid', \Swift_Transport_Api_SendgridTransport::class],
            ['postmark', \Swift_Transport_Api_PostMarkTransport::class],
            ['brevo', \Swift_Transport_Api_BrevoTransport::class],
            ['resend', \Swift_Transport_Api_ResendTransport::class],
            ['mailersend', \Swift_Transport_Api_MailerSendTransport::class],
            ['mailpace', \Swift_Transport_Api_MailPaceTransport::class],
            ['mailgun', \Swift_Transport_Api_MailGunTransport::class],
            ['mailjet', \Swift_Transport_Api_MailJetTransport::class],
            ['scaleway', \Swift_Transport_Api_ScalewayTransport::class],
            ['infobip', \Swift_Transport_Api_InfoBipTransport::class],
            ['mailchimp', \Swift_Transport_Api_MailChimpTransport::class],
            ['azure', \Swift_Transport_Api_AzureTransport::class],
            ['amazon+api', \Swift_Transport_Api_AmazonSesApiTransport::class],
            ['amazon+http', \Swift_Transport_Api_AmazonSesHttpTransport::class],
        ];
    }
}
```

**Step 2: Update the DSN class**

Replace the commented-out TODO block in `Swift_Dsn::$transport_class_map` with actual mappings:

```php
private static array $transport_class_map = [
    'microsoft-graph' => Swift_Transport_Api_MicrosoftGraphTransport::class,
    'gmail+smtp'      => Swift_Transport_EsmtpTransport::class,
    'gmail+api'       => Swift_Transport_Api_GoogleTransport::class,
    'sendgrid'        => Swift_Transport_Api_SendgridTransport::class,
    'postmark'        => Swift_Transport_Api_PostMarkTransport::class,
    'brevo'           => Swift_Transport_Api_BrevoTransport::class,
    'resend'          => Swift_Transport_Api_ResendTransport::class,
    'mailersend'      => Swift_Transport_Api_MailerSendTransport::class,
    'mailpace'        => Swift_Transport_Api_MailPaceTransport::class,
    'mailgun'         => Swift_Transport_Api_MailGunTransport::class,
    'mailjet'         => Swift_Transport_Api_MailJetTransport::class,
    'scaleway'        => Swift_Transport_Api_ScalewayTransport::class,
    'infobip'         => Swift_Transport_Api_InfoBipTransport::class,
    'mailchimp'       => Swift_Transport_Api_MailChimpTransport::class,
    'azure'           => Swift_Transport_Api_AzureTransport::class,
    'amazon+api'      => Swift_Transport_Api_AmazonSesApiTransport::class,
    'amazon+http'     => Swift_Transport_Api_AmazonSesHttpTransport::class,
];
```

**Step 3: Run tests, commit**

```bash
git add lib/classes/Swift/Dsn.php tests/unit/Swift/DsnTest.php
git commit -m "feat: register all transport schemes in DSN class"
```

---

## Task 18: Run Full Test Suite

**Step 1: Run all unit tests**

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --verbose`
Expected: All tests pass.

**Step 2: Fix any failures**

If any tests fail, fix them before proceeding.

**Step 3: Final commit**

If any fixes were needed:
```bash
git add -A
git commit -m "fix: resolve test failures from transport implementations"
```

---

## Summary

| Task | What | Files |
|-|-|-|
| 1 | Add Guzzle dep | composer.json |
| 2 | AbstractHttpApiTransport | +1 class, +1 test |
| 3 | Fix MailGun class name | 1 file |
| 4 | Fix AmazonSes naming | 2 files |
| 5 | SendgridTransport | +1 test, modify 1 |
| 6 | PostMarkTransport | +1 test, modify 1 |
| 7 | BrevoTransport | +1 test, modify 1 |
| 8 | ResendTransport | +1 test, modify 1 |
| 9 | MailerSendTransport | +1 test, modify 1 |
| 10 | MailPaceTransport | +1 test, modify 1 |
| 11 | MailGunTransport | +1 test, modify 1 |
| 12 | MailJetTransport | +1 test, modify 1 |
| 13 | ScalewayTransport | +1 test, modify 1 |
| 14 | InfoBipTransport | +1 test, modify 1 |
| 15 | MailChimpTransport | +1 test, modify 1 |
| 16 | AzureTransport | +1 test, modify 1 |
| 17 | DSN mappings | modify 1, +1 test |
| 18 | Full test suite | verify |
