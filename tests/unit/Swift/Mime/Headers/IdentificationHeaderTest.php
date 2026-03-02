<?php

use Egulias\EmailValidator\EmailValidator;

class Swift_Mime_Headers_IdentificationHeaderTest extends PHPUnit\Framework\TestCase
{
    public function testTypeIsIdHeader()
    {
        $header = $this->getHeader('Message-ID');
        $this->assertEquals(Swift_Mime_Header::TYPE_ID, $header->getFieldType());
    }

    public function testValueMatchesMsgIdSpec()
    {
        /* -- RFC 2822, 3.6.4.
     message-id      =       "Message-ID:" msg-id CRLF

     in-reply-to     =       "In-Reply-To:" 1*msg-id CRLF

     references      =       "References:" 1*msg-id CRLF

     msg-id          =       [CFWS] "<" id-left "@" id-right ">" [CFWS]

     id-left         =       dot-atom-text / no-fold-quote / obs-id-left

     id-right        =       dot-atom-text / no-fold-literal / obs-id-right

     no-fold-quote   =       DQUOTE *(qtext / quoted-pair) DQUOTE

     no-fold-literal =       "[" *(dtext / quoted-pair) "]"
     */

        $header = $this->getHeader('Message-ID');
        $header->setId('id-left@id-right');
        $this->assertEquals('<id-left@id-right>', $header->getFieldBody());
    }

