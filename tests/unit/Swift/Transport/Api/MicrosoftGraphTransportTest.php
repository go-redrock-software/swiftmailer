<?php

namespace Swift\Transport\Api;

use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\GraphServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Class Swift_Transport_Api_MicrosoftGraphTransportTest.
 *
 * Tests for the Swift_Transport_Api_MicrosoftGraphTransport class.
 */
class Swift_Transport_Api_MicrosoftGraphTransportTest extends TestCase
{
    /**
     * Test the __construct method.
     */
    public function testConstruct(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        // Assert that the object was properly initiated.
        $this->assertInstanceOf(\Swift_Transport_Api_MicrosoftGraphTransport::class, $object);

        // Assert that the sendingAccountUserId is properly set.
        $this->assertEquals($string, $object->getSendingAccountUserId());
    }

    public function testConstructDefaultsToNullUserId(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );

        $this->assertNull($object->getSendingAccountUserId());
        $this->assertTrue($object->isUsingMeEndpoint());
    }

    /**
     * Test the convertSwiftEmailAddressToGraphRecipient method for valid Swift email.
     */
    public function testConvertSwiftEmailAddressToGraphRecipientWithValidSwiftEmail(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        $swiftEmail = ['test@test.com' => 'Test User'];

        $expectedResult = new Recipient();
        $emailAddress   = new EmailAddress();
        $emailAddress->setAddress('test@test.com');
        $emailAddress->setName('Test User');
        $expectedResult->setEmailAddress($emailAddress);

        $result = $object->convertSwiftEmailAddressToGraphRecipient($swiftEmail);

        // Assert that they are instances of the same class
        $this->assertInstanceOf(\get_class($expectedResult), $result);

        // Assert individual properties
        $this->assertEquals(
            $expectedResult->getEmailAddress()->getName(),
            $result->getEmailAddress()->getName(),
        );

        $this->assertEquals(
            $expectedResult->getEmailAddress()->getAddress(),
            $result->getEmailAddress()->getAddress(),
        );
    }

    /**
     * Test the convertSwiftEmailAddressToGraphRecipient method for invalid Swift email.
     */
    public function testConvertSwiftEmailAddressToGraphRecipientWithInvalidSwiftEmailStrict(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        $swiftEmail = [];

        $this->expectException(\InvalidArgumentException::class);

        $object->convertSwiftEmailAddressToGraphRecipient($swiftEmail, true);
    }

    /**
     * Test the convertSwiftEmailAddressToGraphRecipient method for invalid Swift email.
     */
    public function testConvertSwiftEmailAddressToGraphRecipientWithInvalidSwiftEmailNotStrict(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        $swiftEmail = [];

        $return = $object->convertSwiftEmailAddressToGraphRecipient($swiftEmail, false);
        $this->assertEquals('', $return->getEmailAddress()->getAddress());
        $this->assertEquals('', $return->getEmailAddress()->getName());
    }

    /**
     * Test the convertSwiftAttachmentToGraphAttachment method with valid Swift attachment.
     */
    public function testConvertSwiftAttachmentToGraphAttachmentWithValidSwiftAttachment(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        $swiftAttachment = $this->getMockBuilder(\Swift_Attachment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $swiftAttachment->method('getBody')
            ->willReturn('QXR0YWNobWVudCBCb2R5');
        $swiftAttachment->method('getContentType')
            ->willReturn('text/plain');
        $swiftAttachment->method('getFilename')
            ->willReturn('attachment.txt');
        $swiftAttachment->method('getSize')
            ->willReturn(1024);
        $swiftAttachment->method('getDisposition')
            ->willReturn('attachment');

        $expectedResult = new FileAttachment();
        $expectedResult->setContentBytes(Utils::streamFor('QXR0YWNobWVudCBCb2R5'));
        $expectedResult->setContentType('text/plain');
        $expectedResult->setName('attachment.txt');
        $expectedResult->setSize(1024);
        $expectedResult->setIsInline(false);

        $result = $object->convertSwiftAttachmentToGraphAttachment($swiftAttachment);

        $this->assertEquals($expectedResult->getBackingStore()->get('name'), $result->getBackingStore()->get('name'));
        $this->assertEquals($expectedResult->getBackingStore()->get('size'), $result->getBackingStore()->get('size'));
        $this->assertEquals($expectedResult->getBackingStore()->get('isInline'), $result->getBackingStore()->get('isInline'));
        $this->assertEquals($expectedResult->getBackingStore()->get('additionalData'), $result->getBackingStore()->get('additionalData'));
        $this->assertEquals($expectedResult->getBackingStore()->get('odataType'), $result->getBackingStore()->get('odataType'));
    }

    /**
     * Test the convertSwiftAttachmentToGraphAttachment method with invalid Swift attachment.
     */
    public function testConvertSwiftAttachmentToGraphAttachmentWithInvalidSwiftAttachment(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        $this->expectException(\TypeError::class);

        $object->convertSwiftAttachmentToGraphAttachment(null);
    }

    /**
     * Test the setSendingAccountUserId method.
     */
    public function testSetSendingAccountUserId(): void
    {
        $graphServiceClientMock = $this->createMock(GraphServiceClient::class);
        $string                 = 'random_string';
        $eventDispatcherMock    = $this->createMock(\Swift_Events_EventDispatcher::class);

        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $graphServiceClientMock,
            $string,
            $eventDispatcherMock,
        );

        $newId = 'new_random_string';
        $object->setSendingAccountUserId($newId);

        // Assert that the sendingAccountUserId is properly set.
        $this->assertEquals($newId, $object->getSendingAccountUserId());
    }

    // -- useFromAddressAsSendingAccountUserId ---

    public function testUseFromAddressAsSendingAccountUserId(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        $object->useFromAddressAsSendingAccountUserId();
        $this->assertFalse($object->isUsingMeEndpoint());
    }

    public function testIsUsingMeEndpointReturnsTrueWhenNoUserId(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            null,
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        $this->assertTrue($object->isUsingMeEndpoint());
    }

    public function testIsUsingMeEndpointReturnsFalseWhenUserIdSet(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        $this->assertFalse($object->isUsingMeEndpoint());
    }

    public function testSetSendingAccountUserIdToNullEnablesMeEndpoint(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        $this->assertFalse($object->isUsingMeEndpoint());
        $object->setSendingAccountUserId(null);
        $this->assertTrue($object->isUsingMeEndpoint());
    }

    // -- ping ---

    public function testPingReturnsTrue(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $dispatcher,
        );

        $this->assertTrue($transport->ping());
    }

    // -- start ---

    public function testStartSetsStartedState(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $dispatcher,
        );

        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testStartDoesNotSetStartedWhenBubbleCancelled(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $changeEvt->method('bubbleCancelled')->willReturn(true);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $dispatcher,
        );

        $transport->start();
        $this->assertFalse($transport->isStarted());
    }

    public function testStartWhenAlreadyStartedDoesNothing(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $dispatcher,
        );

        $transport->start();
        $this->assertTrue($transport->isStarted());
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    // -- getApiConnection ---

    public function testGetApiConnectionReturnsGraphServiceClient(): void
    {
        $client    = $this->createMock(GraphServiceClient::class);
        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $client,
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        $ref = new \ReflectionMethod($transport, 'getApiConnection');
        $this->assertSame($client, $ref->invoke($transport));
    }

    // -- send: success ---

    public function testSendBasicMessageSuccess(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test Subject');
        $m->setBody('Hello body');

        $result = $transport->send($m);
        $this->assertSame(1, $result); // 1 To recipient counted, no CC/BCC/replyTo
    }

    // -- send: with reply-to ---

    public function testSendWithReplyTo(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setReplyTo(['reply@example.com' => 'Reply Name']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        $result = $transport->send($m);
        $this->assertSame(2, $result); // 1 To + replyTo
    }

    // -- send: with attachments ---

    public function testSendWithAttachments(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test');
        $m->setBody('Hello');
        $m->attach(new \Swift_Attachment('file content', 'test.txt', 'text/plain'));

        $result = $transport->send($m);
        $this->assertSame(1, $result); // 1 To recipient counted
    }

    // -- send: with inline attachment ---

    public function testSendWithInlineAttachment(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test');
        $m->setBody('<p>Hello</p>', 'text/html');

        $att = new \Swift_Attachment('imagecontent', 'image.png', 'image/png');
        $att->setDisposition('inline');
        $m->attach($att);

        $result = $transport->send($m);
        $this->assertSame(1, $result); // 1 To recipient counted
    }

    // -- send: bubble cancelled ---

    public function testSendReturnsZeroWhenSendEventBubbleCancelled(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $sendEvt->method('bubbleCancelled')->willReturn(true);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $dispatcher,
        );

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(0, $transport->send($m));
    }

    // -- send: transport change event bubble cancelled ---

    public function testSendReturnsZeroWhenTransportChangeBubbleCancelled(): void
    {
        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);

        $changeEvt = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $changeEvt->method('bubbleCancelled')->willReturn(true);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $dispatcher,
        );

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'S']);
        $m->setTo(['to@example.com' => 'R']);
        $m->setSubject('T');
        $m->setBody('B');

        $this->assertSame(0, $transport->send($m));
    }

    // -- send: API failure throws exception ---

    public function testSendThrowsTransportExceptionOnApiFailure(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willThrowException(new \RuntimeException('Graph API error'));

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Failed to send email');
        $transport->send($m);
    }

    // -- send: HTML body type ---

    public function testSendWithHtmlBodyType(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test');
        $m->setBody('<p>HTML Content</p>', 'text/html');

        $result = $transport->send($m);
        $this->assertSame(1, $result); // 1 To recipient counted
    }

    // Note: testSendWithoutSendEvent is not possible because the transport has a bug
    // in its finally block (line 205) that passes $evt to dispatchEvent even when null.

    // -- send: with CC (exercises CC path despite transport bug) ---

    public function testSendWithCcExercisesCcPath(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $captured        = null;
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturnCallback(function ($body) use ($promise, &$captured) {
            $captured = $body;

            return $promise;
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setCc(['cc@example.com' => 'CC Name']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        // CC is an [address => name] map; the recipient must carry the address, not
        // the name. The previous array_map-over-values implementation threw a
        // TypeError here, so this test would have caught the regression.
        $result = $transport->send($m);

        $this->assertSame(2, $result); // 1 To + 1 CC recipient counted

        $cc = $captured->getMessage()->getCcRecipients();
        $this->assertCount(1, $cc);
        $this->assertSame('cc@example.com', $cc[0]->getEmailAddress()->getAddress());
        $this->assertSame('CC Name', $cc[0]->getEmailAddress()->getName());
    }

    // -- send: with BCC (exercises BCC path despite transport bug) ---

    public function testSendWithBccExercisesBccPath(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $captured        = null;
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturnCallback(function ($body) use ($promise, &$captured) {
            $captured = $body;

            return $promise;
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setBcc(['bcc@example.com' => 'BCC Name']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        // BCC takes the same [address => name] path as CC; assert the address lands
        // on the recipient rather than triggering the old TypeError.
        $result = $transport->send($m);

        $this->assertSame(2, $result); // 1 To + 1 BCC recipient counted

        $bcc = $captured->getMessage()->getBccRecipients();
        $this->assertCount(1, $bcc);
        $this->assertSame('bcc@example.com', $bcc[0]->getEmailAddress()->getAddress());
        $this->assertSame('BCC Name', $bcc[0]->getEmailAddress()->getName());
    }

    // -- send: with multiple To recipients (all sent and counted) ---

    public function testSendWithMultipleToRecipientsSendsAndCountsAll(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $captured        = null;
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturnCallback(function ($body) use ($promise, &$captured) {
            $captured = $body;

            return $promise;
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo([
            'first@example.com'  => 'First Recipient',
            'second@example.com' => 'Second Recipient',
        ]);
        $m->setSubject('Test');
        $m->setBody('Hello');

        // Every To address must be sent (not just the first) and each must be counted.
        $result = $transport->send($m);

        $this->assertSame(2, $result); // both To recipients counted

        $to = $captured->getMessage()->getToRecipients();
        $this->assertCount(2, $to);
        $this->assertSame('first@example.com', $to[0]->getEmailAddress()->getAddress());
        $this->assertSame('First Recipient', $to[0]->getEmailAddress()->getName());
        $this->assertSame('second@example.com', $to[1]->getEmailAddress()->getAddress());
        $this->assertSame('Second Recipient', $to[1]->getEmailAddress()->getName());
    }

    // -- send: /me endpoint (delegated, no user ID) ---

    public function testSendUsesMeEndpointWhenNoUserId(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('me')->willReturn($userItemBuilder);
        $graphClient->expects($this->never())->method('users');

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, null, $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        $transport->send($m);
        $this->assertTrue($transport->isUsingMeEndpoint());
    }

    // -- send: from-address mode ---

    public function testSendUsesFromAddressAsUserId(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->expects($this->once())
            ->method('byUserId')
            ->with('from@example.com')
            ->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $sendEvt    = $this->createMock(\Swift_Events_SendEvent::class);
        $changeEvt  = $this->createMock(\Swift_Events_TransportChangeEvent::class);
        $dispatcher->method('createSendEvent')->willReturn($sendEvt);
        $dispatcher->method('createTransportChangeEvent')->willReturn($changeEvt);

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'ignored-id', $dispatcher);
        $transport->useFromAddressAsSendingAccountUserId();

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Test');
        $m->setBody('Hello');

        $transport->send($m);
        $this->assertTrue(true);
    }

    // -- convertSwiftEmailAddressToGraphRecipient: with name set to null (strict) ---

    public function testConvertSwiftEmailAddressWithNullNameNotStrict(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        $result = $object->convertSwiftEmailAddressToGraphRecipient(['test@x.com' => null]);
        $this->assertNull($result->getEmailAddress()->getName());
    }

    // -- convertSwiftEmailAddressToGraphRecipient: strict with valid json ---

    public function testConvertSwiftEmailAddressStrictWithNullNameThrows(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        // First value is null -> enters strict check -> json_encode succeeds
        // -> falls through to second strict throw
        $this->expectException(\InvalidArgumentException::class);
        $object->convertSwiftEmailAddressToGraphRecipient(['test@x.com' => null], true);
    }

    // -- convertSwiftEmailAddressToGraphRecipient: strict with JsonException ---

    public function testConvertSwiftEmailAddressStrictWithJsonException(): void
    {
        $object = new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
            'user-id',
            $this->createMock(\Swift_Events_EventDispatcher::class),
        );

        // First value is null -> enters strict check -> json_encode throws
        // because NAN is not JSON-encodable
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('error parsing email array');
        $object->convertSwiftEmailAddressToGraphRecipient(['test@x.com' => null, 'extra' => NAN], true);
    }

    // -- large-attachment threshold + partitioning -------------------------------

    private function newTransport(): \Swift_Transport_Api_MicrosoftGraphTransport
    {
        return new \Swift_Transport_Api_MicrosoftGraphTransport(
            $this->createMock(GraphServiceClient::class),
        );
    }

    public function testThresholdDefaultsToThreeMegabytes(): void
    {
        $transport = $this->newTransport();
        $this->assertSame(3 * 1024 * 1024, \Swift_Transport_Api_MicrosoftGraphTransport::LARGE_ATTACHMENT_THRESHOLD);
        $this->assertSame(3 * 1024 * 1024, $transport->getLargeAttachmentThreshold());
    }

    public function testSetThresholdUpdatesValue(): void
    {
        $transport = $this->newTransport();
        $transport->setLargeAttachmentThreshold(10 * 1024 * 1024);
        $this->assertSame(10 * 1024 * 1024, $transport->getLargeAttachmentThreshold());
    }

    public function testSetThresholdRejectsNonPositive(): void
    {
        $transport = $this->newTransport();
        $this->expectException(\InvalidArgumentException::class);
        $transport->setLargeAttachmentThreshold(0);
    }

    public function testPartitionSplitsAtThreshold(): void
    {
        $transport = $this->newTransport();
        $transport->setLargeAttachmentThreshold(10); // 10 bytes, easy to straddle

        $small  = new \Swift_Attachment('123456789', 'small.bin', 'application/octet-stream');   // 9 bytes
        $atEdge = new \Swift_Attachment('1234567890', 'edge.bin', 'application/octet-stream');  // 10 bytes -> large
        $big    = new \Swift_Attachment('1234567890123', 'big.bin', 'application/octet-stream');   // 13 bytes

        $method                  = new \ReflectionMethod($transport, 'partitionAttachmentsBySize');
        [$smallList, $largeList] = $method->invoke($transport, [$small, $atEdge, $big]);

        $this->assertSame([$small], $smallList);
        $this->assertSame([$atEdge, $big], $largeList);
    }

    public function testSendViaDraftAttachesSmallInlineUploadsLargeAndSends(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        // Draft POST returns a Message carrying an id.
        $draft = new \Microsoft\Graph\Generated\Models\Message();
        $draft->setId('draft-123');
        $draftPromise = $this->createMock(\Http\Promise\Promise::class);
        $draftPromise->method('wait')->willReturn($draft);

        // Upload session POST returns an UploadSession.
        $uploadSession  = new \Microsoft\Graph\Generated\Models\UploadSession();
        $sessionPromise = $this->createMock(\Http\Promise\Promise::class);
        $sessionPromise->method('wait')->willReturn($uploadSession);

        $createUpload = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\CreateUploadSession\CreateUploadSessionRequestBuilder::class);
        $createUpload->expects($this->once())->method('post')->willReturn($sessionPromise);

        $attachmentsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\AttachmentsRequestBuilder::class);
        $attachmentsBuilder->expects($this->once())->method('post')->willReturn($promise);          // one small attachment
        $attachmentsBuilder->method('createUploadSession')->willReturn($createUpload);

        $sendBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Send\SendRequestBuilder::class);
        $sendBuilder->expects($this->once())->method('post')->willReturn($promise);

        $messageItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\MessageItemRequestBuilder::class);
        $messageItemBuilder->method('attachments')->willReturn($attachmentsBuilder);
        $messageItemBuilder->method('send')->willReturn($sendBuilder);

        $messagesBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilder::class);
        $messagesBuilder->expects($this->once())->method('post')->willReturn($draftPromise);         // draft create
        $messagesBuilder->method('byMessageId')->with('draft-123')->willReturn($messageItemBuilder);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('messages')->willReturn($messagesBuilder);

        // Partial mock: stub only uploadLargeAttachment so no real streaming occurs.
        $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
            ->setConstructorArgs([$this->createMock(GraphServiceClient::class)])
            ->onlyMethods(['uploadLargeAttachment'])
            ->getMock();
        $transport->expects($this->once())->method('uploadLargeAttachment');

        $graphMessage = new \Microsoft\Graph\Generated\Models\Message();
        $small        = new \Swift_Attachment('small body', 'small.txt', 'text/plain');
        $large        = new \Swift_Attachment('large body bytes', 'big.bin', 'application/octet-stream');

        $method = new \ReflectionMethod($transport, 'sendViaDraft');
        $method->invoke($transport, $userItemBuilder, $graphMessage, [$small], [$large]);
    }

    public function testSendViaDraftBuildsAttachmentItemFromTheLargeAttachment(): void
    {
        // Capture the CreateUploadSessionPostRequestBody handed to createUploadSession()
        // and assert the AttachmentItem mirrors the source attachment's metadata.
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $draft = new \Microsoft\Graph\Generated\Models\Message();
        $draft->setId('draft-xyz');
        $draftPromise = $this->createMock(\Http\Promise\Promise::class);
        $draftPromise->method('wait')->willReturn($draft);

        $uploadSession  = new \Microsoft\Graph\Generated\Models\UploadSession();
        $sessionPromise = $this->createMock(\Http\Promise\Promise::class);
        $sessionPromise->method('wait')->willReturn($uploadSession);

        $capturedBody = null;
        $createUpload = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\CreateUploadSession\CreateUploadSessionRequestBuilder::class);
        $createUpload->method('post')->willReturnCallback(function ($body) use (&$capturedBody, $sessionPromise) {
            $capturedBody = $body;

            return $sessionPromise;
        });

        $attachmentsBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\AttachmentsRequestBuilder::class);
        $attachmentsBuilder->method('createUploadSession')->willReturn($createUpload);

        $sendBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\Send\SendRequestBuilder::class);
        $sendBuilder->method('post')->willReturn($promise);

        $messageItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\Item\MessageItemRequestBuilder::class);
        $messageItemBuilder->method('attachments')->willReturn($attachmentsBuilder);
        $messageItemBuilder->method('send')->willReturn($sendBuilder);

        $messagesBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilder::class);
        $messagesBuilder->method('post')->willReturn($draftPromise);
        $messagesBuilder->method('byMessageId')->with('draft-xyz')->willReturn($messageItemBuilder);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('messages')->willReturn($messagesBuilder);

        $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
            ->setConstructorArgs([$this->createMock(GraphServiceClient::class)])
            ->onlyMethods(['uploadLargeAttachment'])
            ->getMock();
        $transport->method('uploadLargeAttachment');

        $body  = 'the large attachment payload bytes';
        $large = new \Swift_Attachment($body, 'big.bin', 'application/octet-stream');

        $method = new \ReflectionMethod($transport, 'sendViaDraft');
        $method->invoke($transport, $userItemBuilder, new \Microsoft\Graph\Generated\Models\Message(), [], [$large]);

        $this->assertInstanceOf(
            \Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\CreateUploadSession\CreateUploadSessionPostRequestBody::class,
            $capturedBody,
            'createUploadSession()->post() should receive a CreateUploadSessionPostRequestBody',
        );
        $item = $capturedBody->getAttachmentItem();
        $this->assertNotNull($item, 'the upload body must carry an AttachmentItem');
        $this->assertSame('big.bin', $item->getName());
        $this->assertSame(\strlen($body), $item->getSize());
        $this->assertSame('application/octet-stream', $item->getContentType());
        // A normal (disposition 'attachment') file is not inline.
        $this->assertFalse($item->getIsInline());
        $this->assertSame(
            \Microsoft\Graph\Generated\Models\AttachmentType::FILE,
            $item->getAttachmentType()->value(),
        );
    }

    public function testSendViaDraftRejectsAttachmentOverGraphLimitBeforeCreatingDraft(): void
    {
        // An attachment larger than Graph's 150 MB upload-session ceiling must be
        // rejected up front — before a draft is created — so we never orphan a draft
        // that then fails deep in the chunked PUT. We stub attachmentByteSize so the
        // guard trips WITHOUT allocating 150 MB.
        $messagesBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilder::class);
        // No draft must ever be created.
        $messagesBuilder->expects($this->never())->method('post');

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('messages')->willReturn($messagesBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $transport  = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
            ->setConstructorArgs([$this->createMock(GraphServiceClient::class), null, $dispatcher])
            ->onlyMethods(['attachmentByteSize'])
            ->getMock();
        $transport->method('attachmentByteSize')
            ->willReturn(\Swift_Transport_Api_MicrosoftGraphTransport::MAX_ATTACHMENT_SIZE + 1);

        $oversized = new \Swift_Attachment('tiny', 'huge.bin', 'application/octet-stream');

        $this->expectException(\Swift_TransportException::class);
        $method = new \ReflectionMethod($transport, 'sendViaDraft');
        $method->invoke($transport, $userItemBuilder, new \Microsoft\Graph\Generated\Models\Message(), [], [$oversized]);
    }

    public function testSendViaDraftThrowsWhenDraftHasNoId(): void
    {
        $draftPromise = $this->createMock(\Http\Promise\Promise::class);
        $draftPromise->method('wait')->willReturn(null);

        $messagesBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilder::class);
        $messagesBuilder->method('post')->willReturn($draftPromise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('messages')->willReturn($messagesBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $transport  = new \Swift_Transport_Api_MicrosoftGraphTransport($this->createMock(GraphServiceClient::class), null, $dispatcher);

        $this->expectException(\Swift_TransportException::class);
        $method = new \ReflectionMethod($transport, 'sendViaDraft');
        $method->invoke($transport, $userItemBuilder, new \Microsoft\Graph\Generated\Models\Message(), [], [new \Swift_Attachment('x', 'a.bin', 'application/octet-stream')]);
    }

    public function testLargeAttachmentRoutesThroughDraftFlow(): void
    {
        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        // sendMail must NOT be used when a large attachment is present.
        $userItemBuilder->expects($this->never())->method('sendMail');

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);
        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
        $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $transport = $this->getMockBuilder(\Swift_Transport_Api_MicrosoftGraphTransport::class)
            ->setConstructorArgs([$graphClient, 'user-id', $dispatcher])
            ->onlyMethods(['sendViaDraft'])
            ->getMock();
        $transport->expects($this->once())->method('sendViaDraft');
        // Drop the threshold so a tiny body trips the draft flow; the threshold itself
        // is the subject under test, not the cost of allocating a multi-megabyte string.
        $transport->setLargeAttachmentThreshold(10);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Big');
        $m->setBody('Hello');
        $m->attach(new \Swift_Attachment('this body is over ten bytes', 'huge.bin', 'application/octet-stream'));

        $transport->send($m);
    }

    public function testSmallAttachmentStillUsesSendMail(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);
        $userItemBuilder->expects($this->never())->method('messages');

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);
        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
        $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Small');
        $m->setBody('Hello');
        $m->attach(new \Swift_Attachment('tiny', 'tiny.txt', 'text/plain'));

        $transport->send($m);
    }

    // -- send: multipart/alternative body must not be treated as an attachment --

    // Regression: a real HTML email carries a text/plain alternative body, which Swift
    // stores as a Swift_MimePart child of the message. getChildren() returns that part
    // alongside any real attachments, and send() used to feed every child to a closure
    // typed Swift_Attachment, so the alternative body part threw
    //   TypeError: Argument #1 ($a) must be of type Swift_Attachment, Swift_MimePart given
    // This fired even when the message had NO real attachments (MailQueue.Attachment='[]'),
    // because the alternative body part is always present on a multipart/alternative mail.
    public function testSendMultipartAlternativeWithoutAttachmentsDoesNotRejectBodyMimePart(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->expects($this->once())->method('post')->willReturn($promise);

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
        $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Usage Snapshot - Report');
        $m->setBody('<p>Report</p>', 'text/html');
        $m->addPart('Report', 'text/plain'); // text/plain alternative => Swift_MimePart child

        $result = $transport->send($m);
        $this->assertSame(1, $result);
    }

    // Regression companion: with a real attachment present alongside the text/plain
    // alternative, only the real attachment may be shipped to Graph -- the body
    // MimePart must be filtered out, not attached and not crash the send.
    public function testSendMultipartAlternativeWithAttachmentShipsOnlyTheAttachment(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $captured        = null;
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturnCallback(function ($body) use ($promise, &$captured) {
            $captured = $body;

            return $promise;
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
        $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Usage Snapshot - Report');
        $m->setBody('<p>Report</p>', 'text/html');
        $m->addPart('Report', 'text/plain');
        $m->attach(new \Swift_Attachment('<html></html>', 'Usage Snapshot - Report.html', 'text/html'));

        $result = $transport->send($m);
        $this->assertSame(1, $result);

        // The body MimePart is filtered out; only the real file reaches Graph.
        $attachments = $captured->getMessage()->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('Usage Snapshot - Report.html', $attachments[0]->getName());
    }

    // Regression: a text/calendar invite is a Swift_MimePart (like the application's
    // Swift_Calendar) that reports LEVEL_MIXED so a mail client treats it as an
    // attachment, not an alternative body. It must ride along as an attachment. A
    // filter keyed on `instanceof Swift_Mime_Attachment` silently drops it (a MimePart
    // is not a Swift_Mime_Attachment), losing the invite; keying on the nesting level
    // keeps it. The converter then names it invite.ics and sends it inline, because a
    // calendar MimePart has no filename or Content-Disposition.
    public function testSendKeepsCalendarMimePartAsAttachmentInsteadOfDroppingIt(): void
    {
        $promise = $this->createMock(\Http\Promise\Promise::class);
        $promise->method('wait')->willReturn(null);

        $captured        = null;
        $sendMailBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\SendMail\SendMailRequestBuilder::class);
        $sendMailBuilder->method('post')->willReturnCallback(function ($body) use ($promise, &$captured) {
            $captured = $body;

            return $promise;
        });

        $userItemBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilder::class);
        $userItemBuilder->method('sendMail')->willReturn($sendMailBuilder);

        $usersBuilder = $this->createMock(\Microsoft\Graph\Generated\Users\UsersRequestBuilder::class);
        $usersBuilder->method('byUserId')->willReturn($userItemBuilder);

        $graphClient = $this->createMock(GraphServiceClient::class);
        $graphClient->method('users')->willReturn($usersBuilder);

        $dispatcher = $this->createMock(\Swift_Events_EventDispatcher::class);
        $dispatcher->method('createSendEvent')->willReturn($this->createMock(\Swift_Events_SendEvent::class));
        $dispatcher->method('createTransportChangeEvent')->willReturn($this->createMock(\Swift_Events_TransportChangeEvent::class));

        $transport = new \Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user-id', $dispatcher);

        $m = new \Swift_Message();
        $m->setFrom(['from@example.com' => 'Sender']);
        $m->setTo(['to@example.com' => 'Recipient']);
        $m->setSubject('Appointment');
        $m->setBody('<p>Your appointment</p>', 'text/html');
        $m->addPart('Your appointment', 'text/plain'); // alternative body -> skipped
        $m->attach(new CalendarLikeMimePart("BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nEND:VCALENDAR\r\n"));

        $result = $transport->send($m);
        $this->assertSame(1, $result);

        // The invite rides along as an attachment; the alternative body part does not.
        $attachments = $captured->getMessage()->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('invite.ics', $attachments[0]->getName());
        $this->assertTrue($attachments[0]->getIsInline());
        $this->assertStringStartsWith('text/calendar', (string) $attachments[0]->getContentType());
    }
}

/**
 * Mirrors the application's Swift_Calendar: a text/calendar MimePart that reports
 * LEVEL_MIXED so mail clients treat it as an attachment rather than an alternative
 * body. Used to prove the transport keeps calendar parts instead of dropping them.
 */
final class CalendarLikeMimePart extends \Swift_MimePart
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
