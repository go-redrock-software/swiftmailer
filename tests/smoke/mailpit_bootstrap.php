<?php

/*
 * Bootstrap for Mailpit-based smoke tests.
 *
 * Configures a Swift SMTP transport pointing at a local Mailpit instance.
 * Defaults match docker-compose.test.yml (localhost:1025 SMTP, localhost:8025 API).
 *
 * Override with environment variables:
 *   MAILPIT_SMTP_HOST, MAILPIT_SMTP_PORT, MAILPIT_API_URL
 */

require_once __DIR__.'/../../vendor/autoload.php';

\define('MAILPIT_SMTP_HOST', \getenv('MAILPIT_SMTP_HOST') ?: 'localhost');
\define('MAILPIT_SMTP_PORT', (int) (\getenv('MAILPIT_SMTP_PORT') ?: 1025));
\define('MAILPIT_API_URL', \getenv('MAILPIT_API_URL') ?: 'http://localhost:8025');
