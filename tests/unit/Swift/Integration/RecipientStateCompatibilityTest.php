<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Swift_Integration_RecipientStateCompatibilityTest extends TestCase
{
    public function testRejectedAllowlistDiagnosticRetainsEveryOriginalRecipientField(): void
    {
        $message = (new Swift_Message('blocked', 'body'))->setFrom('sender@blocked.test')->setTo('to@blocked.test')->setCc('copy@blocked.test')->setBcc('blind@blocked.test');
        $event   = new Swift_Events_SendEvent(new Swift_NullTransport(), $message);
        (new Swift_Plugins_AllowlistPlugin(['*@allowed.test']))->beforeSendPerformed($event);
        $this->assertTrue($event->isRejected());
        $this->assertSame('AllowlistPlugin: all recipients removed by allowlist filter (to@blocked.test, copy@blocked.test, blind@blocked.test)', $event->getRejectionReason());
    }

    /** @dataProvider messageEvents */
    public function testRestorationUsesTheMessageExposedByTheEvent(string $policies, bool $delegated, bool $missingTo): void
    {
        $message    = $this->message('actual', $missingTo);
        $decoy      = $this->message('unrelated', false);
        $original   = $this->recipients($message);
        $decoyState = $this->recipients($decoy);
        $source     = new Swift_NullTransport();
        $event      = $delegated ? new Swift_CompatibilityMessageEvent($source, $decoy, $message) : new Swift_Events_SendEvent($source, $message);
        $plugins    = $this->plugins($policies);
        foreach ($plugins as $plugin) {
            $plugin->beforeSendPerformed($event);
        }
        $this->assertArrayHasKey('sink@allowed.test', $message->getTo());
        foreach ($plugins as $plugin) {
            $plugin->sendPerformed($event);
        }
        $this->assertSame($original, $this->recipients($message));
        $this->assertSame($decoyState, $this->recipients($decoy));
    }

    public static function messageEvents(): iterable
    {
        foreach (self::policies() as $policies) {
            foreach ([false, true] as $delegated) {
                foreach ([false, true] as $missingTo) {
                    yield $policies.' delegated '.(int) $delegated.' missing To '.(int) $missingTo => [$policies, $delegated, $missingTo];
                }
            }
        }
    }

    /** @dataProvider nestedMessages */
    public function testSharedPluginInstancesRestoreEachInFlightMessage(string $policies, bool $outerFirst, bool $missingTo): void
    {
        $outer      = $this->message('outer', $missingTo);
        $inner      = $this->message('inner', !$missingTo);
        $outerState = $this->recipients($outer);
        $innerState = $this->recipients($inner);
        $source     = new Swift_NullTransport();
        $outerEvent = new Swift_Events_SendEvent($source, $outer);
        $innerEvent = new Swift_Events_SendEvent($source, $inner);
        $plugins    = $this->plugins($policies);
        foreach ([$outerEvent, $innerEvent] as $event) {
            foreach ($plugins as $plugin) {
                $plugin->beforeSendPerformed($event);
            }
        }
        foreach ($outerFirst ? [$outerEvent, $innerEvent] : [$innerEvent, $outerEvent] as $event) {
            foreach ($plugins as $plugin) {
                $plugin->sendPerformed($event);
            }
        }
        $this->assertSame($outerState, $this->recipients($outer));
        $this->assertSame($innerState, $this->recipients($inner));
    }

    public static function nestedMessages(): iterable
    {
        foreach (self::policies() as $policies) {
            foreach ([false, true] as $outerFirst) {
                foreach ([false, true] as $missingTo) {
                    yield $policies.' outer first '.(int) $outerFirst.' missing To '.(int) $missingTo => [$policies, $outerFirst, $missingTo];
                }
            }
        }
    }

    /** @dataProvider policyKinds */
    public function testCompletionWithoutPreparationLeavesMessageUntouched(string $policies): void
    {
        $message = $this->message('actual', true);
        $state   = $this->recipients($message);
        $event   = new Swift_Events_SendEvent(new Swift_NullTransport(), $message);
        foreach ($this->plugins($policies) as $plugin) {
            $plugin->sendPerformed($event);
        }
        $this->assertSame($state, $this->recipients($message));
    }

    /** @dataProvider policyKinds */
    public function testCustomDispatcherKeepsPolicyAndMessageRestorationDuringActualHttpSend(string $policies): void
    {
        $payload = null;
        $http    = new Client(['handler' => HandlerStack::create(new MockHandler([static function ($request) use (&$payload): Response {
            $payload = \json_decode((string) $request->getBody(), true, flags: \JSON_THROW_ON_ERROR);

            return new Response(200, [], '{"id":"accepted"}');
        }]))]);
        $decoy      = $this->message('unrelated', false);
        $dispatcher = new Swift_CompatibilityMessageDispatcher($decoy);
        $transport  = new Swift_Transport_Api_ResendTransport('fixture-key', $http, $dispatcher);
        foreach ($this->plugins($policies) as $plugin) {
            $transport->registerPlugin($plugin);
        }
        $message  = $this->message('actual', false);
        $original = $this->recipients($message);
        $this->assertGreaterThan(0, $transport->send($message));
        $this->assertIsArray($payload);
        $this->assertStringContainsString('sink@allowed.test', \json_encode($payload));
        $this->assertStringNotContainsString('@outside.test', \json_encode($payload));
        $this->assertSame($original, $this->recipients($message));
    }

    public static function policyKinds(): iterable
    {
        foreach (self::policies() as $policies) {
            yield [$policies];
        }
    }

    private static function policies(): array
    {
        return ['redirect', 'allowlist', 'redirect then allowlist', 'allowlist then redirect'];
    }

    private function plugins(string $policies): array
    {
        $redirect  = new Swift_Plugins_RedirectingPlugin('sink@allowed.test', ['/@allowed\\.test$/']);
        $allowlist = new Swift_Plugins_AllowlistPlugin(['*@allowed.test'], 'sink@allowed.test');

        return match ($policies) {
            'redirect' => [$redirect], 'allowlist' => [$allowlist], 'redirect then allowlist' => [$redirect, $allowlist], 'allowlist then redirect' => [$allowlist, $redirect],
        };
    }

    private function message(string $prefix, bool $missingTo): Swift_Message
    {
        $message = (new Swift_Message('Compatibility'))->setFrom('sender@allowed.test')->setBody('Body')->setCc([$prefix.'-copy@outside.test' => 'Copy'])->setBcc([$prefix.'-blind@outside.test' => 'Blind']);
        if (!$missingTo) {
            $message->setTo([$prefix.'@outside.test' => 'To', 'kept@allowed.test' => 'Kept']);
        }

        return $message;
    }

    private function recipients(Swift_Message $message): array
    {
        return [$message->getTo(), $message->getCc(), $message->getBcc()];
    }
}

