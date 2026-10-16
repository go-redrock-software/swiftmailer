<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Redundantly and rotationally uses several Transport implementations when sending.
 *
 * @author Chris Corbyn
 */
class Swift_LoadBalancedTransport extends Swift_Transport_LoadBalancedTransport
{
    /**
     * Creates a new LoadBalancedTransport with $transports.
     *
     * @param Swift_Transport[] $transports
     * @param int               $retryPeriod Failed-backend cooldown in seconds; zero permits recovery on the next operation
     */
    public function __construct(#[SensitiveParameter] $transports = [], int $retryPeriod = 60)
    {
        parent::__construct(...(\func_num_args() > 1 ? [$retryPeriod] : []));

        $this->setTransports($transports);
    }
}
