<?php

class Swift_Mime_SimpleHeaderSetTest extends PHPUnit\Framework\TestCase
{
    public function testAddMailboxHeaderDelegatesToFactory()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createMailboxHeader')
            ->with('From', ['person@domain' => 'Person'])
            ->willReturn($this->createHeader('From'));

        $set = $this->createSet($factory);
        $set->addMailboxHeader('From', ['person@domain' => 'Person']);
    }

    public function testAddDateHeaderDelegatesToFactory()
    {
        $dateTime = new DateTimeImmutable();

        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createDateHeader')
            ->with('Date', $dateTime)
            ->willReturn($this->createHeader('Date'));

        $set = $this->createSet($factory);
        $set->addDateHeader('Date', $dateTime);
    }

    public function testAddTextHeaderDelegatesToFactory()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createTextHeader')
            ->with('Subject', 'some text')
            ->willReturn($this->createHeader('Subject'));

        $set = $this->createSet($factory);
        $set->addTextHeader('Subject', 'some text');
    }

    public function testAddParameterizedHeaderDelegatesToFactory()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createParameterizedHeader')
            ->with('Content-Type', 'text/plain', ['charset' => 'utf-8'])
            ->willReturn($this->createHeader('Content-Type'));

        $set = $this->createSet($factory);
        $set->addParameterizedHeader(
            'Content-Type',
            'text/plain',
            ['charset' => 'utf-8'],
        );
    }

    public function testAddIdHeaderDelegatesToFactory()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($this->createHeader('Message-ID'));

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
    }

    public function testAddPathHeaderDelegatesToFactory()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createPathHeader')
            ->with('Return-Path', 'some@path')
            ->willReturn($this->createHeader('Return-Path'));

        $set = $this->createSet($factory);
        $set->addPathHeader('Return-Path', 'some@path');
    }

    public function testHasReturnsFalseWhenNoHeaders()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertFalse($set->has('Some-Header'));
    }

    public function testAddedMailboxHeaderIsSeenByHas()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createMailboxHeader')
            ->with('From', ['person@domain' => 'Person'])
            ->willReturn($this->createHeader('From'));

        $set = $this->createSet($factory);
        $set->addMailboxHeader('From', ['person@domain' => 'Person']);
        $this->assertTrue($set->has('From'));
    }

    public function testAddedDateHeaderIsSeenByHas()
    {
        $dateTime = new DateTimeImmutable();

        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createDateHeader')
            ->with('Date', $dateTime)
            ->willReturn($this->createHeader('Date'));

        $set = $this->createSet($factory);
        $set->addDateHeader('Date', $dateTime);
        $this->assertTrue($set->has('Date'));
    }

    public function testAddedTextHeaderIsSeenByHas()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createTextHeader')
            ->with('Subject', 'some text')
            ->willReturn($this->createHeader('Subject'));

        $set = $this->createSet($factory);
        $set->addTextHeader('Subject', 'some text');
        $this->assertTrue($set->has('Subject'));
    }

    public function testAddedParameterizedHeaderIsSeenByHas()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createParameterizedHeader')
            ->with('Content-Type', 'text/plain', ['charset' => 'utf-8'])
            ->willReturn($this->createHeader('Content-Type'));

        $set = $this->createSet($factory);
        $set->addParameterizedHeader(
            'Content-Type',
            'text/plain',
            ['charset' => 'utf-8'],
        );
        $this->assertTrue($set->has('Content-Type'));
    }

    public function testAddedIdHeaderIsSeenByHas()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($this->createHeader('Message-ID'));

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertTrue($set->has('Message-ID'));
    }

    public function testAddedPathHeaderIsSeenByHas()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createPathHeader')
            ->with('Return-Path', 'some@path')
            ->willReturn($this->createHeader('Return-Path'));

        $set = $this->createSet($factory);
        $set->addPathHeader('Return-Path', 'some@path');
        $this->assertTrue($set->has('Return-Path'));
    }

    public function testNewlySetHeaderIsSeenByHas()
    {
        $factory = $this->createFactory();
        $header  = $this->createHeader('X-Foo', 'bar');
        $set     = $this->createSet($factory);
        $set->set($header);
        $this->assertTrue($set->has('X-Foo'));
    }

    public function testHasCanAcceptOffset()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($this->createHeader('Message-ID'));

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertTrue($set->has('Message-ID', 0));
    }

    public function testHasWithIllegalOffsetReturnsFalse()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($this->createHeader('Message-ID'));

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertFalse($set->has('Message-ID', 1));
    }

    public function testHasCanDistinguishMultipleHeaders()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Message-ID', 'other@id'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Message-ID'),
                $this->createHeader('Message-ID'),
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Message-ID', 'other@id');
        $this->assertTrue($set->has('Message-ID', 1));
    }

    public function testGetWithUnspecifiedOffset()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertSame($header, $set->get('Message-ID'));
    }

    public function testGetWithSpeiciedOffset()
    {
        $header0 = $this->createHeader('Message-ID');
        $header1 = $this->createHeader('Message-ID');
        $header2 = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(3))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Message-ID', 'other@id'],
                ['Message-ID', 'more@id'],
            )
            ->willReturnOnConsecutiveCalls(
                $header0,
                $header1,
                $header2,
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Message-ID', 'other@id');
        $set->addIdHeader('Message-ID', 'more@id');
        $this->assertSame($header1, $set->get('Message-ID', 1));
    }

    public function testGetReturnsNullIfHeaderNotSet()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertNull($set->get('Message-ID', 99));
    }

    public function testGetAllReturnsAllHeadersMatchingName()
    {
        $header0 = $this->createHeader('Message-ID');
        $header1 = $this->createHeader('Message-ID');
        $header2 = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(3))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Message-ID', 'other@id'],
                ['Message-ID', 'more@id'],
            )
            ->willReturnOnConsecutiveCalls(
                $header0,
                $header1,
                $header2,
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Message-ID', 'other@id');
        $set->addIdHeader('Message-ID', 'more@id');

        $this->assertEquals(
            [$header0, $header1, $header2],
            $set->getAll('Message-ID'),
        );
    }

    public function testGetAllReturnsAllHeadersIfNoArguments()
    {
        $header0 = $this->createHeader('Message-ID');
        $header1 = $this->createHeader('Subject');
        $header2 = $this->createHeader('To');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(3))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Subject', 'thing'],
                ['To', 'person@example.org'],
            )
            ->willReturnOnConsecutiveCalls(
                $header0,
                $header1,
                $header2,
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Subject', 'thing');
        $set->addIdHeader('To', 'person@example.org');

        $this->assertEquals(
            [$header0, $header1, $header2],
            $set->getAll(),
        );
    }

    public function testGetAllReturnsEmptyArrayIfNoneSet()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertEquals([], $set->getAll('Received'));
    }

    public function testRemoveWithUnspecifiedOffset()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->remove('Message-ID');
        $this->assertFalse($set->has('Message-ID'));
    }

    public function testRemoveWithSpecifiedIndexRemovesHeader()
    {
        $header0 = $this->createHeader('Message-ID');
        $header1 = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Message-ID', 'other@id'],
            )
            ->willReturnOnConsecutiveCalls(
                $header0,
                $header1,
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Message-ID', 'other@id');
        $set->remove('Message-ID', 0);
        $this->assertFalse($set->has('Message-ID', 0));
        $this->assertTrue($set->has('Message-ID', 1));
        $this->assertTrue($set->has('Message-ID'));
        $set->remove('Message-ID', 1);
        $this->assertFalse($set->has('Message-ID', 1));
        $this->assertFalse($set->has('Message-ID'));
    }

    public function testRemoveWithSpecifiedIndexLeavesOtherHeaders()
    {
        $header0 = $this->createHeader('Message-ID');
        $header1 = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Message-ID', 'other@id'],
            )
            ->willReturnOnConsecutiveCalls(
                $header0,
                $header1,
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Message-ID', 'other@id');
        $set->remove('Message-ID', 1);
        $this->assertTrue($set->has('Message-ID', 0));
    }

    public function testRemoveWithInvalidOffsetDoesNothing()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->remove('Message-ID', 50);
        $this->assertTrue($set->has('Message-ID'));
    }

    public function testRemoveAllRemovesAllHeadersWithName()
    {
        $header0 = $this->createHeader('Message-ID');
        $header1 = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createIdHeader')
            ->withConsecutive(
                ['Message-ID', 'some@id'],
                ['Message-ID', 'other@id'],
            )
            ->willReturnOnConsecutiveCalls(
                $header0,
                $header1,
            );

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->addIdHeader('Message-ID', 'other@id');
        $set->removeAll('Message-ID');
        $this->assertFalse($set->has('Message-ID', 0));
        $this->assertFalse($set->has('Message-ID', 1));
    }

    public function testHasIsNotCaseSensitive()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertTrue($set->has('message-id'));
    }

    public function testGetIsNotCaseSensitive()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertSame($header, $set->get('message-id'));
    }

    public function testGetAllIsNotCaseSensitive()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $this->assertEquals([$header], $set->getAll('message-id'));
    }

    public function testRemoveIsNotCaseSensitive()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->remove('message-id');
        $this->assertFalse($set->has('Message-ID'));
    }

    public function testRemoveAllIsNotCaseSensitive()
    {
        $header  = $this->createHeader('Message-ID');
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Message-ID', 'some@id')
            ->willReturn($header);

        $set = $this->createSet($factory);
        $set->addIdHeader('Message-ID', 'some@id');
        $set->removeAll('message-id');
        $this->assertFalse($set->has('Message-ID'));
    }

    public function testToStringJoinsHeadersTogether()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Foo', 'bar'],
                ['Zip', 'buttons'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Foo', 'bar'),
                $this->createHeader('Zip', 'buttons'),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Foo', 'bar');
        $set->addTextHeader('Zip', 'buttons');
        $this->assertEquals(
            "Foo: bar\r\n".
            "Zip: buttons\r\n",
            $set->toString(),
        );
    }

    public function testHeadersWithoutBodiesAreNotDisplayed()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Foo', 'bar'],
                ['Zip', ''],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Foo', 'bar'),
                $this->createHeader('Zip', ''),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Foo', 'bar');
        $set->addTextHeader('Zip', '');
        $this->assertEquals(
            "Foo: bar\r\n",
            $set->toString(),
        );
    }

    public function testHeadersWithoutBodiesCanBeForcedToDisplay()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Foo', ''],
                ['Zip', ''],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Foo', ''),
                $this->createHeader('Zip', ''),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Foo', '');
        $set->addTextHeader('Zip', '');
        $set->setAlwaysDisplayed(['Foo', 'Zip']);
        $this->assertEquals(
            "Foo: \r\n".
            "Zip: \r\n",
            $set->toString(),
        );
    }

    public function testHeaderSequencesCanBeSpecified()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(3))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Third', 'three'],
                ['First', 'one'],
                ['Second', 'two'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Third', 'three'),
                $this->createHeader('First', 'one'),
                $this->createHeader('Second', 'two'),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Third', 'three');
        $set->addTextHeader('First', 'one');
        $set->addTextHeader('Second', 'two');

        $set->defineOrdering(['First', 'Second', 'Third']);

        $this->assertEquals(
            "First: one\r\n".
            "Second: two\r\n".
            "Third: three\r\n",
            $set->toString(),
        );
    }

    public function testUnsortedHeadersAppearAtEnd()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(5))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Fourth', 'four'],
                ['Fifth', 'five'],
                ['Third', 'three'],
                ['First', 'one'],
                ['Second', 'two'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Fourth', 'four'),
                $this->createHeader('Fifth', 'five'),
                $this->createHeader('Third', 'three'),
                $this->createHeader('First', 'one'),
                $this->createHeader('Second', 'two'),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Fourth', 'four');
        $set->addTextHeader('Fifth', 'five');
        $set->addTextHeader('Third', 'three');
        $set->addTextHeader('First', 'one');
        $set->addTextHeader('Second', 'two');

        $set->defineOrdering(['First', 'Second', 'Third']);

        $this->assertEquals(
            "First: one\r\n".
            "Second: two\r\n".
            "Third: three\r\n".
            "Fourth: four\r\n".
            "Fifth: five\r\n",
            $set->toString(),
        );
    }

    public function testSettingCharsetNotifiesAlreadyExistingHeaders()
    {
        $subject = $this->createHeader('Subject', 'some text');
        $xHeader = $this->createHeader('X-Header', 'some text');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Subject', 'some text'],
                ['X-Header', 'some text'],
            )
            ->willReturnOnConsecutiveCalls(
                $subject,
                $xHeader,
            );
        $subject->expects($this->once())
            ->method('setCharset')
            ->with('utf-8');
        $xHeader->expects($this->once())
            ->method('setCharset')
            ->with('utf-8');

        $set = $this->createSet($factory);
        $set->addTextHeader('Subject', 'some text');
        $set->addTextHeader('X-Header', 'some text');

        $set->setCharset('utf-8');
    }

    public function testCharsetChangeNotifiesAlreadyExistingHeaders()
    {
        $subject = $this->createHeader('Subject', 'some text');
        $xHeader = $this->createHeader('X-Header', 'some text');
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Subject', 'some text'],
                ['X-Header', 'some text'],
            )
            ->willReturnOnConsecutiveCalls(
                $subject,
                $xHeader,
            );
        $subject->expects($this->once())
            ->method('setCharset')
            ->with('utf-8');
        $xHeader->expects($this->once())
            ->method('setCharset')
            ->with('utf-8');

        $set = $this->createSet($factory);
        $set->addTextHeader('Subject', 'some text');
        $set->addTextHeader('X-Header', 'some text');

        $set->charsetChanged('utf-8');
    }

    public function testCharsetChangeNotifiesFactory()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('charsetChanged')
            ->with('utf-8');

        $set = $this->createSet($factory);

        $set->setCharset('utf-8');
    }

    public function testListAllReturnsEmptyArrayWhenNoHeaders()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertEquals([], $set->listAll());
    }

    public function testListAllReturnsHeaderNames()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Subject', 'text'],
                ['X-Custom', 'val'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Subject', 'text'),
                $this->createHeader('X-Custom', 'val'),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Subject', 'text');
        $set->addTextHeader('X-Custom', 'val');
        $names = $set->listAll();
        $this->assertContains('subject', $names);
        $this->assertContains('x-custom', $names);
    }

    public function testNewInstanceReturnsNewHeaderSet()
    {
        $factory = $this->createFactory();
        $set     = $this->createSet($factory);
        $newSet  = $set->newInstance();
        $this->assertInstanceOf(Swift_Mime_SimpleHeaderSet::class, $newSet);
        $this->assertNotSame($set, $newSet);
    }

    public function testSetHeaderAtSpecificIndex()
    {
        $factory = $this->createFactory();
        $header  = $this->createHeader('X-Foo', 'bar');
        $set     = $this->createSet($factory);
        $set->set($header, 5);
        $this->assertTrue($set->has('X-Foo', 5));
    }

    public function testSetHeaderOverwritesAtIndex()
    {
        $factory = $this->createFactory();
        $header1 = $this->createHeader('X-Foo', 'first');
        $header2 = $this->createHeader('X-Foo', 'second');
        $set     = $this->createSet($factory);
        $set->set($header1, 0);
        $set->set($header2, 0);
        $this->assertSame($header2, $set->get('X-Foo', 0));
    }

    public function testGetReturnsNullForMissingHeader()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertNull($set->get('Nonexistent'));
    }

    public function testHasCaseInsensitiveWithDifferentCases()
    {
        $factory = $this->createFactory();
        $header  = $this->createHeader('X-Custom-Header', 'val');
        $set     = $this->createSet($factory);
        $set->set($header);
        $this->assertTrue($set->has('x-custom-header'));
        $this->assertTrue($set->has('X-CUSTOM-HEADER'));
        $this->assertTrue($set->has('X-Custom-Header'));
    }

    public function testRemoveNonExistentDoesNotThrow()
    {
        $set = $this->createSet($this->createFactory());
        $set->remove('NonExistent');
        $this->assertFalse($set->has('NonExistent'));
    }

    public function testRemoveAllNonExistentDoesNotThrow()
    {
        $set = $this->createSet($this->createFactory());
        $set->removeAll('NonExistent');
        $this->assertFalse($set->has('NonExistent'));
    }

    public function testToStringReturnsEmptyWhenNoHeaders()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertEquals('', $set->toString());
    }

    public function testToStringCastWorks()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createTextHeader')
            ->with('Foo', 'bar')
            ->willReturn($this->createHeader('Foo', 'bar'));

        $set = $this->createSet($factory);
        $set->addTextHeader('Foo', 'bar');
        $this->assertEquals("Foo: bar\r\n", (string) $set);
    }

    public function testDefineOrderingWithEmptyArray()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createTextHeader')
            ->with('Foo', 'bar')
            ->willReturn($this->createHeader('Foo', 'bar'));

        $set = $this->createSet($factory);
        $set->addTextHeader('Foo', 'bar');
        $set->defineOrdering([]);
        $this->assertEquals("Foo: bar\r\n", $set->toString());
    }

    public function testSetHeaderThenGetReturnsSame()
    {
        $factory = $this->createFactory();
        $header  = $this->createHeader('X-Test', 'val');
        $set     = $this->createSet($factory);
        $set->set($header);

        $this->assertSame($header, $set->get('X-Test'));
    }

    public function testGetAllWithNoNameReturnsAllHeaders()
    {
        $factory = $this->createFactory();
        $h1      = $this->createHeader('X-A', 'val1');
        $h2      = $this->createHeader('X-B', 'val2');
        $set     = $this->createSet($factory);
        $set->set($h1);
        $set->set($h2);

        $all = $set->getAll();
        $this->assertCount(2, $all);
    }

    public function testSetAlwaysDisplayedWithEmptyArray()
    {
        $factory = $this->createFactory();
        $set     = $this->createSet($factory);
        $set->setAlwaysDisplayed([]);
        $this->assertEquals('', $set->toString());
    }

    public function testMultipleHeadersWithSameNameGetAll()
    {
        $factory = $this->createFactory();
        $h1      = $this->createHeader('Received', 'from server1');
        $h2      = $this->createHeader('Received', 'from server2');
        $set     = $this->createSet($factory);
        $set->set($h1, 0);
        $set->set($h2, 1);

        $all = $set->getAll('Received');
        $this->assertCount(2, $all);
    }

    public function testDefineOrderingIsCaseInsensitive()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(2))
            ->method('createTextHeader')
            ->withConsecutive(
                ['Bbb', 'second'],
                ['Aaa', 'first'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('Bbb', 'second'),
                $this->createHeader('Aaa', 'first'),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('Bbb', 'second');
        $set->addTextHeader('Aaa', 'first');
        $set->defineOrdering(['aaa', 'bbb']);
        $this->assertEquals(
            "Aaa: first\r\n".
            "Bbb: second\r\n",
            $set->toString(),
        );
    }

    public function testSetAlwaysDisplayedIsCaseInsensitive()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createTextHeader')
            ->with('X-Empty', '')
            ->willReturn($this->createHeader('X-Empty', ''));

        $set = $this->createSet($factory);
        $set->addTextHeader('X-Empty', '');
        $set->setAlwaysDisplayed(['x-empty']);
        $this->assertEquals("X-Empty: \r\n", $set->toString());
    }

    public function testAddIdHeaderWithDifferentId()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createIdHeader')
            ->with('Content-ID', 'abc@def')
            ->willReturn($this->createHeader('Content-ID', 'abc@def'));

        $set = $this->createSet($factory);
        $set->addIdHeader('Content-ID', 'abc@def');
    }

    public function testAddPathHeaderWithBounceAddress()
    {
        $factory = $this->createFactory();
        $factory->expects($this->once())
            ->method('createPathHeader')
            ->with('Return-Path', 'bounce@example.com')
            ->willReturn($this->createHeader('Return-Path', 'bounce@example.com'));

        $set = $this->createSet($factory);
        $set->addPathHeader('Return-Path', 'bounce@example.com');
    }

    public function testHasReturnsFalseForMissingIndex()
    {
        $factory = $this->createFactory();
        $header  = $this->createHeader('X-Foo', 'bar');
        $set     = $this->createSet($factory);
        $set->set($header, 0);
        $this->assertFalse($set->has('X-Foo', 5));
    }

    public function testGetAtSpecificIndex()
    {
        $factory = $this->createFactory();
        $h0      = $this->createHeader('Received', 'from server1');
        $h1      = $this->createHeader('Received', 'from server2');
        $set     = $this->createSet($factory);
        $set->set($h0, 0);
        $set->set($h1, 1);

        $this->assertSame($h0, $set->get('Received', 0));
        $this->assertSame($h1, $set->get('Received', 1));
    }

    public function testRemoveAtSpecificIndex()
    {
        $factory = $this->createFactory();
        $h0      = $this->createHeader('X-Multi', 'first');
        $h1      = $this->createHeader('X-Multi', 'second');
        $set     = $this->createSet($factory);
        $set->set($h0, 0);
        $set->set($h1, 1);
        $set->remove('X-Multi', 0);

        $this->assertFalse($set->has('X-Multi', 0));
        $this->assertTrue($set->has('X-Multi', 1));
    }

    public function testRemoveAllClearsAllIndices()
    {
        $factory = $this->createFactory();
        $h0      = $this->createHeader('X-Multi', 'first');
        $h1      = $this->createHeader('X-Multi', 'second');
        $set     = $this->createSet($factory);
        $set->set($h0, 0);
        $set->set($h1, 1);
        $set->removeAll('X-Multi');

        $this->assertFalse($set->has('X-Multi'));
        $this->assertEquals([], $set->getAll('X-Multi'));
    }

    public function testGetAllReturnsEmptyArrayForMissingName()
    {
        $set = $this->createSet($this->createFactory());
        $this->assertEquals([], $set->getAll('Nonexistent'));
    }

    public function testMultipleHeaderTypesInToString()
    {
        $factory = $this->createFactory();
        $h1      = $this->createHeader('From', 'test@test.com');
        $h2      = $this->createHeader('Subject', 'Hello');
        $set     = $this->createSet($factory);
        $set->set($h1);
        $set->set($h2);

        $output = $set->toString();
        $this->assertStringContainsString('From: test@test.com', $output);
        $this->assertStringContainsString('Subject: Hello', $output);
    }

    public function testDefineOrderingWithPartialMatch()
    {
        $factory = $this->createFactory();
        $factory->expects($this->exactly(3))
            ->method('createTextHeader')
            ->withConsecutive(
                ['C', 'three'],
                ['A', 'one'],
                ['B', 'two'],
            )
            ->willReturnOnConsecutiveCalls(
                $this->createHeader('C', 'three'),
                $this->createHeader('A', 'one'),
                $this->createHeader('B', 'two'),
            );

        $set = $this->createSet($factory);
        $set->addTextHeader('C', 'three');
        $set->addTextHeader('A', 'one');
        $set->addTextHeader('B', 'two');
        // Only order A, no mention of B or C
        $set->defineOrdering(['A']);

        $output = $set->toString();
        $this->assertStringStartsWith('A: one', $output);
    }

    private function createSet($factory)
    {
        return new Swift_Mime_SimpleHeaderSet($factory);
    }

    private function createFactory()
    {
        return $this->getMockBuilder('Swift_Mime_SimpleHeaderFactory')->disableOriginalConstructor()->getMock();
    }

    private function createHeader($name, $body = '')
    {
        $header = $this->getMockBuilder('Swift_Mime_Header')->getMock();
        $header->expects($this->any())
            ->method('getFieldName')
            ->willReturn($name);
        $header->expects($this->any())
            ->method('toString')
            ->willReturn(\sprintf("%s: %s\r\n", $name, $body));
        $header->expects($this->any())
            ->method('getFieldBody')
            ->willReturn($body);

        return $header;
    }
}
