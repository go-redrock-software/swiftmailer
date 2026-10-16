<?php

/*
 * Copyright (c) 2026. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/** A send failure that must not be replayed, such as a policy or version conflict. */
class Swift_Transport_NonRetryableException extends Swift_TransportException
{
}
