# Microsoft Graph — Calendar CANCEL / UPDATE Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing calendar-invite conversion in `Swift_Transport_Api_MicrosoftGraphTransport` so that `METHOD:CANCEL` .ics parts cancel the matching Graph event, and `METHOD:REQUEST` parts with `SEQUENCE > 0` patch the existing event instead of creating a duplicate.

**Architecture:** Today only `METHOD:REQUEST` invitations are converted to Graph events (everything else rides along as a raw .ics, which M365 mangles). This plan broadens detection to also capture `CANCEL`, and replaces the single "create event" loop with a per-operation dispatcher: CREATE (`REQUEST`, `SEQUENCE 0`) stays a `POST /events`; UPDATE (`REQUEST`, `SEQUENCE > 0`) looks up the event by its `iCalUId` and `PATCH`es it (falling back to create if not found); CANCEL looks up by `iCalUId` and calls the Calendar API `cancel` action (logging and skipping if no match). The `iCalUId` lookup is the reliable correlation key because Graph populates it from the originating .ics UID.

**Tech Stack:** PHP 8.1+, `microsoft/microsoft-graph` ^2.10 (Generated `EventsRequestBuilder`, `EventItemRequestBuilder->cancel()/patch()`, `CancelPostRequestBody`, `EventCollectionResponse`), PHPUnit (`vendor/bin/simple-phpunit`).

**Execution constraint:** This plan modifies the same `send()` method as `2026-06-03-16-graph-large-attachments.md`. Execute that plan FIRST and merge it before starting this one, to avoid conflicting edits to `send()`. Do not run them in parallel.

**Prior art in the file (do not re-implement):** `Swift_Transport_Api_Calendar_IcsParser` already parses `METHOD`, `SEQUENCE`, `UID`, attendees, etc. `ParsedEvent` already exposes `method`, `sequence`, `uid`, `attendees`, and `isRequest()`. `extractCalendarInvites()`, `isCalendarPart()`, and `convertParsedEventToGraphEvent()` already exist and work for the CREATE case.

---

### Task 1: `ParsedEvent::isCancel()`

**Goal:** Add a `METHOD:CANCEL` predicate to `ParsedEvent`, mirroring the existing `isRequest()`.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/Calendar/ParsedEvent.php`
- Test: `tests/unit/Swift/Transport/Api/Calendar/ParsedEventTest.php` (create if absent)

**Acceptance Criteria:**
- [ ] `isCancel()` returns true only when `method` is `CANCEL` (case-insensitive).
- [ ] `isCancel()` and `isRequest()` are mutually exclusive for a given event.

**Verify:** `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/Calendar/ParsedEventTest.php` → OK

**Steps:**

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Swift/Transport/Api/Calendar/ParsedEventTest.php`:

```php
<?php

namespace Swift\Transport\Api\Calendar;

use PHPUnit\Framework\TestCase;

class ParsedEventTest extends TestCase
{
    private function parse(string $ics): \Swift_Transport_Api_Calendar_ParsedEvent
    {
        return (new \Swift_Transport_Api_Calendar_IcsParser())->parse($ics);
    }

    private function ics(string $method): string
    {
        return \implode("\r\n", [
            'BEGIN:VCALENDAR',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            'UID:evt-1@example.com',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Sync',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    public function testIsCancelTrueForCancelMethod(): void
    {
        $event = $this->parse($this->ics('CANCEL'));
        $this->assertTrue($event->isCancel());
        $this->assertFalse($event->isRequest());
    }

    public function testIsCancelFalseForRequestMethod(): void
    {
        $event = $this->parse($this->ics('REQUEST'));
        $this->assertFalse($event->isCancel());
        $this->assertTrue($event->isRequest());
    }

    public function testIsCancelCaseInsensitive(): void
    {
        $event = $this->parse($this->ics('cancel'));
        $this->assertTrue($event->isCancel());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/Calendar/ParsedEventTest.php`
Expected: FAIL — `Error: Call to undefined method ...ParsedEvent::isCancel()`.

- [ ] **Step 3: Add `isCancel()`**

In `lib/classes/Swift/Transport/Api/Calendar/ParsedEvent.php`, add directly after the existing `isRequest()` method:

