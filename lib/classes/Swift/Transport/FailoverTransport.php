<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Contains a list of redundant Transports so when one fails, the next is used.
 *
 * @author Chris Corbyn
 */
class Swift_Transport_FailoverTransport extends Swift_Transport_LoadBalancedTransport
{
    /**
     * Registered transport currently used.
     */
    private ?Swift_Transport $currentTransport = null;

    /** Failed-backend cooldown in seconds; this constructor may also be called explicitly. */
    public function __construct(int $retryPeriod = 60)
    {
        parent::__construct($retryPeriod);
    }

    #[Override]
    public function setTransports(array $transports)
    {
        parent::setTransports($transports);
        $this->currentTransport = null;
    }

    #[Override]
    public function ping()
    {
        $this->restoreDueTransports();
        $maxTransports = \count($this->transports);
        for (
            $i        = 0; $i < $maxTransports
        && $transport = $this->getNextTransport(); ++$i
        ) {
            $configurationVersion = $this->transportConfigurationVersion;
            if ($transport->ping()) {
                return true;
            }
            if ($configurationVersion === $this->transportConfigurationVersion) {
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

                    return $sent;
                }
            } catch (Swift_TransportException $e) {
                if (Swift_Transport_RetryPolicy::isTerminalFailure($e)) {
                    throw $e;
                }
                if ($configurationVersion !== $this->transportConfigurationVersion) {
                    continue;
                }
                @\trigger_error(\sprintf(
                    'Swiftmailer: Failover from %s: %s',
                    \get_class($transport),
                    $e->getMessage(),
                ), \E_USER_WARNING);
                $this->killCurrentTransport();
            }
        }

        if (0 == \count($this->transports)) {
            throw new Swift_TransportException('All Transports in FailoverTransport failed, or no Transports available');
        }

        return $sent;
    }

    protected function getNextTransport()
    {
        if (!isset($this->currentTransport)) {
            $this->currentTransport = parent::getNextTransport();
        } elseif ($this->currentTransport !== \end($this->transports)) {
            // Restoration can append backends after the cached selection.
            // Keep the selected backend in the position the retirement hook owns.
            $key = \array_search($this->currentTransport, $this->transports, true);
            if (false === $key) {
                $this->currentTransport = parent::getNextTransport();
            } else {
                unset($this->transports[$key]);
                $this->transports[] = $this->currentTransport;
            }
        }

        return $this->currentTransport;
    }

    protected function killCurrentTransport()
    {
        $this->currentTransport = null;
        parent::killCurrentTransport();
    }
}
