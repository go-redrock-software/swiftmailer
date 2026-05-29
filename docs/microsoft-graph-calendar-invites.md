# Microsoft Graph Transport: Working Calendar Invites

**Author:** Nicolas Corder
**Date:** 2026-05-29
**Status:** Implemented

---

## Executive Summary

Microsoft 365 silently breaks calendar invitations sent as `.ics` attachments through
the Graph `sendMail` API. It strips the `METHOD:REQUEST` line, so the recipient's mail
client treats the file as a plain attachment instead of an actionable meeting request —
no Accept / Tentative / Decline buttons, nothing added to their calendar.

This is a well-known M365 limitation. The supported fix is to stop attaching the `.ics`
and instead create the meeting through the **Graph Calendar API** (`POST /events`), which
makes Exchange send a proper, actionable invitation on our behalf.

We've added an opt-in flag to the Microsoft Graph transport that does exactly that:
when enabled, it detects `METHOD:REQUEST` calendar parts, parses them, and creates real
Graph calendar events instead of mailing the broken attachment.

## The Problem

When you attach an invite and send it via Graph `sendMail`:

```
POST /me/sendMail
  └─ message.attachments[]: text/calendar; method=REQUEST  ← method=REQUEST stripped by M365
```

The recipient gets an email with a dead `.ics` file. Outlook and most clients won't
prompt them to respond, and the event never lands on their calendar.

## The Fix

When conversion is enabled, the transport routes invitations through the Calendar API:

```
POST /me/events            (or /users/{id}/events)
  └─ subject, start, end, location, body, attendees[]
```

Exchange then emails each attendee a native meeting request. The broken `.ics` is
dropped from the outgoing message entirely.

## How It Works

Three pieces of scaffolding were added:

| Component | Responsibility |
|---|---|
| `Swift_Transport_Api_Calendar_IcsParser` | Dependency-free RFC 5545 parser. Unfolds lines, handles quoted parameters, escaping, and TZID / UTC / all-day dates. Extracts the first `VEVENT`. |
| `Swift_Transport_Api_Calendar_ParsedEvent` | Immutable value object holding the parsed event (subject, start/end + time zone, location, attendees, organizer, UID, METHOD, etc.). |
| `Swift_Transport_Api_MicrosoftGraphTransport` | New flag + logic to detect invites, map them onto the Graph `Event` model, and POST them to the events endpoint. |

### Usage

```php
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $dispatcher);
$transport->enableCalendarEventConversion();

// A message carrying a text/calendar; METHOD:REQUEST part now creates a real
// calendar event; Exchange sends the actionable invite. No code change needed
// at the call site beyond enabling the flag.
$mailer->send($message);
```

By default the duplicate raw email is **not** sent, because creating the event already
makes Exchange email the attendees. To send a covering email as well (without the broken
`.ics`):

```php
$transport->setSendEmailAlongsideEvent(true);
```

## Behavior Summary

| Scenario | Result |
|---|---|
| Conversion disabled (default) | Unchanged — `.ics` sent as a normal attachment |
| Enabled + `METHOD:REQUEST` invite | Event created via Calendar API; Exchange sends the invite; raw email skipped |
| Enabled + `METHOD:REQUEST` + `setSendEmailAlongsideEvent(true)` | Event created **and** a covering email sent (without the `.ics`) |
| Enabled + non-REQUEST (`PUBLISH`, `CANCEL`, `REPLY`) | Left as a normal attachment — not converted |
| Enabled, message has no calendar part | Unchanged — ordinary `sendMail` |

## Scope and Limitations

- Only `METHOD:REQUEST` is converted. `CANCEL` and updates need the existing Graph event
  ID, which can't be derived from an `.ics` alone, so they fall through to normal
  attachment behavior. The parser already extracts `METHOD`, `UID` and `SEQUENCE`, so
  this is a natural place to extend later.
- The originating iCalendar `UID` is reused as the Graph `transactionId`, so a retried
  send won't create a duplicate calendar entry.
- Wall-clock times are passed to Graph verbatim alongside the time-zone name — no UTC
  conversion math, which avoids DST off-by-one bugs.
- The parser is intentionally narrow: single `VEVENT`, no recurrence/alarm handling.

## Backwards Compatibility

The change is non-breaking and fully opt-in. The transport constructor is unchanged, and
the conversion only happens after an explicit `enableCalendarEventConversion()` call.
Existing senders behave exactly as before.

## Testing

- `IcsParserTest` — 10 tests covering UTC/TZID/all-day parsing, duration fallback, line
  unfolding, quoted parameters, escaping, and malformed input.
- `MicrosoftGraphCalendarTest` — 7 tests covering the flag accessors, event creation +
  email skipping, send-alongside mode, the disabled path, non-REQUEST fallback, and the
  Graph `Event` field mapping.
