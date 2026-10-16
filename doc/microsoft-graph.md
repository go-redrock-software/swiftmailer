# Microsoft Graph Transport

`Swift_Transport_Api_MicrosoftGraphTransport` sends mail through the Microsoft Graph
API (Microsoft 365 / Exchange Online) instead of SMTP. It builds on the Microsoft
Graph PHP SDK (`microsoft/microsoft-graph`) and its strongly-typed model objects, and
adds three things the raw SDK does not: least-privilege `/me` sending, transparent
large-attachment uploads, and conversion of `.ics` meeting invitations into real Graph
calendar events.

This guide is the authoritative reference for the transport. For the wider transport
architecture and the tag/metadata conventions shared by other providers, see
[api-transports.md](api-transports.md); for DSN-based wiring see [dsn.md](dsn.md); for the
event model see [events.md](events.md).

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MicrosoftGraphTransport` |
| **Base class** | `Swift_Transport_AbstractApiTransport` (SDK-backed; HTTP policy through Kiota) |
| **Dependency** | `microsoft/microsoft-graph` (`^2.10`) |
| **Constructor** | `(GraphServiceClient $client, ?string $sendingAccountUserId = null, ?Swift_Events_EventDispatcher $dispatcher = null)` |
| **DSN scheme** | `microsoft-graph` (explicit bearer or application credentials) |
| **Auth** | Explicit DSN authentication or injected `GraphServiceClient` (OAuth2 via `microsoft/kiota-authentication-phpleague`) |
| **Tags / Metadata** | Not supported |

---

## Requirements

- PHP 8.3+ (the library baseline).
- `microsoft/microsoft-graph ^2.10` and its transitive Kiota packages. These ship as
  hard dependencies in this fork's `composer.json`, so a normal `composer install` pulls
  them in.
- A Microsoft 365 / Entra ID (Azure AD) tenant with an app registration (below).

---

## Request destinations and SDK compatibility

Each Swift send uses an isolated copy of a standard Kiota `GuzzleRequestAdapter`.
All Graph resource requests refuse HTTP redirects: `sendMail`, draft creation,
small attachments, upload sessions and chunks, draft send, and calendar
lookup/create/update/cancel. A 3xx response, including a rejected transfer with redirect headers, becomes a
`Swift_Transport_NonRetryableException`, so Swift retry/pool wrappers do not replay it. Later steps in that send are not attempted.
`ping()` remains local-only.

The caller's `GraphServiceClient`, adapter, base URL, HTTP options and handler
stack remain intact. Scoped authentication copies share the existing token cache;
serializer factories, SDK retry handling, tracing and CAE retain their behavior. A
subsequent request made directly through the original SDK client still uses its
original HTTP policy. The service client is cloned without rerunning its
constructor, preserving caller-registered serialization factories and global
registries. Client subclasses and builder overrides must use the copied adapter;
fixed adapter getters and returned builders tied to an unscoped adapter fail
before sending. Existing protected Swift draft/upload signatures are preserved.

The client and adapter must support cloning and expose the Kiota Guzzle HTTP configuration
expected by this integration. Compatibility is checked before sending; unsupported
custom adapters or SDK layouts fail with a clear non-retryable error. Injected
HTTP clients must expose a Guzzle-compatible configuration and callable handler.
Their options and middleware are retained; custom HTTP-client `send`/`request`
method overrides are not copied. Adapter subclasses must honor the inherited
HTTP configuration contract.

### OAuth token requests

The standard Kiota/PhpLeague token HTTP client also refuses redirects. This covers
initial client-credential, authorization-code and on-behalf-of grants, token
refresh, and CAE challenge grants through `PhpLeagueAccessTokenProvider`, including
its use by `BaseBearerTokenAuthenticationProvider`, `PhpLeagueAuthenticationProvider`
and `GraphPhpLeagueAuthenticationProvider`.

The transport copies the known provider chain without constructors and attaches
a scoped token HTTP client. The original provider and HTTP client identities,
defaults and middleware remain available to the caller, including inside HTTP
callbacks. The existing cache/context objects are shared. Normal SDK changes to
derived scopes or a context returned by a CAE callback are published when
authentication resolves; a newer caller/reentrant state update is preserved.
Normal successful refresh, token reuse, CAE callbacks and ordinary errors retain
SDK behavior internally. Returned failures detach unsafe messages/causes; callback
PHP Errors retain a direct nonretryable marker instead of the original cause.

When a CAE callback returns a different token context, the fallback grant uses
that context's current client credentials, code, redirect URI, grant-specific
parameters and PKCE verifier. SDK scopes, capability claims, registered grant
validation and native token caching are retained. Certificate refresh uses the
canonical `urn:ietf:params:oauth:client-assertion-type:jwt-bearer` assertion type;
the existing signed assertion and refresh token are preserved.

A returned tenant also updates the scoped token and authorization endpoints.
This requires League `GenericProvider` endpoints ending in
`/{old-tenant}/oauth2/v2.0/token` and `/{old-tenant}/oauth2/v2.0/authorize`.
Configured origins, national clouds, path prefixes and queries are preserved.
Tenant IDs must be single safe path segments. Opaque endpoints and custom
providers that do not honor grant preparation or endpoint configuration fail
before the fallback token request. Form, JSON, query-string and HTTP Basic
serialization remain available when their current-context parameters can be
verified. Unverifiable bodies, duplicate form/query parameters and conflicting
context values fail permanently. Caller middleware is checked again immediately
before dispatch.

The caller's grant factory and registrations retain their identity. Context and
endpoint changes are published together only while the source provider and
endpoint values still match the state captured by that operation. A reentrant
send or caller update takes precedence over older completion state.

A refused token redirect remains non-retryable even if CAE replaces its exception
or attempts another grant. No later token or resource request is sent through that
failed scope. Compatibility is validated before traffic, including cache hits
that could later require CAE. Known provider subclasses must support cloning and
honor copied OAuth/token ownership and HTTP setters/getters.

Custom `AuthenticationProvider` implementations and non-PhpLeague
`AccessTokenProvider` implementations retain their own HTTP policy. Their private
networking is outside this token safeguard; the Graph resource-request policy
still applies. SES also retains its separate SDK policy.

---

## Azure app registration

The transport never handles OAuth itself — it consumes an already-authenticated
`GraphServiceClient`. You configure authentication once in Entra ID and hand the SDK the
credentials.

1. In the [Entra admin center](https://entra.microsoft.com) → **App registrations** →
   **New registration**. Note the **Application (client) ID** and **Directory (tenant) ID**.
2. **Certificates & secrets** → create a **client secret** (or upload a certificate).
3. **API permissions** → add the Microsoft Graph permissions for your sending mode (see
   the table in [Permissions per mode](#permissions-per-mode)). Grant admin consent for
   application permissions.
4. For delegated (`/me`) sending, also configure a **redirect URI** under
   **Authentication** for whichever OAuth flow you use (authorization code, device code, …).

### Permissions per mode

The permission *type* (delegated vs application) is what changes between modes, not the
permission *name*.

| Sending mode | Graph endpoint | Mail permission | Extra for calendar conversion | Token / OAuth flow |
|-|-|-|-|-|
| **Default** (`/me`) | `POST /me/sendMail` | `Mail.Send` (delegated) | `Calendars.ReadWrite` (delegated) | Signed-in user token — authorization-code / device-code / on-behalf-of |
| **Explicit user id** | `POST /users/{id}/sendMail` | `Mail.Send` (application) | `Calendars.ReadWrite` (application) | App-only token — client credentials |
| **From-address** | `POST /users/{from}/sendMail` | `Mail.Send` (application) | `Calendars.ReadWrite` (application) | App-only token — client credentials |

Key points:

- **`/me` requires a *delegated* token.** An app-only (client-credentials) token has no
  "me", so the default mode only works with a signed-in-user flow. Conversely, the
  explicit-user-id and from-address modes are normally driven by an app-only token.
- Delegated `Mail.Send` grants "send mail as the signed-in user" only. Application
  `Mail.Send` grants "send as **any** user in the tenant" — a much larger surface, so
  prefer `/me` where the auth flow allows a signed-in user.
- `Calendars.ReadWrite` is the narrowest scope Graph offers for creating events; it is
  only needed when [calendar conversion](#calendar-invitation-conversion) is enabled.
  Mail-only integrations need `Mail.Send` and nothing else.

---

## Constructing the transport

The auth flow lives in the `GraphServiceClient`. The two common shapes:

### Application permissions (send as any user / `/users/{id}`)

```php
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

