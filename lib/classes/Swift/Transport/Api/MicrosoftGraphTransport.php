<?php

use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Core\Tasks\LargeFileUploadTask;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\AttachmentItem;
use Microsoft\Graph\Generated\Models\AttachmentType;
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
use Microsoft\Graph\Generated\Models\UploadSession;
use Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetQueryParameters;
use Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\Item\Events\Item\Cancel\CancelPostRequestBody;
use Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\CreateUploadSession\CreateUploadSessionPostRequestBody;
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
     * Graph's /sendMail endpoint caps the whole request body near 4 MB. Microsoft documents
     * 3 MB as the point at which a single attachment should switch from inline base64 to an
     * upload session, leaving headroom for the rest of the payload. Attachments at or above
     * this size use the draft + upload-session flow.
     */
    public const LARGE_ATTACHMENT_THRESHOLD = 3 * 1024 * 1024;

    /**
     * Microsoft Graph caps a single Outlook-item attachment uploaded via an upload
     * session at 150 MB. Anything larger cannot be sent and is rejected up front.
     */
    public const MAX_ATTACHMENT_SIZE = 150 * 1024 * 1024;

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

        $attachmentsToSend = [];
        foreach ($message->getChildren() ?? [] as $swiftAttachment) {
            // Skip calendar parts we are converting to Graph events; shipping the raw
            // .ics alongside is exactly what M365 mangles.
            if (\in_array($swiftAttachment, $inviteAttachments, true)) {
                continue;
            }
            $attachmentsToSend[] = $swiftAttachment;
        }

        [$smallAttachments, $largeAttachments] = $this->partitionAttachmentsBySize($attachmentsToSend);
        $useDraftFlow                          = [] !== $largeAttachments;

        // The sendMail payload can only carry attachments small enough to inline; when a
        // large attachment is present the draft flow attaches everything itself.
        if (!$useDraftFlow) {
            $graphAttachments = \array_map(
                fn (Swift_Attachment $a): Attachment => $this->convertSwiftAttachmentToGraphAttachment($a),
                $smallAttachments,
            );
            if (!empty($graphAttachments)) {
                $graphMessage->setAttachments($graphAttachments);
            }
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

        // When we act on the calendar entry, Exchange emails the invitation/cancellation
        // itself, so by default we skip the duplicate raw email — UNLESS the message also
        // carries real (non-.ics) attachments, which would otherwise be silently dropped.
        $shouldSendEmail = empty($calendarInvites) || $this->sendEmailAlongsideEvent || [] !== $attachmentsToSend;

        try {
            $userRequestBuilder = $this->resolveUserRequestBuilder($message);

            foreach ($calendarInvites as $invite) {
                $recipient_count += $this->dispatchCalendarOperation($userRequestBuilder, $invite['event']);
            }

            if ($shouldSendEmail) {
                if ($useDraftFlow) {
                    $this->sendViaDraft($userRequestBuilder, $graphMessage, $smallAttachments, $largeAttachments);
                } else {
                    $userRequestBuilder->sendMail()->post($sendMailBody)->wait();
                }
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
     * Sends a message that carries at least one large attachment via the draft +
     * upload-session flow, because Graph's /sendMail rejects bodies over ~4 MB.
     *
     * Creates a draft, attaches small files inline, streams large files through upload
     * sessions, then sends the draft.
     *
     * @param array<int, Swift_Mime_SimpleMimeEntity> $smallAttachments
     * @param array<int, Swift_Mime_SimpleMimeEntity> $largeAttachments
     *
     * @throws Swift_TransportException
     */
    protected function sendViaDraft(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        Message $graphMessage,
        array $smallAttachments,
        array $largeAttachments,
    ): void {
        // Reject anything past Graph's 150 MB upload-session ceiling BEFORE creating the
        // draft — otherwise we orphan a draft that then fails deep in the chunked PUT
        // with an opaque error.
        foreach ($largeAttachments as $attachment) {
            $size = $this->attachmentByteSize($attachment);
            if ($size > self::MAX_ATTACHMENT_SIZE) {
                $this->throwException(new Swift_TransportException(\sprintf(
                    "Attachment '%s' is %d bytes, exceeding Microsoft Graph's %d-byte limit.",
                    $attachment->getFilename(),
                    $size,
                    self::MAX_ATTACHMENT_SIZE,
                )));

                // throwException() can return if a listener cancels bubbling; abort
                // rather than create a draft we can never finish.
                return;
            }
        }

        $draft = $userRequestBuilder->messages()->post($graphMessage)->wait();
        if (null === $draft || null === $draft->getId()) {
            $this->throwException(new Swift_TransportException('Graph did not return a draft message id; cannot attach large files.'));

            // throwException() can return if a listener cancels bubbling; abort rather than deref null.
            return;
        }

        $messageBuilder = $userRequestBuilder->messages()->byMessageId($draft->getId());

        // Files under the threshold are cheap to inline directly on the draft.
        foreach ($smallAttachments as $attachment) {
            $messageBuilder->attachments()->post($this->convertSwiftAttachmentToGraphAttachment($attachment))->wait();
        }

        foreach ($largeAttachments as $attachment) {
            $contents       = (string) $attachment->getBody();
            $attachmentItem = new AttachmentItem();
            try {
                $attachmentItem->setAttachmentType(new AttachmentType(AttachmentType::FILE));
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                $this->throwException(new Swift_TransportException("Failed to set Graph AttachmentType: {$e->getMessage()}"));
            }
            // @codeCoverageIgnoreEnd
            $attachmentItem->setName($attachment->getFilename());
            $attachmentItem->setSize(\strlen($contents));
            $attachmentItem->setContentType((string) $attachment->getContentType());
            $attachmentItem->setIsInline('attachment' !== $attachment->getDisposition());

            $uploadBody = new CreateUploadSessionPostRequestBody();
            $uploadBody->setAttachmentItem($attachmentItem);

            $uploadSession = $messageBuilder->attachments()->createUploadSession()->post($uploadBody)->wait();
            if (null === $uploadSession) {
                $this->throwException(new Swift_TransportException("Graph returned no upload session for attachment '{$attachment->getFilename()}'."));

                // throwException() can return if a listener cancels bubbling; abort rather than deref null.
                return;
            }

            $this->uploadLargeAttachment($uploadSession, $contents);
        }

        // POST /messages/{id}/send takes no body and moves the draft to Sent Items.
        $messageBuilder->send()->post()->wait();
    }

    /**
     * Streams a large attachment's bytes into a previously-created upload session.
     *
     * Isolated so tests can stub the chunked transfer; the SDK's LargeFileUploadTask
     * performs the sequential PUT requests against the session's upload URL.
     */
    protected function uploadLargeAttachment(UploadSession $uploadSession, string $contents): void
    {
        $task = new LargeFileUploadTask(
            $uploadSession,
            $this->client->getRequestAdapter(),
            Utils::streamFor($contents),
        );
        $task->upload()->wait();
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
        return $this->attachmentByteSize($attachment) >= $this->largeAttachmentThreshold;
    }

    /**
     * Decoded byte size of an attachment's body. Isolated so size-based guards can be
     * exercised in tests without allocating a multi-hundred-megabyte string.
     */
    protected function attachmentByteSize(Swift_Mime_SimpleMimeEntity $attachment): int
    {
        return \strlen((string) $attachment->getBody());
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
     * Only text/calendar parts whose METHOD is REQUEST or CANCEL are returned — those
     * are the ones M365 mangles when sent as a sendMail attachment. Other methods
     * (PUBLISH, REPLY, …) and unparseable parts are left to be sent as attachments.
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

            // REQUEST invites and CANCEL notices are both mangled by M365 when sent as
            // raw .ics, so both are routed through the Calendar API. Other methods
            // (PUBLISH, REPLY, …) are left to ride along as ordinary attachments.
            if (null === $parsed || (!$parsed->isRequest() && !$parsed->isCancel())) {
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

    /**
     * Routes a single parsed calendar entry to the correct Graph Calendar operation:
     * CANCEL -> cancel the matching event; REQUEST with SEQUENCE > 0 -> patch the
     * matching event (create if it can't be found); REQUEST with SEQUENCE 0 -> create.
     *
     * @return int the number of recipients actually notified by Graph for this entry
     *             (0 when the operation contacted Graph zero times, e.g. a CANCEL that
     *             matched no event)
     */
    private function dispatchCalendarOperation(
        Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userRequestBuilder,
        Swift_Transport_Api_Calendar_ParsedEvent $parsed,
    ): int {
        if ($parsed->isCancel()) {
            $eventId = null !== $parsed->uid
                ? $this->findEventIdByICalUId($userRequestBuilder, $parsed->uid)
                : null;
            if (null === $eventId) {
                // No matching event (e.g. it was not created via Graph). Nothing
                // actionable; the broken .ics is intentionally not sent, and nobody
                // was notified.
                \trigger_error("Graph calendar CANCEL: no event matched iCalUId '{$parsed->uid}'", E_USER_NOTICE);

                return 0;
            }
            $this->cancelGraphEvent($userRequestBuilder, $eventId);

            return \count($parsed->attendees);
        }

        $graphEvent = $this->convertParsedEventToGraphEvent($parsed);

        // SEQUENCE > 0 marks a revision to an existing invitation; patch it in place so
        // Exchange sends an "updated" notice rather than a second invite.
        if ($parsed->sequence > 0 && null !== $parsed->uid) {
            $eventId = $this->findEventIdByICalUId($userRequestBuilder, $parsed->uid);
            if (null !== $eventId) {
                $this->updateGraphEvent($userRequestBuilder, $eventId, $graphEvent);

                return \count($parsed->attendees);
            }
            // Fall through to create when the original can't be found.
        }

        // POST /users/{id}/events (or /me/events) — Exchange sends a proper, actionable
        // invitation to each attendee.
        $userRequestBuilder->events()->post($graphEvent)->wait();

        return \count($parsed->attendees);
    }

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
        $escaped                         = \str_replace("'", "''", $iCalUId);
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
    ): void {
        $userRequestBuilder->events()->byEventId($eventId)->cancel()->post(new CancelPostRequestBody())->wait();
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

    protected function getApiConnection(): GraphServiceClient
    {
        return $this->client;
    }
}
