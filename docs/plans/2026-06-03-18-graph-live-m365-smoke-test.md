# Graph Transport — Live M365 Smoke-Test Plan

**Status:** Required before treating the 6.5.0 Graph features as production-ready.

## Why this exists

The Microsoft Graph large-attachment upload-session flow and the calendar
CANCEL/UPDATE operations shipped in 6.5.0 are covered only by unit tests that
mock the Graph SDK (`LargeFileUploadTask`, the Calendar `cancel`/PATCH calls,
event lookup by `iCalUId`). No test has ever exercised these paths against a
real Microsoft 365 tenant. Mocks encode our *assumptions* about the SDK's
request/response shapes; only a live run validates them.

Until this checklist passes against a real tenant, document these flows to
consumers as validated-against-mocks-only.

## Prerequisites

- An Azure AD app registration with application permissions:
  `Mail.Send`, `Calendars.ReadWrite` (admin-consented).
- A test mailbox in the tenant and at least one external recipient you control
  (to confirm delivery and inspect raw MIME / calendar state).
- Client credentials (tenant id, client id, client secret) available to the
  test harness via env vars — never committed.

## Checklist

### 1. Small message (regression baseline)
- [ ] Send a plain message (< threshold) via `sendMail`. Confirm it arrives and
      the reported recipient count is correct.

### 2. Large attachment via upload session
- [ ] Send a message with an attachment between the configured threshold
      (default 3 MB) and 150 MB. Confirm:
  - [ ] A draft is created, the upload session completes, and the message is
        sent (not silently dropped).
  - [ ] The recipient receives the full, uncorrupted attachment (verify byte
        size / checksum end-to-end).
  - [ ] The draft is not left orphaned in the mailbox after send.
- [ ] Send an attachment > 150 MB. Confirm it is rejected up front with
      `Swift_TransportException` and no draft is created.

### 3. Calendar invitation (REQUEST, SEQUENCE 0)
- [ ] Send a `METHOD:REQUEST` invite with `enableCalendarEventConversion()`.
      Confirm a Graph event is created and the attendee is notified.
- [ ] Send the same invite carrying a real (non-`.ics`) attachment. Confirm the
      attachment still reaches the recipient.

### 4. Calendar UPDATE (REQUEST, SEQUENCE > 0)
- [ ] Re-send the invite with the same `iCalUId` and `SEQUENCE > 0`. Confirm the
      existing event is PATCHed in place (no duplicate event appears on the
      attendee's calendar).
- [ ] Send an UPDATE for an `iCalUId` that does not exist. Confirm it falls back
      to creating a new event.

### 5. Calendar CANCEL
- [ ] Send a `METHOD:CANCEL` for an existing event. Confirm the event is
      cancelled and the attendee is notified, and the reported recipient count
      reflects the actual notification.
- [ ] Send a `METHOD:CANCEL` for a non-matching `iCalUId`. Confirm it is a
      no-op and the reported recipient count is zero (not inflated by parsed
      `.ics` attendees).

## Sign-off

When every box is checked against a real tenant, record the tenant, date, and
Graph SDK version here and remove the "validated-against-mocks-only" caveat from
consumer-facing docs.

- Tenant / date / SDK version: _____________________
