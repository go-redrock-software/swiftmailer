<?php

namespace Swift\Transport\Api;

use Microsoft\Graph\GraphServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for calendar-invite -> Graph event conversion in
 * Swift_Transport_Api_MicrosoftGraphTransport.
 */
class MicrosoftGraphCalendarTest extends TestCase
{
    private function requestInviteIcs(): string
    {
        return \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:cal-1@example.com',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Project Kickoff',
            'ATTENDEE;CN=Bob;ROLE=REQ-PARTICIPANT:mailto:bob@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    private function dispatcher(): \Swift_Events_EventDispatcher
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
        $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        return $dispatcher;
    }

    private function message(string $ics): \Swift_Message
    {
        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Invitation');
        $m->setBody('Please join.');
        $m->attach(new \Swift_Attachment($ics, 'invite.ics', 'text/calendar'));

        return $m;
    }

    // -- flag accessors ---

    public function testConversionDisabledByDefault(): void
    {
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $this->assertFalse($transport->isCalendarEventConversionEnabled());
        $this->assertFalse($transport->isSendingEmailAlongsideEvent());
    }

    public function testEnableAndDisableConversion(): void
    {
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $transport->enableCalendarEventConversion();
        $this->assertTrue($transport->isCalendarEventConversionEnabled());

        $transport->disableCalendarEventConversion();
        $this->assertFalse($transport->isCalendarEventConversionEnabled());

        $transport->setSendEmailAlongsideEvent(true);
        $this->assertTrue($transport->isSendingEmailAlongsideEvent());
    }

    // -- conversion creates an event and skips the duplicate email ---

    public function testRequestInviteIsCreatedAsEventAndEmailSkipped(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $result = $transport->send($this->message($this->requestInviteIcs()));

        // One attendee via the created event, plus the single To recipient.
        $this->assertSame(2, $result);
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events'], $fixture->paths());
    }

    // -- invite + a real (non-.ics) attachment: the attachment must still be delivered ---

