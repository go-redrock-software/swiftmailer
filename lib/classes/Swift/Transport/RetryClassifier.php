<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Strategy interface for classifying whether a transport exception is retryable.
 */
interface Swift_Transport_RetryClassifier
{
    /**
     * Determine whether the given exception represents a transient failure
     * that should be retried.
     */
    public function isRetryable(Swift_TransportException $e): bool;
}