$tokenRequestContext = new ClientCredentialContext(
    'tenant-id',
    'client-id',
    'client-secret',
);
// Client-credentials tokens use the /.default scope set granted in Entra ID.
$graphClient = new GraphServiceClient($tokenRequestContext);

$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user@company.com');
$mailer    = new Swift_Mailer($transport);
```

### Delegated permissions (least-privilege `/me`)

```php
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext;

$tokenRequestContext = new AuthorizationCodeContext(
    'tenant-id',
    'client-id',
    'client-secret',
    $authCode,                       // obtained from the OAuth redirect
    'https://app.example.com/callback',
);
$graphClient = new GraphServiceClient($tokenRequestContext, ['Mail.Send']);

// No user id => the transport uses /me/sendMail (delegated Mail.Send only).
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient);
$mailer    = new Swift_Mailer($transport);
```

> `AuthorizationCodeContext` is one option; any delegated Kiota token context (device
> code, on-behalf-of, …) works — the transport only cares that `$graphClient->me()`
> resolves. Add `'Calendars.ReadWrite'` to the scope array if you enable calendar
> conversion.

The optional third constructor argument is a `Swift_Events_EventDispatcher`. When you
build the mailer through the normal Swiftmailer wiring it is injected for you; pass it
explicitly only when constructing the transport by hand and you want plugin/event support.

---

## Sending modes

The transport resolves the target mailbox on every `send()` in this precedence order:

| Priority | Trigger | Endpoint used |
|-|-|-|
| 1 | `useFromAddressAsSendingAccountUserId()` was called | `/users/{message From address}` |
| 2 | A sending-account user id is set (constructor arg or `setSendingAccountUserId()`) | `/users/{id}` |
| 3 | Neither of the above (default) | `/me` |

```php
// Default — delegated /me (least privilege). isUsingMeEndpoint() === true.
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient);

