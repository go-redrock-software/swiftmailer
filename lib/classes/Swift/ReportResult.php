<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Enum representing the result of a per-recipient report.
 *
 * Mirrors the RESULT_PASS / RESULT_FAIL constants on Swift_Plugins_Reporter.
 */
enum Swift_ReportResult: int
{
    case PASS = 0x01;
    case FAIL = 0x10;
}
