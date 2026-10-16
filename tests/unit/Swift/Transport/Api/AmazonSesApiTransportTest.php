<?php

use AsyncAws\Ses\Exception\NotFoundException;
use AsyncAws\Ses\Input\SendEmailRequest;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2).'/SesSdkFixture.php';

class Swift_Transport_Api_AmazonSesApiTransportTest extends TestCase
{
    private $eventDispatcherMock;

    protected function setUp(): void
    {
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);
    }

    // ── Construction & interface ───────────────────────────────────

    public function testImplementsSwiftTransport(): void
    {
        $this->assertInstanceOf(Swift_Transport::class, $this->makeTransport());
    }

    public function testExtendsAbstractApiTransport(): void
    {
        $this->assertInstanceOf(Swift_Transport_AbstractApiTransport::class, $this->makeTransport());
    }

    // ── start / isStarted ──────────────────────────────────────

    public function testIsNotStartedByDefault(): void
    {
        $this->assertFalse($this->makeTransport()->isStarted());
    }

    public function testStartSetsStartedState(): void
    {
        $t = $this->makeTransport();
        $t->start();
        $this->assertTrue($t->isStarted());
    }

    // ── ping ────────────────────────────────────────────

    public function testPingReturnsTrueWhenAddressNotSuppressed(): void
    {
        // NotFoundException means SES answered -- the address just isn't suppressed.
        $this->assertTrue($this->makeTransport()->ping());
    }

    public function testPingReturnsFalseOnTransportError(): void
    {
        $c            = new SesSdkFixture();
        $c->onRequest = static fn () => throw new RuntimeException('network down');
        $this->assertFalse($this->makeTransport($c)->ping());
    }

    // ── getApiConnection ────────────────────────────────────

    public function testGetApiConnectionReturnsSesClient(): void
    {
        $c   = new SesSdkFixture();
        $t   = $this->makeTransport($c);
        $ref = new ReflectionMethod($t, 'getApiConnection');
        $this->assertSame($c->client, $ref->invoke($t));
    }

    // ── send: basic ────────────────────────────────────────

    public function testSendBasicMessage(): void
    {
        $c = $this->clientReturning('msg-1');
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recip']);
        $m->setSubject('Subj');
        $m->setBody('Body text');

        $this->assertSame(1, $t->send($m));

        $req = $c->input();
        $this->assertInstanceOf(SendEmailRequest::class, $req);
        $this->assertSame('Sender <from@example.com>', $req->getFromEmailAddress());
        $this->assertSame(['Recip <to@example.com>'], $req->getDestination()->getToAddresses());
        $this->assertSame('Subj', $req->getContent()->getSimple()->getSubject()->getData());
        $this->assertSame('Body text', $req->getContent()->getSimple()->getBody()->getText()->getData());
        $this->assertNull($req->getContent()->getSimple()->getBody()->getHtml());
    }

    public function testSendAutoStarts(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);
        $this->assertFalse($t->isStarted());

        $t->send($this->basicMsg());
        $this->assertTrue($t->isStarted());
    }

    // ── send: CC / BCC ─────────────────────────────────────

    public function testSendWithCc(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->setCc(['cc@example.com' => 'CC']);
        $this->assertSame(2, $t->send($m));

        $this->assertSame(['CC <cc@example.com>'], $c->input()->getDestination()->getCcAddresses());
    }

    public function testSendWithBcc(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->setBcc(['bcc@example.com' => 'BCC']);
        $this->assertSame(2, $t->send($m));

        $this->assertSame(['BCC <bcc@example.com>'], $c->input()->getDestination()->getBccAddresses());
    }

    // ── send: reply-to ─────────────────────────────────────

    public function testSendWithReplyTo(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->setReplyTo(['reply@example.com' => 'Reply']);
        $this->assertSame(1, $t->send($m));

        $this->assertSame(['Reply <reply@example.com>'], $c->input()->getReplyToAddresses());
    }

    // ── send: HTML body ────────────────────────────────────

    public function testSendWithHtmlBodySetsHtmlNotText(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->setBody('<p>HTML</p>', 'text/html');
        $this->assertSame(1, $t->send($m));

        $body = $c->input()->getContent()->getSimple()->getBody();
        $this->assertSame('<p>HTML</p>', $body->getHtml()->getData());
        $this->assertNull($body->getText());
    }

    // ── send: no MessageId ───────────────────────────────────

    public function testMissingMessageIdIsPermanent(): void
    {
        $c      = $this->clientReturning(null);
        $t      = $this->makeTransport($c);
        $failed = [];
        try {
            $t->send($this->basicMsg(), $failed);
            $this->fail('A missing MessageId must not be an ordinary zero send.');
        } catch (Swift_Transport_NonRetryableException $error) {
            $this->assertSame(200, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(\array_keys($this->basicMsg()->getTo()), $failed);
    }

    // ── send: error paths ───────────────────────────────────

    public function testSendThrowsTransportExceptionOnFailure(): void
    {
        $c            = new SesSdkFixture();
        $c->onRequest = static fn () => throw new RuntimeException('AWS fail');
        $t            = $this->makeTransport($c);

        $exEvt = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exEvt);

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Unable to send email');
        $t->send($this->basicMsg());
    }

    public function testSendReturnsZeroWhenExceptionBubbleCancelled(): void
    {
        $c            = new SesSdkFixture();
        $c->onRequest = static fn () => throw new RuntimeException('AWS fail');

        $exEvt = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $exEvt->method('bubbleCancelled')->willReturn(true);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exEvt);

        $t = $this->makeTransport($c);
        $this->assertSame(0, $t->send($this->basicMsg()));
    }

    // ── send: SES headers ───────────────────────────────────

    public function testSendMapsConfigurationSetHeader(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', 'my-config-set');
        $this->assertSame(1, $t->send($m));

        $this->assertSame('my-config-set', $c->input()->getConfigurationSetName());
    }

    public function testSendMapsSourceArnHeaderToIdentityArn(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $arn = 'arn:aws:ses:us-east-1:123:identity/example.com';
        $m   = $this->basicMsg();
        $m->getHeaders()->addTextHeader('X-SES-SOURCE-ARN', $arn);
        $this->assertSame(1, $t->send($m));

        $this->assertSame($arn, $c->input()->getFromEmailAddressIdentityArn());
    }

    public function testSendMapsListManagementOptionsHeader(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->getHeaders()->addTextHeader('X-SES-LIST-MANAGEMENT-OPTIONS', 'contactListName=MyList; topicName=MyTopic');
        $this->assertSame(1, $t->send($m));

        $lmo = $c->input()->getListManagementOptions();
        $this->assertNotNull($lmo);
        $this->assertSame('MyList', $lmo->getContactListName());
        $this->assertSame('MyTopic', $lmo->getTopicName());
    }

    public function testSendMapsReturnPathToFeedbackForwarding(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->setReturnPath('bounce@example.com');
        $this->assertSame(1, $t->send($m));

        $this->assertSame('bounce@example.com', $c->input()->getFeedbackForwardingEmailAddress());
    }

    // ── send: X-Mailer-Tag headers → EmailTags ────────────────────────

    public function testSendExtractsMailerTagsIntoEmailTagsAndRemovesHeader(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->getHeaders()->addTextHeader('X-Mailer-Tag', 'campaign-1');
        $this->assertSame(1, $t->send($m));

        $tags = $c->input()->getEmailTags();
        $this->assertCount(1, $tags);
        $this->assertSame('tag', $tags[0]->getName());
        $this->assertSame('campaign-1', $tags[0]->getValue());
        $this->assertEmpty($m->getHeaders()->getAll('X-Mailer-Tag'));
    }

    public function testSendWithMultipleMailerTags(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->basicMsg();
        $m->getHeaders()->addTextHeader('X-Mailer-Tag', 't1');
        $m->getHeaders()->addTextHeader('X-Mailer-Tag', 't2');
        $this->assertSame(1, $t->send($m));

        $tags = $c->input()->getEmailTags();
        $this->assertCount(2, $tags);
        $this->assertSame(['t1', 't2'], [$tags[0]->getValue(), $tags[1]->getValue()]);
        $this->assertEmpty($m->getHeaders()->getAll('X-Mailer-Tag'));
    }

    // ── send: recipients & encoding ──────────────────────────────

    public function testSendWithMultipleToRecipients(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['a@example.com' => 'A', 'b@example.com' => 'B']);
        $m->setSubject('T');
        $m->setBody('B');
        $this->assertSame(2, $t->send($m));

        $this->assertSame(
            ['A <a@example.com>', 'B <b@example.com>'],
            $c->input()->getDestination()->getToAddresses(),
        );
    }

    public function testSendBEncodesUtf8DisplayName(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $name = "M\xC3\xBCller"; // "Müller" in UTF-8 -- non-ASCII bytes
        $m    = $this->msg();
        $m->setFrom(['from@example.com' => $name]);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');
        $this->assertSame(1, $t->send($m));

        $expected = \sprintf('=?UTF-8?B?%s?= <from@example.com>', \base64_encode($name));
        $this->assertSame($expected, $c->input()->getFromEmailAddress());
    }

    public function testSendWithoutDisplayNamesUsesBareEmails(): void
    {
        $c = $this->clientReturning('id');
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom('from@example.com');
        $m->setTo('to@example.com');
        $m->setSubject('T');
        $m->setBody('B');
        $this->assertSame(1, $t->send($m));

        $this->assertSame('from@example.com', $c->input()->getFromEmailAddress());
        $this->assertSame(['to@example.com'], $c->input()->getDestination()->getToAddresses());
    }

    // ── Helpers ──────────────────────────────────────────

    private function msg(): Swift_Message
    {
        return new Swift_Message();
    }

    private function basicMsg(): Swift_Message
    {
        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        return $m;
    }

    private function clientReturning(?string $messageId): SesSdkFixture
    {
        return new SesSdkFixture([new Symfony\Component\HttpClient\Response\MockResponse(\json_encode(['MessageId' => $messageId]), ['http_code' => 200])]);
    }

    private function makeTransport(?SesSdkFixture $client = null): Swift_Transport_Api_AmazonSesApiTransport
    {
        return new Swift_Transport_Api_AmazonSesApiTransport(
            ($client ?? new SesSdkFixture())->client,
            $this->eventDispatcherMock,
        );
    }
}
