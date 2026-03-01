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

        echo $sent->getMessageId();        // e.g., "abc-123-def"
        echo $sent->getRecipientCount();   // e.g., 3
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
        // Modify message, or cancel:
        // $evt->cancelBubble(true);
    }

    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        $result = $evt->getResult();
        // Swift_Events_SendEvent::RESULT_SUCCESS
        // Swift_Events_SendEvent::RESULT_TENTATIVE
        // Swift_Events_SendEvent::RESULT_FAILED
    }
}
```

### Pre-Send Rejection

Plugins can cancel sending in `beforeSendPerformed` by calling `$evt->cancelBubble(true)`. The transport will not attempt to send, and `sendPerformed` will fire with `RESULT_FAILED`. This is how `AllowlistPlugin` cancels sends when no recipients remain.

```php
public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
{
    if ($this->shouldReject($evt->getMessage())) {
        $evt->cancelBubble(true); // Message will not be sent
    }
}
```

### ResponseEvent

Fired by SMTP transports when a server response is received.

### TransportChangeEvent

Fired when a transport starts or stops.

### TransportExceptionEvent

Fired when a transport encounters an error. Listeners can suppress the exception by cancelling the bubble.

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
