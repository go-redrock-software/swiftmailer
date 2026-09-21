<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests: an API transport may be constructed without an event
 * dispatcher (the constructor argument is nullable on every transport), so the
 * lifecycle and error paths in Swift_Transport_AbstractApiTransport must not
 * dereference a null dispatcher.
 *
 * Before the fix, stop() and throwException() called methods on a null
 * dispatcher and raised an \Error (not an \Exception), so __destruct's
 * catch (Exception) did not contain it and API failures surfaced as a fatal
 * "Call to a member function ... on null" instead of a Swift_TransportException.
 *
 * Sendgrid is used as a representative concrete HTTP API transport; the code
 * under test lives in the shared abstract base.
 */
class Swift_Transport_AbstractApiTransportNullDispatcherTest extends TestCase
{
    public function testStopDoesNotFatalWithoutDispatcher(): void
    {
        $transport = new Swift_Transport_Api_SendgridTransport('api-key', $this->okClient());
        $transport->start();
        $this->assertTrue($transport->isStarted());

        $transport->stop();
        $this->assertFalse($transport->isStarted());
    }

    public function testApiErrorThrowsTransportExceptionWithoutDispatcher(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn(new Response(500, [], '{"errors":[{"message":"boom"}]}'));

        $transport = new Swift_Transport_Api_SendgridTransport('api-key', $client);

        $message = (new Swift_Message('Subject'))
            ->setFrom(['from@example.com' => 'From'])
            ->setTo(['to@example.com' => 'To'])
            ->setBody('Body');

        $this->expectException(Swift_TransportException::class);
        $transport->send($message);
    }

    public function testStartedTransportDestructsCleanlyWithoutDispatcher(): void
    {
        $transport = new Swift_Transport_Api_SendgridTransport('api-key', $this->okClient());
        $transport->start();

        // __destruct -> stop(); a null dispatcher must not raise during teardown.
        unset($transport);
        $this->addToAssertionCount(1);
    }

    public function testRegisterPluginDoesNotFatalWithoutDispatcher(): void
    {
        $transport = new Swift_Transport_Api_SendgridTransport('api-key', $this->okClient());

        // registerPlugin binds the listener on the dispatcher; with no dispatcher it must
        // be a no-op, not a "Call to a member function bindEventListener() on null".
        $transport->registerPlugin(new class implements Swift_Events_EventListener {});
        $this->addToAssertionCount(1);
    }

    private function okClient(): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn(new Response(200, [], '{}'));

        return $client;
    }
}
