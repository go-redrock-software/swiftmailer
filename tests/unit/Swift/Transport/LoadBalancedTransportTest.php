<?php

class Swift_Transport_LoadBalancedTransportTest extends SwiftMailerTestCase
{
    public function testEachTransportIsUsedInTurn()
    {
        $message1         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message2         = $this->getMockery('Swift_Mime_SimpleMessage');
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
            ->andReturnUsing(function () use (&$connectionState1, $testCase) {
                if ($connectionState1) {
                    return 1;
                }
                $testCase->fail();
            });
        $t1->shouldReceive('send')
            ->never()
            ->with($message2, Mockery::any(), Mockery::any());

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
            ->with($message2, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2, $testCase) {
                if ($connectionState2) {
                    return 1;
                }
                $testCase->fail();
            });
        $t2->shouldReceive('send')
            ->never()
            ->with($message1, Mockery::any(), Mockery::any());

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message1));
        $this->assertEquals(1, $transport->send($message2));
    }

    public function testTransportsAreReusedInRotatingFashion()
    {
        $message1         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message2         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message3         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message4         = $this->getMockery('Swift_Mime_SimpleMessage');
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
            ->andReturnUsing(function () use (&$connectionState1, $testCase) {
                if ($connectionState1) {
                    return 1;
                }
                $testCase->fail();
            });
        $t1->shouldReceive('send')
            ->never()
            ->with($message2, Mockery::any(), Mockery::any());
        $t1->shouldReceive('send')
            ->once()
            ->with($message3, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState1, $testCase) {
                if ($connectionState1) {
                    return 1;
                }
                $testCase->fail();
            });
        $t1->shouldReceive('send')
            ->never()
            ->with($message4, Mockery::any(), Mockery::any());

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
            ->with($message2, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2, $testCase) {
                if ($connectionState2) {
                    return 1;
                }
                $testCase->fail();
            });
        $t2->shouldReceive('send')
            ->never()
            ->with($message1, Mockery::any(), Mockery::any());
        $t2->shouldReceive('send')
            ->once()
            ->with($message4, Mockery::any(), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState2, $testCase) {
                if ($connectionState2) {
                    return 1;
                }
                $testCase->fail();
            });
        $t2->shouldReceive('send')
            ->never()
            ->with($message3, Mockery::any(), Mockery::any());

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();

        $this->assertEquals(1, $transport->send($message1));
        $this->assertEquals(1, $transport->send($message2));
        $this->assertEquals(1, $transport->send($message3));
        $this->assertEquals(1, $transport->send($message4));
    }

    public function testMessageCanBeTriedOnNextTransportIfExceptionThrown()
    {
        $e = new Swift_TransportException('b0rken');

        $message          = $this->getMockery('Swift_Mime_SimpleMessage');
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
            ->andReturnUsing(function () use (&$connectionState1, $e, $testCase) {
                if ($connectionState1) {
                    throw $e;
                }
                $testCase->fail();
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
            ->andReturnUsing(function () use (&$connectionState2, $testCase) {
                if ($connectionState2) {
                    return 1;
                }
                $testCase->fail();
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message));
    }

    public function testMessageIsTriedOnNextTransportIfZeroReturned()
    {
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
            ->andReturnUsing(function () use (&$connectionState1) {
                if ($connectionState1) {
                    return 0;
                }

                return 1;
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

                return 0;
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message));
    }

    public function testZeroIsReturnedIfAllTransportsReturnZero()
    {
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
            ->andReturnUsing(function () use (&$connectionState1) {
                if ($connectionState1) {
                    return 0;
                }

                return 1;
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
                    return 0;
                }

                return 1;
            });

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertEquals(0, $transport->send($message));
    }

    /*
    FIXME: Does not work anymore after upgrading Mockery
        public function testTransportsWhichThrowExceptionsAreNotRetried()
        {
            $e = new Swift_TransportException('maur b0rken');

            $message1 = $this->getMockery('Swift_Mime_SimpleMessage');
            $message2 = $this->getMockery('Swift_Mime_SimpleMessage');
            $message3 = $this->getMockery('Swift_Mime_SimpleMessage');
            $message4 = $this->getMockery('Swift_Mime_SimpleMessage');
            $t1 = $this->getMockery('Swift_Transport');
            $t2 = $this->getMockery('Swift_Transport');
            $connectionState1 = false;
            $connectionState2 = false;

            $testCase = $this;
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
               ->with($message1, \Mockery::any())
               ->andReturnUsing(function () use (&$connectionState1, $e, $testCase) {
                   if ($connectionState1) {
                       throw $e;
                   }
                   $testCase->fail();
               });
            $t1->shouldReceive('send')
               ->never()
               ->with($message2, \Mockery::any());
            $t1->shouldReceive('send')
               ->never()
               ->with($message3, \Mockery::any());
            $t1->shouldReceive('send')
               ->never()
               ->with($message4, \Mockery::any());

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
               ->with(\Mockery::anyOf($message1, $message3, $message3, $message4), \Mockery::any())
               ->andReturnUsing(function () use (&$connectionState2, $testCase) {
                   if ($connectionState2) {
                       return 1;
                   }
                   $testCase->fail();
               });

            $transport = $this->getTransport([$t1, $t2]);
            $transport->start();
            $this->assertEquals(1, $transport->send($message1));
            $this->assertEquals(1, $transport->send($message2));
            $this->assertEquals(1, $transport->send($message3));
            $this->assertEquals(1, $transport->send($message4));
        }
    */
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
        $t1               = $this->getMockery('Swift_Transport');
        $t2               = $this->getMockery('Swift_Transport');
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

        $message1         = $this->getMockery('Swift_Mime_SimpleMessage');
        $message2         = $this->getMockery('Swift_Mime_SimpleMessage');
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
        $testCase = $this;

        $message         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
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
            ->once()
            ->with($message, Mockery::on(function (&$var) use (&$failures, $testCase) {
                return $testCase->varsAreReferences($var, $failures);
            }), Mockery::any())
            ->andReturnUsing(function () use (&$connectionState) {
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
            ->andReturn(true);

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('ping')
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
            ->twice()
            ->andReturn(true);

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('ping')
            ->once()
            ->andReturn(false);

        $transport = $this->getTransport([$t1, $t2]);
        $this->assertTrue($transport->ping());
        $this->assertTrue($transport->ping());
        $this->assertTrue($transport->isStarted());
    }

    public function testTransportShowsAsNotStartedIfAllPingFails()
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

    /**
     * Adapted from Yay_Matchers_ReferenceMatcher.
     */
    public function varsAreReferences(&$ref1, &$ref2)
    {
        if (\is_object($ref2)) {
            return $ref1 === $ref2;
        }
        if ($ref1 !== $ref2) {
            return false;
        }

        $copy         = $ref2;
        $randomString = \uniqid('yay', true);
        $ref2         = $randomString;
        $isRef        = ($ref1 === $ref2);
        $ref2         = $copy;

        return $isRef;
    }

    public function testGetTransportsReturnsConfiguredTransports()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);
        $t2->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);

        $transport = $this->getTransport([$t1, $t2]);
        $this->assertCount(2, $transport->getTransports());
    }

    public function testIsStartedReturnsTrueWhenTransportsExist()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t1->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(true);
        $t1->shouldReceive('ping')->zeroOrMoreTimes()->andReturn(true);

        $transport = $this->getTransport([$t1]);
        $this->assertTrue($transport->isStarted());
    }

    public function testStartedAfterStartCall()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturn(true);
        $t1->shouldReceive('ping')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturn(true);
        $t2->shouldReceive('ping')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testSingleTransportSendsSuccessfully()
    {
        $message         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
        $connectionState = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState) {
                $connectionState = true;
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturn(1);

        $transport = $this->getTransport([$t1]);
        $transport->start();
        $this->assertEquals(1, $transport->send($message));
    }

    public function testSetTransportsOverwritesPrevious()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');
        $t3 = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);
        $t2->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);
        $t3->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);

        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports([$t1, $t2]);
        $this->assertCount(2, $transport->getTransports());

        $transport->setTransports([$t3]);
        $this->assertCount(1, $transport->getTransports());
    }

    public function testRegisterPluginDelegatesToAllTransportsIncludingNew()
    {
        $plugin = $this->createPlugin();

        $t1 = $this->getMockery('Swift_Transport');
        $t1->shouldReceive('registerPlugin')
            ->once()
            ->with($plugin);

        $transport = $this->getTransport([$t1]);
        $transport->registerPlugin($plugin);
    }

    public function testPingReturnsTrueWhenAtLeastOneAlive()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t2 = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(true);
        $t1->shouldReceive('ping')->once()->andReturn(false);

        $t2->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(true);
        $t2->shouldReceive('ping')->once()->andReturn(true);

        $transport = $this->getTransport([$t1, $t2]);
        $this->assertTrue($transport->ping());
    }

    public function testPingReturnsFalseWhenAllDead()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t1->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);
        $t1->shouldReceive('ping')->once()->andReturn(false);

        $transport = $this->getTransport([$t1]);
        $this->assertFalse($transport->ping());
    }

    public function testStopCalledOnAllTransports()
    {
        $t1               = $this->getMockery('Swift_Transport');
        $connectionState1 = true;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('stop')
            ->once()
            ->andReturnUsing(function () use (&$connectionState1) {
                $connectionState1 = false;
            });

        $transport = $this->getTransport([$t1]);
        $transport->start();
        $transport->stop();
    }

    public function testGetLastUsedTransportReturnsNullBeforeSend()
    {
        $t1 = $this->getMockery('Swift_Transport');
        $t1->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(false);

        $transport = $this->getTransport([$t1]);
        $this->assertNull($transport->getLastUsedTransport());
    }

    public function testGetLastUsedTransportReturnsTransportAfterSend()
    {
        $message         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
        $connectionState = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState) {
                $connectionState = true;
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturn(1);

        $transport = $this->getTransport([$t1]);
        $transport->start();
        $transport->send($message);
        $this->assertSame($t1, $transport->getLastUsedTransport());
    }

    public function testIsStartedReturnsFalseWithNoTransports()
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $this->assertFalse($transport->isStarted());
    }

    public function testSetTransportsResetsDeadTransports()
    {
        $e = new Swift_TransportException('b0rken');

        $message          = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1               = $this->getMockery('Swift_Transport');
        $t2               = $this->getMockery('Swift_Transport');
        $connectionState1 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                $connectionState1 = true;
            });
        $t1->shouldReceive('send')
            ->zeroOrMoreTimes()
            ->andThrow($e);
        $t1->shouldReceive('stop')
            ->zeroOrMoreTimes();

        $transport = $this->getTransport([$t1]);
        $transport->start();
        try {
            $transport->send($message);
        } catch (Swift_TransportException $ex) {
        }
        $this->assertFalse($transport->isStarted());

        // Now replace transports
        $t2->shouldReceive('isStarted')->zeroOrMoreTimes()->andReturn(true);
        $t2->shouldReceive('ping')->zeroOrMoreTimes()->andReturn(true);
        $transport->setTransports([$t2]);
        $this->assertTrue($transport->isStarted());
    }

    public function testEmptyTransportsThrowsOnSend()
    {
        $message   = $this->getMockery('Swift_Mime_SimpleMessage');
        $transport = new Swift_Transport_LoadBalancedTransport();

        $this->expectException(Swift_TransportException::class);
        $transport->send($message);
    }

    public function testThreeTransportsRoundRobin()
    {
        $message         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
        $t2              = $this->getMockery('Swift_Transport');
        $t3              = $this->getMockery('Swift_Transport');
        $connectionState = [false, false, false];

        foreach ([[$t1, 0], [$t2, 1], [$t3, 2]] as [$t, $i]) {
            $t->shouldReceive('isStarted')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function () use (&$connectionState, $i) {
                    return $connectionState[$i];
                });
            $t->shouldReceive('start')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function () use (&$connectionState, $i) {
                    $connectionState[$i] = true;
                });
            $t->shouldReceive('send')
                ->zeroOrMoreTimes()
                ->with($message, Mockery::any(), Mockery::any())
                ->andReturn(1);
        }

        $transport = $this->getTransport([$t1, $t2, $t3]);
        $transport->start();
        $transport->send($message);
        $transport->send($message);
        $transport->send($message);
        $this->assertCount(3, $transport->getTransports());
    }

    public function testRegisterPluginRegistersOnAllDelegates()
    {
        $plugin = $this->createPlugin();
        $t1     = $this->getMockery('Swift_Transport');
        $t2     = $this->getMockery('Swift_Transport');
        $t3     = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('registerPlugin')->once()->with($plugin);
        $t2->shouldReceive('registerPlugin')->once()->with($plugin);
        $t3->shouldReceive('registerPlugin')->once()->with($plugin);

        $transport = $this->getTransport([$t1, $t2, $t3]);
        $transport->registerPlugin($plugin);
    }

    public function testGetTransportsIncludesDeadTransports()
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
                $connectionState1 = true;
            });
        $t1->shouldReceive('send')
            ->once()
            ->andThrow($e);
        $t1->shouldReceive('stop')
            ->zeroOrMoreTimes();

        $t2->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState2) {
                return $connectionState2;
            });
        $t2->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState2) {
                $connectionState2 = true;
            });
        $t2->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturn(1);

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();
        $transport->send($message);
        // Even though t1 is dead, getTransports() includes it
        $this->assertCount(2, $transport->getTransports());
    }

    public function testStartRevivesDeadTransports()
    {
        $e = new Swift_TransportException('b0rken');

        $message          = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1               = $this->getMockery('Swift_Transport');
        $connectionState1 = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                return $connectionState1;
            });
        $t1->shouldReceive('start')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState1) {
                $connectionState1 = true;
            });
        $t1->shouldReceive('send')
            ->once()
            ->andThrow($e);
        $t1->shouldReceive('stop')
            ->zeroOrMoreTimes();

        $transport = $this->getTransport([$t1]);
        $transport->start();
        try {
            $transport->send($message);
        } catch (Swift_TransportException $ex) {
        }
        $this->assertFalse($transport->isStarted());

        // Restart should revive dead transports
        $transport->start();
        $this->assertTrue($transport->isStarted());
    }

    public function testSendReturnsZeroWhenTransportReturnsZero()
    {
        $message         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
        $connectionState = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState) {
                $connectionState = true;
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturn(0);

        $transport = $this->getTransport([$t1]);
        $transport->start();
        // send returns 0 - it keeps trying other transports when sent=0
        $result = $transport->send($message);
        $this->assertEquals(0, $result);
    }

    public function testStopOnEmptyTransportDoesNotThrow()
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->stop();
        $this->assertFalse($transport->isStarted());
    }

    public function testPingOnEmptyTransportReturnsFalse()
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $this->assertFalse($transport->ping());
    }

    public function testConstructorCreatesEmptyTransport()
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $this->assertEmpty($transport->getTransports());
        $this->assertNull($transport->getLastUsedTransport());
    }

    public function testSendWithMultipleRecipientCount()
    {
        $message         = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1              = $this->getMockery('Swift_Transport');
        $connectionState = false;

        $t1->shouldReceive('isStarted')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$connectionState) {
                return $connectionState;
            });
        $t1->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use (&$connectionState) {
                $connectionState = true;
            });
        $t1->shouldReceive('send')
            ->once()
            ->with($message, Mockery::any(), Mockery::any())
            ->andReturn(5);

        $transport = $this->getTransport([$t1]);
        $transport->start();
        $this->assertEquals(5, $transport->send($message));
    }

    public function testLoadBalancedTransportLogsErrors()
    {
        $message = $this->getMockery('Swift_Mime_SimpleMessage');
        $t1      = $this->getMockery('Swift_Transport');
        $t2      = $this->getMockery('Swift_Transport');

        $t1->shouldReceive('isStarted')->andReturn(true);
        $t1->shouldReceive('send')->once()->andThrow(new Swift_TransportException('smtp down'));
        $t1->shouldReceive('stop')->once()->andThrow(new \RuntimeException('stop failed'));

        $t2->shouldReceive('isStarted')->andReturn(true);
        $t2->shouldReceive('send')->once()->andReturn(1);

        $transport = $this->getTransport([$t1, $t2]);
        $transport->start();

        $logged = false;
        \set_error_handler(function (int $errno, string $errstr) use (&$logged) {
            if (\str_contains($errstr, 'LoadBalancedTransport error from') && \str_contains($errstr, 'stop failed')) {
                $logged = true;
            }

            return true;
        });

        try {
            $this->assertEquals(1, $transport->send($message));
        } finally {
            \restore_error_handler();
        }

        $this->assertTrue($logged, 'Expected LoadBalancedTransport error to be logged via error_log');
    }

    private function getTransport(array $transports)
    {
        $transport = new Swift_Transport_LoadBalancedTransport();
        $transport->setTransports($transports);

        return $transport;
    }

    private function createPlugin()
    {
        return $this->getMockery('Swift_Events_EventListener');
    }
}
