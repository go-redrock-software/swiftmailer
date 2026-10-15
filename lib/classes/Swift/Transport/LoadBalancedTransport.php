<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Redundantly and rotationally uses several Transports when sending.
 *
 * @author Chris Corbyn
 */
class Swift_Transport_LoadBalancedTransport implements Swift_Transport
{
    /**
     * Transports which are deemed useless.
     *
     * @var Swift_Transport[]
     */
    private $deadTransports = [];

    /** @var SplObjectStorage<Swift_Transport, float> */
    private SplObjectStorage $failedSince;

    private int $retryPeriod = 60;

    /**
     * The Transports which are used in rotation.
     *
     * @var Swift_Transport[]
     */
    protected $transports = [];

    /** Distinguish a caller's pool replacement even when its entries compare equal. */
    protected int $transportConfigurationVersion = 0;

    /**
     * The Transport used in the last successful send operation.
     *
     * @var Swift_Transport
     */
    protected $lastUsedTransport;

    /** Failed-backend cooldown in seconds; this constructor may also be called explicitly. */
    public function __construct(int $retryPeriod = 60)
    {
        $this->failedSince ??= new SplObjectStorage();
        $this->setRetryPeriod($retryPeriod);
    }

    /** Failed-backend cooldown in seconds. */
    public function getRetryPeriod(): int
    {
        return $this->retryPeriod;
    }

    /** Apply a non-negative cooldown to the original failure times, without sleeping. */
    public function setRetryPeriod(int $retryPeriod): static
    {
        if ($retryPeriod < 0) {
            throw new InvalidArgumentException('retryPeriod must be a non-negative number of seconds.');
        }
        $this->retryPeriod = $retryPeriod;

        return $this;
    }

    /** Monotonic seconds; overrides must provide the same clock throughout the pool's lifetime. */
    protected function getCurrentTime(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /** Recover only at operation entry, so a zero period cannot create an inner retry loop. */
    protected function restoreDueTransports(): void
    {
        if (!$this->deadTransports) {
            return;
        }
        $now       = $this->getCurrentTime();
        $waiting   = [];
        $recovered = new SplObjectStorage();
        foreach ($this->deadTransports as $transport) {
            if ($now - $this->failedSince->offsetGet($transport) >= $this->retryPeriod) {
                $this->transports[] = $transport;
                $recovered->offsetSet($transport, true);
            } else {
                $waiting[] = $transport;
            }
        }
        $this->deadTransports = $waiting;
        foreach ($recovered as $transport) {
            $this->failedSince->offsetUnset($transport);
        }
    }

    /**
     * Set $transports to delegate to.
     *
     * @param Swift_Transport[] $transports
     */
    public function setTransports(array $transports)
    {
        $this->transports     = $transports;
        $this->deadTransports = [];
        $this->failedSince    = new SplObjectStorage();
        ++$this->transportConfigurationVersion;
    }

    /**
     * Get $transports to delegate to.
     *
     * @return Swift_Transport[]
     */
    public function getTransports()
    {
        return \array_merge($this->transports, $this->deadTransports);
    }

    /**
     * Get the Transport used in the last successful send operation.
     *
     * @return Swift_Transport
     */
    public function getLastUsedTransport()
    {
        return $this->lastUsedTransport;
    }

    /**
     * Test if this Transport mechanism has started.
     *
     * @return bool
     */
    #[Override]
    public function isStarted()
    {
        return \count($this->transports) > 0;
    }

    /**
     * Start this Transport mechanism.
     */
    #[Override]
    public function start()
    {
        $this->restoreDueTransports();
    }

    /**
     * Stop this Transport mechanism.
     */
    #[Override]
    public function stop()
    {
        foreach ($this->transports as $transport) {
            $transport->stop();
        }
    }

    #[Override]
    public function ping()
    {
        $this->restoreDueTransports();
        $maxTransports = \count($this->transports);
        for ($i = 0; $i < $maxTransports && $transport = $this->getNextTransport(); ++$i) {
            $configurationVersion = $this->transportConfigurationVersion;
            if (!$transport->ping() && $configurationVersion === $this->transportConfigurationVersion) {
                $this->killCurrentTransport();
            }
        }

        return \count($this->transports) > 0;
    }

    /**
     * Send the given Message.
     *
     * Recipient/sender data will be retrieved from the Message API.
     * The return value is the number of recipients who were accepted for delivery.
     *
     * @param string[] $failedRecipients An array of failures by-reference
     *
     * @return int
     */
    #[Override]
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null)
    {
        $this->lastUsedTransport = null;
        $this->restoreDueTransports();
        $maxTransports = \count($this->transports);
        $sent          = 0;

        for (
            $i        = 0; $i < $maxTransports
        && $transport = $this->getNextTransport(); ++$i
        ) {
            $configurationVersion = $this->transportConfigurationVersion;
            try {
                if (!$transport->isStarted()) {
                    $transport->start();
                }
                if ($sent = $transport->send($message, $failedRecipients, $envelope)) {
                    $this->lastUsedTransport = $transport;
                    break;
                }
            } catch (Swift_TransportException $e) {
                if (Swift_Transport_RetryPolicy::isTerminalFailure($e)) {
                    throw $e;
                }
                if ($configurationVersion !== $this->transportConfigurationVersion) {
                    continue;
                }
                $this->killCurrentTransport();
            }
        }

        if (0 == \count($this->transports)) {
            throw new Swift_TransportException('All Transports in LoadBalancedTransport failed, or no Transports available');
        }

        return $sent;
    }

    /**
     * Register a plugin.
     */
    #[Override]
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $registered = new SplObjectStorage();
        foreach (\array_merge($this->transports, $this->deadTransports) as $transport) {
            if ($registered->offsetExists($transport)) {
                continue;
            }
            $registered->offsetSet($transport, true);
            $transport->registerPlugin($plugin);
        }
    }

    /**
     * Rotates the transport list around and returns the first instance.
     *
     * @return Swift_Transport|null
     */
    protected function getNextTransport()
    {
        if ($next = \array_shift($this->transports)) {
            $this->transports[] = $next;
        }

        return $next;
    }

    /**
     * Tag the currently used (top of stack) transport as dead/useless.
     */
    protected function killCurrentTransport()
    {
        if (!$this->transports) {
            return;
        }
        $failedAt  = $this->getCurrentTime();
        $original  = $this->transports;
        $transport = \array_pop($this->transports);
        // Every weighted slot for this backend shares its cooldown.
        $retired              = \array_values(\array_filter($original, static fn ($candidate): bool => $candidate === $transport));
        $this->transports     = \array_filter($this->transports, static fn ($candidate): bool => $candidate !== $transport);
        $remaining            = $this->transports;
        $configurationVersion = $this->transportConfigurationVersion;
        try {
            $transport->stop();
        } catch (Throwable $e) {
            if (Swift_Transport_RetryPolicy::isTerminalFailure($e)) {
                if ($configurationVersion === $this->transportConfigurationVersion) {
                    // Retain changes from callbacks operating on the same pool,
                    // while a replacement configuration owns its own entries.
                    $this->transports = $this->transports === $remaining ? $original : \array_merge($this->transports, $retired);
                }
                throw $e;
            }
            @\trigger_error(\sprintf(
                'Swiftmailer: LoadBalancedTransport error from %s: %s',
                \get_class($transport),
                $e->getMessage(),
            ), \E_USER_WARNING);
        }
        if ($configurationVersion === $this->transportConfigurationVersion) {
            $this->deadTransports = \array_merge($this->deadTransports, $retired);
            $this->failedSince->offsetSet($transport, $failedAt);
        }
    }
}