class Swift_CompatibilityMessageEvent extends Swift_Events_SendEvent
{
    public function __construct(Swift_Transport $source, Swift_Mime_SimpleMessage $baseMessage, private Swift_Mime_SimpleMessage $effectiveMessage)
    {
        parent::__construct($source, $baseMessage);
    }

    public function getMessage()
    {
        return $this->effectiveMessage;
    }
}

class Swift_CompatibilityMessageDispatcher extends Swift_Events_SimpleEventDispatcher
{
    private array $sendListeners = [];

    public function bindEventListener(Swift_Events_EventListener $listener)
    {
        parent::bindEventListener($listener);
        if ($listener instanceof Swift_Events_SendListener && !\in_array($listener, $this->sendListeners, true)) {
            $this->sendListeners[] = $listener;
        }
    }

    public function dispatchEvent(Swift_Events_Event $event, $target)
    {
        if (!$event instanceof Swift_CompatibilityMessageEvent) {
            parent::dispatchEvent($event, $target);

            return;
        }
        foreach ($this->sendListeners as $listener) {
            if ($event->bubbleCancelled()) {
                break;
            }
            $listener->$target($event);
        }
    }

    public function __construct(private Swift_Message $decoy)
    {
        parent::__construct();
    }

    public function createSendEvent(Swift_Transport $source, Swift_Mime_SimpleMessage $message)
    {
        return new Swift_CompatibilityMessageEvent($source, $this->decoy, $message);
    }
}
