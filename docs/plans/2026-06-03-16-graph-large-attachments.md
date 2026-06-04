# Microsoft Graph — Large Attachment Upload Sessions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `Swift_Transport_Api_MicrosoftGraphTransport` send messages whose attachments exceed Graph's ~4 MB `/sendMail` body limit, by switching to a draft-message + upload-session flow for files at or above a configurable threshold.

**Architecture:** When every attachment is small, the existing `/sendMail` path is untouched. When any attachment crosses the threshold (default 3 MB, Microsoft's documented boundary), the transport instead creates a draft message (`POST /messages`), attaches small files inline (`POST /messages/{id}/attachments`), streams large files through upload sessions (`POST /messages/{id}/attachments/createUploadSession` + the SDK's `LargeFileUploadTask`), then sends the draft (`POST /messages/{id}/send`). Size is measured from the decoded body, not `Swift_Attachment::getSize()` (which reads the usually-unset Content-Disposition `size` parameter).

**Tech Stack:** PHP 8.1+, `microsoft/microsoft-graph` ^2.10 (Generated request builders + `Microsoft\Graph\Core\Tasks\LargeFileUploadTask`), `guzzlehttp/psr7` (`Utils::streamFor`), PHPUnit (`vendor/bin/simple-phpunit`).

**Execution constraint:** This plan and the calendar CANCEL/UPDATE plan (`2026-06-03-17-graph-calendar-cancel-update.md`) BOTH modify the `send()` method of the same transport class. They MUST be executed sequentially — finish this plan first. Do not run them in parallel.

---

### Task 1: Attachment-size threshold configuration and partitioning

**Goal:** Add a configurable large-attachment threshold and helpers that decide which attachments are "large", with no change to `send()` behaviour yet.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php`
- Test: `tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php`

**Acceptance Criteria:**
- [ ] `LARGE_ATTACHMENT_THRESHOLD` constant equals `3 * 1024 * 1024`.
- [ ] `getLargeAttachmentThreshold()` returns the default until changed; `setLargeAttachmentThreshold()` updates it.
- [ ] `setLargeAttachmentThreshold()` rejects values `< 1` with `InvalidArgumentException`.
- [ ] `partitionAttachmentsBySize()` returns `[small, large]` split at the threshold (a body exactly at the threshold is "large").
- [ ] All previously-passing tests still pass.

**Verify:** `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php` → OK (all green)

**Steps:**

- [ ] **Step 1: Write the failing tests**

`partitionAttachmentsBySize` and `attachmentExceedsThreshold` are `private`; exercise them through a tiny reflection helper so the test stays at the unit level. Add to `Swift_Transport_Api_MicrosoftGraphTransportTest`:

```php
private function newTransport(): \Swift_Transport_Api_MicrosoftGraphTransport
{
    return new \Swift_Transport_Api_MicrosoftGraphTransport(
        $this->createMock(GraphServiceClient::class),
    );
}

public function testThresholdDefaultsToThreeMegabytes(): void
{
    $transport = $this->newTransport();
    $this->assertSame(3 * 1024 * 1024, \Swift_Transport_Api_MicrosoftGraphTransport::LARGE_ATTACHMENT_THRESHOLD);
    $this->assertSame(3 * 1024 * 1024, $transport->getLargeAttachmentThreshold());
}

public function testSetThresholdUpdatesValue(): void
{
    $transport = $this->newTransport();
    $transport->setLargeAttachmentThreshold(10 * 1024 * 1024);
    $this->assertSame(10 * 1024 * 1024, $transport->getLargeAttachmentThreshold());
}

public function testSetThresholdRejectsNonPositive(): void
{
    $transport = $this->newTransport();
    $this->expectException(\InvalidArgumentException::class);
    $transport->setLargeAttachmentThreshold(0);
}