    public function testIdCanBeRetrievedVerbatim()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('id-left@id-right');
        $this->assertEquals('id-left@id-right', $header->getId());
    }

    public function testMultipleIdsCanBeSet()
    {
        $header = $this->getHeader('References');
        $header->setIds(['a@b', 'x@y']);
        $this->assertEquals(['a@b', 'x@y'], $header->getIds());
    }

    public function testSettingMultipleIdsProducesAListValue()
    {
        /* -- RFC 2822, 3.6.4.
     The "References:" and "In-Reply-To:" field each contain one or more
     unique message identifiers, optionally separated by CFWS.

     .. SNIP ..

     in-reply-to     =       "In-Reply-To:" 1*msg-id CRLF

     references      =       "References:" 1*msg-id CRLF
     */

        $header = $this->getHeader('References');
        $header->setIds(['a@b', 'x@y']);
        $this->assertEquals('<a@b> <x@y>', $header->getFieldBody());
    }

    public function testIdLeftCanBeQuoted()
    {
        /* -- RFC 2822, 3.6.4.
     id-left         =       dot-atom-text / no-fold-quote / obs-id-left
     */

        $header = $this->getHeader('References');
        $header->setId('"ab"@c');
        $this->assertEquals('"ab"@c', $header->getId());
        $this->assertEquals('<"ab"@c>', $header->getFieldBody());
    }

    public function testIdLeftCanContainAnglesAsQuotedPairs()
    {
        /* -- RFC 2822, 3.6.4.
     no-fold-quote   =       DQUOTE *(qtext / quoted-pair) DQUOTE
     */

        $header = $this->getHeader('References');
        $header->setId('"a\\<\\>b"@c');
        $this->assertEquals('"a\\<\\>b"@c', $header->getId());
        $this->assertEquals('<"a\\<\\>b"@c>', $header->getFieldBody());
    }

    public function testIdLeftCanBeDotAtom()
    {
        $header = $this->getHeader('References');
        $header->setId('a.b+&%$.c@d');
        $this->assertEquals('a.b+&%$.c@d', $header->getId());
        $this->assertEquals('<a.b+&%$.c@d>', $header->getFieldBody());
    }

    public function testInvalidIdLeftThrowsException()
    {
        $this->expectException(Swift_RfcComplianceException::class);
        $this->expectExceptionMessage('Invalid ID given <a b c@d>');

        $header = $this->getHeader('References');
        $header->setId('a b c@d');
    }

    public function testIdRightCanBeDotAtom()
    {
        /* -- RFC 2822, 3.6.4.
     id-right        =       dot-atom-text / no-fold-literal / obs-id-right
     */

        $header = $this->getHeader('References');
        $header->setId('a@b.c+&%$.d');
        $this->assertEquals('a@b.c+&%$.d', $header->getId());
        $this->assertEquals('<a@b.c+&%$.d>', $header->getFieldBody());
    }

    public function testIdRightCanBeLiteral()
    {
        /* -- RFC 2822, 3.6.4.
     no-fold-literal =       "[" *(dtext / quoted-pair) "]"
     */

        $header = $this->getHeader('References');
        $header->setId('a@[1.2.3.4]');
        $this->assertEquals('a@[1.2.3.4]', $header->getId());
        $this->assertEquals('<a@[1.2.3.4]>', $header->getFieldBody());
    }

    public function testIdRigthIsIdnEncoded()
    {
        $header = $this->getHeader('References');
        $header->setId('a@ä');
        $this->assertEquals('a@ä', $header->getId());
        $this->assertEquals('<a@xn--4ca>', $header->getFieldBody());
    }

    public function testInvalidIdRightThrowsException()
    {
        $this->expectException(Swift_RfcComplianceException::class);
        $this->expectExceptionMessage('Invalid ID given <a@b c d>');

        $header = $this->getHeader('References');
        $header->setId('a@b c d');
    }

    public function testMissingAtSignThrowsException()
    {
        $this->expectException(Swift_RfcComplianceException::class);
        $this->expectExceptionMessage('Invalid ID given <abc>');

        /* -- RFC 2822, 3.6.4.
     msg-id          =       [CFWS] "<" id-left "@" id-right ">" [CFWS]
     */
        $header = $this->getHeader('References');
        $header->setId('abc');
    }

    public function testSetBodyModel()
    {
        $header = $this->getHeader('Message-ID');
        $header->setFieldBodyModel('a@b');
        $this->assertEquals(['a@b'], $header->getIds());
    }

    public function testGetBodyModel()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('a@b');
        $this->assertEquals(['a@b'], $header->getFieldBodyModel());
    }

    public function testStringValue()
    {
        $header = $this->getHeader('References');
        $header->setIds(['a@b', 'x@y']);
        $this->assertEquals('References: <a@b> <x@y>'."\r\n", $header->toString());
    }

    public function testSetIdWithAngleBrackets()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('simple@test.com');
        $this->assertEquals('<simple@test.com>', $header->getFieldBody());
    }

    public function testSetIdsWithEmptyArray()
    {
        $header = $this->getHeader('References');
        $header->setIds([]);
        $this->assertEquals([], $header->getIds());
        $this->assertEquals('', $header->getFieldBody());
    }

    public function testSetBodyModelWithArray()
    {
        $header = $this->getHeader('References');
        $header->setFieldBodyModel(['a@b', 'x@y']);
        $this->assertEquals(['a@b', 'x@y'], $header->getFieldBodyModel());
    }

    public function testSetBodyModelWithString()
    {
        $header = $this->getHeader('Message-ID');
        $header->setFieldBodyModel('single@id');
        $this->assertEquals(['single@id'], $header->getFieldBodyModel());
    }

    public function testThreeIdsProduceCorrectFieldBody()
    {
        $header = $this->getHeader('References');
        $header->setIds(['a@b', 'c@d', 'e@f']);
        $this->assertEquals('<a@b> <c@d> <e@f>', $header->getFieldBody());
    }

    public function testIdWithDotAtomLeftAndRight()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('foo.bar@baz.qux');
        $this->assertEquals('foo.bar@baz.qux', $header->getId());
        $this->assertEquals('<foo.bar@baz.qux>', $header->getFieldBody());
    }

    public function testIdWithQuotedLocalPart()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('"foo bar"@test.com');
        $this->assertEquals('"foo bar"@test.com', $header->getId());
    }

    public function testIdRightAsLiteralWithIPv6()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('abc@[::1]');
        $this->assertEquals('abc@[::1]', $header->getId());
        $this->assertEquals('<abc@[::1]>', $header->getFieldBody());
    }

    public function testEmptyIdThrowsException()
    {
        $this->expectException(Swift_RfcComplianceException::class);
        $header = $this->getHeader('Message-ID');
        $header->setId('');
    }

    public function testToStringWithSingleId()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('unique@host');
        $this->assertEquals("Message-ID: <unique@host>\r\n", $header->toString());
    }

    public function testMultipleSetIdOverwritesPrevious()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('first@host');
        $header->setId('second@host');
        $this->assertEquals('second@host', $header->getId());
    }

    public function testSetIdsOverwritesPreviousIds()
    {
        $header = $this->getHeader('References');
        $header->setIds(['a@b', 'c@d']);
        $header->setIds(['x@y']);
        $this->assertEquals(['x@y'], $header->getIds());
    }

    public function testFieldTypeIsAlwaysId()
    {
        $header = $this->getHeader('References');
        $this->assertEquals(Swift_Mime_Header::TYPE_ID, $header->getFieldType());
    }

    public function testFieldTypeIsIdForContentId()
    {
        $header = $this->getHeader('Content-ID');
        $this->assertEquals(Swift_Mime_Header::TYPE_ID, $header->getFieldType());
    }

    public function testFieldTypeIsIdForInReplyTo()
    {
        $header = $this->getHeader('In-Reply-To');
        $this->assertEquals(Swift_Mime_Header::TYPE_ID, $header->getFieldType());
    }

    public function testIdWithComplexDotAtom()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('a.b.c.d.e@f.g.h.i');
        $this->assertEquals('a.b.c.d.e@f.g.h.i', $header->getId());
    }

    public function testIdWithSpecialCharsInDotAtom()
    {
        $header = $this->getHeader('Message-ID');
        $header->setId('user+tag@example.com');
        $this->assertEquals('user+tag@example.com', $header->getId());
    }

    public function testManyIdsProduceCorrectToString()
    {
        $header = $this->getHeader('References');
        $header->setIds(['a@b', 'c@d', 'e@f', 'g@h']);
        $this->assertEquals('References: <a@b> <c@d> <e@f> <g@h>'."\r\n", $header->toString());
    }

    public function testSingleIdToString()
    {
        $header = $this->getHeader('In-Reply-To');
        $header->setId('reply@host');
        $this->assertEquals("In-Reply-To: <reply@host>\r\n", $header->toString());
    }

    private function getHeader($name)
    {
        return new Swift_Mime_Headers_IdentificationHeader($name, new EmailValidator(), new Swift_AddressEncoder_IdnAddressEncoder());
    }
}
