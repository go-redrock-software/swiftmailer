<?php

class Swift_Events_SimpleEventDispatcherTest extends PHPUnit\Framework\TestCase
{
    private $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new Swift_Events_SimpleEventDispatcher();
    }

    public function testSendEventCanBeCreated()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $evt       = $this->dispatcher->createSendEvent($transport, $message);
        $this->assertInstanceOf('Swift_Events_SendEvent', $evt);
        $this->assertSame($message, $evt->getMessage());
        $this->assertSame($transport, $evt->getTransport());
    }

    public function testCommandEventCanBeCreated()
    {
        $buf = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt = $this->dispatcher->createCommandEvent($buf, "FOO\r\n", [250]);
        $this->assertInstanceOf('Swift_Events_CommandEvent', $evt);
        $this->assertSame($buf, $evt->getSource());
        $this->assertEquals("FOO\r\n", $evt->getCommand());
        $this->assertEquals([250], $evt->getSuccessCodes());
    }

    public function testResponseEventCanBeCreated()
    {
        $buf = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt = $this->dispatcher->createResponseEvent($buf, "250 Ok\r\n", true);
        $this->assertInstanceOf('Swift_Events_ResponseEvent', $evt);
        $this->assertSame($buf, $evt->getSource());
        $this->assertEquals("250 Ok\r\n", $evt->getResponse());
        $this->assertTrue($evt->isValid());
    }

    public function testTransportChangeEventCanBeCreated()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createTransportChangeEvent($transport);
        $this->assertInstanceOf('Swift_Events_TransportChangeEvent', $evt);
        $this->assertSame($transport, $evt->getSource());
    }

    public function testTransportExceptionEventCanBeCreated()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $ex        = new Swift_TransportException('');
        $evt       = $this->dispatcher->createTransportExceptionEvent($transport, $ex);
        $this->assertInstanceOf('Swift_Events_TransportExceptionEvent', $evt);
        $this->assertSame($transport, $evt->getSource());
        $this->assertSame($ex, $evt->getException());
    }

    public function testSentMessageEventCanBeCreated()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $sentMsg   = new Swift_SentMessage($message, $transport, ['message_id' => 'abc']);
        $evt       = $this->dispatcher->createSentMessageEvent($transport, $sentMsg);
        $this->assertInstanceOf('Swift_Events_SentMessageEvent', $evt);
        $this->assertSame($transport, $evt->getSource());
        $this->assertSame($sentMsg, $evt->getSentMessage());
    }

    public function testFailedMessageEventCanBeCreated()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $ex        = new Swift_TransportException('fail');
        $evt       = $this->dispatcher->createFailedMessageEvent($transport, $message, $ex, ['a@b.com']);
        $this->assertInstanceOf('Swift_Events_FailedMessageEvent', $evt);
        $this->assertSame($transport, $evt->getSource());
        $this->assertSame($message, $evt->getMessage());
        $this->assertSame($ex, $evt->getException());
        $this->assertEquals(['a@b.com'], $evt->getFailedRecipients());
    }

    public function testSentMessageListenersAreNotifiedOfDispatch()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $sentMsg   = new Swift_SentMessage($message, $transport);
        $evt       = $this->dispatcher->createSentMessageEvent($transport, $sentMsg);

        $listener = $this->getMockBuilder('Swift_Events_SentMessageListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('sentMessage')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'sentMessage');
    }

    public function testFailedMessageListenersAreNotifiedOfDispatch()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $ex        = new Swift_TransportException('fail');
        $evt       = $this->dispatcher->createFailedMessageEvent($transport, $message, $ex);

        $listener = $this->getMockBuilder('Swift_Events_FailedMessageListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('failedMessage')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'failedMessage');
    }

    public function testListenersAreNotifiedOfDispatchedEvent()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();

        $evt = $this->dispatcher->createTransportChangeEvent($transport);

        $listenerA = $this->getMockBuilder('Swift_Events_TransportChangeListener')->getMock();
        $listenerB = $this->getMockBuilder('Swift_Events_TransportChangeListener')->getMock();

        $this->dispatcher->bindEventListener($listenerA);
        $this->dispatcher->bindEventListener($listenerB);

        $listenerA->expects($this->once())
            ->method('transportStarted')
            ->with($evt);
        $listenerB->expects($this->once())
            ->method('transportStarted')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'transportStarted');
    }

    public function testListenersAreOnlyCalledIfImplementingCorrectInterface()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();

        $evt = $this->dispatcher->createSendEvent($transport, $message);

        $targetListener = $this->getMockBuilder('Swift_Events_SendListener')->getMock();
        $otherListener  = $this->getMockBuilder('DummyListener')->getMock();

        $this->dispatcher->bindEventListener($targetListener);
        $this->dispatcher->bindEventListener($otherListener);

        $targetListener->expects($this->once())
            ->method('sendPerformed')
            ->with($evt);
        $otherListener->expects($this->never())
            ->method('sendPerformed');

        $this->dispatcher->dispatchEvent($evt, 'sendPerformed');
    }

    public function testListenersCanCancelBubblingOfEvent()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();

        $evt = $this->dispatcher->createSendEvent($transport, $message);

        $listenerA = $this->getMockBuilder('Swift_Events_SendListener')->getMock();
        $listenerB = $this->getMockBuilder('Swift_Events_SendListener')->getMock();

        $this->dispatcher->bindEventListener($listenerA);
        $this->dispatcher->bindEventListener($listenerB);

        $listenerA->expects($this->once())
            ->method('sendPerformed')
            ->with($evt)
            ->willReturnCallback(function ($object) {
                $object->cancelBubble(true);
            });
        $listenerB->expects($this->never())
            ->method('sendPerformed');

        $this->dispatcher->dispatchEvent($evt, 'sendPerformed');

        $this->assertTrue($evt->bubbleCancelled());
    }

    public function testPreventFlushingQueueBubbleOnInternalEventsRising()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();

        $evtA = $this->dispatcher->createSendEvent($transport, $message);

        $evtB = $this->dispatcher->createTransportChangeEvent($transport);

        $listenerB = $this->getMockBuilder('Swift_Events_TransportChangeListener')->getMock();

        $this->dispatcher->bindEventListener($listenerB);

        $listenerA1 = $this->getMockBuilder('Swift_Events_SendListener')->getMock();
        $listenerA2 = $this->getMockBuilder('Swift_Events_SendListener')->getMock();

        $this->dispatcher->bindEventListener($listenerA1);
        $this->dispatcher->bindEventListener($listenerA2);

        $listenerA1->expects($this->once())
            ->method('sendPerformed')
            ->with($evtA)
            ->will($this->returnCallback(function ($object) use ($evtB) {
                $this->dispatcher->dispatchEvent($evtB, 'beforeTransportStarted');
            }));
        $listenerA2->expects($this->once())
            ->method('sendPerformed')
            ->with($evtA);
        $listenerB->expects($this->once())
            ->method('beforeTransportStarted')
            ->with($evtB);

        $this->dispatcher->dispatchEvent($evtA, 'sendPerformed');
    }

    public function testBindingSameListenerTwiceHasNoEffect()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();

        $evt = $this->dispatcher->createSendEvent($transport, $message);

        $listener = $this->getMockBuilder('Swift_Events_SendListener')->getMock();
        $this->dispatcher->bindEventListener($listener);
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('sendPerformed')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'sendPerformed');
    }

    public function testCommandListenersAreNotifiedOfDispatch()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createCommandEvent($transport, "EHLO\r\n", [250]);

        $listener = $this->getMockBuilder('Swift_Events_CommandListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('commandSent')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'commandSent');
    }

    public function testResponseListenersAreNotifiedOfDispatch()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createResponseEvent($transport, "250 Ok\r\n", true);

        $listener = $this->getMockBuilder('Swift_Events_ResponseListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('responseReceived')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'responseReceived');
    }

    public function testTransportExceptionListenersAreNotifiedOfDispatch()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $ex        = new Swift_TransportException('Error');
        $evt       = $this->dispatcher->createTransportExceptionEvent($transport, $ex);

        $listener = $this->getMockBuilder('Swift_Events_TransportExceptionListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('exceptionThrown')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'exceptionThrown');
    }

    public function testTransportChangeBeforeStartListenersAreNotified()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createTransportChangeEvent($transport);

        $listener = $this->getMockBuilder('Swift_Events_TransportChangeListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('beforeTransportStarted')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'beforeTransportStarted');
    }

    public function testTransportChangeBeforeStopListenersAreNotified()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createTransportChangeEvent($transport);

        $listener = $this->getMockBuilder('Swift_Events_TransportChangeListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('beforeTransportStopped')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'beforeTransportStopped');
    }

    public function testTransportStoppedListenersAreNotified()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createTransportChangeEvent($transport);

        $listener = $this->getMockBuilder('Swift_Events_TransportChangeListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('transportStopped')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'transportStopped');
    }

    public function testBeforeSendPerformedListenersAreNotified()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $evt       = $this->dispatcher->createSendEvent($transport, $message);

        $listener = $this->getMockBuilder('Swift_Events_SendListener')->getMock();
        $this->dispatcher->bindEventListener($listener);

        $listener->expects($this->once())
            ->method('beforeSendPerformed')
            ->with($evt);

        $this->dispatcher->dispatchEvent($evt, 'beforeSendPerformed');
    }

    public function testMultipleListenersOfDifferentTypes()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();

        $sendEvent = $this->dispatcher->createSendEvent($transport, $message);
        $cmdEvent  = $this->dispatcher->createCommandEvent($transport, "EHLO\r\n");

        $sendListener = $this->getMockBuilder('Swift_Events_SendListener')->getMock();
        $cmdListener  = $this->getMockBuilder('Swift_Events_CommandListener')->getMock();

        $this->dispatcher->bindEventListener($sendListener);
        $this->dispatcher->bindEventListener($cmdListener);

        $sendListener->expects($this->once())->method('sendPerformed');
        $cmdListener->expects($this->never())->method('commandSent');

        $this->dispatcher->dispatchEvent($sendEvent, 'sendPerformed');
    }

    public function testNoListenersBoundDoesNotCrash()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createTransportChangeEvent($transport);

        // Should not throw
        $this->dispatcher->dispatchEvent($evt, 'transportStarted');
        $this->assertFalse($evt->bubbleCancelled());
    }

    public function testDispatchEventWithUnknownMethodDoesNotCrash()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt       = $this->dispatcher->createTransportChangeEvent($transport);

        // Non-existent method on listeners should be silently ignored
        $this->dispatcher->dispatchEvent($evt, 'nonExistentMethod');
        $this->assertFalse($evt->bubbleCancelled());
    }

    public function testCreateSendEventSetsCorrectDefaultResult()
    {
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $message   = $this->getMockBuilder('Swift_Mime_SimpleMessage')->disableOriginalConstructor()->getMock();
        $evt       = $this->dispatcher->createSendEvent($transport, $message);

        $this->assertSame(Swift_Events_SendEvent::RESULT_PENDING, $evt->getResult());
    }

    public function testNewDispatcherHasNoListeners()
    {
        $dispatcher = new Swift_Events_SimpleEventDispatcher();
        $transport  = $this->getMockBuilder('Swift_Transport')->getMock();
        $evt        = $dispatcher->createTransportChangeEvent($transport);

        // Should not throw with no listeners
        $dispatcher->dispatchEvent($evt, 'transportStarted');
        $this->assertTrue(true);
    }

    private function createDispatcher(array $map)
    {
        return new Swift_Events_SimpleEventDispatcher($map);
    }
}

class DummyListener implements Swift_Events_EventListener
{
    public function sendPerformed(Swift_Events_SendEvent $evt)
    {
    }
}
