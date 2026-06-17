<?php

/*
 * Bootstrap for the Mailtrap sandbox live-smoke test.
 *
 * The sandbox ("Email Testing") captures messages in an inbox WITHOUT delivering
 * them, so this exercises the real Mailtrap send API end-to-end with no risk of
 * real email going out.
 *
 * Required to run (otherwise the test is skipped):
 *   MAILTRAP_SANDBOX_TOKEN  Sandbox sending token (Email Testing > your inbox > API)
 *   MAILTRAP_INBOX_ID       Numeric sandbox inbox id
 *
 * Optional read-back verification (asserts the message actually landed):
 *   MAILTRAP_ACCOUNT_ID     Mailtrap account id (enables the read-back step)
 *   MAILTRAP_API_TOKEN      API token for reading (defaults to the sandbox token)
 *   MAILTRAP_API_URL        Read API base; default https://mailtrap.io/api
 *                           (some accounts use https://api.mailtrap.io -- override if so)
 */

require_once __DIR__.'/../../vendor/autoload.php';

\define('MAILTRAP_SANDBOX_TOKEN', \getenv('MAILTRAP_SANDBOX_TOKEN') ?: '');
\define('MAILTRAP_INBOX_ID', \getenv('MAILTRAP_INBOX_ID') ?: '');
\define('MAILTRAP_ACCOUNT_ID', \getenv('MAILTRAP_ACCOUNT_ID') ?: '');
\define('MAILTRAP_API_TOKEN', \getenv('MAILTRAP_API_TOKEN') ?: (\getenv('MAILTRAP_SANDBOX_TOKEN') ?: ''));
\define('MAILTRAP_API_URL', \getenv('MAILTRAP_API_URL') ?: 'https://mailtrap.io/api');
