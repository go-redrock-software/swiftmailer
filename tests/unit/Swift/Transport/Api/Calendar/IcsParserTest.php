<?php

namespace Swift\Transport\Api\Calendar;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Swift_Transport_Api_Calendar_IcsParser.
 */
class IcsParserTest extends TestCase
{
    private function parser(): \Swift_Transport_Api_Calendar_IcsParser
    {
        return new \Swift_Transport_Api_Calendar_IcsParser();
    }

    public function testParsesRequestInviteWithUtcTimes(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//Test//EN',
            'VERSION:2.0',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:event-123@example.com',
            'SEQUENCE:2',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Quarterly Review',
            'LOCATION:Room 4',
            'DESCRIPTION:Line one\\nLine two\\, still going',
            'ORGANIZER;CN=Jane Boss:mailto:jane@example.com',
            'ATTENDEE;CN=Bob Worker;ROLE=REQ-PARTICIPANT:mailto:bob@example.com',
            'ATTENDEE;CN=Optional Guy;ROLE=OPT-PARTICIPANT:mailto:opt@example.com',
            'ATTENDEE;CUTYPE=ROOM;CN=Room 4:mailto:room4@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        $this->assertSame('REQUEST', $event->method);
        $this->assertTrue($event->isRequest());
        $this->assertSame('Quarterly Review', $event->subject);
        $this->assertSame('Room 4', $event->location);
        $this->assertSame("Line one\nLine two, still going", $event->body);
        $this->assertFalse($event->isHtmlBody);
        $this->assertSame('2026-03-01T14:00:00', $event->start);
        $this->assertSame('UTC', $event->startTimeZone);
        $this->assertSame('2026-03-01T15:00:00', $event->end);
        $this->assertSame('UTC', $event->endTimeZone);
        $this->assertFalse($event->isAllDay);
        $this->assertSame('event-123@example.com', $event->uid);
        $this->assertSame(2, $event->sequence);
        $this->assertSame('jane@example.com', $event->organizerEmail);
        $this->assertSame('Jane Boss', $event->organizerName);

        $this->assertCount(3, $event->attendees);
        $this->assertSame('bob@example.com', $event->attendees[0]['email']);
        $this->assertSame('Bob Worker', $event->attendees[0]['name']);
        $this->assertSame('required', $event->attendees[0]['type']);
        $this->assertSame('optional', $event->attendees[1]['type']);
        $this->assertSame('resource', $event->attendees[2]['type']);
    }

    public function testParsesTzidLocalTimeWithoutConversion(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:tz-1',
            'DTSTART;TZID=America/New_York:20260301T090000',
            'DTEND;TZID=America/New_York:20260301T100000',
            'SUMMARY:Local time meeting',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        // Wall-clock time must be preserved verbatim; no shifting to UTC.
        $this->assertSame('2026-03-01T09:00:00', $event->start);
        $this->assertSame('America/New_York', $event->startTimeZone);
        $this->assertSame('2026-03-01T10:00:00', $event->end);
        $this->assertSame('America/New_York', $event->endTimeZone);
    }

    public function testParsesAllDayEventDefaultsToOneDay(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:allday-1',
            'DTSTART;VALUE=DATE:20260301',
            'SUMMARY:Company Holiday',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        $this->assertTrue($event->isAllDay);
        $this->assertSame('2026-03-01T00:00:00', $event->start);
        $this->assertSame('2026-03-02T00:00:00', $event->end);
    }

    public function testDurationFallbackComputesEnd(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:dur-1',
            'DTSTART:20260301T140000Z',
            'DURATION:PT1H30M',
            'SUMMARY:Has duration',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        $this->assertSame('2026-03-01T15:30:00', $event->end);
    }

    public function testUnfoldsContinuationLines(): void
    {
        // RFC 5545 folding: a CRLF followed by a space continues the previous line.
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:fold-1\r\nDTSTART:20260301T140000Z\r\nSUMMARY:A very long subject that has been\r\n  folded across two lines\r\nEND:VEVENT\r\nEND:VCALENDAR";

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        $this->assertSame('A very long subject that has been folded across two lines', $event->subject);
    }

    public function testReturnsNullWhenNoVeventPresent(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:PUBLISH\r\nEND:VCALENDAR";

        $this->assertNull($this->parser()->parse($ics));
    }

    public function testReturnsNullForEmptyPayload(): void
    {
        $this->assertNull($this->parser()->parse(''));
    }

    public function testNonRequestMethodIsNotARequest(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:pub-1',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Published event',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        $this->assertSame('PUBLISH', $event->method);
        $this->assertFalse($event->isRequest());
    }

    public function testQuotedParameterValueWithColonIsHandled(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:quote-1',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Quoted CN',
            'ATTENDEE;CN="Doe, John":mailto:john@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $event = $this->parser()->parse($ics);

        $this->assertNotNull($event);
        $this->assertCount(1, $event->attendees);
        $this->assertSame('john@example.com', $event->attendees[0]['email']);
        $this->assertSame('Doe, John', $event->attendees[0]['name']);
    }

    public function testThrowsOnUnparseableDate(): void
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:bad-1',
            'DTSTART:not-a-date',
            'SUMMARY:Bad date',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->parser()->parse($ics);
    }
}
