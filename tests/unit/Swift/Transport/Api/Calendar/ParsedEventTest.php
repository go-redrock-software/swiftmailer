<?php

namespace Swift\Transport\Api\Calendar;

use PHPUnit\Framework\TestCase;

class ParsedEventTest extends TestCase
{
    private function parse(string $ics): \Swift_Transport_Api_Calendar_ParsedEvent
    {
        return (new \Swift_Transport_Api_Calendar_IcsParser())->parse($ics);
    }

    private function ics(string $method): string
    {
        return \implode("\r\n", [
            'BEGIN:VCALENDAR',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            'UID:evt-1@example.com',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Sync',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    public function testIsCancelTrueForCancelMethod(): void
    {
        $event = $this->parse($this->ics('CANCEL'));
        $this->assertTrue($event->isCancel());
        $this->assertFalse($event->isRequest());
    }

    public function testIsCancelFalseForRequestMethod(): void
    {
        $event = $this->parse($this->ics('REQUEST'));
        $this->assertFalse($event->isCancel());
        $this->assertTrue($event->isRequest());
    }

    public function testIsCancelCaseInsensitive(): void
    {
        $event = $this->parse($this->ics('cancel'));
        $this->assertTrue($event->isCancel());
    }

    public function testReplacingAttendeesPreservesImmutableEventDetails(): void
    {
        $event     = $this->parse($this->ics('REQUEST'));
        $attendees = [['email' => 'sink@example.com', 'name' => 'Sink', 'type' => 'required']];
        $copy      = $event->withAttendees($attendees);
        $this->assertSame([], $event->attendees);
        $this->assertSame($attendees, $copy->attendees);
        $this->assertNotSame($event, $copy);
        $original = \get_object_vars($event);
        $updated  = \get_object_vars($copy);
        unset($original['attendees'], $updated['attendees']);
        $this->assertSame($original, $updated);
    }
}
