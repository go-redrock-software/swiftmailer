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

    /** @var list<list<string>> Delivery audiences submitted to mutating endpoints. */
    private array $notifications = [];

    private array $sentMail = [];

    private bool $omitAudience = false;

    private bool $failMutation = false;

    public function testStudentAndStaffInvitesForOneAppointmentBothBecomeEvents(): void
    {
        $transport = $this->transport();

        $this->assertSame(1, $transport->send($this->invite('REQUEST', 'student@kpu.ca', 'Tutoring with Ann', '09')));
        $this->assertSame(1, $transport->send($this->invite('REQUEST', 'ann@kpu.ca', 'Tutoring with Sam', '09')));

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
        $this->assertSame(['GET /v1.0/me/events', 'GET /v1.0/me/events/evt-1', 'PATCH /v1.0/me/events/evt-1'], $this->requests);
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

        $this->assertSame(['GET /v1.0/me/events', 'GET /v1.0/me/events/evt-1', 'POST /v1.0/me/events/evt-1/cancel'], $this->requests);
        $this->assertTrue($this->events['evt-1']['cancelled']);
        $this->assertFalse($this->events['evt-2']['cancelled']);
    }

    /** @dataProvider provideCalendarPolicies */
    public function testPolicyControlsTheCalendarDeliveryAudience(string $policy, array $attendees, string $visible, array $expected): void
    {
        $transport = $this->transport($this->policies($policy));
        $message   = $this->policyInvite('REQUEST', $attendees, $visible);
        $original  = $message->toString();
        $failed    = [];

        $this->assertSame(\count($expected), $transport->send($message, $failed));
        $this->assertSame($expected ? [$expected] : [], $this->notifications);
        $this->assertSame([], $failed);
        $this->assertSame($original, $message->toString());
        if (!$expected) {
            $this->assertSame([], $this->requests);
        }
    }

    public static function provideCalendarPolicies(): iterable
    {
        yield 'allowlist mixed' => ['allowlist', ['kept@safe.test', 'blocked@outside.test'], 'visible@safe.test', ['kept@safe.test']];
        yield 'catch-all mixed' => ['catch-all', ['kept@safe.test', 'blocked@outside.test'], 'visible@safe.test', ['kept@safe.test', 'catch@safe.test']];
        yield 'redirect mixed whitelist' => ['redirect', ['kept@safe.test', 'blocked@outside.test'], 'visible@safe.test', ['kept@safe.test', 'sink@safe.test']];
        yield 'redirect all' => ['redirect', ['blocked@outside.test'], 'visible@safe.test', ['sink@safe.test']];
        yield 'allowlist then redirect' => ['allowlist-redirect', ['kept@safe.test', 'blocked@outside.test'], 'visible@safe.test', ['kept@safe.test', 'catch@safe.test', 'sink@safe.test']];
        yield 'redirect then allowlist' => ['redirect-allowlist', ['kept@safe.test', 'blocked@outside.test'], 'visible@safe.test', ['kept@safe.test', 'sink@safe.test']];
        yield 'all calendar recipients blocked' => ['allowlist', ['blocked@outside.test'], 'visible@safe.test', []];
        yield 'all delivery recipients blocked' => ['allowlist', ['blocked@outside.test'], 'blocked@outside.test', []];
        yield 'calendar allowed despite filtered header' => ['allowlist', ['kept@safe.test'], 'blocked@outside.test', ['kept@safe.test']];
    }

    public function testRetainedCalendarAttendeeKeepsNameAndRole(): void
    {
        $this->transport($this->policies('allowlist'))->send($this->policyInvite('REQUEST', ['kept@safe.test', 'blocked@outside.test']));

        $this->assertSame(['address' => 'kept@safe.test', 'name' => 'Guest 0'], $this->events['evt-1']['attendeeDetails'][0]['emailAddress']);
        $this->assertSame('optional', $this->events['evt-1']['attendeeDetails'][0]['type']);
    }

    /** @dataProvider provideLifecyclePolicies */
    public function testSafePolicyAudienceSupportsCreateUpdateAndCancel(string $policy, array $expected): void
    {
        $transport = $this->transport($this->policies($policy));
        foreach (['REQUEST', 'REQUEST', 'CANCEL'] as $method) {
            $message  = $this->policyInvite($method, ['kept@safe.test', 'blocked@outside.test']);
            $original = $message->toString();
            $this->assertSame(\count($expected), $transport->send($message));
            $this->assertSame($original, $message->toString());
        }
        $this->assertCount(1, $this->events);
        $this->assertTrue($this->events['evt-1']['cancelled']);
        $this->assertSame([$expected, $expected, $expected], $this->notifications);
        $this->assertStringNotContainsString('blocked@outside.test', $this->events['evt-1']['key']);
    }

    public static function provideLifecyclePolicies(): iterable
    {
        yield 'allowlist' => ['allowlist', ['kept@safe.test']];
        yield 'catch-all' => ['catch-all', ['kept@safe.test', 'catch@safe.test']];
        yield 'redirect' => ['redirect', ['kept@safe.test', 'sink@safe.test']];
        yield 'allowlist then redirect' => ['allowlist-redirect', ['kept@safe.test', 'catch@safe.test', 'sink@safe.test']];
        yield 'redirect then allowlist' => ['redirect-allowlist', ['kept@safe.test', 'sink@safe.test']];
    }

    /** @dataProvider provideUnsafeStoredAudiences */
    public function testUnsafeStoredAudienceIsRejectedBeforeAnyMutation(string $method, string $condition): void
    {
        $transport = $this->transport($this->policies('allowlist'));
        $transport->send($this->policyInvite('REQUEST', ['kept@safe.test']));
        $this->requests = $this->notifications = [];
        if ('blocked' === $condition) {
            $this->events['evt-1']['attendees'][] = 'blocked@outside.test';
        } elseif ('unknown' === $condition) {
            $this->omitAudience = true;
        } else {
            $this->events['evt-1']['type'] = 'seriesMaster';
        }
        $message  = $this->policyInvite($method, ['kept@safe.test']);
        $original = $message->toString();
        try {
            $transport->send($message);
            $this->fail('An unsafe or unknown stored audience must not be updated or cancelled');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('calendar', $e->getMessage());
        }
        $this->assertSame([], $this->notifications);
        foreach ($this->requests as $request) {
            $this->assertStringStartsWith('GET ', $request);
        }
        $this->assertSame($original, $message->toString());
    }

    public static function provideUnsafeStoredAudiences(): iterable
    {
        foreach (['REQUEST', 'CANCEL'] as $method) {
            foreach (['blocked', 'unknown', 'recurring'] as $condition) {
                yield $method.' '.$condition => [$method, $condition];
            }
        }
    }

    public function testRedirectedCancelDoesNotFindProductionAudienceEvent(): void
    {
        $this->transport()->send($this->policyInvite('REQUEST', ['blocked@outside.test']));
        $this->requests = $this->notifications = [];
        $transport      = $this->transport($this->policies('redirect'));

        $this->assertSame(0, @$transport->send($this->policyInvite('CANCEL', ['blocked@outside.test'])));
        $this->assertFalse($this->events['evt-1']['cancelled']);
        $this->assertSame([], $this->notifications);
        $this->assertSame(['GET /v1.0/me/events'], $this->requests);
    }

    /** @dataProvider provideMailAlongside */
    public function testOnlySubmittedMailIsAddedToCalendarCounts(bool $attachment): void
    {
        $transport = $this->transport($this->policies('allowlist'));
        $transport->setSendEmailAlongsideEvent(!$attachment);
        $message = $this->policyInvite('REQUEST', ['kept@safe.test', 'blocked@outside.test']);
        if ($attachment) {
            $message->attach(new Swift_Attachment('Report body', 'report.txt', 'text/plain'));
        }

        $this->assertSame(2, $transport->send($message));
        $this->assertSame([['kept@safe.test'], ['visible@safe.test']], $this->notifications);
        $this->assertContains('POST /v1.0/me/sendMail', $this->requests);
        $this->assertSame($attachment ? ['report.txt'] : [], \array_column($this->sentMail[0]['attachments'] ?? [], 'name'));
    }

    public static function provideMailAlongside(): iterable
    {
        yield 'explicit send alongside' => [false];
        yield 'attachment requires ordinary mail' => [true];
    }

    /** @dataProvider provideStoredAudienceCounts */
    public function testExistingAudienceContributesToNotificationCounts(string $method, bool $policy): void
    {
        $transport = $this->transport($policy ? $this->policies('allowlist') : []);
        $transport->send($this->policyInvite('REQUEST', ['kept@safe.test']));
        $this->events['evt-1']['attendees'][] = 'added@safe.test';
        $this->requests                       = $this->notifications = [];

        $this->assertSame(2, $transport->send($this->policyInvite($method, ['kept@safe.test'])));
        $this->assertSame([['kept@safe.test', 'added@safe.test']], $this->notifications);
    }

    public static function provideStoredAudienceCounts(): iterable
    {
        foreach (['REQUEST', 'CANCEL'] as $method) {
            yield $method.' with policy' => [$method, true];
            yield $method.' without policy' => [$method, false];
        }
    }

    /** @dataProvider provideLifecyclePolicies */
    public function testProviderFailureRestoresBothPolicyOrdersAndReportsCalendarAudience(string $policy, array $expected): void
    {
        $transport = $this->transport($this->policies($policy));
        $observer  = new class implements Swift_Events_SendListener {
            public int $completed = 0;

            public ?int $result = null;

            public array $failures = [];

            public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
            {
            }

            public function sendPerformed(Swift_Events_SendEvent $evt): void
            {
                ++$this->completed;
                $this->result   = $evt->getResult();
                $this->failures = $evt->getFailedRecipients();
            }
        };
        $transport->registerPlugin($observer);
        $message            = $this->policyInvite('REQUEST', ['kept@safe.test', 'blocked@outside.test'])->setCc('copy@safe.test')->setBcc('blind@outside.test');
        $original           = $message->toString();
        $failed             = [];
        $this->failMutation = true;
        try {
            $transport->send($message, $failed);
            $this->fail('A provider failure must propagate');
        } catch (Swift_TransportException $e) {
            $this->assertSame($expected, $failed);
        }
        $this->assertSame($original, $message->toString());
        $this->assertSame(1, $observer->completed);
        $this->assertSame(Swift_Events_SendEvent::RESULT_FAILED, $observer->result);
        $this->assertSame($expected, $observer->failures);
        $this->assertSame([], $this->notifications);
        $this->failMutation = false;
        $this->assertSame(\count($expected), $transport->send($message));
        $this->assertSame($original, $message->toString());
    }

    public function testEveryInviteIsPreflightedBeforeAnyMutatingRequest(): void
    {
        $transport = $this->transport($this->policies('allowlist'));
        $transport->send($this->policyInvite('REQUEST', ['kept@safe.test']));
        $this->events['evt-1']['attendees'][] = 'blocked@outside.test';
        $this->requests                       = $this->notifications = [];
        $message                              = $this->policyInvite('REQUEST', ['kept@safe.test']);
        $originalInvite                       = $message->getChildren()[0];
        $first                                = new Swift_Attachment(\str_replace('UID:policy-lifecycle', 'UID:new-invite', $originalInvite->getBody()), 'first.ics', 'text/calendar');
        $message->setChildren([$first, $originalInvite]);
        try {
            $transport->send($message);
            $this->fail('Unsafe second invite must stop the first invite before mutation');
        } catch (Swift_TransportException $e) {
            $this->assertStringContainsString('calendar', $e->getMessage());
        }
        $this->assertCount(1, $this->events);
        $this->assertSame([], $this->notifications);
        foreach ($this->requests as $request) {
            $this->assertStringStartsWith('GET ', $request);
        }
    }

    public function testNoDispatcherStillRecordsCalendarFailureAudience(): void
    {
        $transport          = $this->transport([], false);
        $this->failMutation = true;
        $failed             = [];
        try {
            $transport->send($this->policyInvite('REQUEST', ['kept@safe.test']), $failed);
            $this->fail('Provider failure must propagate');
        } catch (Swift_TransportException $e) {
            $this->assertSame(['kept@safe.test'], $failed);
        }
    }

    public function testNoDispatcherNoMatchCancelAddsNoFailedMailRecipients(): void
    {
        $failed = [];
        $this->assertSame(0, @$this->transport([], false)->send($this->policyInvite('CANCEL', ['kept@safe.test']), $failed));
        $this->assertSame([], $failed);
    }

    private function policies(string $policy): array
    {
        $allow    = new Swift_Plugins_AllowlistPlugin(['*@safe.test']);
        $catch    = new Swift_Plugins_AllowlistPlugin(['*@safe.test'], 'catch@safe.test');
        $redirect = new Swift_Plugins_RedirectingPlugin('sink@safe.test', ['/@safe\.test$/']);

        return match ($policy) {
            'allowlist'          => [$allow],
            'catch-all'          => [$catch],
            'redirect'           => [$redirect],
            'allowlist-redirect' => [$catch, $redirect],
            'redirect-allowlist' => [$redirect, $catch],
        };
    }

    private function policyInvite(string $method, array $attendees, string $visible = 'visible@safe.test'): Swift_Message
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:'.$method, 'BEGIN:VEVENT', 'UID:policy-lifecycle', 'DTSTART:20261015T120000Z', 'DTEND:20261015T130000Z', 'SUMMARY:Policy lifecycle'];
        foreach ($attendees as $index => $address) {
            $lines[] = 'ATTENDEE;CN=Guest '.$index.';ROLE='.($index ? 'REQ-PARTICIPANT' : 'OPT-PARTICIPANT').':mailto:'.$address;
        }
        $lines = \array_merge($lines, ['END:VEVENT', 'END:VCALENDAR']);

        return (new Swift_Message('Policy lifecycle'))->setFrom('author@safe.test')->setTo($visible)->setBody('Body')
            ->attach(new Swift_Attachment(\implode("\r\n", $lines), 'invite.ics', 'text/calendar'));
    }

    private function transport(array $plugins = [], bool $withDispatcher = true): Swift_Transport_Api_MicrosoftGraphTransport
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
            $withDispatcher ? $this->dispatcher() : null,
        );
        $transport->enableCalendarEventConversion();
        foreach ($plugins as $plugin) {
            $transport->registerPlugin($plugin);
        }

        return $transport;
    }

    /** The parts of an Exchange mailbox's /me/events API that the transport uses. */
    private function mailbox(RequestInterface $request): Response
    {
        $path             = $request->getUri()->getPath();
        $this->requests[] = $request->getMethod().' '.$path;
        $body             = \json_decode((string) $request->getBody(), true) ?? [];

        if ($this->failMutation && 'GET' !== $request->getMethod()) {
            return $this->json(400, ['error' => ['code' => 'Failure', 'message' => 'Provider failure']]);
        }

        if ('POST' === $request->getMethod() && '/v1.0/me/sendMail' === $path) {
            $addresses        = [];
            $mail             = $body['message'] ?? $body['Message'];
            $this->sentMail[] = $mail;
            foreach (['toRecipients', 'ccRecipients', 'bccRecipients'] as $field) {
                foreach ($mail[$field] ?? [] as $recipient) {
                    $addresses[] = $recipient['emailAddress']['address'];
                }
            }
            $this->notifications[] = $addresses;

            return new Response(202);
        }

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
                'id'              => $id,
                'key'             => $body['singleValueExtendedProperties'][0]['value'] ?? null,
                'subject'         => $body['subject']                                   ?? null,
                'attendees'       => \array_map(static fn (array $a): string => $a['emailAddress']['address'], $body['attendees'] ?? []),
                'attendeeDetails' => $body['attendees'] ?? [],
                'type'            => 'singleInstance',
                'start'           => $body['start'] ?? null,
                'cancelled'       => false,
            ];
            $this->notifications[] = $this->events[$id]['attendees'];

            return $this->json(201, ['id' => $id]);
        }

        if (\preg_match('#^/v1\.0/me/events/(evt-\d+)(/cancel)?$#', $path, $match) && isset($this->events[$match[1]])) {
            if ('GET' === $request->getMethod() && !isset($match[2])) {
                $event    = $this->events[$match[1]];
                $response = ['id' => $event['id'], 'type' => $event['type']];
                if (!$this->omitAudience) {
                    $response['attendees'] = \array_map(static fn (string $address): array => ['emailAddress' => ['address' => $address], 'type' => 'required'], $event['attendees']);
                }

                return $this->json(200, $response);
            }
            if ('PATCH' === $request->getMethod() && !isset($match[2])) {
                $addresses                            = \array_map(static fn (array $a): string => $a['emailAddress']['address'], $body['attendees'] ?? []);
                $this->notifications[]                = \array_values(\array_unique(\array_merge($this->events[$match[1]]['attendees'], $addresses)));
                $this->events[$match[1]]['attendees'] = $addresses;
                $this->events[$match[1]]['start']     = $body['start']   ?? $this->events[$match[1]]['start'];
                $this->events[$match[1]]['subject']   = $body['subject'] ?? $this->events[$match[1]]['subject'];

                return $this->json(200, ['id' => $match[1]]);
            }
            if ('POST' === $request->getMethod() && isset($match[2])) {
                $this->notifications[]                = $this->events[$match[1]]['attendees'];
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
        return new Swift_Events_SimpleEventDispatcher();
    }
}
