# Event System

SwiftMailer uses an event-driven architecture. Transports and the mailer dispatch events at key points in the send lifecycle. Plugins subscribe to these events via listener interfaces.

## Event Lifecycle

Every send is bookended by the same two `SendEvent` dispatches, with
transport-family-specific events in between.

**Common to all transports** (SMTP, Sendmail, Spool, Null, and HTTP API):

```
1. beforeSendPerformed   (SendEvent)
   - Carries the message and a copy of the SMTP envelope
   - Plugins can modify the message, replace the envelope, or reject the send

2. Transport delivers the message

3. sendPerformed         (SendEvent)
   - Always fires -- success, failure, or rejection -- and carries the result code
```

**HTTP API transports only** (subclasses of `Swift_Transport_AbstractHttpApiTransport`)
fire one extra event between steps 2 and 3, and dispatch `sendPerformed` from a
`finally` block so it runs even when the send throws:

```
2a. ON SUCCESS:  sentMessage     (SentMessageEvent)    -- Swift_SentMessage + provider message ID
2b. ON FAILURE:  failedMessage   (FailedMessageEvent)  -- exception + failed recipients
                 exceptionThrown (TransportExceptionEvent), then the exception propagates
```

SMTP transports instead emit `commandSent` (CommandEvent) and `responseReceived`
(ResponseEvent) throughout the SMTP conversation, and never emit `sentMessage`
or `failedMessage`.

Transport start/stop lifecycle events:

```
beforeTransportStarted   (TransportChangeEvent)
transportStarted         (TransportChangeEvent)
beforeTransportStopped   (TransportChangeEvent)
transportStopped         (TransportChangeEvent)   -- SMTP/Sendmail only; see Dispatch Points
exceptionThrown          (TransportExceptionEvent)
```

### Dispatch Points

Where each event is created and dispatched (verified against the transport
sources):

| Event | Listener interface | Dispatch points |
|-|-|-|
| `SendEvent` | `SendListener` | `beforeSendPerformed` + `sendPerformed` in every transport's `send()` -- `AbstractSmtpTransport`, `AbstractHttpApiTransport`, `SendmailTransport`, `SpoolTransport`, `NullTransport` |
| `SentMessageEvent` | `SentMessageListener` | `sentMessage` -- `AbstractHttpApiTransport::send()` on success (HTTP API only) |
| `FailedMessageEvent` | `FailedMessageListener` | `failedMessage` -- `AbstractHttpApiTransport::send()` on failure (HTTP API only) |
| `CommandEvent` | `CommandListener` | `commandSent` -- `AbstractSmtpTransport`, as each SMTP command is written |
| `ResponseEvent` | `ResponseListener` | `responseReceived` -- `AbstractSmtpTransport`, on each server response |
| `TransportChangeEvent` | `TransportChangeListener` | `start()`: `beforeTransportStarted` + `transportStarted`; `stop()`: `beforeTransportStopped` + `transportStopped`. SMTP/Sendmail fire all four; HTTP API fires the two start events but **not** `transportStopped` -- `AbstractApiTransport::stop()` emits `beforeTransportStopped` only |
| `TransportExceptionEvent` | `TransportExceptionListener` | `exceptionThrown` -- `throwException()` in `AbstractSmtpTransport` and `AbstractApiTransport` |

## New Events

### SentMessageEvent

Fired after a message is successfully sent. Only dispatched by transports that extend `Swift_Transport_AbstractHttpApiTransport`.

**Class:** `Swift_Events_SentMessageEvent`

```php
class MyListener implements Swift_Events_SentMessageListener
{
    public function sentMessage(Swift_Events_SentMessageEvent $evt): void
    {
        $sent = $evt->getSentMessage();

        echo $sent->getMessageId();          // e.g., "abc-123-def"
        echo $sent->getRecipientCount();     // e.g., 3
        echo $sent->getOriginalMessage()->getSubject();
        echo get_class($evt->getTransport()); // transport that sent it
    }
}

$mailer->registerPlugin(new MyListener());
```

**SentMessageEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getSentMessage()` | `Swift_SentMessage` | The sent message value object |
| `getTransport()` | `Swift_Transport` | The transport that dispatched the event |
| `getSource()` | `Swift_Transport` | Alias for `getTransport()` (inherited) |

**Swift_SentMessage API:**

| Method | Returns | Description |
|-|-|-|
| `getOriginalMessage()` | `Swift_Mime_SimpleMessage` | The message that was sent |
| `getTransport()` | `Swift_Transport` | The transport that sent it |
| `getMessageId()` | `?string` | Provider-assigned message ID |
| `getRecipientCount()` | `int` | Number of recipients accepted |
| `getDebug()` | `array` | Raw result data from the transport |
| `getFailedRecipients()` | `array` | Addresses that failed delivery |

### FailedMessageEvent

Fired when a message fails to send. Only dispatched by transports that extend `Swift_Transport_AbstractHttpApiTransport`.

**Class:** `Swift_Events_FailedMessageEvent`

```php
class MyFailureListener implements Swift_Events_FailedMessageListener
{
    public function failedMessage(Swift_Events_FailedMessageEvent $evt): void
    {
        $exception = $evt->getException();
        $failed = $evt->getFailedRecipients();
        $message = $evt->getMessage();

        error_log(sprintf(
            'Failed to send "%s" to %s: %s',
            $message->getSubject(),
            implode(', ', $failed),
            $exception->getMessage(),
        ));
    }
}

$mailer->registerPlugin(new MyFailureListener());
```

**FailedMessageEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getMessage()` | `Swift_Mime_SimpleMessage` | The message that failed |
| `getException()` | `Swift_TransportException` | The exception that caused the failure |
| `getFailedRecipients()` | `string[]` | Email addresses that were not delivered |
| `getTransport()` | `Swift_Transport` | The transport that dispatched the event |

## Existing Events

### SendEvent

Fired before and after every send attempt. Used by most plugins.

```php
class MySendListener implements Swift_Events_SendListener
{
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        // Modify message, or reject:
        // $evt->reject('Blocked by policy');
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        $result = $evt->getResult();
        // Swift_Events_SendEvent::RESULT_PENDING   (0x0001) -- not yet sent
        // Swift_Events_SendEvent::RESULT_SPOOLED   (0x0011) -- queued in spool
        // Swift_Events_SendEvent::RESULT_SUCCESS   (0x0010) -- sent successfully
        // Swift_Events_SendEvent::RESULT_TENTATIVE (0x0100) -- partial success
        // Swift_Events_SendEvent::RESULT_FAILED    (0x1000) -- send failed
    }
}
```

**SendEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getMessage()` | `Swift_Mime_SimpleMessage` | The message being sent |
| `getTransport()` | `Swift_Transport` | The transport performing the send |
| `getResult()` | `int` | Result bitmask (see constants above) |
| `getFailedRecipients()` | `string[]` | Addresses that were not accepted |
| `reject(?string $reason)` | `void` | Reject the message, preventing send and cancelling bubble |
| `isRejected()` | `bool` | Whether a listener has rejected the message |
| `getRejectionReason()` | `?string` | Human-readable rejection reason, if provided |
| `setEnvelope(?Swift_Envelope)` | `void` | Store an explicit SMTP envelope (kept as a defensive `clone`) |
| `getEnvelope()` | `?Swift_Envelope` | Return a `clone` of the stored envelope, or `null` |

**Envelope isolation (security fix):** `setEnvelope()` stores a `clone` of the
envelope and `getEnvelope()` returns a fresh `clone` on every call, so a listener
cannot reach through the event to mutate the transport's live envelope by
reference. To change the envelope, a listener must call `setEnvelope()` with a new
or modified instance; the transport re-reads it via `getEnvelope()` after
`beforeSendPerformed`.

### Pre-Send Rejection

Plugins can prevent sending in `beforeSendPerformed` using the `reject()` method (preferred) or `cancelBubble(true)`. The transport will not attempt to send, and `sendPerformed` still fires. SMTP, Sendmail, Spool, and Null transports set the result to `RESULT_FAILED` first; the HTTP API base (`Swift_Transport_AbstractHttpApiTransport`) dispatches `sendPerformed` without changing the result, so on a rejected API send it stays `RESULT_PENDING`.

The `reject()` method is preferred because it records a rejection reason and also cancels the bubble automatically:

```php
public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
{
    if ($this->shouldReject($evt->getMessage())) {
        $evt->reject('Blocked by allowlist policy');
    }
}
```

### ResponseEvent

Fired by SMTP transports when a server response is received.

```php
class MyResponseListener implements Swift_Events_ResponseListener
{
    public function responseReceived(Swift_Events_ResponseEvent $evt): void
    {
        $response = $evt->getResponse(); // Raw SMTP response string
        $valid    = $evt->isValid();     // Whether the response indicates success
    }
}
```

**ResponseEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getResponse()` | `string` | The raw SMTP response string from the server |
| `isValid()` | `bool` | Whether the response indicates success |
| `getSource()` | `Swift_Transport` | The transport that received the response |

