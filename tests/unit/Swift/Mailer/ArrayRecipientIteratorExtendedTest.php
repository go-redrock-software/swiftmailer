<?php

class Swift_Mailer_ArrayRecipientIteratorExtendedTest extends PHPUnit\Framework\TestCase
{
    public function testEmptyArrayHasNoNext()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator([]);
        $this->assertFalse($it->hasNext());
    }

    public function testSingleRecipientHasNext()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator(['a@b.com' => 'A']);
        $this->assertTrue($it->hasNext());
    }

    public function testNextRecipientReturnsFirstItem()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator(['a@b.com' => 'A']);
        $this->assertEquals(['a@b.com' => 'A'], $it->nextRecipient());
    }

    public function testHasNextIsFalseAfterReadingAll()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator(['a@b.com' => 'A']);
        $it->nextRecipient();
        $this->assertFalse($it->hasNext());
    }

    public function testIteratesMultipleRecipients()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator([
            'a@b.com' => 'A',
            'c@d.com' => 'C',
            'e@f.com' => null,
        ]);
        $this->assertEquals(['a@b.com' => 'A'], $it->nextRecipient());
        $this->assertTrue($it->hasNext());
        $this->assertEquals(['c@d.com' => 'C'], $it->nextRecipient());
        $this->assertTrue($it->hasNext());
        $this->assertEquals(['e@f.com' => null], $it->nextRecipient());
        $this->assertFalse($it->hasNext());
    }

    public function testRecipientWithNullName()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator(['test@test.com' => null]);
        $result = $it->nextRecipient();
        $this->assertArrayHasKey('test@test.com', $result);
        $this->assertNull($result['test@test.com']);
    }

    public function testLargeRecipientList()
    {
        $recipients = [];
        for ($i = 0; $i < 50; ++$i) {
            $recipients["user{$i}@example.com"] = "User {$i}";
        }
        $it = new Swift_Mailer_ArrayRecipientIterator($recipients);

        $count = 0;
        while ($it->hasNext()) {
            $it->nextRecipient();
            ++$count;
        }
        $this->assertEquals(50, $count);
    }

    public function testImplementsRecipientIteratorInterface()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator([]);
        $this->assertInstanceOf(Swift_Mailer_RecipientIterator::class, $it);
    }

    public function testPreservesKeyValuePairs()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator([
            'user+tag@example.com' => 'User With Tag',
        ]);
        $result = $it->nextRecipient();
        $this->assertEquals('User With Tag', $result['user+tag@example.com']);
    }

    public function testNumericKeys()
    {
        $it = new Swift_Mailer_ArrayRecipientIterator([
            0 => 'first@test.com',
            1 => 'second@test.com',
        ]);
        $first = $it->nextRecipient();
        $this->assertEquals([0 => 'first@test.com'], $first);
    }
}
