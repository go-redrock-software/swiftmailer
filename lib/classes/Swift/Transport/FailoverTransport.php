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

    // needed as __construct is called from elsewhere explicitly
    public function __construct()
    {
        parent::__construct();
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
        $maxTransports = \count($this->transports);
        for (
            $i        = 0; $i < $maxTransports
        && $transport = $this->getNextTransport(); ++$i
        ) {
            if ($transport->ping()) {
                return true;
            }
            $this->killCurrentTransport();
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
        $maxTransports           = \count($this->transports);
        $sent                    = 0;
        $this->lastUsedTransport = null;

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
        }

        return $this->currentTransport;
    }

    protected function killCurrentTransport()
    {
        $this->currentTransport = null;
        parent::killCurrentTransport();
    }
}