```php
    /**
     * True for METHOD:CANCEL — a cancellation of a previously-sent invitation. Like
     * REQUEST, M365 mangles these when delivered as a sendMail attachment, so we route
     * them through the Calendar API's cancel action instead.
     */
    public function isCancel(): bool
    {
        return 'CANCEL' === \strtoupper((string) $this->method);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/Calendar/ParsedEventTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/Api/Calendar/ParsedEvent.php tests/unit/Swift/Transport/Api/Calendar/ParsedEventTest.php
git commit -m "feat(graph): add ParsedEvent::isCancel for METHOD:CANCEL invites"
```

---

### Task 2: Detect CANCEL parts in `extractCalendarInvites()`

**Goal:** Broaden invite detection so `CANCEL` parts are extracted (and stripped from the email) alongside `REQUEST` parts; non-actionable methods (PUBLISH, REPLY, …) still ride along as attachments.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php` (`extractCalendarInvites()` docblock + filter)
- Test: `tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php`

**Acceptance Criteria:**
- [ ] A `METHOD:CANCEL` .ics part is returned by `extractCalendarInvites()`.
- [ ] A `METHOD:REQUEST` .ics part is still returned (unchanged).
- [ ] A `METHOD:PUBLISH` / `METHOD:REPLY` part is NOT returned.
- [ ] A malformed .ics still falls back silently (no exception escapes).

**Verify:** `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter Extract` → OK

**Steps:**

- [ ] **Step 1: Write the failing test**

`extractCalendarInvites()` is private; reach it by reflection. Add to `MicrosoftGraphCalendarTest`:

```php
private function icsWithMethod(string $method): string
{
    return \implode("\r\n", [
        'BEGIN:VCALENDAR',
        "METHOD:{$method}",
        'BEGIN:VEVENT',
        'UID:extract-1@example.com',
        'DTSTART:20260301T140000Z',
        'DTEND:20260301T150000Z',
        'SUMMARY:Extract test',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);
}

private function messageWithIcs(string $ics): \Swift_Message
{
    $m = new \Swift_Message();
    $m->setFrom(['from@example.com' => 'Sender']);
    $m->setTo(['to@example.com' => 'Recipient']);
    $m->setSubject('Invite');
    $m->setBody('See attached.');
    $m->attach(new \Swift_Attachment($ics, 'invite.ics', 'text/calendar'));

    return $m;
}

private function extractInvites(\Swift_Transport_Api_MicrosoftGraphTransport $t, \Swift_Message $m): array
{
    $method = new \ReflectionMethod($t, 'extractCalendarInvites');

    return $method->invoke($t, $m);
}

public function testExtractReturnsCancelPart(): void
{
    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $invites = $this->extractInvites($t, $this->messageWithIcs($this->icsWithMethod('CANCEL')));

    $this->assertCount(1, $invites);
    $this->assertTrue($invites[0]['event']->isCancel());
}

public function testExtractReturnsRequestPart(): void
{
    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $invites = $this->extractInvites($t, $this->messageWithIcs($this->icsWithMethod('REQUEST')));

    $this->assertCount(1, $invites);
    $this->assertTrue($invites[0]['event']->isRequest());
}

public function testExtractIgnoresPublishPart(): void
{
    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $invites = $this->extractInvites($t, $this->messageWithIcs($this->icsWithMethod('PUBLISH')));

    $this->assertCount(0, $invites);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter Extract`
Expected: FAIL — `testExtractReturnsCancelPart` returns 0 invites (CANCEL is currently filtered out by the `!isRequest()` guard).

- [ ] **Step 3: Broaden the filter in `extractCalendarInvites()`**

In `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php`, find this guard inside `extractCalendarInvites()`:

```php
            if (null === $parsed || !$parsed->isRequest()) {
                continue;
            }
```

Replace it with:

```php
            // REQUEST invites and CANCEL notices are both mangled by M365 when sent as
            // raw .ics, so both are routed through the Calendar API. Other methods
            // (PUBLISH, REPLY, …) are left to ride along as ordinary attachments.
            if (null === $parsed || (!$parsed->isRequest() && !$parsed->isCancel())) {
                continue;
            }
```

Also update the method's docblock summary line so it no longer claims "only … REQUEST". Change the existing docblock paragraph that begins `Only text/calendar parts whose METHOD is REQUEST are returned` to:

```php
     * Only text/calendar parts whose METHOD is REQUEST or CANCEL are returned — those
     * are the ones M365 mangles when sent as a sendMail attachment. Other methods
     * (PUBLISH, REPLY, …) and unparseable parts are left to be sent as attachments.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter Extract`
Expected: PASS. Also run the full calendar file to confirm no regression:
`vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php` → OK.

- [ ] **Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php
git commit -m "feat(graph): detect METHOD:CANCEL calendar parts for conversion"
```

---

### Task 3: Event lookup, cancel, and update seams

**Goal:** Add three protected helpers — `findEventIdByICalUId()`, `cancelGraphEvent()`, `updateGraphEvent()` — each independently unit-testable with mocked builders.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php` (imports + three methods)
- Test: `tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php`

**Acceptance Criteria:**
- [ ] `findEventIdByICalUId()` issues a GET with an OData `$filter` of `iCalUId eq '<escaped-uid>'` and returns the first matching event id, or null when the response has no events.
- [ ] Single quotes in the UID are escaped by doubling (OData literal rule).
- [ ] `cancelGraphEvent()` POSTs to the event's `cancel` action, optionally setting a comment.
- [ ] `updateGraphEvent()` PATCHes the event by id.

**Verify:** `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter 'FindEvent|CancelGraph|UpdateGraph'` → OK

**Steps:**

- [ ] **Step 1: Write the failing tests**

Add to `MicrosoftGraphCalendarTest`:

```php
public function testFindEventIdReturnsFirstMatch(): void
{
    $event = new \Microsoft\Graph\Generated\Models\Event();
    $event->setId('evt-graph-1');
    $response = new \Microsoft\Graph\Generated\Models\EventCollectionResponse();
    $response->setValue([$event]);
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn($response);

    $capturedConfig = null;
    $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
    $eventsBuilder->method('get')->willReturnCallback(function ($config) use (&$capturedConfig, $promise) {
        $capturedConfig = $config;

        return $promise;
    });

    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('events')->willReturn($eventsBuilder);

    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $method = new \ReflectionMethod($t, 'findEventIdByICalUId');
    $id = $method->invoke($t, $userItemBuilder, "o'brien-uid");

    $this->assertSame('evt-graph-1', $id);
    $this->assertSame("iCalUId eq 'o''brien-uid'", $capturedConfig->queryParameters->filter);
}

public function testFindEventIdReturnsNullWhenNoMatch(): void
{
    $response = new \Microsoft\Graph\Generated\Models\EventCollectionResponse();
    $response->setValue([]);
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn($response);

    $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
    $eventsBuilder->method('get')->willReturn($promise);
    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('events')->willReturn($eventsBuilder);

    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $method = new \ReflectionMethod($t, 'findEventIdByICalUId');
    $this->assertNull($method->invoke($t, $userItemBuilder, 'missing-uid'));
}

public function testCancelGraphEventPostsCancel(): void
{
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn(null);

    $cancelBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\Item\Cancel\CancelRequestBuilder::class);
    $cancelBuilder->expects($this->once())->method('post')->willReturn($promise);

    $eventItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\Item\EventItemRequestBuilder::class);
    $eventItemBuilder->method('cancel')->willReturn($cancelBuilder);

    $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
    $eventsBuilder->method('byEventId')->with('evt-graph-1')->willReturn($eventItemBuilder);

    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('events')->willReturn($eventsBuilder);

    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $method = new \ReflectionMethod($t, 'cancelGraphEvent');
    $method->invoke($t, $userItemBuilder, 'evt-graph-1', 'Meeting cancelled');
}

