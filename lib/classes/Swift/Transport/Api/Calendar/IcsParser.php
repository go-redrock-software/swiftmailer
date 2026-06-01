<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

/**
 * Minimal, dependency-free iCalendar (RFC 5545) parser.
 *
 * It extracts just enough of the first VEVENT to recreate a meeting via the
 * Microsoft Graph Calendar API: subject, body, location, start/end (with time
 * zone), organizer, attendees, UID, METHOD and SEQUENCE.
 *
 * It is intentionally narrow — it does not attempt to support recurrence,
 * alarms, multiple VEVENTs or VTODO/VJOURNAL components.
 */
class Swift_Transport_Api_Calendar_IcsParser
{
    /**
     * Parses an iCalendar payload, returning the first VEVENT or null when the
     * payload contains no usable event (no VEVENT, or a VEVENT without DTSTART).
     *
     * @throws InvalidArgumentException when a date/duration value cannot be understood
     */
    public function parse(string $ics): ?Swift_Transport_Api_Calendar_ParsedEvent
    {
        $lines = $this->unfold($ics);
        if ([] === $lines) {
            return null;
        }

        $method    = null;
        $inEvent   = false;
        $props     = [];
        $attendees = [];
        $organizer = null;

        foreach ($lines as $line) {
            [$name, $params, $value] = $this->parseLine($line);
            if (null === $name) {
                continue;
            }
            $name = \strtoupper($name);

            if ('BEGIN' === $name && 'VEVENT' === \strtoupper(\trim($value))) {
                $inEvent = true;
                continue;
            }
            if ('END' === $name && 'VEVENT' === \strtoupper(\trim($value))) {
                // Only the first VEVENT is consumed.
                break;
            }
            // METHOD is a VCALENDAR-level property, outside the VEVENT block.
            if ('METHOD' === $name && !$inEvent) {
                $method = \strtoupper(\trim($value));
                continue;
            }
            if (!$inEvent) {
                continue;
            }

            switch ($name) {
                case 'ATTENDEE':
                    $attendees[] = $this->parseParticipant($params, $value);
                    break;
                case 'ORGANIZER':
                    $organizer = $this->parseParticipant($params, $value);
                    break;
                default:
                    // Last value wins for single-valued properties.
                    $props[$name] = ['params' => $params, 'value' => $value];
            }
        }

        if (!isset($props['DTSTART'])) {
            return null;
        }

        [$start, $startTz, $allDay] = $this->parseDate($props['DTSTART']['value'], $props['DTSTART']['params']);

        if (isset($props['DTEND'])) {
            [$end, $endTz] = $this->parseDate($props['DTEND']['value'], $props['DTEND']['params']);
        } elseif (isset($props['DURATION'])) {
            $end   = $this->applyDuration($start, $props['DURATION']['value']);
            $endTz = $startTz;
        } elseif ($allDay) {
            // RFC 5545: a date-only event with no DTEND lasts exactly one day.
            $end   = $this->addOneDay($start);
            $endTz = $startTz;
        } else {
            $end   = $start;
            $endTz = $startTz;
        }

        return new Swift_Transport_Api_Calendar_ParsedEvent(
            subject: isset($props['SUMMARY']) ? $this->unescapeText($props['SUMMARY']['value']) : null,
            body: isset($props['DESCRIPTION']) ? $this->unescapeText($props['DESCRIPTION']['value']) : null,
            isHtmlBody: false,
            location: isset($props['LOCATION']) ? $this->unescapeText($props['LOCATION']['value']) : null,
            start: $start,
            startTimeZone: $startTz,
            end: $end,
            endTimeZone: $endTz,
            isAllDay: $allDay,
            organizerEmail: $organizer['email'] ?? null,
            organizerName: $organizer['name']   ?? null,
            attendees: $attendees,
            uid: isset($props['UID']) ? \trim($props['UID']['value']) : null,
            method: $method,
            sequence: isset($props['SEQUENCE']) ? (int) \trim($props['SEQUENCE']['value']) : 0,
            url: isset($props['URL']) ? \trim($props['URL']['value']) : null,
        );
    }

    /**
     * Normalizes line endings and re-joins RFC 5545 folded lines (a CRLF followed
     * by a space or tab is a continuation of the previous line).
     *
     * @return array<int, string>
     */
    private function unfold(string $ics): array
    {
        $normalized = \str_replace(["\r\n", "\r"], "\n", $ics);
        $normalized = \preg_replace('/\n[ \t]/', '', $normalized);
        $lines      = \explode("\n", (string) $normalized);

        return \array_values(\array_filter($lines, static fn (string $l): bool => '' !== \trim($l)));
    }

