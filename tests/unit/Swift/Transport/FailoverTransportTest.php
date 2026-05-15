<?php

class Swift_Transport_FailoverTransportTest extends SwiftMailerTestCase
{
    public function testFirstTransportIsUsed()
    {
        $message1        = $this->getMockery('Swift_Mime_SimpleMessage');
        $message2        = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
        $t2              = $this->getMockery('Swift_Transport');
        $connectionState = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState) {
                if (!$connectionState) {
                    $connectionState = true;
                }
            });
        $t1->shouldReceive('send')
            ->twice()
            ->with(Mockery::anyOf($message1, $message2), Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState) {
                if ($connectionState) {
                    return 1;
                }
            });
        $t2->shouldReceive('start')->never();
        $t2->shouldReceive('send')->never();

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message1));
        $this->assertEquals(1, $transport->send($message2));
    }

    public function testMessageCanBeTriedOnNextTransportIfExceptionThrown()
    {
        $e = new Swift_TransportException('b0rken');

        $message          = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1               = $this->getMockery('Swift_Transport');
        $t2               = $this->getMockery('Swift_Transport');
        $connectionState1 = false;
        $connectionState2 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState1) {
                if (!$connectionState1) {
                    $connectionState1 = true;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1, $e) {
                if ($connectionState1) {
                    throw $e;
                }
            });

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                if (!$connectionState2) {
                    $connectionState2 = true;
                }
            });
        $t2->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2) {
                if ($connectionState2) {
                    return 1;
                }
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message));
    }

    public function testZeroIsReturnedIfTransportReturnsZero()
    {
        $message = $this->getMockery('Swift_Mime_SimpleMessage')->shouldIgnoreMissing();
        $t1      = $this->getMockery('Swift_Transport')->shouldIgnoreMissing();

        $connectionState = false;
        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState) {
                if (!$connectionState) {
                    $connectionState = true;
                }
            });
        $testCase = $this;
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState, $testCase) {
                if (!$connectionState) {
                    $testCase->fail();
                }

                return 0;
            });

        $transport = $this->getTransport([$t1]);
        $transport->start();
        $this->assertEquals(0, $transport->send($message));
    }

    public function testTransportsWhichThrowExceptionsAreNotRetried()
    {
        $e = new Swift_TransportException('maur b0rken');

        $message1         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message2         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message3         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message4         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1               = $this->getMockery('Swift_Transport');
        $t2               = $this->getMockery('Swift_Transport');
        $connectionState1 = false;
        $connectionState2 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState1) {
                if (!$connectionState1) {
                    $connectionState1 = true;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message1, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1, $e) {
                if ($connectionState1) {
                    throw $e;
                }
            });
        $t1->shouldReceive('send')
            ->never()
            ->with($message2, Mockery::any(), Mockery::any());
        $t1->shouldReceive('send')
            ->never()
            ->with($message3, Mockery::any());
        $t1->shouldReceive('send')
            ->never()
            ->with($message4, Mockery::any());

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                if (!$connectionState2) {
                    $connectionState2 = true;
                }
            });
        $t2->shouldReceive('send')
            ->times(4)
            ->with(Mockery::anyOf($message1, $message2, $message3, $message4), Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2) {
                if ($connectionState2) {
                    return 1;
                }
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message1));
        $this->assertEquals(1, $transport->send($message2));
        $this->assertEquals(1, $transport->send($message3));
        $this->assertEquals(1, $transport->send($message4));
    }

    public function testExceptionIsThrownIfAllTransportsDie()
    {
        $e = new Swift_TransportException('b0rken');

        $message          = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1               = $this->getMockery('Swift_Transport');
        $t2               = $this->getMockery('Swift_Transport');
        $connectionState1 = false;
        $connectionState2 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState1) {
                if (!$connectionState1) {
                    $connectionState1 = true;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1, $e) {
                if ($connectionState1) {
                    throw $e;
                }
            });

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                if (!$connectionState2) {
                    $connectionState2 = true;
                }
            });
        $t2->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2, $e) {
                if ($connectionState2) {
                    throw $e;
                }
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        try {
            $transport->send($message);
            $this->fail('All transports failed so Exception should be thrown');
        } catch (Exception $e) {
        }
    }

    public function testStoppingTransportStopsAllDelegates()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $connectionState1 = true;
        $connectionState2 = true;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('stop')
            ->once()
            ->andReturnUsing(function () use (&$connectionState1) {
                if ($connectionState1) {
                    $connectionState1 = false;
                }
            });

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('stop')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                if ($connectionState2) {
                    $connectionState2 = false;
                }
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $transport->stop();
    }

    public function testTransportShowsAsNotStartedIfAllDelegatesDead()
    {
        $e = new Swift_TransportException('b0rken');

        $message = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1      = $this->getMockery('Swift_Transport');
        $t2      = $this->getMockery('Swift_Transport');

        $connectionState1 = false;
        $connectionState2 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState1) {
                if (!$connectionState1) {
                    $connectionState1 = true;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1, $e) {
                if ($connectionState1) {
                    $connectionState1 = false;
                    throw $e;
                }
            });

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                if (!$connectionState2) {
                    $connectionState2 = true;
                }
            });
        $t2->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2, $e) {
                if ($connectionState2) {
                    $connectionState2 = false;
                    throw $e;
                }
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertTrue($transport->isStarted());
        try {
            $transport->send($message);
            $this->fail('All transports failed so Exception should be thrown');
        } catch (Exception $e) {
            $this->assertFalse($transport->isStarted());
        }
    }

    public function testRestartingTransportRestartsDeadDelegates()
    {
        $e = new Swift_TransportException('b0rken');

        $message1 = $this->getMockery('Swift_Mime_SimpleMessage');
        $message2 = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1       = $this->getMockery('Swift_Transport');
        $t2       = $this->getMockery('Swift_Transport');

        $connectionState1 = false;
        $connectionState2 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->twice()
            ->andReturnUsing(function () use (&$connectionState1) {
                if (!$connectionState1) {
                    $connectionState1 = true;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message1, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1, $e) {
                if ($connectionState1) {
                    $connectionState1 = false;
                    throw $e;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message2, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1) {
                if ($connectionState1) {
                    return 10;
                }
            });

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                if (!$connectionState2) {
                    $connectionState2 = true;
                }
            });
        $t2->shouldReceive('send')
            ->once()
            ->with($message1, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2, $e) {
                if ($connectionState2) {
                    $connectionState2 = false;
                    throw $e;
                }
            });
        $t2->shouldReceive('send')
            ->never()
            ->with($message2, Mockery::any(), Mockery::any());

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertTrue($transport->isStarted());
        try {
            $transport->send($message1);
            $this->fail('All transports failed so Exception should be thrown');
        } catch (Exception $e) {
            $this->assertFalse($transport->isStarted());
        }
        // Restart and re-try
        $transport->start();
        $this->assertTrue($transport->isStarted());
        $this->assertEquals(10, $transport->send($message2));
    }

    public function testFailureReferenceIsPassedToDelegates()
    {
        $failures = [];

        $message = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1      = $this->getMockery('Swift_Transport');

        $connectionState = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use ($connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use ($connectionState) {
                if (!$connectionState) {
                    $connectionState = true;
                }
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, $failures, Mockery::any())
            ->andReturnUsing(function () use ($connectionState) {
                if ($connectionState) {
                    return 1;
                }
            });

        $transport = $this->getTransport([$t1]);
        $transport->start();
        $transport->send($message, $failures);
    }

    public function testRegisterPluginDelegatesToLoadedTransports()
    {
        $plugin = $this->createPlugin();

        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');
        $t1->shouldReceive('registerPlugin')
            ->once()
            ->with($plugin);
        $t2->shouldReceive('registerPlugin')
            ->once()
            ->with($plugin);

        $transport = $this->getTransport([$t1, $t2]);
        $transport->registerPlugin($plugin);
    }

    public function testEachDelegateIsPinged()
    {
        $t1               = $this->getMockery('Swift_Transport');
        $t2               = $this->getMockery('Swift_Transport');
        $connectionState1 = false;
        $connectionState2 = false;

        $testCase = $this;
        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('ping')
            ->once()
            ->andReturn(true);

        $transport = $this->getTransport([$t1, $t2]);
        $this->assertTrue($transport->isStarted());
        $this->assertTrue($transport->ping());
    }

    public function testDelegateIsKilledWhenPingFails()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $testCase = $this;
        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('ping')
            ->once()
            ->andReturn(false);

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('ping')
            ->twice()
            ->andReturn(true);

        $transport = $this->getTransport([$t1, $t2]);
        $this->assertTrue($transport->ping());
        $this->assertTrue($transport->ping());
        $this->assertTrue($transport->isStarted());
    }

    public function testPingReturnsFalseWhenAllTransportsFailPing()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturn(false);
        $t1->shouldReceive('ping')
            ->once()
            ->andReturn(false);
        $t1->shouldReceive('stop')
            ->zeroOrMoreTimes();

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturn(false);
        $t2->shouldReceive('ping')
            ->once()
            ->andReturn(false);
        $t2->shouldReceive('stop')
            ->zeroOrMoreTimes();

        $transport = $this->getTransport([$t1, $t2]);
        // When all transports fail ping and get killed, transports array is empty => false
        $this->assertFalse($transport->ping());
    }

    public function XtestTransportShowsAsNotStartedIfAllPingFails()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $testCase = $this;
        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('ping')
            ->once()
            ->andReturn(false);

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('ping')
            ->once()
            ->andReturn(false);

        $transport = $this->getTransport([$t1, $t2]);
        $this->assertFalse($transport->ping());
        $this->assertFalse($transport->isStarted());
        $this->assertFalse($transport->ping());
    }

    public function testFailoverLogsWhenSwitchingTransports()
    {
        $e       = new Swift_TransportException('connection lost');
        $message = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1      = $this->getMockery('Swift_Transport');
        $t2      = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')->andReturn(true);
        $t1->shouldReceive('send')->once()->andThrow($e);
        $t1->shouldReceive('stop')->once();

        $t2->shouldReceive('isStarted')->andReturn(true);
        $t2->shouldReceive('send')->once()->andReturn(1);

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();

        $logged = false;
        \set_error_handler(function (int $errno, string $errstr) use (&$logged) {
            if (\str_contains($errstr, 'Failover from') && \str_contains($errstr, 'connection lost')) {
                $logged = true;
            }

            return true;
        });

        try {
            $this->assertEquals(1, $transport->send($message));
        } finally {
            \restore_error_handler();
        }

        $this->assertTrue($logged, 'Expected failover to be logged via error_log');
    }

    private function getTransport(array $transports)
    {
        $transport = new Swift_Transport_FailoverTransport();
        $transport->setTransports($transports);

        return $transport;
    }

    private function createPlugin()
    {
        return $this->getMockery('Swift_Events_EventListener');
    }
}