public function testPartitionSplitsAtThreshold(): void
{
    $transport = $this->newTransport();
    $transport->setLargeAttachmentThreshold(10); // 10 bytes, easy to straddle

    $small = new \Swift_Attachment('123456789', 'small.bin', 'application/octet-stream');   // 9 bytes
    $atEdge = new \Swift_Attachment('1234567890', 'edge.bin', 'application/octet-stream');  // 10 bytes -> large
    $big = new \Swift_Attachment('1234567890123', 'big.bin', 'application/octet-stream');   // 13 bytes

    $method = new \ReflectionMethod($transport, 'partitionAttachmentsBySize');
    [$smallList, $largeList] = $method->invoke($transport, [$small, $atEdge, $big]);

    $this->assertSame([$small], $smallList);
    $this->assertSame([$atEdge, $big], $largeList);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php --filter 'Threshold|Partition'`
Expected: FAIL — `Error: Undefined constant ... LARGE_ATTACHMENT_THRESHOLD` / `Call to undefined method getLargeAttachmentThreshold`.

- [ ] **Step 3: Add the constant, property, accessors, and helpers**

In `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php`, add the constant + property near the other properties (just after `private Swift_Transport_Api_Calendar_IcsParser $icsParser;`):

```php
    /**
     * Graph's /sendMail endpoint rejects request bodies larger than ~4 MB. Attachments
     * at or above this size must be uploaded via an upload session against a draft
     * message instead. 3 MB is Microsoft's documented boundary for switching approaches.
     */
    public const LARGE_ATTACHMENT_THRESHOLD = 3 * 1024 * 1024;

    private int $largeAttachmentThreshold = self::LARGE_ATTACHMENT_THRESHOLD;
```

Add the accessors near the other getters/setters (e.g. after `useFromAddressAsSendingAccountUserId()`):

```php
    public function getLargeAttachmentThreshold(): int
    {
        return $this->largeAttachmentThreshold;
    }

    /**
     * @throws InvalidArgumentException when the byte count is not positive
     */
    public function setLargeAttachmentThreshold(int $bytes): void
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('Large-attachment threshold must be a positive byte count.');
        }
        $this->largeAttachmentThreshold = $bytes;
    }
```

Add the helpers near the other private helpers (e.g. just above `extractCalendarInvites()`):

```php
    /**
     * Whether a MIME child's decoded body is large enough to require an upload session
     * rather than inline base64 in the sendMail payload.
     *
     * Swift_Attachment::getSize() reads the Content-Disposition "size" parameter, which
     * is frequently unset, so we measure the decoded body directly.
     */
    private function attachmentExceedsThreshold(Swift_Mime_SimpleMimeEntity $attachment): bool
    {
        return \strlen((string) $attachment->getBody()) >= $this->largeAttachmentThreshold;
    }

    /**
     * Splits attachments into [small, large] by the configured threshold.
     *
     * @param array<int, Swift_Mime_SimpleMimeEntity> $attachments
     *
     * @return array{0: array<int, Swift_Mime_SimpleMimeEntity>, 1: array<int, Swift_Mime_SimpleMimeEntity>}
     */
    private function partitionAttachmentsBySize(array $attachments): array
    {
        $small = [];
        $large = [];
        foreach ($attachments as $attachment) {
            if ($this->attachmentExceedsThreshold($attachment)) {
                $large[] = $attachment;
            } else {
                $small[] = $attachment;
            }
        }

        return [$small, $large];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php`
Expected: PASS (new tests green, all prior tests still green).

- [ ] **Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php
git commit -m "feat(graph): add configurable large-attachment threshold and partitioning"
```

---

### Task 2: Draft-then-send flow with upload sessions

**Goal:** Add `sendViaDraft()` (draft create -> attach small -> upload large -> send) and the testable `uploadLargeAttachment()` seam, without yet wiring them into `send()`.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php` (add imports + two methods)
- Test: `tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php`

**Acceptance Criteria:**
- [ ] `sendViaDraft()` POSTs a draft, attaches each small attachment via `attachments()->post()`, creates an upload session per large attachment, calls `uploadLargeAttachment()` once per large attachment, then calls `send()->post()` on the draft message builder.
- [ ] If the draft POST returns null or an id-less message, a `Swift_TransportException` is thrown.
- [ ] `uploadLargeAttachment()` is `protected` so tests can stub the chunked transfer.
- [ ] The `AttachmentItem` for a large file carries type `file`, the filename, the decoded byte size, the content type, and the inline flag derived from disposition.

**Verify:** `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php --filter SendViaDraft` → OK

**Steps:**

- [ ] **Step 1: Write the failing test**

This tests `sendViaDraft()` directly via reflection, with a fully mocked builder chain and `uploadLargeAttachment()` stubbed by a partial mock so no real HTTP/streaming happens. Add to `Swift_Transport_Api_MicrosoftGraphTransportTest`:

```php
public function testSendViaDraftAttachesSmallInlineUploadsLargeAndSends(): void
{
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn(null);

    // Draft POST returns a Message carrying an id.
    $draft = new \Microsoft\Graph\Generated\Models\Message();
    $draft->setId('draft-123');
    $draftPromise = $this->createMock(\Http\Promise\Promise::class);
    $draftPromise->method('wait')->willReturn($draft);

    // Upload session POST returns an UploadSession.
    $uploadSession = new \Microsoft\Graph\Generated\Models\UploadSession();
    $sessionPromise = $this->createMock(\Http\Promise\Promise::class);
    $sessionPromise->method('wait')->willReturn($uploadSession);

    $createUpload = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\CreateUploadSession\CreateUploadSessionRequestBuilder::class);
    $createUpload->expects($this->once())->method('post')->willReturn($sessionPromise);

    $attachmentsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\AttachmentsRequestBuilder::class);
    $attachmentsBuilder->expects($this->once())->method('post')->willReturn($promise);          // one small attachment
    $attachmentsBuilder->method('createUploadSession')->willReturn($createUpload);

    $sendBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Send\SendRequestBuilder::class);
    $sendBuilder->expects($this->once())->method('post')->willReturn($promise);

    $messageItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\MessageItemRequestBuilder::class);
    $messageItemBuilder->method('attachments')->willReturn($attachmentsBuilder);
    $messageItemBuilder->method('send')->willReturn($sendBuilder);

    $messagesBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilder::class);
    $messagesBuilder->expects($this->once())->method('post')->willReturn($draftPromise);         // draft create
    $messagesBuilder->method('byMessageId')->with('draft-123')->willReturn($messageItemBuilder);

    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('messages')->willReturn($messagesBuilder);

    // Partial mock: stub only uploadLargeAttachment so no real streaming occurs.
    $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
        ->setConstructorArgs([$this->createMock(GraphServiceClient::class)])
        ->onlyMethods(['uploadLargeAttachment'])
        ->getMock();
    $transport->expects($this->once())->method('uploadLargeAttachment');

    $graphMessage = new \Microsoft\Graph\Generated\Models\Message();
    $small = new \Swift_Attachment('small body', 'small.txt', 'text/plain');
    $large = new \Swift_Attachment('large body bytes', 'big.bin', 'application/octet-stream');

    $method = new \ReflectionMethod($transport, 'sendViaDraft');
    $method->invoke($transport, $userItemBuilder, $graphMessage, [$small], [$large]);
}

