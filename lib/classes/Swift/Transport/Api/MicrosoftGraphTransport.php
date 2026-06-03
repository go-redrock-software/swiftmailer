<?php

use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\Attendee;
use Microsoft\Graph\Generated\Models\AttendeeType;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\DateTimeTimeZone;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\Event;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Location;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody;
use Microsoft\Graph\GraphServiceClient;

class Swift_Transport_Api_MicrosoftGraphTransport extends Swift_Transport_AbstractApiTransport
{
    private GraphServiceClient $client;

    private ?string $sendingAccountUserId;

    private bool $shouldUseFromAddress = false;

    /**
     * When true, text/calendar parts carrying METHOD:REQUEST are created via the
     * Graph Calendar API instead of being attached to a sendMail call.
     */
    private bool $convertCalendarToEvents = false;

    /**
     * When converting an invite to a Graph event, whether to ALSO send the raw
     * email. Off by default because Exchange already emails the invitation when
     * the event is created, so sending the email too would duplicate it.
     */
    private bool $sendEmailAlongsideEvent = false;

    private Swift_Transport_Api_Calendar_IcsParser $icsParser;

    /**
     * Graph's /sendMail endpoint rejects request bodies larger than ~4 MB. Attachments
     * at or above this size must be uploaded via an upload session against a draft
     * message instead. 3 MB is Microsoft's documented boundary for switching approaches.
     */
    public const LARGE_ATTACHMENT_THRESHOLD = 3 * 1024 * 1024;

    private int $largeAttachmentThreshold = self::LARGE_ATTACHMENT_THRESHOLD;

    /**
     * @param string|null $sendingAccountUserId null = use /me endpoint (delegated Mail.Send only)
     */
    public function __construct(
        GraphServiceClient $client,
        ?string $sendingAccountUserId = null,
        ?Swift_Events_EventDispatcher $dispatcher = null,
    ) {
        $this->client               = $client;
        $this->eventDispatcher      = $dispatcher;
        $this->sendingAccountUserId = $sendingAccountUserId;
        $this->icsParser            = new Swift_Transport_Api_Calendar_IcsParser();
    }

    public function getSendingAccountUserId(): ?string
    {
        return $this->sendingAccountUserId;
    }

    public function setSendingAccountUserId(?string $sendingAccountUserId): void
    {
        $this->sendingAccountUserId = $sendingAccountUserId;
        $this->shouldUseFromAddress = false;
    }

    public function useFromAddressAsSendingAccountUserId(): void
    {
        $this->shouldUseFromAddress = true;
    }

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

    public function isUsingMeEndpoint(): bool
    {
        return null === $this->sendingAccountUserId && !$this->shouldUseFromAddress;
    }

    /**
     * Convert calendar invitations (text/calendar; METHOD:REQUEST) into real Graph
     * calendar events rather than sending the raw .ics as an attachment.
     *
     * M365 strips METHOD:REQUEST from .ics parts sent via /sendMail, so recipients
     * never get a prompt to accept/decline. Creating the event via the Calendar API
     * makes Exchange deliver a proper, actionable meeting request instead.
     */
    public function enableCalendarEventConversion(): void
    {
        $this->convertCalendarToEvents = true;
    }

    public function disableCalendarEventConversion(): void
    {
        $this->convertCalendarToEvents = false;
    }

    public function isCalendarEventConversionEnabled(): bool
    {
        return $this->convertCalendarToEvents;
    }

    /**
     * Controls whether the raw email is still sent after an invite is converted to
     * a Graph event. Default false: Exchange emails the invitation itself when the
     * event is created, so sending the email too would deliver a duplicate.
     */
    public function setSendEmailAlongsideEvent(bool $sendEmailAlongsideEvent): void
    {
        $this->sendEmailAlongsideEvent = $sendEmailAlongsideEvent;
    }

    public function isSendingEmailAlongsideEvent(): bool
    {
        return $this->sendEmailAlongsideEvent;
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        // nothing to do for a "ping" really

        return true;
    }

