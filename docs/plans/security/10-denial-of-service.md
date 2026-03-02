# Threat 10: Denial of Service via Resource Exhaustion

**STRIDE Category:** Denial of Service
**Severity:** MEDIUM
**Likelihood:** Medium
**CWE:** CWE-400 (Uncontrolled Resource Consumption)

---

## Description

Swiftmailer has no built-in limits on message size, attachment count, recipient count, or connection duration. An attacker or misconfigured application could exhaust server memory, disk space, or network resources by sending extremely large messages, attaching massive files, or targeting thousands of recipients in a single message.

## Attack Vectors

1. **Memory exhaustion via large body** — `Swift_Message::setBody()` accepts arbitrary-length strings; with HTML and inline images, memory can spike
2. **Disk exhaustion via FileSpool** — `FileSpool::queueMessage()` serializes the entire message to disk with no size limit
3. **Large attachment payloads** — No limit on attachment count or total size; API transports base64-encode attachments (33% size increase)
4. **Recipient flooding** — No limit on To/Cc/Bcc counts; a message with thousands of recipients could exhaust SMTP transaction timeouts
5. **Connection starvation** — `AntiFloodPlugin` pauses between batches but doesn't limit total connections
6. **Retry amplification** — `RetryTransport` retries failed sends; combined with large messages, this multiplies resource consumption
7. **Regex catastrophic backtracking** — Complex PHRASE_PATTERN regex in `AbstractHeader` could cause excessive CPU usage on crafted input

## Affected Files

| File | Risk |
|-|-|
| `lib/classes/Swift/Message.php` | No body size limit |
| `lib/classes/Swift/Mime/Attachment.php` | No file size or count limit |
| `lib/classes/Swift/FileSpool.php` | No spool size limit |
| `lib/classes/Swift/Transport/RetryTransport.php` | Retry amplification |
| `lib/classes/Swift/Plugins/AntiFloodPlugin.php` | Batch pausing but no total limit |
| `lib/classes/Swift/Mime/Headers/AbstractHeader.php` | Complex regex (PHRASE_PATTERN) |
| `lib/classes/Swift/Transport/AbstractHttpApiTransport.php` | Base64 attachment inflation |

## Existing Controls

- `AntiFloodPlugin` limits messages per connection and pauses between batches
- `ThrottlerPlugin` rate-limits sending by time or byte count
- SMTP connection timeout (default 30 seconds)
- `FileSpool` retry limit (configurable)
- `RetryTransport` has configurable max retries (default 3)

## Control Gaps

1. **No message size limit** — No check on total message size before sending
2. **No attachment count/size limit** — Unlimited attachments accepted
3. **No recipient count limit** — No maximum for To/Cc/Bcc
4. **No spool quota** — `FileSpool` has no disk usage limit
5. **No memory tracking** — No estimation of memory impact before processing
6. **Regex backtracking** — `PHRASE_PATTERN` is complex but likely safe; should be audited

## Mitigation Plan

### Phase 1: Size and Count Guards (Short-term)
Add optional limits with sensible defaults:
```php
// In Swift_Message or a new validator:
class Swift_MessageLimits
{
    public int $maxBodySize = 10 * 1024 * 1024;     // 10 MB
    public int $maxAttachmentSize = 25 * 1024 * 1024; // 25 MB per attachment
    public int $maxTotalSize = 50 * 1024 * 1024;      // 50 MB total
    public int $maxAttachmentCount = 50;
    public int $maxRecipientCount = 1000;
}
```

### Phase 2: FileSpool Quota (Short-term)
- Add `$maxSpoolSize` property to `FileSpool` that checks total spool directory size before queuing
- Add `$maxSpoolFiles` property to limit the number of queued messages
- Throw `Swift_IoException` when limits are exceeded

### Phase 3: Transport-Level Guards (Medium-term)
- Add message size estimation before sending (sum of body + attachments)
- For API transports, estimate base64-inflated payload size
- Warn or fail if estimated payload exceeds provider limits (SendGrid 30MB, Mailgun 25MB, etc.)

### Phase 4: Regex Audit
- Audit `PHRASE_PATTERN` for catastrophic backtracking with tools like `rxxr2` or `regex-dos`
- If backtracking risk exists, add `(*LIMIT_MATCH=1000000)` PCRE limit or refactor the pattern
- Add timeout wrapper around regex operations on untrusted input

## Test Cases

```php
// Oversized message should be rejected
$message = new Swift_Message('Test');
$message->setBody(str_repeat('x', 100 * 1024 * 1024)); // 100 MB
$this->expectException(Swift_SwiftException::class);
$validator->validate($message);

// Too many recipients should be rejected
$to = array_fill_keys(
    array_map(fn($i) => "user{$i}@example.com", range(1, 2000)),
    null
);
$message->setTo($to);
$this->expectException(Swift_SwiftException::class);
$validator->validate($message);

// FileSpool should enforce quota
$spool = new Swift_FileSpool('/tmp/spool');
$spool->setMaxSpoolSize(1024); // 1 KB
$this->expectException(Swift_IoException::class);
$spool->queueMessage($largeMessage);
```

## Risk After Mitigation

**Residual Risk:** LOW — With size/count limits and spool quotas, resource exhaustion requires deliberate bypass of the validation layer.
