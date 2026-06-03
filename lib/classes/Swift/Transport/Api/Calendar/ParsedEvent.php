<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

/**
 * Immutable representation of a single VEVENT parsed from an iCalendar payload.
 *
 * Dates are stored exactly as Microsoft Graph's DateTimeTimeZone expects them: a
 * local wall-clock string ("Y-m-d\TH:i:s", no offset) paired with a separate time
 * zone name. This avoids any UTC conversion math when handing the event to Graph.
 */
class Swift_Transport_Api_Calendar_ParsedEvent
{
    /**
     * @param string|null                                                       $subject        SUMMARY
     * @param string|null                                                       $body           DESCRIPTION (plain text)
     * @param bool                                                              $isHtmlBody     whether $body is HTML
     * @param string|null                                                       $location       LOCATION display name
     * @param string                                                            $start          local datetime, e.g. "2026-03-01T09:00:00"
     * @param string                                                            $startTimeZone  Graph/IANA/Windows time zone name
     * @param string                                                            $end            local datetime, e.g. "2026-03-01T10:00:00"
     * @param string                                                            $endTimeZone    Graph/IANA/Windows time zone name
     * @param bool                                                              $isAllDay       VALUE=DATE / date-only
     * @param string|null                                                       $organizerEmail ORGANIZER address
     * @param string|null                                                       $organizerName  ORGANIZER CN
     * @param array<int, array{email: string, name: string|null, type: string}> $attendees      ATTENDEE list
     * @param string|null                                                       $uid            UID
     * @param string|null                                                       $method         VCALENDAR METHOD (REQUEST, CANCEL, ...)
     * @param int                                                               $sequence       SEQUENCE
     * @param string|null                                                       $url            URL
     */
    public function __construct(
        public readonly ?string $subject,
        public readonly ?string $body,
        public readonly bool $isHtmlBody,
        public readonly ?string $location,
        public readonly string $start,
        public readonly string $startTimeZone,
        public readonly string $end,
        public readonly string $endTimeZone,
        public readonly bool $isAllDay,
        public readonly ?string $organizerEmail,
        public readonly ?string $organizerName,
        public readonly array $attendees,
        public readonly ?string $uid,
        public readonly ?string $method,
        public readonly int $sequence,
        public readonly ?string $url,
    ) {
    }

    /**
     * True for METHOD:REQUEST invitations — the only kind M365 mangles when sent as a
     * sendMail attachment and the only kind we can losslessly turn into a created event.
     */
    public function isRequest(): bool
    {
        return 'REQUEST' === \strtoupper((string) $this->method);
    }

    /**
     * True for METHOD:CANCEL — a cancellation of a previously-sent invitation. Like
     * REQUEST, M365 mangles these when delivered as a sendMail attachment, so we route
     * them through the Calendar API's cancel action instead.
     */
    public function isCancel(): bool
    {
        return 'CANCEL' === \strtoupper((string) $this->method);
    }
}