public function testUpdateGraphEventPatches(): void
{
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn(null);

    $eventItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\Item\EventItemRequestBuilder::class);
    $eventItemBuilder->expects($this->once())->method('patch')->willReturn($promise);

    $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
    $eventsBuilder->method('byEventId')->with('evt-graph-1')->willReturn($eventItemBuilder);

    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('events')->willReturn($eventsBuilder);

    $t = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
    $method = new \ReflectionMethod($t, 'updateGraphEvent');
    $method->invoke($t, $userItemBuilder, 'evt-graph-1', new \Microsoft\Graph\Generated\Models\Event());
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter 'FindEvent|CancelGraph|UpdateGraph'`
Expected: FAIL — `ReflectionException: Method findEventIdByICalUId does not exist`.

- [ ] **Step 3: Add imports**

At the top of `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php`, add alongside the existing `use Microsoft\Graph\...` block:

```php
use Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetQueryParameters;
use Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\Item\Events\Item\Cancel\CancelPostRequestBody;
```

(`Event` is already imported at the top of the file.)

- [ ] **Step 4: Add the three helpers**

Add near the other private/protected send helpers (e.g. after `convertParsedEventToGraphEvent()`):

```php
    /**
     * Looks up the Graph event id for a given iCalendar UID, or null if none matches.
     *
     * Graph stores the originating .ics UID in the event's iCalUId property, so this is
     * the reliable key for correlating an UPDATE/CANCEL .ics back to the created event.
     */
    protected function findEventIdByICalUId(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        string $iCalUId,
    ): ?string {
        $config                  = new EventsRequestBuilderGetRequestConfiguration();
        $config->queryParameters = new EventsRequestBuilderGetQueryParameters();
        // OData string literals escape a single quote by doubling it.
        $escaped                 = \str_replace("'", "''", $iCalUId);
        $config->queryParameters->filter = "iCalUId eq '{$escaped}'";

        $response = $userRequestBuilder->events()->get($config)->wait();
        foreach ($response?->getValue() ?? [] as $event) {
            if (null !== $event->getId()) {
                return $event->getId();
            }
        }

        return null;
    }

    /**
     * Cancels a Graph event via the Calendar API cancel action, which sends a proper
     * cancellation notice to all attendees (unlike a plain delete).
     */
    protected function cancelGraphEvent(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        string $eventId,
        ?string $comment = null,
    ): void {
        $body = new CancelPostRequestBody();
        if (null !== $comment) {
            $body->setComment($comment);
        }
        $userRequestBuilder->events()->byEventId($eventId)->cancel()->post($body)->wait();
    }

    /**
     * Patches an existing Graph event in place so Exchange sends an "updated" notice
     * rather than creating a second invitation.
     */
    protected function updateGraphEvent(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        string $eventId,
        Event $event,
    ): void {
        $userRequestBuilder->events()->byEventId($eventId)->patch($event)->wait();
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter 'FindEvent|CancelGraph|UpdateGraph'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php
git commit -m "feat(graph): add event lookup, cancel, and update helpers"
```

---

### Task 4: Dispatch CREATE / UPDATE / CANCEL inside `send()`

**Goal:** Replace the single "create event" loop in `send()` with a per-operation dispatcher that creates, updates, or cancels each calendar entry as appropriate.

**Files:**
- Modify: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php` (`send()` body + new `dispatchCalendarOperation()`)
- Test: `tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php`

**Acceptance Criteria:**
- [ ] `METHOD:REQUEST` with `SEQUENCE 0` still calls `events()->post()` (CREATE) and never `patch()`/`cancel()` — existing behaviour preserved.
- [ ] `METHOD:REQUEST` with `SEQUENCE > 0` whose UID resolves calls `updateGraphEvent()` (PATCH), not `post()`.
- [ ] `METHOD:REQUEST` with `SEQUENCE > 0` whose UID does NOT resolve falls back to `events()->post()` (CREATE).
- [ ] `METHOD:CANCEL` whose UID resolves calls `cancelGraphEvent()`; when it does not resolve, no Graph event call is made (a notice is logged via `trigger_error`).
- [ ] All existing `MicrosoftGraphCalendarTest` cases still pass.

**Verify:** `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --filter MicrosoftGraph` → OK

**Steps:**

- [ ] **Step 1: Write the failing tests**

These stub the protected seams from Task 3 via partial mocks so each test asserts only routing. `setSequence`/`setMethod` are not settable on a parsed event, so drive the sequence through the .ics `SEQUENCE` property. Add to `MicrosoftGraphCalendarTest`:

```php
private function dispatcherTransport(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userItemBuilder, array $stub): \Swift_Transport_Api_MicrosoftGraphTransport
{
    $graphClient = $this->createMock(GraphServiceClient::class);
    $graphClient->method('me')->willReturn($userItemBuilder);

    $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
        ->setConstructorArgs([$graphClient, null, $this->dispatcher()])
        ->onlyMethods($stub)
        ->getMock();
    $transport->enableCalendarEventConversion();

    return $transport;
}

public function testCancelInviteCancelsMatchingEvent(): void
{
    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->expects($this->never())->method('sendMail');

    $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
    $transport->method('findEventIdByICalUId')->willReturn('evt-graph-1');
    $transport->expects($this->once())->method('cancelGraphEvent')->with($userItemBuilder, 'evt-graph-1');

    $cancelIcs = \implode("\r\n", [
        'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
        'UID:cancel-1@example.com', 'SEQUENCE:1',
        'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
        'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
        'END:VEVENT', 'END:VCALENDAR',
    ]);
    $transport->send($this->messageWithIcs($cancelIcs));
}

public function testCancelInviteWithNoMatchMakesNoEventCall(): void
{
    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->expects($this->never())->method('events');
    $userItemBuilder->expects($this->never())->method('sendMail');

    $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
    $transport->method('findEventIdByICalUId')->willReturn(null);
    $transport->expects($this->never())->method('cancelGraphEvent');

    $cancelIcs = \implode("\r\n", [
        'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
        'UID:cancel-2@example.com',
        'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
        'SUMMARY:Cancelled', 'END:VEVENT', 'END:VCALENDAR',
    ]);
    // @ suppresses the expected E_USER_NOTICE so the test does not error on it.
    @$transport->send($this->messageWithIcs($cancelIcs));
}

public function testUpdateInviteWithSequencePatchesMatchingEvent(): void
{
    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->expects($this->never())->method('sendMail');

    $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'updateGraphEvent']);
    $transport->method('findEventIdByICalUId')->willReturn('evt-graph-1');
    $transport->expects($this->once())->method('updateGraphEvent')->with($userItemBuilder, 'evt-graph-1', $this->isInstanceOf(\Microsoft\Graph\Generated\Models\Event::class));

    $updateIcs = \implode("\r\n", [
        'BEGIN:VCALENDAR', 'METHOD:REQUEST', 'BEGIN:VEVENT',
        'UID:update-1@example.com', 'SEQUENCE:2',
        'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
        'SUMMARY:Rescheduled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
        'END:VEVENT', 'END:VCALENDAR',
    ]);
    $transport->send($this->messageWithIcs($updateIcs));
}

public function testUpdateInviteWithNoMatchFallsBackToCreate(): void
{
    $promise = $this->createMock(\Http\Promise\Promise::class);
    $promise->method('wait')->willReturn(null);
    $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
    $eventsBuilder->expects($this->once())->method('post')->willReturn($promise);
    $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
    $userItemBuilder->method('events')->willReturn($eventsBuilder);

    $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'updateGraphEvent']);
    $transport->method('findEventIdByICalUId')->willReturn(null);
    $transport->expects($this->never())->method('updateGraphEvent');

    $updateIcs = \implode("\r\n", [
        'BEGIN:VCALENDAR', 'METHOD:REQUEST', 'BEGIN:VEVENT',
        'UID:update-2@example.com', 'SEQUENCE:3',
        'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
        'SUMMARY:Rescheduled', 'END:VEVENT', 'END:VCALENDAR',
    ]);
    $transport->send($this->messageWithIcs($updateIcs));
}
```

Note: `messageWithIcs()` was added in Task 2; reuse it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter 'CancelInvite|UpdateInvite'`
Expected: FAIL — the current `send()` always calls `events()->post()` for every extracted invite, so `cancelGraphEvent`/`updateGraphEvent` are never invoked.

- [ ] **Step 3: Replace the event-creation block in `send()` and add the dispatcher**

In `send()`, find the graph-events construction (just before the `try`):

```php
        $graphEvents = [];
        foreach ($calendarInvites as $invite) {
            $graphEvents[] = $this->convertParsedEventToGraphEvent($invite['event']);
        }

        // When we create the event(s), Exchange emails the invitation itself, so by
        // default we skip the duplicate raw email. Callers can opt back in.
        $shouldSendEmail = empty($graphEvents) || $this->sendEmailAlongsideEvent;
```

Replace it with (no pre-built `$graphEvents`; dispatch decides per entry):

```php
        // When we act on the calendar entry, Exchange emails the invitation/cancellation
        // itself, so by default we skip the duplicate raw email. Callers can opt back in.
        $shouldSendEmail = empty($calendarInvites) || $this->sendEmailAlongsideEvent;
```

Then, inside the `try` block, find the events loop:

```php
            foreach ($graphEvents as $graphEvent) {
                // POST /users/{id}/events (or /me/events) — Exchange sends a proper,
                // actionable invitation to each attendee.
                $userRequestBuilder->events()->post($graphEvent)->wait();
            }
```

Replace it with:

```php
            foreach ($calendarInvites as $invite) {
                $this->dispatchCalendarOperation($userRequestBuilder, $invite['event']);
            }
```

The recipient-count loop that follows (`foreach ($calendarInvites as $invite) { $recipient_count += \count($invite['event']->attendees); }`) stays unchanged.

Now add the dispatcher method near the other calendar helpers (e.g. after `convertParsedEventToGraphEvent()`):

```php
    /**
     * Routes a single parsed calendar entry to the correct Graph Calendar operation:
     * CANCEL -> cancel the matching event; REQUEST with SEQUENCE > 0 -> patch the
     * matching event (create if it can't be found); REQUEST with SEQUENCE 0 -> create.
     */
    private function dispatchCalendarOperation(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        Swift_Transport_Api_Calendar_ParsedEvent $parsed,
    ): void {
        if ($parsed->isCancel()) {
            $eventId = null !== $parsed->uid
                ? $this->findEventIdByICalUId($userRequestBuilder, $parsed->uid)
                : null;
            if (null === $eventId) {
                // No matching event (e.g. it was not created via Graph). Nothing
                // actionable; the broken .ics is intentionally not sent.
                \trigger_error("Graph calendar CANCEL: no event matched iCalUId '{$parsed->uid}'", E_USER_NOTICE);

                return;
            }
            $this->cancelGraphEvent($userRequestBuilder, $eventId);

            return;
        }

        $graphEvent = $this->convertParsedEventToGraphEvent($parsed);

        // SEQUENCE > 0 marks a revision to an existing invitation; patch it in place so
        // Exchange sends an "updated" notice rather than a second invite.
        if ($parsed->sequence > 0 && null !== $parsed->uid) {
            $eventId = $this->findEventIdByICalUId($userRequestBuilder, $parsed->uid);
            if (null !== $eventId) {
                $this->updateGraphEvent($userRequestBuilder, $eventId, $graphEvent);

                return;
            }
            // Fall through to create when the original can't be found.
        }

        // POST /users/{id}/events (or /me/events) — Exchange sends a proper, actionable
        // invitation to each attendee.
        $userRequestBuilder->events()->post($graphEvent)->wait();
    }
```

- [ ] **Step 4: Run the focused tests, then the full Graph suite**

Run: `vendor/bin/simple-phpunit tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php --filter 'CancelInvite|UpdateInvite'`
Expected: PASS.

Run: `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests" --filter MicrosoftGraph`
Expected: PASS — every prior `MicrosoftGraphTransportTest` and `MicrosoftGraphCalendarTest` case still green (notably `testRequestInviteIsCreatedAsEventAndEmailSkipped`, `testMultipleInvitesCreateMultipleEvents`).

- [ ] **Step 5: Commit**

```bash
git add lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php
git commit -m "feat(graph): dispatch calendar CANCEL and UPDATE via the Calendar API"
```

---

## Notes for the implementer

- **`Swift_Transport_Api_Calendar_ParsedEvent` is immutable** (readonly properties, constructor-only). Tests drive METHOD/SEQUENCE through the .ics text, not setters — the helper builders in the tests do this.
- **The CANCEL no-match path emits `E_USER_NOTICE`** via `trigger_error`, matching the repo's existing logging convention (see the failover/load-balanced transport logging). Tests must suppress it with `@` or PHPUnit will convert the notice into a failure.
- **`convertParsedEventToGraphEvent()` already sets `transactionId` from the UID**, which makes a CREATE idempotent on retry; leaving it set on the PATCH path is harmless.
- **Existing tests already cover CREATE** (`testRequestInviteIsCreatedAsEventAndEmailSkipped`). Do not weaken them; the dispatcher must keep SEQUENCE-0 REQUESTs on the `events()->post()` path.
