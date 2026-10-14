<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Microsoft\Graph\Core\GraphClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Calendar-invite conversion driven through the real Graph SDK against an in-memory mailbox.
 *
 * TracCloud sends one appointment's invite to the student and to the staff member in two
 * separate messages that share one .ics UID, then sends the same UID again for each
 * reschedule or cancellation. Before this fix the second invite of every appointment
 * failed (the shared UID was sent as a Graph transactionId, which Graph rejects when
 * repeated), and updates and cancellations never found their event (they looked it up by
 * iCalUId, which Graph assigns itself).
 */
class Swift_Transport_Api_MicrosoftGraphCalendarMailboxTest extends TestCase
{
    private const UID = 'TRACCLOUD_APPT_KPU_275369';

    /** @var array<string, array<string, mixed>> created events by Graph id */
    private array $events = [];

    /** @var list<string> */
    private array $transactionIds = [];

    /** @var list<string> "METHOD path" of every request, in order */
    private array $requests = [];

    public function testStudentAndStaffInvitesForOneAppointmentBothBecomeEvents(): void
    {
        $transport = $this->transport();

        $this->assertSame(2, $transport->send($this->invite('REQUEST', 'student@kpu.ca', 'Tutoring with Ann', '09')));
        $this->assertSame(2, $transport->send($this->invite('REQUEST', 'ann@kpu.ca', 'Tutoring with Sam', '09')));

        $this->assertCount(2, $this->events, 'each recipient gets an event of their own');
        $this->assertSame(
            ['student@kpu.ca' => 'Tutoring with Ann', 'ann@kpu.ca' => 'Tutoring with Sam'],
            $this->subjectsByAttendee(),
            'each recipient keeps the wording written for them',
        );
    }

    public function testRescheduleUpdatesOnlyThatRecipientsEvent(): void
    {
        $transport = $this->transport();
        $transport->send($this->invite('REQUEST', 'student@kpu.ca', 'Tutoring with Ann', '09'));
        $transport->send($this->invite('REQUEST', 'ann@kpu.ca', 'Tutoring with Sam', '09'));
        $this->requests = [];

        $transport->send($this->invite('REQUEST', 'student@kpu.ca', 'Tutoring with Ann', '11'));

        $this->assertCount(2, $this->events, 'a reschedule must not add a second event');
        $this->assertSame(['GET /v1.0/me/events', 'PATCH /v1.0/me/events/evt-1'], $this->requests);
        $this->assertSame('2026-11-02T11:00:00', $this->events['evt-1']['start']['dateTime']);
        $this->assertSame('2026-11-02T09:00:00', $this->events['evt-2']['start']['dateTime']);
    }

    public function testCancelCancelsOnlyThatRecipientsEvent(): void
    {
        $transport = $this->transport();
        $transport->send($this->invite('REQUEST', 'student@kpu.ca', 'Tutoring with Ann', '09'));
        $transport->send($this->invite('REQUEST', 'ann@kpu.ca', 'Tutoring with Sam', '09'));
        $this->requests = [];

        $transport->send($this->invite('CANCEL', 'student@kpu.ca', 'Tutoring with Ann', '09'));

        $this->assertSame(['GET /v1.0/me/events', 'POST /v1.0/me/events/evt-1/cancel'], $this->requests);
        $this->assertTrue($this->events['evt-1']['cancelled']);
        $this->assertFalse($this->events['evt-2']['cancelled']);
    }

    private function transport(): Swift_Transport_Api_MicrosoftGraphTransport
    {
        $adapter = new Microsoft\Graph\GraphRequestAdapter(
            new Microsoft\Kiota\Abstractions\Authentication\AnonymousAuthenticationProvider(),
            // The SDK's own middleware stack (it rewrites /users/me-token-to-replace to /me),
            // ending in the in-memory mailbox instead of the network.
            GraphClientFactory::createWithMiddleware(GraphClientFactory::getDefaultHandlerStack(
                fn (RequestInterface $request): PromiseInterface => Create::promiseFor($this->mailbox($request)),
            )),
        );
        $transport = new Swift_Transport_Api_MicrosoftGraphTransport(
            Microsoft\Graph\GraphServiceClient::createWithRequestAdapter($adapter),
            null,
            $this->dispatcher(),
        );
        $transport->enableCalendarEventConversion();

        return $transport;
    }