public function testSendViaDraftThrowsWhenDraftHasNoId(): void
{
    $draftPromise = $this->createMock(\Http\Promise\Promise::class);
    $draftPromise->method('wait')->willReturn(null);

    $messagesBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilder::class);
    $messagesBuilder->method('post')->willReturn($draftPromise);

    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('messages')->willReturn($messagesBuilder);

    $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
    $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class), null, $dispatcher);

    $this->expectException(\Swift_TransportException::class);
    $method = new \ReflectionMethod($transport, 'sendViaDraft');
    $method->invoke($transport, $userItemBuilder, new \Microsoft\Graph\Generated\Models\Message(), [], [new \Swift_Attachment('x', 'a.bin', 'application/octet-stream')]);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php --filter SendViaDraft`
Expected: FAIL — `ReflectionException: Method sendViaDraft does not exist`.

- [ ] **Step 3: Add imports**

At the top of `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php`, add these `use` statements alongside the existing `use Microsoft\Graph\...` block:

```php
use Microsoft\Graph\Core\Tasks\LargeFileUploadTask;
use Microsoft\Graph\Generated\Models\AttachmentItem;
use Microsoft\Graph\Generated\Models\AttachmentType;
use Microsoft\Graph\Generated\Models\UploadSession;
use Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\CreateUploadSession\CreateUploadSessionPostRequestBody;
```

- [ ] **Step 4: Add `sendViaDraft()` and `uploadLargeAttachment()`**

Add both methods near the other private send helpers (e.g. after `convertSwiftAttachmentToGraphAttachment()`):

```php
    /**
     * Sends a message that carries at least one large attachment via the draft +
     * upload-session flow, because Graph's /sendMail rejects bodies over ~4 MB.
     *
     * Creates a draft, attaches small files inline, streams large files through upload
     * sessions, then sends the draft.
     *
     * @param array<int, Swift_Mime_SimpleMimeEntity> $smallAttachments
     * @param array<int, Swift_Mime_SimpleMimeEntity> $largeAttachments
     *
     * @throws Swift_TransportException
     */
    private function sendViaDraft(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        Message $graphMessage,
        array $smallAttachments,
        array $largeAttachments,
    ): void {
        $draft = $userRequestBuilder->messages()->post($graphMessage)->wait();
        if (null === $draft || null === $draft->getId()) {
            $this->throwException(new Swift_TransportException('Graph did not return a draft message id; cannot attach large files.'));
        }

        $messageBuilder = $userRequestBuilder->messages()->byMessageId($draft->getId());

        // Files under the threshold are cheap to inline directly on the draft.
        foreach ($smallAttachments as $attachment) {
            $messageBuilder->attachments()->post($this->convertSwiftAttachmentToGraphAttachment($attachment))->wait();
        }

        foreach ($largeAttachments as $attachment) {
            $contents       = (string) $attachment->getBody();
            $attachmentItem = new AttachmentItem();
            try {
                $attachmentItem->setAttachmentType(new AttachmentType(AttachmentType::FILE));
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                $this->throwException(new Swift_TransportException("Failed to set Graph AttachmentType: {$e->getMessage()}"));
            }
            // @codeCoverageIgnoreEnd
            $attachmentItem->setName($attachment->getFilename());
            $attachmentItem->setSize(\strlen($contents));
            $attachmentItem->setContentType((string) $attachment->getContentType());
            $attachmentItem->setIsInline('attachment' !== $attachment->getDisposition());

            $uploadBody = new CreateUploadSessionPostRequestBody();
            $uploadBody->setAttachmentItem($attachmentItem);

            $uploadSession = $messageBuilder->attachments()->createUploadSession()->post($uploadBody)->wait();
            if (null === $uploadSession) {
                $this->throwException(new Swift_TransportException("Graph returned no upload session for attachment '{$attachment->getFilename()}'."));
            }

            $this->uploadLargeAttachment($uploadSession, $contents);
        }

        // POST /messages/{id}/send takes no body and moves the draft to Sent Items.
        $messageBuilder->send()->post()->wait();
    }

    /**
     * Streams a large attachment's bytes into a previously-created upload session.
     *
     * Isolated so tests can stub the chunked transfer; the SDK's LargeFileUploadTask
     * performs the sequential PUT requests against the session's upload URL.
     */
    protected function uploadLargeAttachment(UploadSession $uploadSession, string $contents): void
    {
        $task = new LargeFileUploadTask(
            $uploadSession,
            $this->client->getRequestAdapter(),
            Utils::streamFor($contents),
        );
        $task->upload()->wait();
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php --filter SendViaDraft`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php
git commit -m "feat(graph): add draft-then-send upload-session flow for large attachments"
```

---

### Task 3: Route large-attachment sends through the draft flow inside `send()`

**Goal:** Branch `send()` so messages with at least one large attachment use `sendViaDraft()`, while small-only messages keep the existing `/sendMail` path unchanged.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php` (the `send()` method body)
- Test: `tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php`

**Acceptance Criteria:**
- [ ] A message with all-small attachments still calls `sendMail()->post()` and never `messages()->post()` (existing behaviour preserved).
- [ ] A message with one or more large attachments calls `sendViaDraft()` and never `sendMail()->post()`.
- [ ] Large attachments are excluded from the `sendMail` payload entirely (no oversized inline body).
- [ ] All existing `MicrosoftGraphTransportTest` and `MicrosoftGraphCalendarTest` cases still pass.

**Verify:** `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --filter MicrosoftGraph` → OK

**Steps:**

- [ ] **Step 1: Write the failing test**

Add to `Swift_Transport_Api_MicrosoftGraphTransportTest`. The large-attachment body must exceed the default 3 MB threshold; build it with `str_repeat`. Stub `sendViaDraft` via a partial mock so the test asserts routing, not the draft internals.

```php
public function testLargeAttachmentRoutesThroughDraftFlow(): void
{
    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    // sendMail must NOT be used when a large attachment is present.
    $userItemBuilder->expects($this->never())->method('sendMail');

    $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
    $usersBuilder->method('byUserId')->willReturn($userItemBuilder);
    $graphClient = $this->createMock(GraphServiceClient::class);
    $graphClient->method('users')->willReturn($usersBuilder);

    $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
    $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
    $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

    $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
        ->setConstructorArgs([$graphClient, 'user-id', $dispatcher])
        ->onlyMethods(['sendViaDraft'])
        ->getMock();
    $transport->expects($this->once())->method('sendViaDraft');

    $m = new \Swift_Message();
    $m->setFrom(['from@example.com' => 'Sender']);
    $m->setTo(['to@example.com' => 'Recipient']);
    $m->setSubject('Big');
    $m->setBody('Hello');
    $m->attach(new \Swift_Attachment(\str_repeat('A', 4 * 1024 * 1024), 'huge.bin', 'application/octet-stream'));

    $transport->send($m);
}

public function testSmallAttachmentStillUsesSendMail(): void
{
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn(null);

    $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
    $sendMailBuilder->expects($this->once())->method('post')->willReturn($promise);

    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);
    $userItemBuilder->expects($this->never())->method('messages');

    $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
    $usersBuilder->method('byUserId')->willReturn($userItemBuilder);
    $graphClient = $this->createMock(GraphServiceClient::class);
    $graphClient->method('users')->willReturn($usersBuilder);

    $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
    $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
    $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

    $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

    $m = new \Swift_Message();
    $m->setFrom(['from@example.com' => 'Sender']);
    $m->setTo(['to@example.com' => 'Recipient']);
    $m->setSubject('Small');
    $m->setBody('Hello');
    $m->attach(new \Swift_Attachment('tiny', 'tiny.txt', 'text/plain'));

    $transport->send($m);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php --filter 'LargeAttachmentRoutes|SmallAttachmentStill'`
Expected: FAIL — `sendViaDraft` is never called (large path not wired yet); the large-attachment test fails the `->expects($this->once())` assertion.

- [ ] **Step 3: Replace the attachment-collection + transmission blocks in `send()`**

In `send()`, find the existing attachment-building loop:

```php
        $graphAttachments = [];

        foreach ($message->getChildren() ?? [] as $swiftAttachment) {
            // Skip calendar parts we are converting to Graph events; shipping the raw
            // .ics alongside is exactly what M365 mangles.
            if (\in_array($swiftAttachment, $inviteAttachments, true)) {
                continue;
            }
            $graphAttachments[] = $this->convertSwiftAttachmentToGraphAttachment($swiftAttachment);
        }

        if (!empty($graphAttachments)) {
            $graphMessage->setAttachments($graphAttachments);
        }
```

Replace it with collection-then-partition (do NOT convert large ones into the sendMail payload):

```php
        $attachmentsToSend = [];
        foreach ($message->getChildren() ?? [] as $swiftAttachment) {
            // Skip calendar parts we are converting to Graph events; shipping the raw
            // .ics alongside is exactly what M365 mangles.
            if (\in_array($swiftAttachment, $inviteAttachments, true)) {
                continue;
            }
            $attachmentsToSend[] = $swiftAttachment;
        }

        [$smallAttachments, $largeAttachments] = $this->partitionAttachmentsBySize($attachmentsToSend);
        $useDraftFlow = [] !== $largeAttachments;

        // The sendMail payload can only carry attachments small enough to inline; when a
        // large attachment is present the draft flow attaches everything itself.
        if (!$useDraftFlow) {
            $graphAttachments = \array_map(
                fn (Swift_Attachment $a): Attachment => $this->convertSwiftAttachmentToGraphAttachment($a),
                $smallAttachments,
            );
            if (!empty($graphAttachments)) {
                $graphMessage->setAttachments($graphAttachments);
            }
        }
```

Then find the transmission line inside the `try` block:

```php
            if ($shouldSendEmail) {
                $userRequestBuilder->sendMail()->post($sendMailBody)->wait();
            }
```

Replace it with the branch:

```php
            if ($shouldSendEmail) {
                if ($useDraftFlow) {
                    $this->sendViaDraft($userRequestBuilder, $graphMessage, $smallAttachments, $largeAttachments);
                } else {
                    $userRequestBuilder->sendMail()->post($sendMailBody)->wait();
                }
            }
```

- [ ] **Step 4: Run the focused tests, then the full Graph suite**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php --filter 'LargeAttachmentRoutes|SmallAttachmentStill'`
Expected: PASS.

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --filter MicrosoftGraph`
Expected: PASS — every prior `MicrosoftGraphTransportTest` and `MicrosoftGraphCalendarTest` case still green.

- [ ] **Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php
git commit -m "feat(graph): route large-attachment sends through the draft upload flow"
```

---

## Notes for the implementer

- **Unit tests cannot exercise the real chunked PUT transfer.** `uploadLargeAttachment()` is stubbed in tests; the `LargeFileUploadTask` itself is covered by the SDK. If a live mailbox is available, do a manual smoke send with a ~5 MB attachment and confirm it arrives intact.
- **`Utils` is already imported** at the top of the file (`use GuzzleHttp\Psr7\Utils;`) — do not re-add it.
- **`Message`, `Attachment`, `FileAttachment`, `ReflectionException`** are already in scope; only the five imports in Task 2 Step 3 are new.
- **Disposition/inline parity:** `convertSwiftAttachmentToGraphAttachment()` already sets `isInline` as `'attachment' !== getDisposition()`; the large-file `AttachmentItem` mirrors that exactly so behaviour is consistent across both paths.
