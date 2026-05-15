<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class Swift_Transport_UrlValidator
{
    private const BLOCKED_HOSTS = ['127.0.0.1', '0.0.0.0', 'localhost', '::1', '[::1]'];

    public static function validate(string $url): void
    {
        $parsed = \parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        if ('https' !== $scheme) {
            throw new \InvalidArgumentException('API endpoint must use HTTPS, got: '.$scheme);
        }

        $host = $parsed['host'] ?? '';
        $normalizedHost = \trim($host, '[]');
        if (\in_array($normalizedHost, self::BLOCKED_HOSTS, true)) {
            throw new \InvalidArgumentException('API endpoint must not point to localhost or loopback.');
        }

        if (\filter_var($normalizedHost, \FILTER_VALIDATE_IP)) {
            if (\filter_var($normalizedHost, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('API endpoint resolves to a private/reserved IP address.');
            }
        } else {
            $ip = @\gethostbyname($normalizedHost);
            if ($ip !== $normalizedHost && \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('API endpoint resolves to a private/reserved IP address.');
            }
        }
    }
}