### CommandEvent

Fired by SMTP transports when a command is sent to the server.

```php
class MyCommandListener implements Swift_Events_CommandListener
{
    public function commandSent(Swift_Events_CommandEvent $evt): void
    {
        $command      = $evt->getCommand();      // e.g., "EHLO example.com\r\n"
        $successCodes = $evt->getSuccessCodes();  // e.g., [250]
    }
}
```

**CommandEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getCommand()` | `string` | The SMTP command sent to the server |
| `getSuccessCodes()` | `int[]` | Numeric response codes that indicate success |
| `getSource()` | `Swift_Transport` | The transport that sent the command |

### TransportChangeEvent

Fired when a transport starts or stops. Four dispatch points cover the full lifecycle.

```php
class MyTransportListener implements Swift_Events_TransportChangeListener
{
    public function beforeTransportStarted(Swift_Events_TransportChangeEvent $evt): void
    {
        // Cancel startup by calling $evt->cancelBubble(true)
    }

    public function transportStarted(Swift_Events_TransportChangeEvent $evt): void {}
    public function beforeTransportStopped(Swift_Events_TransportChangeEvent $evt): void {}
    public function transportStopped(Swift_Events_TransportChangeEvent $evt): void {}
}
```

**TransportChangeEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getTransport()` | `Swift_Transport` | The transport changing state |
| `getSource()` | `Swift_Transport` | Alias (inherited from `EventObject`) |

### TransportExceptionEvent

Fired when a transport encounters an error. Listeners can suppress the exception by cancelling the bubble.

```php
class MyExceptionListener implements Swift_Events_TransportExceptionListener
{
    public function exceptionThrown(Swift_Events_TransportExceptionEvent $evt): void
    {
        $exception = $evt->getException();
        error_log('Transport error: '.$exception->getMessage());

        // Suppress the exception so it does not propagate:
        // $evt->cancelBubble(true);
    }
}
```

**TransportExceptionEvent API:**

| Method | Returns | Description |
|-|-|-|
| `getException()` | `Swift_TransportException` | The exception that was thrown |
| `getSource()` | `Swift_Transport` | The transport that threw the exception |

## Listener Interfaces

| Interface | Method(s) | Event |
|-|-|-|
| `Swift_Events_SendListener` | `beforeSendPerformed`, `sendPerformed` | `SendEvent` |
| `Swift_Events_SentMessageListener` | `sentMessage` | `SentMessageEvent` |
| `Swift_Events_FailedMessageListener` | `failedMessage` | `FailedMessageEvent` |
| `Swift_Events_ResponseListener` | `responseReceived` | `ResponseEvent` |
| `Swift_Events_TransportChangeListener` | `beforeTransportStarted`, `transportStarted`, `beforeTransportStopped`, `transportStopped` | `TransportChangeEvent` |
| `Swift_Events_TransportExceptionListener` | `exceptionThrown` | `TransportExceptionEvent` |
| `Swift_Events_CommandListener` | `commandSent` | `CommandEvent` |

## Registering Listeners

Listeners (plugins) are registered via the mailer or directly on a transport:

```php
// Via mailer (preferred)
$mailer->registerPlugin($myPlugin);

// Via transport (for transport-level events)
$transport->registerPlugin($myPlugin);
```

A single class can implement multiple listener interfaces to react to different events. For the plugins that ship with SwiftMailer, see [plugins.md](plugins.md).

### API transports and a null dispatcher

HTTP API transports (see [api-transports.md](api-transports.md)) declare their dispatcher as `?Swift_Events_EventDispatcher` and null-guard every dispatch (`$this->eventDispatcher?->...`). When the dispatcher is `null`:

- No events fire for that transport -- including `sentMessage` and `failedMessage`.
- `registerPlugin()` silently does nothing (`$this->eventDispatcher?->bindEventListener(...)`), so any plugin you register is dropped without error.

Transports built through the DSN factory always receive a `Swift_Events_SimpleEventDispatcher`, so this only affects HTTP API transports you construct directly without passing a dispatcher. SMTP-based transports take a required dispatcher and are never null.