// Explicit mailbox — application /users/{id}.
$transport->setSendingAccountUserId('other-user@company.com');

// Send as whatever the message's From header says — application /users/{from}.
$transport->useFromAddressAsSendingAccountUserId();

// Inspect the active mode.
$transport->isUsingMeEndpoint();       // true only when no user id AND from-address mode is off
$transport->getSendingAccountUserId(); // the configured id, or null
```

Notes:

- `setSendingAccountUserId()` also clears from-address mode, so the two are mutually
  exclusive — the last one you call wins.
- From-address mode throws `Swift_TransportException` at send time if the message has no
  `From` address.
- `isUsingMeEndpoint()` returns true only when the user id is `null` **and** from-address
  mode is off.

---

## Basic usage

```php
$message = (new Swift_Message('Quarterly report'))
    ->setFrom(['sender@company.com' => 'Reporting Bot'])
    ->setTo(['jane@contoso.com' => 'Jane Doe'])
    ->setCc('team@company.com')
    ->setBody('<p>See attached.</p>', 'text/html');

$sent = $mailer->send($message);   // returns the recipient count
```

The message body must be `text/plain` or `text/html`; the transport maps those to Graph's
`text` / `html` body types and does not handle other content types. Recipient names are
preserved (Graph `EmailAddress.name`), and attachments are converted to Graph
`FileAttachment` objects (base64-encoded, with inline/attachment disposition honored).

---

## Large attachments

Graph's `sendMail` caps the whole request body near 4 MB. To lift that limit the transport
routes any message carrying a large attachment through a **draft + upload-session** flow
instead of a single `sendMail` call.

| Constant / setter | Value | Meaning |
|-|-|-|
| `LARGE_ATTACHMENT_THRESHOLD` | `3 * 1024 * 1024` (3 MB) | At/above this decoded size, an attachment is streamed via an upload session |
| `getLargeAttachmentThreshold()` / `setLargeAttachmentThreshold(int $bytes)` | default 3 MB | Override the threshold; throws `InvalidArgumentException` for a non-positive byte count |
| `MAX_ATTACHMENT_SIZE` | `150 * 1024 * 1024` (150 MB) | Graph's hard ceiling for a single uploaded item; larger is rejected |

How it works:

1. Attachments are partitioned into small (`< threshold`) and large (`>= threshold`) by
   measuring the **decoded body length** — not the `Content-Disposition` `size` parameter,
   which is frequently unset.
2. If every attachment is small, the message is sent as an ordinary `sendMail` with the
   files inlined as base64 — unchanged from the simple path.
3. If any attachment is large, the whole message switches to the draft flow: create a draft
   (`POST /messages`), attach the small files inline, stream each large file through an
   upload session (`createUploadSession` + the SDK's `LargeFileUploadTask`), then send the
   draft (`POST /messages/{id}/send`).
4. Any attachment **larger than 150 MB** is rejected up front with a
   `Swift_TransportException` *before* the draft is created, so a doomed send never orphans
   a half-built draft. (Exactly 150 MB is allowed; only strictly-greater is rejected.)

```php
// Lower the threshold to 1 MB, e.g. to stay well under a proxy body limit.
$transport->setLargeAttachmentThreshold(1 * 1024 * 1024);
```

> **Live-tenant coverage:** automated tests use real Graph/Kiota clients and SDK
> upload tasks against synthetic Guzzle handlers, including sequential multi-chunk
> progress and empty-body completion. No live M365 mailbox is used in these tests.

---

## Calendar invitation conversion

Microsoft 365 strips the `METHOD:REQUEST` / `METHOD:CANCEL` line from `.ics` parts sent as
`sendMail` attachments, so recipients get a dead file instead of an actionable meeting
request — no Accept/Decline, nothing on their calendar. When conversion is enabled, the
transport instead drives the **Graph Calendar API**, which makes Exchange deliver a proper
meeting request (and email it on your behalf).

```php
$transport->enableCalendarEventConversion();   // opt-in; off by default
$transport->isCalendarEventConversionEnabled();
$transport->disableCalendarEventConversion();
```

### What gets converted

A message child is treated as a calendar part when its content type is `text/calendar` or
its filename ends in `.ics`. Only parts whose `METHOD` is `REQUEST` or `CANCEL` are
converted; everything else rides along untouched.

| `.ics` `METHOD` + state | Action | Graph call |
|-|-|-|
| `REQUEST`, `SEQUENCE` = 0 | Create the event | `POST /{user}/events` |
| `REQUEST`, `SEQUENCE` > 0, matching event found | Update in place (Exchange sends an "updated" notice) | `PATCH /{user}/events/{id}` (matched by `iCalUId`) |
| `REQUEST`, `SEQUENCE` > 0, no matching event | Falls back to create | `POST /{user}/events` |
| `CANCEL`, matching event found | Cancel (sends a cancellation notice) | `POST /{user}/events/{id}/cancel` |
| `CANCEL`, no matching event | No-op; emits an `E_USER_NOTICE`, nobody notified | — |
| `PUBLISH`, `REPLY`, other | Left as an ordinary attachment | — |
| Malformed / unparseable `.ics` | Left as an ordinary attachment (parse failure never aborts the send) | — |

### Behavior details

- **The raw email is suppressed by default.** Creating/cancelling/updating the event makes
  Exchange email the invitation itself, so sending the raw message too would duplicate it.
  Re-enable a covering email (still without the broken `.ics`) with
  `setSendEmailAlongsideEvent(true)` / inspect via `isSendingEmailAlongsideEvent()`.
- **Real attachments alongside an invite are still delivered.** If the message carries a
  genuine non-`.ics` attachment next to the invite, the email *is* sent (so the attachment
  reaches the recipient) even though the invite was converted — only the `.ics` part is
  dropped from the outgoing mail.
- **Invite correlation.** The originating `.ics` UID plus normalized attendees are
  stored in the `SwiftInviteKey` single-value extended property. The transport does
  not set Graph `transactionId` or use Graph-generated `iCalUId` for this lookup.
- **Matching updates/cancels.** The extended-property filter locates an existing
  event, following valid same-collection pages before concluding there is no match.
  A complete empty result permits creation for REQUEST; CANCEL becomes a no-op.
  Malformed or unresolved lookup results cannot silently create another event.
- **Partial notification failures.** Once an event notification is accepted, a later
  failure in the same Swift send is terminal, preventing an automatic replay of
  the earlier invitation through retry or pool transports.
- **Times are passed as wall-clock + time-zone name**, with no UTC conversion math, which
  avoids DST off-by-one bugs.

### Parser scope

The bundled `Swift_Transport_Api_Calendar_IcsParser` is intentionally narrow (RFC 5545,
dependency-free). It reads only the **first `VEVENT`** and extracts subject, description,
location, start/end (with `TZID`/UTC/all-day handling and `DURATION` fallback), organizer,
attendees (mapping `ROLE`/`CUTYPE` to required/optional/resource), `UID`, `METHOD`, and
`SEQUENCE`. It does **not** support recurrence, alarms, multiple `VEVENT`s, or
`VTODO`/`VJOURNAL`. The parsed data lands in the immutable
`Swift_Transport_Api_Calendar_ParsedEvent`.

> **Not yet validated against a live tenant.** Like the large-attachment flow, the calendar
> create/update/cancel operations are covered only by mock-based unit tests. Smoke-test the
> full invite → accept/decline round trip against a real M365 mailbox before production use.

---

## Recipient handling

- **To / CC / BCC** are all sent. Each address becomes a Graph `Recipient`
  (address + display name) and is set on the message via `setToRecipients` /
  `setCcRecipients` / `setBccRecipients`. (Earlier versions dropped CC/BCC with a
  `TypeError`; that was fixed by iterating the `address => name` map by key.)
- **Reply-To**: only the **first** reply-to address is applied (as a bare address, without
  its display name), and it counts toward the returned recipient total — see the quirk in
  [Known limitations](#known-limitations).
- **Return value / recipient count.** `send()` returns the number of recipients notified:
  To + CC + BCC, plus one if a Reply-To is present, plus the attendees actually notified by
  each calendar operation. A `CANCEL` that matches no event contributes **0** (it notified
  no one) rather than inflating the count with the parsed `.ics` attendees. On failure the
  count is `0`.
- **`&$failedRecipients`.** Populated with the To/CC/BCC addresses while the payload is
  built and cleared to `[]` on success; on failure the send event is given that recipient
  list as the failed set.

---

## Events dispatched

The transport is event-aware and null-safe (it works with or without a dispatcher). During
`send()` it fires, in order:

| Event | When | Cancellation effect |
|-|-|-|
| `beforeSendPerformed` | Before building the payload | Bubble-cancel → send is skipped, `sendPerformed` fires with `RESULT_FAILED`, returns `0` |
| `beforeTransportStarted` | Before contacting Graph | Bubble-cancel → returns `0` (with `RESULT_FAILED` if a send event exists) |
| `sendPerformed` | Always, in a `finally` | Carries `RESULT_SUCCESS` or `RESULT_FAILED` (+ failed recipients) |

`start()` separately fires `beforeTransportStarted` / `transportStarted`, and `stop()`
fires `beforeTransportStopped` (inherited). See [events.md](events.md) and
[plugins.md](plugins.md) for the listener model. `registerPlugin()` binds a listener to the
dispatcher as usual. If no dispatcher was injected, the first registration creates
a simple dispatcher; subsequent listeners reuse it.

---

## DSN

The operational factory builds real Graph SDK clients from explicit credentials:

```text
microsoft-graph://ACCESS_TOKEN@default
microsoft-graph://CLIENT_ID:CLIENT_SECRET@default?tenant_id=TENANT&user_id=sender%40example.com
```

Bearer mode defaults to delegated `/me`, or accepts an explicit `user_id`.
Application mode requires an explicit mailbox and tenant and uses the fixed
Graph `.default` scope. The SDK owns application token exchange, caching and
renewal. Fixed bearer tokens require caller renewal; a claims challenge requires
a new token. Construction performs no I/O or ambient credential discovery.

See [the Graph DSN contract](dsn.md#microsoft-graph-sdk-dsns) for strict options,
encoding, fixed global endpoints and separate caller-owned resource/token HTTP
clients. Resource HTTP defaults cannot override SDK authentication or authenticate
foreign uploads: the factory removes `auth` and `Authorization` from its isolated
client copy. Native SDK injection supports advanced flows and CAE callbacks.
The `retry`, `failover` and `roundrobin` DSN wrappers retain the same terminal
acceptance and diagnostic boundaries as native construction.

---

## Operation acceptance

The transport checks success status and raw response metadata before Kiota model
hydration and before retry middleware can replay a result. SDK parsing must then
confirm the required models. These are acceptance acknowledgements, not final
message-delivery receipts.

| Operation | Required success evidence |
| --- | --- |
| sendMail, draft send, event cancel | `202` with an empty body |
| Draft, small attachment, event creation | `201` with a nonempty string ID |
| Event update | `200` with the requested event ID |
| Event lookup | `200` with a valid `value` collection of event IDs; paginated empty pages are resolved before creation |
| Upload session | `201` with a usable HTTPS upload URL, unexpired ISO timestamp and initial byte-zero range |
| Upload progress | `200` with the exact next sequential byte range |
| Final upload | `201` and a usable HTTPS `Location`; the final request must cover the last byte |

Missing, malformed, coerced or interrupted successful responses produce a
`Swift_Transport_NonRetryableException` with static diagnostics and the observed
status. This refusal persists if SDK/caller middleware replaces an error, and
Swift retry/failover/round-robin cannot replay it. A separately initiated send
gets fresh state. Ordinary non-success responses, native transient retries and
CAE remain supported before any notification has been accepted.

Outlook's documented final upload can have an empty body. Its `Location` confirms
completion without a JSON model; an empty completion body is presented to Kiota
without a JSON content-type hint. Other valid JSON and nonseekable responses are
buffered for the normal SDK parser. Upload URLs remain opaque and are never
copied into returned diagnostics.

Sources: [sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail),
[draft/send](https://learn.microsoft.com/en-us/graph/api/message-send),
[create message](https://learn.microsoft.com/en-us/graph/api/user-post-messages),
[attachments](https://learn.microsoft.com/en-us/graph/api/message-post-attachments),
[upload sessions and chunks](https://learn.microsoft.com/en-us/graph/outlook-large-attachments),
[event list](https://learn.microsoft.com/en-us/graph/api/user-list-events),
[create](https://learn.microsoft.com/en-us/graph/api/user-post-events),
[update](https://learn.microsoft.com/en-us/graph/api/event-update), and
[cancel](https://learn.microsoft.com/en-us/graph/api/event-cancel).

## Failure diagnostics

Returned SDK operation failures are Swift transport exceptions with a static
message and safe numeric code. Kiota stores HTTP status separately from the PHP
Exception code; the transport preserves that status. Standard OAuth token status
is recorded by the scoped HTTP guard before League decoding can discard it.
Only the latest request/authentication operation contributes that metadata.

Use `getCode()` and `Swift_Transport_RetryPolicy::isTerminalFailure()` when handling
these failures. Original SDK messages and `getPrevious()` causes are intentionally
unavailable because their graphs can retain OAuth credentials, requests and upload
URLs. Ordinary failures remain Swift_TransportException; hard refusals, PHP Error
causes and failed metadata inspection use Swift_Transport_NonRetryableException.
The default retry classifier recognizes final 401/403 and standard OAuth
authentication error codes, including `400 invalid_client`, through a static
authentication-failure diagnostic. It retains existing transient statuses,
including HTTP 500. Native `ContinuousAccessEvaluationException` also supplies a
typed authentication signal when the safe code is zero; it stays zero and does
not acquire a hard-failure marker. This stops default Swift retries of an
unresolved claims challenge. Real authoritative transient status still wins.
Other shared classifier rules remain unchanged.
Arbitrary caller frames, SDK logging and callbacks remain caller-controlled.

## Known limitations

- **`send()` does not mark the transport started.** It dispatches `beforeTransportStarted`
  but never `transportStarted` and never sets the `started` flag, so `isStarted()` stays
  `false` after a successful send. Call `start()` (or `ping()`, which starts on demand)
  explicitly if downstream code relies on the started state.
- **Reply-To is lossy.** Only the first Reply-To address is used, its display name is
  dropped, and it is counted as an extra recipient in the return value.
- **Body must be `text/plain` or `text/html`.** Any other body content type is unmapped and
  will surface as an unhandled error rather than a wrapped `Swift_TransportException`.
- **No tags / metadata.** Unlike SendGrid/Mailgun/etc., the `X-Mailer-Tag` and
  `X-Mailer-Metadata-*` conventions are not implemented for Graph.
- **Large-attachment and calendar tests use synthetic endpoints.** Real SDK serialization, authentication, parsing and upload logic are exercised, but live-tenant delivery is not covered.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|-|-|-|
| `401 InvalidAuthenticationToken` / token has no `me` | Using the default `/me` mode with an **app-only** (client-credentials) token | Use a delegated flow (`AuthorizationCodeContext`, device code, …) for `/me`, or switch to `setSendingAccountUserId()` for app-only |
| `403 ErrorAccessDenied` on `sendMail` | Missing/unconsented `Mail.Send` of the right *type* | Grant delegated `Mail.Send` for `/me`, or application `Mail.Send` (with admin consent) for `/users/{id}` |
| `403` when a calendar invite is present | `Calendars.ReadWrite` not granted | Add `Calendars.ReadWrite` (delegated for `/me`, application for `/users/{id}`), or leave conversion disabled so the `.ics` sends as a plain attachment |
| `Cannot use from-address mode: message has no From address` | `useFromAddressAsSendingAccountUserId()` set but the message has no `From` | Set a `From` address, or use `setSendingAccountUserId()` / default `/me` |
| `Attachment '…' is N bytes, exceeding Microsoft Graph's 157286400-byte limit` | Attachment over the 150 MB ceiling | Host the file and send a link, or split it |
| Recipients get a dead `.ics` with no Accept/Decline | Calendar conversion not enabled, or the `.ics` isn't `METHOD:REQUEST`/`CANCEL` | Call `enableCalendarEventConversion()`; confirm the payload's `METHOD` and that it's `text/calendar` |
| `Graph calendar CANCEL: no event matched invite '…'` (E_USER_NOTICE) | Cancelling an event that was never created via Graph (so no invite-key match) | Expected — nothing to cancel; the notice is informational |
| Duplicate calendar entries on retry | `UID` missing from the `.ics` (no `transactionId` to de-dupe on) | Ensure invitations carry a stable `UID` |
| `InvalidArgumentException: Large-attachment threshold must be a positive byte count` | `setLargeAttachmentThreshold()` called with `< 1` | Pass a positive byte count |

---

## Related

- [api-transports.md](api-transports.md) — all API transports, shared lifecycle, tags/metadata
- [dsn.md](dsn.md) — DSN schemes and meta-transport wrappers
- [events.md](events.md) / [plugins.md](plugins.md) — the event and plugin model
- Source: `lib/classes/Swift/Transport/Api/MicrosoftGraphTransport.php`,
  `lib/classes/Swift/Transport/Api/Calendar/IcsParser.php`,
  `lib/classes/Swift/Transport/Api/Calendar/ParsedEvent.php`
- Tests: `tests/unit/Swift/Transport/Api/MicrosoftGraphTransportTest.php`,
  `tests/unit/Swift/Transport/Api/MicrosoftGraphCalendarTest.php`