    /**
     * Splits a content line into [name, params, value].
     *
     * @return array{0: string|null, 1: array<string, string>, 2: string}
     */
    private function parseLine(string $line): array
    {
        $colon = $this->findValueColon($line);
        if (null === $colon) {
            return [null, [], ''];
        }

        $nameAndParams = \substr($line, 0, $colon);
        $value         = \substr($line, $colon + 1);

        $segments = $this->splitOnUnquoted($nameAndParams, ';');
        $name     = \trim((string) \array_shift($segments));

        $params = [];
        foreach ($segments as $segment) {
            if (!\str_contains($segment, '=')) {
                continue;
            }
            [$pName, $pValue]                   = \explode('=', $segment, 2);
            $params[\strtoupper(\trim($pName))] = \trim($pValue, ' "');
        }

        return ['' === $name ? null : $name, $params, $value];
    }

    /**
     * Finds the index of the first colon that is not inside a quoted string
     * (parameter values may be quoted and contain colons).
     */
    private function findValueColon(string $line): ?int
    {
        $inQuotes = false;
        $length   = \strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];
            if ('"' === $char) {
                $inQuotes = !$inQuotes;
            } elseif (':' === $char && !$inQuotes) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Splits a string on a delimiter, ignoring delimiters inside double quotes.
     *
     * @return array<int, string>
     */
    private function splitOnUnquoted(string $value, string $delimiter): array
    {
        $parts    = [];
        $current  = '';
        $inQuotes = false;
        $length   = \strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ('"' === $char) {
                $inQuotes = !$inQuotes;
                $current .= $char;
            } elseif ($char === $delimiter && !$inQuotes) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Parses an ORGANIZER/ATTENDEE line into an email/name/type triple.
     *
     * @param array<string, string> $params
     *
     * @return array{email: string, name: string|null, type: string}
     */
    private function parseParticipant(array $params, string $value): array
    {
        $email = \trim($value);
        if (0 === \stripos($email, 'mailto:')) {
            $email = \substr($email, 7);
        }

        $role   = \strtoupper($params['ROLE'] ?? '');
        $cutype = \strtoupper($params['CUTYPE'] ?? '');

        $type = 'required';
        if ('OPT-PARTICIPANT' === $role || 'NON-PARTICIPANT' === $role) {
            $type = 'optional';
        }
        if ('RESOURCE' === $cutype || 'ROOM' === $cutype) {
            $type = 'resource';
        }

        return [
            'email' => $email,
            'name'  => isset($params['CN']) ? $this->unescapeText($params['CN']) : null,
            'type'  => $type,
        ];
    }

    /**
     * Converts an iCalendar DATE/DATE-TIME value into a Graph-ready local datetime
     * string plus a time zone name, without performing any UTC conversion.
     *
     * @param array<string, string> $params
     *
     * @return array{0: string, 1: string, 2: bool} [localDateTime, timeZone, isAllDay]
     *
     * @throws InvalidArgumentException
     */
    private function parseDate(string $value, array $params): array
    {
        $value = \trim($value);
        $tz    = $params['TZID'] ?? 'UTC';

        $isDate = ('DATE' === \strtoupper($params['VALUE'] ?? '')) || 1 === \preg_match('/^\d{8}$/', $value);

        if ($isDate) {
            if (!\preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
                throw new InvalidArgumentException("Unrecognized iCalendar date value: {$value}");
            }

            return ["{$m[1]}-{$m[2]}-{$m[3]}T00:00:00", $tz, true];
        }

        if (\str_ends_with($value, 'Z')) {
            $value = \substr($value, 0, -1);
            $tz    = 'UTC';
        }

        if (!\preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
            throw new InvalidArgumentException("Unrecognized iCalendar datetime value: {$value}");
        }

        return ["{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}", $tz, false];
    }

    /**
     * Adds one calendar day to a local datetime string (used for all-day events).
     */
    private function addOneDay(string $localDateTime): string
    {
        $dt = new DateTimeImmutable($localDateTime, new DateTimeZone('UTC'));

        return $dt->add(new DateInterval('P1D'))->format('Y-m-d\TH:i:s');
    }

    /**
     * Applies an ISO 8601 / iCalendar DURATION to a local datetime string.
     *
     * @throws InvalidArgumentException
     */
    private function applyDuration(string $localDateTime, string $duration): string
    {
        $duration = \trim($duration);
        try {
            $interval = new DateInterval($duration);
        } catch (Exception $e) {
            throw new InvalidArgumentException("Unrecognized iCalendar DURATION value: {$duration}", 0, $e);
        }

        $dt = new DateTimeImmutable($localDateTime, new DateTimeZone('UTC'));

        return $dt->add($interval)->format('Y-m-d\TH:i:s');
    }

    /**
     * Unescapes RFC 5545 TEXT values (\\n, \\N, \\, \\; and \\\\).
     */
    private function unescapeText(string $value): string
    {
        return \str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $value,
        );
    }
}
