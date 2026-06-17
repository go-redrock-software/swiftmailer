<?php

use AsyncAws\Ses\Result\SendEmailResponse;
use AsyncAws\Ses\SesClient;
use PHPUnit\Framework\TestCase;

/*
 * Stub classes for Symfony Mime components not installed in this project.
 * AmazonSesApiTransport references these in the global namespace.
 */
if (!\class_exists('Address', false)) {
    class Address
    {
        private string $address;

        private string $name;

        public function __construct(mixed $address, string $name = '')
        {
            if (\is_array($address)) {
                $this->address = (string) \array_key_first($address);
                $this->name    = (string) \array_values($address)[0];
            } else {
                $this->address = (string) $address;
                $this->name    = $name;
            }
        }

        public function getAddress(): string
        {
            return $this->address;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getEncodedAddress(): string
        {
            return $this->address;
        }

        public function toString(): string
        {
            return $this->name ? "{$this->name} <{$this->address}>" : $this->address;
        }
    }
}

if (!\class_exists('Email', false)) {
    class Email
    {
        private ?string $subject = null;

        private array $from = [];

        private array $to = [];

        private array $cc = [];

        private array $bcc = [];

        private array $replyTo = [];

        private ?string $textBody = null;

        private ?string $htmlBody = null;

        private ?Address $returnPath = null;

        private object $headers;

        public function __construct()
        {
            $this->headers = new class {
                private array $headers = [];

                public function get(string $name): ?object
                {
                    return $this->headers[$name] ?? null;
                }

                public function addHeader(string $name, object $header): void
                {
                    $this->headers[$name] = $header;
                }

                public function all(): array
                {
                    return \array_values($this->headers);
                }
            };
        }

        public function subject(string $subject): self
        {
            $this->subject = $subject;

            return $this;
        }

        public function getSubject(): ?string
        {
            return $this->subject;
        }

        public function from(Address $a): self
        {
            $this->from = [$a];

            return $this;
        }

        public function getFrom(): array
        {
            return $this->from;
        }

        public function to(Address ...$a): self
        {
            $this->to = $a;

            return $this;
        }

        public function getTo(): array
        {
            return $this->to;
        }

        public function cc(Address ...$a): self
        {
            $this->cc = $a;

            return $this;
        }

        public function getCc(): array
        {
            return $this->cc;
        }

        public function bcc(Address ...$a): self
        {
            $this->bcc = $a;

            return $this;
        }

        public function getBcc(): array
        {
            return $this->bcc;
        }

        public function replyTo(Address ...$a): self
        {
            $this->replyTo = $a;

            return $this;
        }

        public function getReplyTo(): array
        {
            return $this->replyTo;
        }

        public function text(string $t): self
        {
            $this->textBody = $t;

            return $this;
        }

        public function getTextBody(): ?string
        {
            return $this->textBody;
        }

        public function html(string $h): self
        {
            $this->htmlBody = $h;

            return $this;
        }

        public function getHtmlBody(): ?string
        {
            return $this->htmlBody;
        }

        public function returnPath(Address $a): self
        {
            $this->returnPath = $a;

            return $this;
        }

        public function getReturnPath(): ?Address
        {
            return $this->returnPath;
        }

        public function getHeaders(): object
        {
            return $this->headers;
        }
    }
}

// Alias AsyncAws classes to global namespace -- the transport uses them unqualified
if (!\class_exists('Content', false)) {
    \class_alias(AsyncAws\Ses\ValueObject\Content::class, 'Content');
}
if (!\class_exists('SendEmailRequest', false)) {
    \class_alias(AsyncAws\Ses\Input\SendEmailRequest::class, 'SendEmailRequest');
}

// MetadataHeader stub (from Symfony Mime, not installed)
if (!\class_exists('MetadataHeader', false)) {
    class MetadataHeader
    {
        private string $key;

        private string $value;

        public function __construct(string $key, string $value)
        {
            $this->key   = $key;
            $this->value = $value;
        }

        public function getKey(): string
        {
            return $this->key;
        }

        public function getValue(): string
        {
            return $this->value;
        }
    }
}

/**
 * Fake SES client -- extends real SesClient for type compatibility.
 */
class FakeSesClient extends SesClient
{
    private ?SendEmailResponse $response = null;

    private ?Exception $sendException = null;

    private ?Exception $pingException = null;

    public function __construct()
    {
    }

    public function setSendResponse(SendEmailResponse $r): void
    {
        $this->response = $r;
    }

    public function setSendException(Exception $e): void
    {
        $this->sendException = $e;
    }

    public function setPingException(Exception $e): void
    {
        $this->pingException = $e;
    }

    public function sendEmail($input): SendEmailResponse
    {
        if ($this->sendException) {
            throw $this->sendException;
        }

        return $this->response;
    }

    public function getAccountSendingEnabled(): object
    {
        if ($this->pingException) {
            throw $this->pingException;
        }

        return new stdClass();
    }
}

/**
 * Fake SendEmailResponse -- adds the get() method the transport expects.
 */
class FakeSendEmailResponse extends SendEmailResponse
{
    private ?string $fakeMessageId;

    public static function create(?string $messageId = 'test-msg-id'): self
    {
        $rc                      = new ReflectionClass(self::class);
        $instance                = $rc->newInstanceWithoutConstructor();
        $instance->fakeMessageId = $messageId;

        return $instance;
    }

    public function getMessageId(): ?string
    {
        return $this->fakeMessageId;
    }
}

class Swift_Transport_Api_AmazonSesApiTransportTest extends TestCase
{
    private $eventDispatcherMock;

    protected function setUp(): void
    {
        $this->eventDispatcherMock = $this->createMock(Swift_Events_EventDispatcher::class);
    }

    // ── Construction & interface ─────────────────────────────────────────

    public function testImplementsSwiftTransport(): void
    {
        $this->assertInstanceOf(Swift_Transport::class, $this->makeTransport());
    }

    public function testExtendsAbstractApiTransport(): void
    {
        $this->assertInstanceOf(Swift_Transport_AbstractApiTransport::class, $this->makeTransport());
    }

    // ── start / isStarted ───────────────────────────────────────────────

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

    // ── ping ────────────────────────────────────────────────────────────

    public function testPingReturnsTrueOnSuccess(): void
    {
        $this->assertTrue($this->makeTransport()->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $c = new FakeSesClient();
        $c->setPingException(new RuntimeException('fail'));
        $this->assertFalse($this->makeTransport($c)->ping());
    }

    // ── getApiConnection ────────────────────────────────────────────────

    public function testGetApiConnectionReturnsSesClient(): void
    {
        $c   = new FakeSesClient();
        $t   = $this->makeTransport($c);
        $ref = new ReflectionMethod($t, 'getApiConnection');
        $this->assertSame($c, $ref->invoke($t));
    }

    // ── send: basic ─────────────────────────────────────────────────────

    public function testSendBasicMessage(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create('msg-1'));
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recip']);
        $m->setSubject('Subj');
        $m->setBody('Body text');

        $this->assertSame(1, $t->send($m));
    }

    public function testSendAutoStarts(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);
        $this->assertFalse($t->isStarted());

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $t->send($m);
        $this->assertTrue($t->isStarted());
    }

    // ── send: CC / BCC ──────────────────────────────────────────────────

    public function testSendWithCc(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setCc(['cc@example.com' => 'CC']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(1, $t->send($m));
    }

    public function testSendWithBcc(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setBcc(['bcc@example.com' => 'BCC']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(1, $t->send($m));
    }

    public function testSendWithCcAndBcc(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setCc(['cc@example.com' => 'CC']);
        $m->setBcc(['bcc@example.com' => 'BCC']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(1, $t->send($m));
    }

    // ── send: HTML body ─────────────────────────────────────────────────

    public function testSendWithHtmlBody(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('<p>HTML</p>', 'text/html');

        $this->assertSame(1, $t->send($m));
    }

    // ── send: no MessageId ──────────────────────────────────────────────

    public function testSendReturnsZeroWhenNoMessageId(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create(null));
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(0, $t->send($m));
    }

    // ── send: error paths ───────────────────────────────────────────────

    public function testSendThrowsTransportExceptionOnFailure(): void
    {
        $c = new FakeSesClient();
        $c->setSendException(new RuntimeException('AWS fail'));
        $t = $this->makeTransport($c);

        $exEvt = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exEvt);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('Unable to send email');
        $t->send($m);
    }

    public function testSendReturnsZeroWhenExceptionBubbleCancelled(): void
    {
        $c = new FakeSesClient();
        $c->setSendException(new RuntimeException('AWS fail'));

        $exEvt = $this->createMock(Swift_Events_TransportExceptionEvent::class);
        $exEvt->method('bubbleCancelled')->willReturn(true);
        $this->eventDispatcherMock->method('createTransportExceptionEvent')->willReturn($exEvt);

        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(0, $t->send($m));
    }

    // ── send: X-Mailer-Tag headers ──────────────────────────────────────

    public function testSendExtractsMailerTagHeaders(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');
        $m->getHeaders()->addTextHeader('X-Mailer-Tag', 'campaign-1');

        $this->assertSame(1, $t->send($m));
        $this->assertEmpty($m->getHeaders()->getAll('X-Mailer-Tag'));
    }

    public function testSendWithMultipleMailerTags(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');
        $m->getHeaders()->addTextHeader('X-Mailer-Tag', 't1');
        $m->getHeaders()->addTextHeader('X-Mailer-Tag', 't2');

        $this->assertSame(1, $t->send($m));
        $this->assertEmpty($m->getHeaders()->getAll('X-Mailer-Tag'));
    }

    public function testSendWithMultipleToRecipients(): void
    {
        $c = new FakeSesClient();
        $c->setSendResponse(FakeSendEmailResponse::create());
        $t = $this->makeTransport($c);

        $m = $this->msg();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['a@example.com' => 'A', 'b@example.com' => 'B']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(1, $t->send($m));
    }

    // ── stringifyAddress ────────────────────────────────────────────────

    public function testStringifyAddressWithAsciiName(): void
    {
        $t   = $this->makeTransport();
        $ref = new ReflectionMethod($t, 'stringifyAddress');
        $a   = new Address('test@example.com', 'John Doe');

        $this->assertSame('John Doe <test@example.com>', $ref->invoke($t, $a));
    }

    public function testStringifyAddressWithUtf8Name(): void
    {
        $t    = $this->makeTransport();
        $ref  = new ReflectionMethod($t, 'stringifyAddress');
        $name = "M\xC3\xBCller";
        $a    = new Address('test@example.com', $name);

        $expected = \sprintf('=?UTF-8?B?%s?= <test@example.com>', \base64_encode($name));
        $this->assertSame($expected, $ref->invoke($t, $a));
    }

    public function testStringifyAddressWithNoName(): void
    {
        $t   = $this->makeTransport();
        $ref = new ReflectionMethod($t, 'stringifyAddress');
        $a   = new Address('test@example.com');

        $this->assertSame('test@example.com', $ref->invoke($t, $a));
    }

    // ── stringifyAddresses ──────────────────────────────────────────────

    public function testStringifyAddresses(): void
    {
        $t     = $this->makeTransport();
        $ref   = new ReflectionMethod($t, 'stringifyAddresses');
        $addrs = [new Address('a@example.com', 'Alice'), new Address('b@example.com', 'Bob')];

        $result = $ref->invoke($t, $addrs);
        $this->assertCount(2, $result);
        $this->assertSame('Alice <a@example.com>', $result[0]);
        $this->assertSame('Bob <b@example.com>', $result[1]);
    }

    // ── getRequest ──────────────────────────────────────────────────────

    private function makeEmail(): Email
    {
        $e = new Email();
        $e->subject('Test');
        $e->from(new Address('from@example.com', 'Sender'));
        $e->to(new Address('to@example.com', 'To'));
        $e->text('Body');

        return $e;
    }

    private function invokeGetRequest(Email $email, array $tags = []): object
    {
        $t   = $this->makeTransport();
        $ref = new ReflectionMethod($t, 'getRequest');

        return $ref->invoke($t, $email, $tags);
    }

    public function testGetRequestBasic(): void
    {
        $this->assertInstanceOf(
            AsyncAws\Ses\Input\SendEmailRequest::class,
            $this->invokeGetRequest($this->makeEmail()),
        );
    }

    public function testGetRequestWithHtmlBody(): void
    {
        $e = $this->makeEmail();
        $e->html('<p>HTML</p>');
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithCcAndBcc(): void
    {
        $e = $this->makeEmail();
        $e->cc(new Address('cc@example.com', 'CC'));
        $e->bcc(new Address('bcc@example.com', 'BCC'));
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithReplyTo(): void
    {
        $e = $this->makeEmail();
        $e->replyTo(new Address('reply@example.com', 'Reply'));
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithConfigurationSetHeader(): void
    {
        $e = $this->makeEmail();
        $h = new class {
            public function getBodyAsString(): string
            {
                return 'my-config-set';
            }
        };
        $e->getHeaders()->addHeader('X-SES-CONFIGURATION-SET', $h);
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithSourceArnHeader(): void
    {
        $e = $this->makeEmail();
        $h = new class {
            public function getBodyAsString(): string
            {
                return 'arn:aws:ses:us-east-1:123:identity/x';
            }
        };
        $e->getHeaders()->addHeader('X-SES-SOURCE-ARN', $h);
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithListManagementOptionsHeader(): void
    {
        $e = $this->makeEmail();
        $h = new class {
            public function getBodyAsString(): string
            {
                return 'contactListName=MyList; topicName=MyTopic';
            }
        };
        $e->getHeaders()->addHeader('X-SES-LIST-MANAGEMENT-OPTIONS', $h);
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithReturnPath(): void
    {
        $e = $this->makeEmail();
        $e->returnPath(new Address('bounce@example.com'));
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithMetadataHeaders(): void
    {
        $e = $this->makeEmail();
        $e->getHeaders()->addHeader('X-Metadata', new MetadataHeader('campaign', 'welcome'));
        $this->assertInstanceOf(AsyncAws\Ses\Input\SendEmailRequest::class, $this->invokeGetRequest($e));
    }

    public function testGetRequestWithTags(): void
    {
        $this->assertInstanceOf(
            AsyncAws\Ses\Input\SendEmailRequest::class,
            $this->invokeGetRequest($this->makeEmail(), ['tag1', 'tag2']),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function msg(): Swift_Message
    {
        return new Swift_Message();
    }

    private function makeTransport(?FakeSesClient $client = null): Swift_Transport_Api_AmazonSesApiTransport
    {
        return new Swift_Transport_Api_AmazonSesApiTransport(
            $client ?? new FakeSesClient(),
            $this->eventDispatcherMock,
        );
    }
}