    /** The parts of an Exchange mailbox's /me/events API that the transport uses. */
    private function mailbox(RequestInterface $request): Response
    {
        $path             = $request->getUri()->getPath();
        $this->requests[] = $request->getMethod().' '.$path;
        $body             = \json_decode((string) $request->getBody(), true) ?? [];

        if ('GET' === $request->getMethod() && '/v1.0/me/events' === $path) {
            \parse_str($request->getUri()->getQuery(), $query);
            \preg_match("/ep\\/value eq '((?:[^']|'')*)'/", (string) ($query['$filter'] ?? ''), $match);
            $key     = \str_replace("''", "'", $match[1] ?? '');
            $matches = \array_values(\array_filter($this->events, static fn (array $event): bool => $event['key'] === $key));

            return $this->json(200, ['value' => \array_map(static fn (array $event): array => ['id' => $event['id']], $matches)]);
        }

        if ('POST' === $request->getMethod() && '/v1.0/me/events' === $path) {
            if (isset($body['transactionId'])) {
                if (\in_array($body['transactionId'], $this->transactionIds, true)) {
                    return $this->json(409, ['error' => ['code' => 'ErrorDuplicateTransactionId', 'message' => 'Duplicate transactionId.']]);
                }
                $this->transactionIds[] = $body['transactionId'];
            }
            $id                = 'evt-'.(\count($this->events) + 1);
            $this->events[$id] = [
                'id'        => $id,
                'key'       => $body['singleValueExtendedProperties'][0]['value'] ?? null,
                'subject'   => $body['subject']                                   ?? null,
                'attendees' => \array_map(static fn (array $a): string => $a['emailAddress']['address'], $body['attendees'] ?? []),
                'start'     => $body['start'] ?? null,
                'cancelled' => false,
            ];

            return $this->json(201, ['id' => $id]);
        }

        if (\preg_match('#^/v1\.0/me/events/(evt-\d+)(/cancel)?$#', $path, $match) && isset($this->events[$match[1]])) {
            if ('PATCH' === $request->getMethod() && !isset($match[2])) {
                $this->events[$match[1]]['start']   = $body['start']   ?? $this->events[$match[1]]['start'];
                $this->events[$match[1]]['subject'] = $body['subject'] ?? $this->events[$match[1]]['subject'];

                return $this->json(200, ['id' => $match[1]]);
            }
            if ('POST' === $request->getMethod() && isset($match[2])) {
                $this->events[$match[1]]['cancelled'] = true;

                return new Response(202);
            }
        }

        return $this->json(400, ['error' => ['code' => 'Unexpected', 'message' => $request->getMethod().' '.$path]]);
    }

    /** @return array<string, string> */
    private function subjectsByAttendee(): array
    {
        $subjects = [];
        foreach ($this->events as $event) {
            foreach ($event['attendees'] as $address) {
                $subjects[$address] = $event['subject'];
            }
        }

        return $subjects;
    }

    /** One recipient's copy of an appointment invite, shaped like the ones TracCloud sends. */
    private function invite(string $method, string $attendee, string $summary, string $hour): Swift_Message
    {
        $ics = \implode("\r\n", [
            'BEGIN:VCALENDAR',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            'UID:'.self::UID,
            'SEQUENCE:275369',
            "DTSTART;TZID=America/Vancouver:20261102T{$hour}0000",
            'DTEND;TZID=America/Vancouver:20261102T'.\sprintf('%02d', (int) $hour + 1).'0000',
            "SUMMARY:{$summary}",
            "ATTENDEE;CN=Attendee;ROLE=REQ-PARTICIPANT:MAILTO:{$attendee}",
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $message = new Swift_Message($summary);
        $message->setFrom(['tutoring@kpu.ca' => 'KPU']);
        $message->setTo([$attendee => 'Attendee']);
        $message->setBody('<p>Your appointment</p>', 'text/html');
        $message->attach(new Swift_Attachment($ics, 'invite.ics', 'text/calendar'));

        return $message;
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], \json_encode($body));
    }

    private function dispatcher(): Swift_Events_EventDispatcher
    {
        $change = $this->createMock(Swift_Events_TransportChangeEvent::class);
        $change->method('bubbleCancelled')->willReturn(false);
        $send = $this->createMock(Swift_Events_SendEvent::class);
        $send->method('bubbleCancelled')->willReturn(false);

        $dispatcher = $this->createMock(Swift_Events_EventDispatcher::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($change);
        $dispatcher->method('createSendEvent')->willReturn($send);

        return $dispatcher;
    }
}
