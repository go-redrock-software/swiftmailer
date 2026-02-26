<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Thrown when a webhook signature fails verification.
 */
class Swift_Webhook_SignatureVerificationException extends \RuntimeException
{
    public function __construct(string $providerName)
    {
        parent::__construct(
            \sprintf('Webhook signature verification failed for provider "%s".', $providerName)
        );
    }
}
