<?php

use Egulias\EmailValidator\EmailValidator;

class Swift_Mime_Headers_MailboxHeaderTest extends SwiftMailerTestCase
{
    /* -- RFC 2822, 3.6.2 for all tests.
     */

    private $charset = 'utf-8';

    public function testTypeIsMailboxHeader()
    {
        $header = $this->getHeader('To');
        $this->assertEquals(Swift_Mime_Header::TYPE_MAILBOX, $header->getFieldType());
    }

    public function testMailboxIsSetForAddress()
    {
        $header = $this->getHeader('From');
        $header->setAddresses('chris@swiftmailer.org');
        $this->assertEquals(
            ['chris@swiftmailer.org'],
            $header->getNameAddressStrings(),
        );
    }

    public function testMailboxIsRenderedForNameAddress()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['chris@swiftmailer.org' => 'Chris Corbyn']);
        $this->assertEquals(
            ['Chris Corbyn <chris@swiftmailer.org>'],
            $header->getNameAddressStrings(),
        );
    }

    public function testAddressCanBeReturnedForAddress()
    {
        $header = $this->getHeader('From');
        $header->setAddresses('chris@swiftmailer.org');
        $this->assertEquals(['chris@swiftmailer.org'], $header->getAddresses());
    }

    public function testAddressCanBeReturnedForNameAddress()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['chris@swiftmailer.org' => 'Chris Corbyn']);
        $this->assertEquals(['chris@swiftmailer.org'], $header->getAddresses());
    }

    public function testQuotesInNameAreQuoted()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn, "DHE"',
        ]);
        $this->assertEquals(
            ['"Chris Corbyn, \"DHE\"" <chris@swiftmailer.org>'],
            $header->getNameAddressStrings(),
        );
    }

    public function testEscapeCharsInNameAreQuoted()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn, \\escaped\\',
        ]);
        $this->assertEquals(
            ['"Chris Corbyn, \\\\escaped\\\\" <chris@swiftmailer.org>'],
            $header->getNameAddressStrings(),
        );
    }

    public function testUtf8CharsInDomainAreIdnEncoded()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swïftmailer.org' => 'Chris Corbyn',
        ]);
        $this->assertEquals(
            ['Chris Corbyn <chris@xn--swftmailer-78a.org>'],
            $header->getNameAddressStrings(),
        );
    }

    public function testUtf8CharsInLocalPartThrows()
    {
        $this->expectException(Swift_AddressEncoderException::class);

        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chrïs@swiftmailer.org' => 'Chris Corbyn',
        ]);
        $header->getNameAddressStrings();
    }

    public function testUtf8CharsInEmail()
    {
        $header = $this->getHeader('From', null, new Swift_AddressEncoder_Utf8AddressEncoder());
        $header->setNameAddresses([
            'chrïs@swïftmailer.org' => 'Chris Corbyn',
        ]);
        $this->assertEquals(
            ['Chris Corbyn <chrïs@swïftmailer.org>'],
            $header->getNameAddressStrings(),
        );
    }

    public function testGetMailboxesReturnsNameValuePairs()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn, DHE',
        ]);
        $this->assertEquals(
            ['chris@swiftmailer.org' => 'Chris Corbyn, DHE'],
            $header->getNameAddresses(),
        );
    }

    public function testMultipleAddressesCanBeSetAndFetched()
    {
        $header = $this->getHeader('From');
        $header->setAddresses([
            'chris@swiftmailer.org', 'mark@swiftmailer.org',
        ]);
        $this->assertEquals(
            ['chris@swiftmailer.org', 'mark@swiftmailer.org'],
            $header->getAddresses(),
        );
    }

    public function testMultipleAddressesAsMailboxes()
    {
        $header = $this->getHeader('From');
        $header->setAddresses([
            'chris@swiftmailer.org', 'mark@swiftmailer.org',
        ]);
        $this->assertEquals(
            ['chris@swiftmailer.org' => null, 'mark@swiftmailer.org' => null],
            $header->getNameAddresses(),
        );
    }

    public function testMultipleAddressesAsMailboxStrings()
    {
        $header = $this->getHeader('From');
        $header->setAddresses([
            'chris@swiftmailer.org', 'mark@swiftmailer.org',
        ]);
        $this->assertEquals(
            ['chris@swiftmailer.org', 'mark@swiftmailer.org'],
            $header->getNameAddressStrings(),
        );
    }

    public function testMultipleNamedMailboxesReturnsMultipleAddresses()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $this->assertEquals(
            ['chris@swiftmailer.org', 'mark@swiftmailer.org'],
            $header->getAddresses(),
        );
    }

    public function testMultipleNamedMailboxesReturnsMultipleMailboxes()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $this->assertEquals(
            [
                'chris@swiftmailer.org' => 'Chris Corbyn',
                'mark@swiftmailer.org'  => 'Mark Corbyn',
            ],
            $header->getNameAddresses(),
        );
    }

    public function testMultipleMailboxesProducesMultipleMailboxStrings()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $this->assertEquals(
            [
                'Chris Corbyn <chris@swiftmailer.org>',
                'Mark Corbyn <mark@swiftmailer.org>',
            ],
            $header->getNameAddressStrings(),
        );
    }

    public function testSetAddressesOverwritesAnyMailboxes()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $this->assertEquals(
            ['chris@swiftmailer.org'   => 'Chris Corbyn',
                'mark@swiftmailer.org' => 'Mark Corbyn', ],
            $header->getNameAddresses(),
        );
        $this->assertEquals(
            ['chris@swiftmailer.org', 'mark@swiftmailer.org'],
            $header->getAddresses(),
        );

        $header->setAddresses(['chris@swiftmailer.org', 'mark@swiftmailer.org']);

        $this->assertEquals(
            ['chris@swiftmailer.org' => null, 'mark@swiftmailer.org' => null],
            $header->getNameAddresses(),
        );
        $this->assertEquals(
            ['chris@swiftmailer.org', 'mark@swiftmailer.org'],
            $header->getAddresses(),
        );
    }

    public function testNameIsEncodedIfNonAscii()
    {
        $name = 'C'.\pack('C', 0x8F).'rbyn';

        $encoder = $this->getEncoder('Q');
        $encoder->shouldReceive('encodeString')
            ->once()
            ->with($name, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn('C=8Frbyn');

        $header = $this->getHeader('From', $encoder);
        $header->setNameAddresses(['chris@swiftmailer.org' => 'Chris '.$name]);

        $addresses = $header->getNameAddressStrings();
        $this->assertEquals(
            'Chris =?'.$this->charset.'?Q?C=8Frbyn?= <chris@swiftmailer.org>',
            \array_shift($addresses),
        );
    }

    public function testEncodingLineLengthCalculations()
    {
        /* -- RFC 2047, 2.
        An 'encoded-word' may not be more than 75 characters long, including
        'charset', 'encoding', 'encoded-text', and delimiters.
        */

        $name = 'C'.\pack('C', 0x8F).'rbyn';

        $encoder = $this->getEncoder('Q');
        $encoder->shouldReceive('encodeString')
            ->once()
            ->with($name, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn('C=8Frbyn');

        $header = $this->getHeader('From', $encoder);
        $header->setNameAddresses(['chris@swiftmailer.org' => 'Chris '.$name]);

        $header->getNameAddressStrings();
    }

    public function testGetValueReturnsMailboxStringValue()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
        ]);
        $this->assertEquals(
            'Chris Corbyn <chris@swiftmailer.org>',
            $header->getFieldBody(),
        );
    }

    public function testGetValueReturnsMailboxStringValueForMultipleMailboxes()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $this->assertEquals(
            'Chris Corbyn <chris@swiftmailer.org>, Mark Corbyn <mark@swiftmailer.org>',
            $header->getFieldBody(),
        );
    }

    public function testRemoveAddressesWithSingleValue()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $header->removeAddresses('chris@swiftmailer.org');
        $this->assertEquals(
            ['mark@swiftmailer.org'],
            $header->getAddresses(),
        );
    }

    public function testRemoveAddressesWithList()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $header->removeAddresses(
            ['chris@swiftmailer.org', 'mark@swiftmailer.org'],
        );
        $this->assertEquals([], $header->getAddresses());
    }

    public function testSetBodyModel()
    {
        $header = $this->getHeader('From');
        $header->setFieldBodyModel('chris@swiftmailer.org');
        $this->assertEquals(['chris@swiftmailer.org' => null], $header->getNameAddresses());
    }

    public function testGetBodyModel()
    {
        $header = $this->getHeader('From');
        $header->setAddresses(['chris@swiftmailer.org']);
        $this->assertEquals(['chris@swiftmailer.org' => null], $header->getFieldBodyModel());
    }

    public function testToString()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses([
            'chris@swiftmailer.org' => 'Chris Corbyn',
            'mark@swiftmailer.org'  => 'Mark Corbyn',
        ]);
        $this->assertEquals(
            'From: Chris Corbyn <chris@swiftmailer.org>, '.
            'Mark Corbyn <mark@swiftmailer.org>'."\r\n",
            $header->toString(),
        );
    }

    public function testSetAddressesWithEmptyArray()
    {
        $header = $this->getHeader('To');
        $header->setAddresses([]);
        $this->assertEquals([], $header->getAddresses());
    }

    public function testSetNameAddressesWithEmptyArray()
    {
        $header = $this->getHeader('To');
        $header->setNameAddresses([]);
        $this->assertEquals([], $header->getNameAddresses());
    }

    public function testGetFieldBodyWithSingleAddress()
    {
        $header = $this->getHeader('From');
        $header->setAddresses('test@example.com');
        $this->assertEquals('test@example.com', $header->getFieldBody());
    }

    public function testSetBodyModelWithArray()
    {
        $header = $this->getHeader('To');
        $header->setFieldBodyModel(['a@b.com' => 'Alpha', 'c@d.com' => null]);
        $addresses = $header->getNameAddresses();
        $this->assertEquals('Alpha', $addresses['a@b.com']);
        $this->assertNull($addresses['c@d.com']);
    }

    public function testGetBodyModelReturnsNameAddresses()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['chris@test.com' => 'Chris']);
        $model = $header->getFieldBodyModel();
        $this->assertEquals(['chris@test.com' => 'Chris'], $model);
    }

    public function testRemoveNonExistentAddressDoesNothing()
    {
        $header = $this->getHeader('From');
        $header->setAddresses(['a@b.com']);
        $header->removeAddresses('nonexistent@nowhere.com');
        $this->assertEquals(['a@b.com'], $header->getAddresses());
    }

    public function testToStringWithSingleAddress()
    {
        $header = $this->getHeader('From');
        $header->setAddresses('user@example.com');
        $this->assertEquals("From: user@example.com\r\n", $header->toString());
    }

    public function testFieldTypeIsMailbox()
    {
        $header = $this->getHeader('From');
        $this->assertEquals(Swift_Mime_Header::TYPE_MAILBOX, $header->getFieldType());
    }

    public function testFieldTypeForTo()
    {
        $header = $this->getHeader('To');
        $this->assertEquals(Swift_Mime_Header::TYPE_MAILBOX, $header->getFieldType());
    }

    public function testFieldTypeForCc()
    {
        $header = $this->getHeader('Cc');
        $this->assertEquals(Swift_Mime_Header::TYPE_MAILBOX, $header->getFieldType());
    }

    public function testFieldTypeForBcc()
    {
        $header = $this->getHeader('Bcc');
        $this->assertEquals(Swift_Mime_Header::TYPE_MAILBOX, $header->getFieldType());
    }

    public function testGetNameReturnsNameVerbatim()
    {
        $header = $this->getHeader('Reply-To');
        $this->assertEquals('Reply-To', $header->getFieldName());
    }

    public function testNameAddressWithoutName()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['test@example.com' => null]);
        $this->assertEquals(['test@example.com'], $header->getNameAddressStrings());
    }

    public function testMultipleRemoveAddressesClearsAll()
    {
        $header = $this->getHeader('To');
        $header->setNameAddresses([
            'a@b.com' => 'A',
            'c@d.com' => 'C',
            'e@f.com' => 'E',
        ]);
        $header->removeAddresses(['a@b.com', 'e@f.com']);
        $this->assertEquals(['c@d.com'], $header->getAddresses());
    }

    public function testSpecialCharsInNameAreHandled()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['test@example.com' => 'Name (with parens)']);
        $strings = $header->getNameAddressStrings();
        $this->assertCount(1, $strings);
        $this->assertStringContainsString('test@example.com', $strings[0]);
    }

    public function testSetAddressesWithString()
    {
        $header = $this->getHeader('From');
        $header->setAddresses('single@test.com');
        $this->assertEquals(['single@test.com'], $header->getAddresses());
    }

    public function testSetNameAddressesOverwritesPrevious()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['first@test.com' => 'First']);
        $header->setNameAddresses(['second@test.com' => 'Second']);
        $this->assertEquals(['second@test.com'], $header->getAddresses());
    }

    public function testGetFieldBodyWithMultipleAddresses()
    {
        $header = $this->getHeader('To');
        $header->setAddresses(['a@b.com', 'c@d.com']);
        $body = $header->getFieldBody();
        $this->assertStringContainsString('a@b.com', $body);
        $this->assertStringContainsString('c@d.com', $body);
    }

    public function testToStringWithNamedAddress()
    {
        $header = $this->getHeader('From');
        $header->setNameAddresses(['user@example.com' => 'User Name']);
        $this->assertEquals("From: User Name <user@example.com>\r\n", $header->toString());
    }

    public function testSetBodyModelWithString()
    {
        $header = $this->getHeader('Sender');
        $header->setFieldBodyModel('sender@test.com');
        $this->assertEquals(['sender@test.com' => null], $header->getNameAddresses());
    }

    public function testAddressWithSubdomains()
    {
        $header = $this->getHeader('From');
        $header->setAddresses('user@sub.domain.example.com');
        $this->assertEquals(['user@sub.domain.example.com'], $header->getAddresses());
    }

    public function testThreeAddressesInFieldBody()
    {
        $header = $this->getHeader('To');
        $header->setNameAddresses([
            'a@b.com' => 'Alpha',
            'c@d.com' => 'Charlie',
            'e@f.com' => 'Echo',
        ]);
        $body = $header->getFieldBody();
        $this->assertStringContainsString('Alpha <a@b.com>', $body);
        $this->assertStringContainsString('Charlie <c@d.com>', $body);
        $this->assertStringContainsString('Echo <e@f.com>', $body);
    }

    public function testGetNameAddressesReturnsEmptyArrayByDefault()
    {
        $header = $this->getHeader('To');
        $this->assertEquals([], $header->getNameAddresses());
    }

    public function testGetAddressesReturnsEmptyArrayByDefault()
    {
        $header = $this->getHeader('To');
        $this->assertEquals([], $header->getAddresses());
    }

    public function testSetNameAddressesWithMixed()
    {
        $header = $this->getHeader('To');
        $header->setNameAddresses(['a@b.com' => 'Alpha', 'c@d.com']);
        $nameAddresses = $header->getNameAddresses();
        $this->assertEquals('Alpha', $nameAddresses['a@b.com']);
        $this->assertContains('c@d.com', $header->getAddresses());
    }

    public function testRemoveAddressThatDoesNotExist()
    {
        $header = $this->getHeader('To');
        $header->setAddresses(['a@b.com']);
        $header->removeAddresses('nonexistent@nowhere.com');
        $this->assertEquals(['a@b.com'], $header->getAddresses());
    }

    public function testGetFieldBodyReturnsEmptyStringForNoAddresses()
    {
        $header = $this->getHeader('To');
        $this->assertEquals('', $header->getFieldBody());
    }

    private function getHeader($name, $encoder = null, $addressEncoder = null)
    {
        $encoder        = $encoder        ?? $this->getEncoder('Q', true);
        $addressEncoder = $addressEncoder ?? new Swift_AddressEncoder_IdnAddressEncoder();
        $header         = new Swift_Mime_Headers_MailboxHeader($name, $encoder, new EmailValidator(), $addressEncoder);
        $header->setCharset($this->charset);

        return $header;
    }

    private function getEncoder($type)
    {
        $encoder = $this->getMockery('Swift_Mime_HeaderEncoder')->shouldIgnoreMissing();
        $encoder->shouldReceive('getName')
            ->zeroOrMoreTimes()
            ->andReturn($type);

        return $encoder;
    }
}