    public function start(): void
    {
        if (!$this->started) {
            if ($evt = $this->eventDispatcher?->createTransportChangeEvent($this)) {
                $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStarted');
                if ($evt->bubbleCancelled()) {
                    return;
                }
            }

            // nothing to "start" with an API connection, but we should still honor the event dispatcher expectations
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
            }

            $this->started = true;
        }
    }

    /**
     * Sends a Swift_Mime_SimpleMessage using the Microsoft Graph API.
     *
     * @param Swift_Mime_SimpleMessage $message           the message to send
     * @param array|null               &$failedRecipients A reference to an array that will contain any failed recipients
     *
     * @throws Swift_TransportException
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        if (null === $failedRecipients) {
            $failedRecipients = [];
        }

        if ($evt = $this->eventDispatcher?->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->cancelBubble(false);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');

                return 0;
            }
        }
        // create and dispatch event before transport start
        $event = $this->eventDispatcher->createTransportChangeEvent($this);
        $this->eventDispatcher->dispatchEvent($event, 'beforeTransportStarted');
        if ($event->bubbleCancelled()) {
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->cancelBubble(false);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }

            return 0;
        }

        $recipient_count = 0;

        $recipient    = new Recipient();
        $emailAddress = new EmailAddress();

        $failedRecipients[] = \array_key_first($message->getTo());
        $emailAddress->setAddress(\array_key_first($message->getTo()));
        $emailAddress->setName(\array_values($message->getTo())[0]);
        $recipient->setEmailAddress($emailAddress);

        $graphMessage = new Message();
        $graphMessage->setSubject($message->getSubject());

        $body = new ItemBody();
        $body->setContent($message->getBody());
        try {
            $body->setContentType(
                new BodyType(
                    match ($message->getBodyContentType()) {
                        'text/plain' => 'text',
                        'text/html'  => 'html',
                    },
                ),
            );
            // @codeCoverageIgnoreStart
        } catch (ReflectionException $e) {
            $this->throwException(new Swift_TransportException("Failed to set Graph BodyType: {$e->getMessage()}"));
        }
        // @codeCoverageIgnoreEnd
        $graphMessage->setBody($body);
        $graphMessage->setToRecipients([$recipient]);

        // Swift addresses are an [address => name] map, so iterate by key. (array_map
        // over the array would hand the callback the name strings, not the pairs.)
        if (\count($message->getCc() ?? []) > 0) {
            $ccRecipients = [];
            foreach ($message->getCc() ?? [] as $address => $name) {
                $failedRecipients[] = $address;
                ++$recipient_count;
                $ccRecipients[] = $this->convertSwiftEmailAddressToGraphRecipient([$address => $name]);
            }
            $graphMessage->setCcRecipients($ccRecipients);
        }

        if (\count($message->getBcc() ?? []) > 0) {
            $bccRecipients = [];
            foreach ($message->getBcc() ?? [] as $address => $name) {
                $failedRecipients[] = $address;
                ++$recipient_count;
                $bccRecipients[] = $this->convertSwiftEmailAddressToGraphRecipient([$address => $name]);
            }
            $graphMessage->setBccRecipients($bccRecipients);
        }

        // Detect calendar invitations we should convert into real Graph events. M365
        // strips METHOD:REQUEST from .ics parts sent via sendMail, so the recipient
        // never sees an actionable invite. Creating the event via the Calendar API
        // makes Exchange deliver a proper meeting request instead.
        $calendarInvites   = $this->convertCalendarToEvents ? $this->extractCalendarInvites($message) : [];
        $inviteAttachments = \array_column($calendarInvites, 'attachment');

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

        $recipient    = new Recipient();
        $emailAddress = new EmailAddress();
        $replyTo      = $message->getReplyTo();
        if (\is_array($replyTo)) {
            $replyTo = \array_key_first($replyTo);
            $emailAddress->setAddress($replyTo);
            $recipient->setEmailAddress($emailAddress);
            $graphMessage->setReplyTo([$recipient]);
            ++$recipient_count;
        }

        $sendMailBody = new SendMailPostRequestBody();
        $sendMailBody->setMessage($graphMessage);

        $graphEvents = [];
        foreach ($calendarInvites as $invite) {
            $graphEvents[] = $this->convertParsedEventToGraphEvent($invite['event']);
        }

        // When we create the event(s), Exchange emails the invitation itself, so by
        // default we skip the duplicate raw email. Callers can opt back in.
        $shouldSendEmail = empty($graphEvents) || $this->sendEmailAlongsideEvent;

        try {
            $userRequestBuilder = $this->resolveUserRequestBuilder($message);

            foreach ($graphEvents as $graphEvent) {
                // POST /users/{id}/events (or /me/events) — Exchange sends a proper,
                // actionable invitation to each attendee.
                $userRequestBuilder->events()->post($graphEvent)->wait();
            }

            if ($shouldSendEmail) {
                $userRequestBuilder->sendMail()->post($sendMailBody)->wait();
            }

            foreach ($calendarInvites as $invite) {
                $recipient_count += \count($invite['event']->attendees);
            }

            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            }
            $failedRecipients = [];
        } catch (Throwable $e) {
            $exception = new Swift_TransportException("Failed to send email: {$e->getMessage()}", $e->getCode(), $e);
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->setFailedRecipients($failedRecipients);
            }
            $recipient_count = 0;
            $failure         = true;
        } finally {
            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        if (($failure ?? false) && isset($exception)) {
            throw $exception;
        }

        return $recipient_count;
    }

    public function convertSwiftEmailAddressToGraphRecipient(array $swift_email, bool $strict = false): Recipient
    {
        $recipient    = new Recipient();
        $emailAddress = new EmailAddress();

        $emailAddress->setAddress(\array_key_first($swift_email));

        if ($strict && !isset(\array_values($swift_email)[0])) {
            try {
                $dec = \json_encode($swift_email, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $dec = '[error parsing email array]';
                throw new InvalidArgumentException("Invalid Swift EmailAddress given: {$dec}");
            }
        }

        if (isset(\array_values($swift_email)[0])) {
            $emailAddress->setName(\array_values($swift_email)[0]);
        } elseif ($strict) {
            throw new InvalidArgumentException("Invalid Swift EmailAddress given: {$dec}");
        }

        $recipient->setEmailAddress($emailAddress);

        return $recipient;
    }

    public function convertSwiftAttachmentToGraphAttachment(Swift_Attachment $swiftAttachment): Attachment
    {
        $graphAttachment = new FileAttachment();
        $graphAttachment->setName($swiftAttachment->getFilename());
        $graphAttachment->setContentType($swiftAttachment->getContentType());
        $graphAttachment->setIsInline('attachment' !== $swiftAttachment->getDisposition());
        $graphAttachment->setContentBytes(
            Utils::streamFor(\base64_encode($swiftAttachment->getBody())),
        ); // Graph API requires the content to be base64-encoded
        $graphAttachment->setSize($swiftAttachment->getSize());

        return $graphAttachment;
    }

    /**
     * Resolves the Graph user endpoint: /me (delegated) or /users/{id} (application).
     */
    private function resolveUserRequestBuilder(Swift_Mime_SimpleMessage $message): Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder
    {
        if ($this->shouldUseFromAddress) {
            $from = $message->getFrom();
            if (!$from) {
                $this->throwException(new Swift_TransportException('Cannot use from-address mode: message has no From address'));
            }

            return $this->client->users()->byUserId(\array_key_first($from));
        }

        if (null === $this->sendingAccountUserId) {
            return $this->client->me();
        }

        return $this->client->users()->byUserId($this->sendingAccountUserId);
    }

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

    /**
     * Finds calendar parts that should be converted into Graph events.
     *
     * Only text/calendar parts whose METHOD is REQUEST are returned — those are the
     * invitations M365 mangles. Other methods (PUBLISH, CANCEL, REPLY, ...) and
     * unparseable parts are left to be sent as ordinary attachments.
     *
     * @return array<int, array{attachment: Swift_Mime_SimpleMimeEntity, event: Swift_Transport_Api_Calendar_ParsedEvent}>
     */
    private function extractCalendarInvites(Swift_Mime_SimpleMessage $message): array
    {
        $invites = [];

        foreach ($message->getChildren() as $child) {
            if (!$this->isCalendarPart($child)) {
                continue;
            }

            // A malformed .ics must not abort the whole send: if we can't parse it,
            // fall back to shipping it as an ordinary attachment (pre-conversion
            // behavior) rather than letting the parser exception escape send().
            try {
                $parsed = $this->icsParser->parse($child->getBody());
            } catch (InvalidArgumentException $e) {
                continue;
            }

            if (null === $parsed || !$parsed->isRequest()) {
                continue;
            }

            $invites[] = ['attachment' => $child, 'event' => $parsed];
        }

        return $invites;
    }

    /**
     * Whether a MIME child is an iCalendar part (by content type or .ics filename).
     */
    private function isCalendarPart(Swift_Mime_SimpleMimeEntity $child): bool
    {
        if ('text/calendar' === \strtolower((string) $child->getContentType())) {
            return true;
        }

        $filename = \method_exists($child, 'getFilename') ? (string) $child->getFilename() : '';

        return '' !== $filename && \str_ends_with(\strtolower($filename), '.ics');
    }

    /**
     * Maps a parsed iCalendar event onto a Microsoft Graph Event model.
     */
    public function convertParsedEventToGraphEvent(Swift_Transport_Api_Calendar_ParsedEvent $parsed): Event
    {
        $event = new Event();
        $event->setSubject($parsed->subject);

        if (null !== $parsed->body) {
            $body = new ItemBody();
            $body->setContent($parsed->body);
            try {
                $body->setContentType(new BodyType($parsed->isHtmlBody ? 'html' : 'text'));
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                $this->throwException(new Swift_TransportException("Failed to set Graph BodyType: {$e->getMessage()}"));
            }
            // @codeCoverageIgnoreEnd
            $event->setBody($body);
        }

        $start = new DateTimeTimeZone();
        $start->setDateTime($parsed->start);
        $start->setTimeZone($parsed->startTimeZone);
        $event->setStart($start);

        $end = new DateTimeTimeZone();
        $end->setDateTime($parsed->end);
        $end->setTimeZone($parsed->endTimeZone);
        $event->setEnd($end);

        if ($parsed->isAllDay) {
            $event->setIsAllDay(true);
        }

        if (null !== $parsed->location) {
            $location = new Location();
            $location->setDisplayName($parsed->location);
            $event->setLocation($location);
        }

        $attendees = [];
        foreach ($parsed->attendees as $participant) {
            if (empty($participant['email'])) {
                continue;
            }

            $attendee     = new Attendee();
            $emailAddress = new EmailAddress();
            $emailAddress->setAddress($participant['email']);
            if (!empty($participant['name'])) {
                $emailAddress->setName($participant['name']);
            }
            $attendee->setEmailAddress($emailAddress);

            try {
                $attendee->setType(new AttendeeType($participant['type']));
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                $attendee->setType(new AttendeeType(AttendeeType::REQUIRED));
            }
            // @codeCoverageIgnoreEnd

            $attendees[] = $attendee;
        }
        if (!empty($attendees)) {
            $event->setAttendees($attendees);
        }

        // Reuse the iCalendar UID as a Graph transactionId so a retried send doesn't
        // create a duplicate calendar entry (Graph rejects a repeated transactionId).
        if (null !== $parsed->uid) {
            $event->setTransactionId(\substr($parsed->uid, 0, 256));
        }

        return $event;
    }

    protected function getApiConnection(): GraphServiceClient
    {
        return $this->client;
    }
}
