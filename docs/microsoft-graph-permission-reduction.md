# Microsoft Graph Transport: API Permission Reduction

**Author:** Nicolas Corder  
**Date:** 2026-05-28 (calendar section added 2026-05-29)  
**Status:** Implemented, pending Azure configuration change

---

## Executive Summary

Our Microsoft Graph email integration previously required **application-level permissions** that granted the ability to send email as **any user in the entire Microsoft 365 tenant**. This was a significant over-privilege — we only need to send as the authenticated user.

We've updated the transport to use Microsoft Graph's `/me/sendMail` endpoint, which operates under **delegated permissions** and restricts sending to the signed-in user only. This is the least-privilege configuration recommended by Microsoft.

## The Problem

The transport was calling:

```
POST /users/{user-id}/sendMail
```

This endpoint requires the **`Mail.Send` application permission**, which grants:

> "Send mail as any user in the organization, without a signed-in user."

In practice, this means the app registration had the technical capability to send email impersonating any mailbox in the tenant — executives, service accounts, shared mailboxes, etc. This is a broader permission surface than we need.

## The Fix

The transport now defaults to:

```
POST /me/sendMail
```

This endpoint uses the **`Mail.Send` delegated permission**, which grants:

> "Send mail on behalf of the signed-in user."

The app can only send as the user who authenticated. It cannot access or impersonate other mailboxes.

## Permission Comparison

| | Before | After |
|---|---|---|
| **Graph endpoint** | `/users/{id}/sendMail` | `/me/sendMail` |
| **Permission type** | Application | Delegated |
| **Permission name** | `Mail.Send` | `Mail.Send` |
| **Can send as any user** | Yes | No |
| **Can send as signed-in user** | Yes | Yes |
| **Requires admin consent** | Yes | No (user consent sufficient) |
| **Microsoft security tier** | High privilege | Standard |

## Calendar Event Creation (Calendar Graph API)

The transport can now also turn outgoing meeting invitations into real calendar
events. When calendar conversion is enabled, any `text/calendar` part carrying
`METHOD:REQUEST` is created via the Graph Calendar API instead of being attached
to the email as a raw `.ics`:

```
POST /me/events            (delegated — default)
POST /users/{id}/events    (explicit user id / from-address mode)
```

**Why:** Microsoft 365 strips `METHOD:REQUEST` from `.ics` parts sent through
`/sendMail`, so recipients never get an actionable accept/decline prompt.
Creating the event through the Calendar API makes Exchange deliver a proper
meeting request — and, because Exchange emails that invitation itself, the raw
email is suppressed by default (opt back in with `setSendEmailAlongsideEvent(true)`).

Creating an event requires an **additional** Graph permission alongside
`Mail.Send`. Per the [Create event](https://learn.microsoft.com/en-us/graph/api/user-post-events)
reference, both `POST /me/events` and `POST /users/{id}/events` require:

| Permission type | Permission name |
|---|---|
| Delegated (work/school) | `Calendars.ReadWrite` |
| Delegated (personal) | `Calendars.ReadWrite` |
| Application | `Calendars.ReadWrite` |

There is no narrower scope for event creation — `Calendars.ReadWrite` is the
least-privileged permission Graph offers for this call. Used delegated (against
`/me`), it is still scoped to the signed-in user's own calendar only, matching
the least-privilege posture we adopted for mail.

This permission is only needed if calendar conversion is enabled
(`enableCalendarEventConversion()`). Integrations that only send mail need
`Mail.Send` and nothing else.

## What Needs to Change in Azure

In the Azure Portal, under the app registration's **API Permissions** blade:

1. **Remove** `Mail.Send` — Application type
2. **Add** `Mail.Send` — Delegated type
3. **Add** `Calendars.ReadWrite` — Delegated type — *only if calendar event
   conversion is used*
4. If admin consent was previously granted org-wide, an admin should revoke the
   old application permission grant

No code changes are required by consuming applications — the transport constructor remains backwards compatible. Applications that were passing a user ID will continue to work. Applications that omit the user ID (or pass `null`) will automatically use the more secure `/me` endpoint.

## Backwards Compatibility

The change is non-breaking. The constructor signature changed from:

```php
__construct(GraphServiceClient $client, string $sendingAccountUserId, ...)
```

To:

```php
__construct(GraphServiceClient $client, ?string $sendingAccountUserId = null, ...)
```

Existing code that passes a user ID string continues to call `/users/{id}/sendMail` exactly as before. Only new or updated integrations that omit the parameter will use the `/me` path.

## Risk Assessment

| Risk | Mitigation |
|---|---|
| Existing integrations break | Non-breaking change; explicit user ID still supported |
| `/me` doesn't work with client credentials flow | Correct — `/me` requires a delegated token (user sign-in). Document this clearly. |
| Some use case needs to send as another user | The explicit user ID mode and from-address mode remain available for those cases |
| Calendar conversion fails for lack of permission | `Calendars.ReadWrite` (delegated) must be granted; without it, leave calendar conversion disabled and `.ics` parts are sent as plain attachments |

## Recommendation

Switch the Azure app registration to delegated `Mail.Send` at the earliest opportunity. The application-level permission is the single largest privilege escalation vector in our Microsoft integration — removing it eliminates the risk of tenant-wide email impersonation if the app's credentials are ever compromised.
