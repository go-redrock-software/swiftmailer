# Event System

SwiftMailer uses an event-driven architecture. Transports and the mailer dispatch events at key points in the send lifecycle. Plugins subscribe to these events via listener interfaces.

## Event Lifecycle

When `$mailer->send($message)` is called, events fire in this order:

```
1. beforeSendPerformed  (SendEvent)
   - Plugins can modify the message or cancel sending

2. Transport sends the message

3a. ON SUCCESS:
    sentMessage           (SentMessageEvent)    [NEW]
    - Carries Swift_SentMessage with provider message ID

3b. ON FAILURE:
    failedMessage         (FailedMessageEvent)  [NEW]
    - Carries exception and failed recipient list

4. sendPerformed          (SendEvent)
   - Always fires (success or failure), carries result code
```

Transport lifecycle events:

```
beforeTransportStarted   (TransportChangeEvent)
transportStarted         (TransportChangeEvent)
beforeTransportStopped   (TransportChangeEvent)
transportStopped         (TransportChangeEvent)
exceptionThrown          (TransportExceptionEvent)
```

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
| `setEnvelope(?Swift_Envelope)` | `void` | Set an explicit SMTP envelope for this send |
| `getEnvelope()` | `?Swift_Envelope` | Get the SMTP envelope, if one was provided |

### Pre-Send Rejection

Plugins can prevent sending in `beforeSendPerformed` using the `reject()` method (preferred) or `cancelBubble(true)`. The transport will not attempt to send, and `sendPerformed` will fire with `RESULT_FAILED`.

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

A single class can implement multiple listener interfaces to react to different events.
