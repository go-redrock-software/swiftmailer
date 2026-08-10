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

    private function promise(): \Http\Promise\Promise
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        return $promise;
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
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);
        $userItemBuilder->expects($this->never())->method('sendMail');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $result = $transport->send($this->message($this->requestInviteIcs()));

        // One attendee via the created event, plus the single To recipient.
        $this->assertSame(2, $result);
    }

    // -- invite + a real (non-.ics) attachment: the attachment must still be delivered ---

    public function testRequestInviteWithRealAttachmentSendsBothEvenWithoutSendAlongside(): void
    {
        // Default sendEmailAlongsideEvent (false), but a genuine PDF rides alongside the
        // invite. The .ics is converted to an event; the PDF must NOT be silently dropped.
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

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
    }

    // -- sendAlongside also sends the email (without the broken .ics) ---

    public function testRequestInviteWithSendAlongsideSendsBoth(): void
    {
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();
        $transport->setSendEmailAlongsideEvent(true);

        $result = $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(2, $result); // 1 To recipient + 1 attendee via the event
    }

    // -- conversion disabled: .ics rides along as a normal attachment ---

    public function testConversionDisabledSendsIcsAsAttachment(): void
    {
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);
        $userItemBuilder->expects($this->never())->method('events');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        // conversion intentionally left disabled

        $result = $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(1, $result); // 1 To recipient; .ics sent as a plain attachment, no event
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

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);
        $userItemBuilder->expects($this->never())->method('events');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $result = $transport->send($this->message($publishIcs));
        $this->assertSame(1, $result); // 1 To recipient; PUBLISH not converted, no event
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
        $this->assertSame('map-1', $graphEvent->getTransactionId());
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

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);
        $userItemBuilder->expects($this->never())->method('events');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        // Must not throw, and must not create an event.
        $result = $transport->send($this->message($malformed));
        $this->assertSame(1, $result); // 1 To recipient; malformed .ics falls back to an attachment, no event
    }

    // -- conversion enabled but no calendar part: ordinary sendMail, no event ---

    public function testNoCalendarPartSendsNormally(): void
    {
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);
        $userItemBuilder->expects($this->never())->method('events');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('No invite here');
        $m->setBody('Just a normal email.');

        $result = $transport->send($m);
        $this->assertSame(1, $result); // 1 To recipient; ordinary sendMail, no event
    }

    // -- multiple invites create multiple events ---

    public function testMultipleInvitesCreateMultipleEvents(): void
    {
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->exactly(2))->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);
        $userItemBuilder->expects($this->never())->method('sendMail');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

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
    }

    // -- detection by .ics filename when the content type isn't text/calendar ---

    public function testDetectionByIcsFilename(): void
    {
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);
        $userItemBuilder->expects($this->never())->method('sendMail');

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

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
    }

    // -- explicit user id routes events through /users/{id}, not /me ---

    public function testExplicitUserIdRoutesEventsThroughByUserId(): void
    {
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->expects($this->once())->method('byUserId')->with('user-123')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);
        $graphClient->expects($this->never())->method('me');

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-123', $this->dispatcher());
        $transport->enableCalendarEventConversion();

        $result = $transport->send($this->message($this->requestInviteIcs()));
        $this->assertSame(2, $result); // 1 To recipient + 1 attendee via the event
    }

    // -- send-alongside strips the .ics but keeps real file attachments ---

    public function testSendAlongsideStripsIcsButKeepsFileAttachment(): void
    {
        $captured = null;

        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($this->promise());

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturnCallback(function ($body) use (&$captured) {
            $captured = $body;

            return $this->promise();
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

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

        $this->assertNotNull($captured, 'sendMail should have been called');
        $attachments = $captured->getMessage()->getAttachments() ?? [];
        $names       = \array_map(static fn ($a) => $a->getName(), $attachments);

        $this->assertContains('report.pdf', $names, 'real attachment must survive');
        $this->assertNotContains('invite.ics', $names, 'the broken .ics must be stripped');
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
        $event = new \Microsoft\Graph\Generated\Models\Event();
        $event->setId('evt-graph-1');
        $response = new \Microsoft\Graph\Generated\Models\EventCollectionResponse();
        $response->setValue([$event]);
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn($response);

        $capturedConfig = null;
        $eventsBuilder  = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->method('get')->willReturnCallback(function ($config) use (&$capturedConfig, $promise) {
            $capturedConfig = $config;

            return $promise;
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);

        $t      = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
        $method = new \ReflectionMethod($t, 'findEventIdByICalUId');
        $id     = $method->invoke($t, $userItemBuilder, "o'brien-uid");

        $this->assertSame('evt-graph-1', $id);
        $this->assertSame("iCalUId eq 'o''brien-uid'", $capturedConfig->queryParameters->filter);
    }

    public function testFindEventIdReturnsNullWhenNoMatch(): void
    {
        $response = new \Microsoft\Graph\Generated\Models\EventCollectionResponse();
        $response->setValue([]);
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn($response);

        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->method('get')->willReturn($promise);
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);

        $t      = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
        $method = new \ReflectionMethod($t, 'findEventIdByICalUId');
        $this->assertNull($method->invoke($t, $userItemBuilder, 'missing-uid'));
    }

    public function testCancelGraphEventPostsCancel(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $cancelBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\Item\Cancel\CancelRequestBuilder::class);
        $cancelBuilder->expects($this->once())->method('post')->willReturn($promise);

        $eventItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\Item\EventItemRequestBuilder::class);
        $eventItemBuilder->method('cancel')->willReturn($cancelBuilder);

        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->method('byEventId')->with('evt-graph-1')->willReturn($eventItemBuilder);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);

        $t      = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
        $method = new \ReflectionMethod($t, 'cancelGraphEvent');
        $method->invoke($t, $userItemBuilder, 'evt-graph-1');
    }

    public function testUpdateGraphEventPatches(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $eventItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\Item\EventItemRequestBuilder::class);
        $eventItemBuilder->expects($this->once())->method('patch')->willReturn($promise);

        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->method('byEventId')->with('evt-graph-1')->willReturn($eventItemBuilder);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);

        $t      = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class));
        $method = new \ReflectionMethod($t, 'updateGraphEvent');
        $method->invoke($t, $userItemBuilder, 'evt-graph-1', new \Microsoft\Graph\Generated\Models\Event());
    }

    // -- send() routes each parsed entry to CREATE / UPDATE / CANCEL ---

    private function dispatcherTransport(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder $userItemBuilder, array $stub): \Swift_Transport_Api_MicrosoftGraphTransport
    {
        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);

        $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
            ->setConstructorArgs([$graphClient, null, $this->dispatcher()])
            ->onlyMethods($stub)
            ->getMock();
        $transport->enableCalendarEventConversion();

        return $transport;
    }

    public function testCancelInviteCancelsMatchingEvent(): void
    {
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->expects($this->never())->method('sendMail');

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn('evt-graph-1');
        $transport->expects($this->once())->method('cancelGraphEvent')->with($userItemBuilder, 'evt-graph-1');

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-1@example.com', 'SEQUENCE:1',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $transport->send($this->messageWithIcs($cancelIcs));
    }

    public function testCancelInviteWithNoMatchMakesNoEventCall(): void
    {
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->expects($this->never())->method('events');
        $userItemBuilder->expects($this->never())->method('sendMail');

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn(null);
        $transport->expects($this->never())->method('cancelGraphEvent');

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-2@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'END:VEVENT', 'END:VCALENDAR',
        ]);
        // @ suppresses the expected E_USER_NOTICE so the test does not error on it.
        @$transport->send($this->messageWithIcs($cancelIcs));
    }

    public function testNoMatchCancelEmitsNoticeNamingTheICalUId(): void
    {
        // The no-match CANCEL path warns operators via E_USER_NOTICE. Assert it actually
        // fires and names the operation + the unmatched iCalUId, so a silently-dropped
        // cancellation is observable in logs.
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->expects($this->never())->method('events');
        $userItemBuilder->expects($this->never())->method('sendMail');

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn(null);

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
    }

    public function testNoMatchCancelCountsOnlyToRecipient(): void
    {
        // A CANCEL that matches no Graph event cancels nothing, so the calendar op
        // contributes 0. The single To recipient is still counted, so send() returns 1
        // (count(To)=1 + calendar 0).
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->expects($this->never())->method('events');
        $userItemBuilder->expects($this->never())->method('sendMail');

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn(null);
        $transport->expects($this->never())->method('cancelGraphEvent');

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-nomatch@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $result = @$transport->send($this->messageWithIcs($cancelIcs));
        $this->assertSame(1, $result, 'no-match CANCEL: only the To recipient is counted');
    }

    public function testMatchedCancelCountsToPlusAttendee(): void
    {
        // A CANCEL that matches a Graph event notifies its one attendee (calendar
        // contributes 1) on top of the single To recipient, so send() returns 2.
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->expects($this->never())->method('sendMail');

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'cancelGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn('evt-graph-1');
        $transport->expects($this->once())->method('cancelGraphEvent')->with($userItemBuilder, 'evt-graph-1');

        $cancelIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:CANCEL', 'BEGIN:VEVENT',
            'UID:cancel-match@example.com',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Cancelled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $result = $transport->send($this->messageWithIcs($cancelIcs));
        $this->assertSame(2, $result, 'matched CANCEL: 1 To recipient + 1 cancelled-event attendee');
    }

    public function testUpdateInviteWithSequencePatchesMatchingEvent(): void
    {
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->expects($this->never())->method('sendMail');

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'updateGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn('evt-graph-1');
        $transport->expects($this->once())->method('updateGraphEvent')->with($userItemBuilder, 'evt-graph-1', $this->isInstanceOf(\Microsoft\Graph\Generated\Models\Event::class));

        $updateIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:REQUEST', 'BEGIN:VEVENT',
            'UID:update-1@example.com', 'SEQUENCE:2',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Rescheduled', 'ATTENDEE;CN=Bob:mailto:bob@example.com',
            'END:VEVENT', 'END:VCALENDAR',
        ]);
        $transport->send($this->messageWithIcs($updateIcs));
    }

    public function testUpdateInviteWithNoMatchFallsBackToCreate(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);
        $eventsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilder::class);
        $eventsBuilder->expects($this->once())->method('post')->willReturn($promise);
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('events')->willReturn($eventsBuilder);

        $transport = $this->dispatcherTransport($userItemBuilder, ['findEventIdByICalUId', 'updateGraphEvent']);
        $transport->method('findEventIdByICalUId')->willReturn(null);
        $transport->expects($this->never())->method('updateGraphEvent');

        $updateIcs = \implode("\r\n", [
            'BEGIN:VCALENDAR', 'METHOD:REQUEST', 'BEGIN:VEVENT',
            'UID:update-2@example.com', 'SEQUENCE:3',
            'DTSTART:20260301T140000Z', 'DTEND:20260301T150000Z',
            'SUMMARY:Rescheduled', 'END:VEVENT', 'END:VCALENDAR',
        ]);
        $transport->send($this->messageWithIcs($updateIcs));
    }
}
