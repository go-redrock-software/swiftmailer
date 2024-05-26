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
}
