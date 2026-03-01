<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Enum representing the result of a send operation.
 *
 * Replaces the RESULT_* integer constants previously defined on
 * Swift_Events_SendEvent. The values are identical to the old constants,
 * so bitmask operations continue to work with ->value.
 */
enum Swift_SendResult: int
{
    /** Sending has yet to occur */
    case PENDING   = 0x0001;

    /** Email is spooled, ready to be sent */
    case SPOOLED   = 0x0011;

    /** Sending was successful */
    case SUCCESS   = 0x0010;

    /** Sending worked, but there were some failures */
    case TENTATIVE = 0x0100;

    /** Sending failed */
    case FAILED    = 0x1000;
}