    public function testRequestInviteWithRealAttachmentSendsBothEvenWithoutSendAlongside(): void
    {
        // Default sendEmailAlongsideEvent (false), but a genuine PDF rides alongside the
        // invite. The .ics is converted to an event; the PDF must NOT be silently dropped.
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();
        // intentionally NOT calling setSendEmailAlongsideEvent(true)

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Invite plus report');
        $m->setBody('See attached.');
        $m->attach(new \Swift_Attachment('report body', 'report.pdf', 'application/pdf'));
        $m->attach(new \Swift_Attachment($this->requestInviteIcs(), 'invite.ics', 'text/calendar'));

        $transport->send($m);
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events', 'POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- sendAlongside also sends the email (without the broken .ics) ---

    public function testRequestInviteWithSendAlongsideSendsBoth(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();
        $transport->setSendEmailAlongsideEvent(true);

        $result = $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(2, $result); // 1 To recipient + 1 attendee via the event
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events', 'POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- conversion disabled: .ics rides along as a normal attachment ---

    public function testConversionDisabledSendsIcsAsAttachment(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        // conversion intentionally left disabled

        $result = $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(1, $result); // 1 To recipient; .ics sent as a plain attachment, no event
        $this->assertSame(['POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- non-REQUEST methods are not converted ---

    public function testPublishMethodIsNotConverted(): void
    {
        $publishIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:pub-1',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:FYI event',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $result = $transport->send($this->message($publishIcs));
        $this->assertSame(1, $result); // 1 To recipient; PUBLISH not converted, no event
        $this->assertSame(['POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- convertParsedEventToGraphEvent maps fields onto the Graph model ---

    public function testConvertParsedEventToGraphEventMapsFields(): void
    {
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $parsed = (new \Swift_Transport_Api_Calendar_IcsParser())->parse(
            \implode("\r\n", [
                'BEGIN:VCALENDAR',
                'METHOD:REQUEST',
                'BEGIN:VEVENT',
                'UID:map-1',
                'DTSTART;TZID=America/New_York:20260301T090000',
                'DTEND;TZID=America/New_York:20260301T100000',
                'SUMMARY:Mapped Meeting',
                'LOCATION:HQ',
                'DESCRIPTION:Agenda',
                'ATTENDEE;CN=Bob:mailto:bob@example.com',
                'END:VEVENT',
                'END:VCALENDAR',
            ]),
        );

        $graphEvent = $transport->convertParsedEventToGraphEvent($parsed);

        $this->assertSame('Mapped Meeting', $graphEvent->getSubject());
        $this->assertSame('Agenda', $graphEvent->getBody()->getContent());
        $this->assertSame('2026-03-01T09:00:00', $graphEvent->getStart()->getDateTime());
        $this->assertSame('America/New_York', $graphEvent->getStart()->getTimeZone());
        $this->assertSame('2026-03-01T10:00:00', $graphEvent->getEnd()->getDateTime());
        $this->assertSame('HQ', $graphEvent->getLocation()->getDisplayName());
        $this->assertCount(1, $graphEvent->getAttendees());
        $this->assertSame('bob@example.com', $graphEvent->getAttendees()[0]->getEmailAddress()->getAddress());
        $this->assertNull($graphEvent->getTransactionId(), 'a repeated transactionId makes Graph reject the next invite');
        $properties = $graphEvent->getSingleValueExtendedProperties();
        $this->assertCount(1, $properties);
        $this->assertSame(\Swift_Transport_Api_MicrosoftGraphTransport::INVITE_KEY_PROPERTY_ID, $properties[0]->getId());
        $this->assertSame('map-1|bob@example.com', $properties[0]->getValue());
    }

    // -- a malformed .ics must not crash the send; it falls back to an attachment ---

    public function testMalformedIcsFallsBackToAttachment(): void
    {
        // METHOD:REQUEST so detection fires, but an unparseable DTSTART so the parser
        // throws. The transport must swallow that and send the .ics as an attachment.
        $malformed = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:bad-1',
            'DTSTART:not-a-real-date',
            'SUMMARY:Broken',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        // Must not throw, and must not create an event.
        $result = $transport->send($this->message($malformed));
        $this->assertSame(1, $result); // 1 To recipient; malformed .ics falls back to an attachment, no event
        $this->assertSame(['POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- conversion enabled but no calendar part: ordinary sendMail, no event ---

    public function testNoCalendarPartSendsNormally(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('No invite here');
        $m->setBody('Just a normal email.');

        $result = $transport->send($m);
        $this->assertSame(1, $result); // 1 To recipient; ordinary sendMail, no event
        $this->assertSame(['POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- multiple invites create multiple events ---

    public function testMultipleInvitesCreateMultipleEvents(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Two invites');
        $m->setBody('See attached.');
        $m->attach(new \Swift_Attachment($this->requestInviteIcs(), 'a.ics', 'text/calendar'));
        $m->attach(new \Swift_Attachment($this->requestInviteIcs(), 'b.ics', 'text/calendar'));

        // Two attendees (one per invite) plus the single To recipient.
        $result = $transport->send($m);
        $this->assertSame(3, $result);
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events', 'GET /v1.0/me/events', 'POST /v1.0/me/events'], $fixture->paths());
    }

    // -- detection by .ics filename when the content type isn't text/calendar ---

    public function testDetectionByIcsFilename(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Odd content type');
        $m->setBody('See attached.');
        // Generic content type, but a .ics filename should still be detected.
        $m->attach(new \Swift_Attachment($this->requestInviteIcs(), 'meeting.ics', 'application/octet-stream'));

        $result = $transport->send($m);
        $this->assertSame(2, $result); // 1 To recipient + 1 attendee via the event
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events'], $fixture->paths());
    }

    // -- explicit user id routes events through /users/{id}, not /me ---

    public function testExplicitUserIdRoutesEventsThroughByUserId(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-123', $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $result = $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(2, $result); // 1 To recipient + 1 attendee via the event
        $this->assertSame(['GET /v1.0/users/user-123/events', 'POST /v1.0/users/user-123/events'], $fixture->paths());
    }

    // -- send-alongside strips the .ics but keeps real file attachments ---

    public function testSendAlongsideStripsIcsButKeepsFileAttachment(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();
        $transport->setSendEmailAlongsideEvent(true);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Invite plus file');
        $m->setBody('See attached.');
        $m->attach(new \Swift_Attachment('real file body', 'report.pdf', 'application/pdf'));
        $m->attach(new \Swift_Attachment($this->requestInviteIcs(), 'invite.ics', 'text/calendar'));

        $transport->send($m);
        $captured = $fixture->mailBody();

        $this->assertNotNull($captured, 'sendMail should have been called');
        $attachments = $captured->getMessage()->getAttachments() ?? [];
        $names       = \array_map(static fn ($a) => $a->getName(), $attachments);

        $this->assertContains('report.pdf', $names, 'real attachment must survive');
        $this->assertNotContains('invite.ics', $names, 'the broken .ics must be stripped');
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events', 'POST /v1.0/me/sendMail'], $fixture->paths());
    }

    // -- extractCalendarInvites(): REQUEST and CANCEL are extracted; others are not ---

    private function icsWithMethod(string $method): string
    {
        return \implode("\r\n", [
            'BEGIN:VCALENDAR',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            'UID:extract-1@example.com',
            'DTSTART:20260301T140000Z',
            'DTEND:20260301T150000Z',
            'SUMMARY:Extract test',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    private function messageWithIcs(string $ics): \Swift_Message
    {
        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Invite');
        $m->setBody('See attached.');
        $m->attach(new \Swift_Attachment($ics, 'invite.ics', 'text/calendar'));

        return $m;
    }

    private function extractInvites(\Swift_Transport_Api_MicrosoftGraphTransport $t, \Swift_Message $m): array
    {
        $method = new \ReflectionMethod($t, 'extractCalendarInvites');

        return $method->invoke($t, $m);
    }

    public function testExtractReturnsCancelPart(): void
    {
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $invites = $this->extractInvites($transport, $this->messageWithIcs($this->icsWithMethod('CANCEL')));

        $this->assertCount(1, $invites);
        $this->assertTrue($invites[0]['event']->isCancel());
    }

    public function testExtractReturnsRequestPart(): void
    {
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $invites = $this->extractInvites($transport, $this->messageWithIcs($this->icsWithMethod('REQUEST')));

        $this->assertCount(1, $invites);
        $this->assertTrue($invites[0]['event']->isRequest());
    }

    public function testExtractIgnoresPublishPart(): void
    {
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $invites = $this->extractInvites($transport, $this->messageWithIcs($this->icsWithMethod('PUBLISH')));

        $this->assertCount(0, $invites);
    }

    // -- event lookup / cancel / update seams ---

    public function testFindEventIdReturnsFirstMatch(): void
    {
        $fixture = new \Swift_GraphSdkFixture(static fn ($request) => 'GET' === $request->getMethod()
            ? \Swift_GraphSdkFixture::json(200, ['value' => [['id' => 'evt-graph-1'], ['id' => 'evt-graph-2']]])
            : \Swift_GraphSdkFixture::json(200, ['id' => 'evt-graph-1']));
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $transport->enableCalendarEventConversion();
        $transport->send($this->message(\str_replace('cal-1@example.com', "o'brien-uid", $this->requestInviteIcs())));
        $this->assertSame(['GET /v1.0/me/events', 'PATCH /v1.0/me/events/evt-graph-1'], $fixture->paths());
        \parse_str($fixture->requests[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame("singleValueExtendedProperties/Any(ep: ep/id eq '".\Swift_Transport_Api_MicrosoftGraphTransport::INVITE_KEY_PROPERTY_ID."' and ep/value eq 'o''brien-uid|bob@example.com')", $query['$filter']);
        $this->assertSame('id', $query['$select']);
    }

    public function testFindEventIdReturnsNullWhenNoMatch(): void
    {
        $fixture   = new \Swift_GraphSdkFixture();
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $transport->enableCalendarEventConversion();
        $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events'], $fixture->paths());
    }

    public function testCancelGraphEventPostsCancel(): void
    {
        $fixture = new \Swift_GraphSdkFixture(static fn ($request) => 'GET' === $request->getMethod()
            ? \Swift_GraphSdkFixture::json(200, ['value' => [['id' => 'evt-graph-1']]]) : new \GuzzleHttp\Psr7\Response(202));
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $transport->enableCalendarEventConversion();
        $transport->send($this->message(\str_replace('METHOD:REQUEST', 'METHOD:CANCEL', $this->requestInviteIcs())));
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events/evt-graph-1/cancel'], $fixture->paths());
    }

    public function testUpdateGraphEventPatches(): void
    {
        $fixture = new \Swift_GraphSdkFixture(static fn ($request) => 'GET' === $request->getMethod()
            ? \Swift_GraphSdkFixture::json(200, ['value' => [['id' => 'evt-graph-1']]]) : \Swift_GraphSdkFixture::json(200, ['id' => 'evt-graph-1']));
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client);
        $transport->enableCalendarEventConversion();
        $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(['GET /v1.0/me/events', 'PATCH /v1.0/me/events/evt-graph-1'], $fixture->paths());
    }

    // -- send() routes each parsed entry to CREATE / UPDATE / CANCEL ---

    public function testCancelInviteCancelsMatchingEvent(): void
    {
        $fixture = new \Swift_GraphSdkFixture(static fn ($request) => 'GET' === $request->getMethod()
            ? \Swift_GraphSdkFixture::json(200, ['value' => [['id' => 'evt-graph-1']]])
            : new \GuzzleHttp\Psr7\Response(202));
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-1@example.com', 'SEQUENCE:1',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $transport->send($this->messageWithIcs($cancelIcs));
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events/evt-graph-1/cancel'], $fixture->paths());
    }

    public function testCancelInviteWithNoMatchMakesNoEventCall(): void
    {
        $fixture   = new \Swift_GraphSdkFixture();
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-2@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'END:VEVENT', 'END:VCALENDAR',
        ]);
        // @ suppresses the expected E_USER_NOTICE so the test does not error on it.
        @$transport->send($this->messageWithIcs($cancelIcs));
        $this->assertSame(['GET /v1.0/me/events'], $fixture->paths());
    }

    public function testNoMatchCancelEmitsNoticeNamingTheICalUId(): void
    {
        $fixture   = new \Swift_GraphSdkFixture();
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-notice@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'END:VEVENT', 'END:VCALENDAR',
        ]);

        $captured = null;
        \set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = $errstr;

            return true; // swallow so the notice doesn't bubble to PHPUnit
        }, E_USER_NOTICE);
        try {
            $transport->send($this->messageWithIcs($cancelIcs));
        } finally {
            \restore_error_handler();
        }

        $this->assertNotNull($captured, 'a no-match CANCEL must emit an E_USER_NOTICE');
        $this->assertStringContainsString('Graph calendar CANCEL', $captured);
        $this->assertStringContainsString('cancel-notice@example.com', $captured);
        $this->assertSame(['GET /v1.0/me/events'], $fixture->paths());
    }

    public function testNoMatchCancelCountsOnlyToRecipient(): void
    {
        $fixture   = new \Swift_GraphSdkFixture();
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-nomatch@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $result = @$transport->send($this->messageWithIcs($cancelIcs));
        $this->assertSame(1, $result, 'no-match CANCEL: only the To recipient is counted');
        $this->assertSame(['GET /v1.0/me/events'], $fixture->paths());
    }

    public function testMatchedCancelCountsToPlusAttendee(): void
    {
        $fixture = new \Swift_GraphSdkFixture(static fn ($request) => 'GET' === $request->getMethod()
            ? \Swift_GraphSdkFixture::json(200, ['value' => [['id' => 'evt-graph-1']]])
            : new \GuzzleHttp\Psr7\Response(202));
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-match@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $result = $transport->send($this->messageWithIcs($cancelIcs));
        $this->assertSame(2, $result, 'matched CANCEL: 1 To recipient + 1 cancelled-event attendee');
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events/evt-graph-1/cancel'], $fixture->paths());
    }

    public function testUpdateInviteWithSequencePatchesMatchingEvent(): void
    {
        $fixture = new \Swift_GraphSdkFixture(static fn ($request) => 'GET' === $request->getMethod()
            ? \Swift_GraphSdkFixture::json(200, ['value' => [['id' => 'evt-graph-1']]])
            : \Swift_GraphSdkFixture::json(200, ['id' => 'evt-graph-1']));
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $updateIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:REQUEST', 'BEGIN:VEVENT',
            'UID:update-1@example.com', 'SEQUENCE:2',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Rescheduled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $transport->send($this->messageWithIcs($updateIcs));
        $this->assertSame(['GET /v1.0/me/events', 'PATCH /v1.0/me/events/evt-graph-1'], $fixture->paths());
        $this->assertSame('Rescheduled', $fixture->payload()['subject']);
    }

    public function testUpdateInviteWithNoMatchFallsBackToCreate(): void
    {
        $fixture   = new \Swift_GraphSdkFixture();
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($fixture->client, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $updateIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:REQUEST', 'BEGIN:VEVENT',
            'UID:update-2@example.com', 'SEQUENCE:3',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Rescheduled', 'END:VEVENT', 'END:VCALENDAR',
        ]);
        $transport->send($this->messageWithIcs($updateIcs));
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events'], $fixture->paths());
        $this->assertSame('Rescheduled', $fixture->payload()['subject']);
    }

    // -- a parameterised text/calendar content-type (a real calendar MIME part, e.g. the
    //    application's Swift_Calendar) must be detected and converted to an event, not
    //    shipped as a raw .ics. Its Content-Type carries `; charset=...; method=REQUEST`
    //    and it has no .ics filename, so an exact `text/calendar` match (or a filename
    //    check) misses it — which is why such invites never converted before. ---

    public function testParameterisedCalendarContentTypeInviteConvertsToEvent(): void
    {
        $fixture     = new \Swift_GraphSdkFixture();
        $graphClient = $fixture->client;

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $message = new \Swift_Message();
        $message->setFrom(['from@example.com' => 'Sender']);
        $message->setTo(['to@example.com' => 'Recipient']);
        $message->setSubject('Invitation');
        $message->setBody('Please join.');
        $message->attach(new RequestMethodCalendarMimePart($this->requestInviteIcs()));

        // One attendee via the created event, plus the single To recipient.
        $this->assertSame(2, $transport->send($message));
        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events'], $fixture->paths());
    }
}

/**
 * A calendar MIME part shaped like the application's Swift_Calendar: a text/calendar
 * MimePart whose Content-Type carries parameters and which has no .ics filename, sitting
 * at LEVEL_MIXED so it is collected as an attachment candidate. Proves the transport
 * detects and converts such parts, not only bare `text/calendar` Swift_Attachments.
 */
final class RequestMethodCalendarMimePart extends \Swift_MimePart
{
    public function getNestingLevel()
    {
        return self::LEVEL_MIXED;
    }

    public function getContentType()
    {
        return 'text/calendar; charset="utf-8"; method=REQUEST';
    }
}
