# CHANGES File Overhaul -- Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Update the CHANGES file to document all additions made by the Redrock Software Corporation fork, replacing the end-of-maintenance notice with proper fork versioning and categorized entries.

**Architecture:** The CHANGES file uses a flat-text format with version headers (`X.Y.Z (YYYY-MM-DD)`), dashed underlines, and bulleted entries. The fork has ~95 commits spanning new transports, a webhook system, event system additions, security hardening, plugins, DSN enhancements, and infrastructure modernization. These will be consolidated into grouped entries under a single new version header (`6.4.0`) since `composer.json` still uses `6.3-dev` branch alias and `Swift::VERSION` is `6.3.0` -- meaning no version bump has been published yet. The `6.4.0` version represents the first Redrock release.

**Tech Stack:** Git, text editing. No code changes.

---

## Step 1: Read and categorize all fork commits

Read the full git log from `ac81907e` (Redrock ownership) through `HEAD` and categorize every commit. Do NOT re-read files already examined -- use the categorization below.

### Commit Categorization

**New Features -- API Transports (17 transports total):**
- `65f8b717` / `d14234c2` -- Amazon SES API and HTTP transports
- `f2b72c6e` -- Gmail transport
- `c66f6ab0` -- Google Client, Microsoft Client
- `c1e73645` -- Microsoft Graph transport
- `6e39ba99` -- AbstractHttpApiTransport base class
- `921c638f` -- Postmark HTTP API transport
- `b73f5a84` -- SendGrid HTTP API transport
- `ced264f4` -- Brevo HTTP API transport
- `09e2f5cc` -- Resend HTTP API transport
- `b9ea17d7` -- MailPace HTTP API transport
- `a0c4f158` -- MailerSend HTTP API transport
- `dc7f44f0` -- Mailgun HTTP API transport
- `69514de2` -- Mailjet HTTP API transport
- `5c6d63d7` -- Scaleway HTTP API transport
- `02825a62` -- Mandrill/MailChimp HTTP API transport
- `c2b8b80b` -- Azure Communication Services HTTP API transport
- `cd8772de` -- AhaSend, Mailomat, Mailtrap, Postal, Sweego HTTP API transports

**New Features -- DSN System:**
- `8c67b140` / `e7015bba` -- DSN objects, parse functions, parser library
- `2c6de3fe` -- Register all transport schemes in DSN class
- `f0d40e93` -- Add null, smtp, smtp+tls, smtp+ssl schemes to DSN map
- `f74e887e` -- DsnTransportFactory with failover/roundrobin DSN support
- `610d638f` -- smtputf8 DSN parameter support

**New Features -- Webhook System:**
- `91ff3814` -- Swift_Webhook_Event value object
- `5ac87c95` -- PayloadConverterInterface and AbstractPayloadConverter with HMAC helpers
- `92ce000a` -- Swift_Webhook_RequestHandler with signature verification and JSON decoding
- `68da7ef9` -- SendGrid webhook payload converter
- `f7755622` -- Mailgun webhook payload converter
- `17479a83` -- Postmark webhook payload converter
- `1a5b59dd` -- Amazon SES webhook payload converter (via SNS)

**New Features -- Event System:**
- `91133900` -- Swift_SentMessage value object
- `c3eda15a` -- SentMessageEvent and FailedMessageEvent with listener interfaces
- `e6e14159` -- Dispatch SentMessageEvent and FailedMessageEvent from HTTP API transports
- `ad4dd79a` -- SentMessagePlugin for convenient SentMessage access
- `5c03a725` -- reject() method on SendEvent for pre-send message suppression
- `60d0cd6d` -- Dispatch sendPerformed with RESULT_FAILED on rejected messages

**New Features -- Plugins:**
- `527474ca` -- CssInlinerPlugin for automatic CSS inlining in HTML emails
- `d6dab7b0` / `e8f0c6fc` -- AllowlistPlugin for restricting delivery to approved recipients, with redirect mode and X-Original-To header

**New Features -- SMTP/Transport Improvements:**
- `02494970` / `2ed2816f` / `60aeb09b` -- AutoAddressEncoder for SMTPUTF8 auto-detection
- `7d31fb86` -- TLS/STARTTLS constants
- `a2bde539` -- TLS 1.3 support, disable TLS 1.0 and 1.1
- `64d26976` -- DES-ECB support
- `c9e84cbf` -- Tag and metadata extraction helpers in AbstractHttpApiTransport

**Improvements -- Tag/Metadata Support:**
- `8d047884` -- Tag/metadata for SendGrid, Mailgun, PostMark
- `d2ca63d2` -- Tag/metadata for Brevo, Resend, MailPace
- `87aee9f9` -- Tag/metadata for MailerSend, Mailjet, Mandrill
- `d3291c88` -- Tag/metadata for Amazon SES

**Improvements -- Logging:**
- `b83f7b92` -- LoggerPlugin logs SentMessage and FailedMessage events
- `2a374aab` -- LoggerPlugin logs pre-send rejections with reason

**Security:**
- `49d0f316` -- SensitiveParameter attribute on API key constructor params
- `18eaa32c` -- Prevent API key leakage via Guzzle exception chain

**Bug Fixes:**
- `bd725b50` -- Resolve AmazonSes transport naming conflict
- `77fc4d2e` -- Correct MailGun transport class name to PSR-0 convention
- `b75b3c05` -- StreamFilters only apply needed ones (no duplicates)
- `c76c83cc` / `2e6f94e8` -- SSL connection fixes
- `a6f88be6` -- Handle strings and arrays when writing bytes to Streams
- `bf7a9b67` -- Fix broken tests
- `0917618c` -- Remove broken OpenDKIM components
- `4135a127` -- Fix deprecated call_user_func_array

**Internal / Infrastructure:**
- `ac81907e` -- Redrock assumes ownership
- `428fa06a` -- Update to PHPUnit 9.6
- `7e80aac5` / `376f24d1` / `ba53562e` / etc. -- PHP-CS-Fixer modernization
- `3da11647` -- Code quality standards applied
- `4c4d555a` -- Nullsafe operators, interface type hints
- `83b55240` -- DKIM Signer typehint adjustments
- `8615842b` -- Code formatting improvements
- Various test additions and CI fixes

---

## Step 2: Draft the new CHANGES entries

Write the new version block to be inserted at the top of the CHANGES file (after the `Changelog` / `=========` header), replacing the deprecation notice.

The new entry should use this exact format:

```
6.4.0 (2026-XX-XX)
------------------

 Maintained by Redrock Software Corporation. This fork integrates features
 from Symfony Mailer back into Swiftmailer for legacy/enterprise applications.

 New Features:

 * Added 17 HTTP API transports: Amazon SES, SendGrid, Postmark, Brevo,
   Mailgun, Mailjet, MailerSend, MailPace, Resend, Scaleway,
   Mandrill/MailChimp, Azure Communication Services, AhaSend, Mailomat,
   Mailtrap, Postal, and Sweego
 * Added AbstractHttpApiTransport base class for building HTTP API transports
 * Added Gmail transport via Google API
 * Added Microsoft Graph transport
 * Added DSN transport factory with support for failover and round-robin
   multi-transport configurations (e.g. `failover(sendgrid+api://... mailgun+api://...)`)
 * Added DSN parsing system with scheme-to-transport class mapping for all
   built-in transports including null, smtp, smtp+tls, and smtp+ssl schemes
 * Added webhook system (Swift_Webhook) with request handler, signature
   verification, and payload converters for SendGrid, Mailgun, Postmark,
   and Amazon SES (via SNS)
 * Added SentMessage/FailedMessage event system with SentMessageEvent,
   FailedMessageEvent, listener interfaces, and SentMessagePlugin
 * Added reject() method on SendEvent for pre-send message suppression
   with RESULT_FAILED dispatching across all transports
 * Added CssInlinerPlugin for automatic CSS inlining in HTML emails
   (requires tijsverkoyen/css-to-inline-styles)
 * Added AllowlistPlugin for restricting delivery to approved recipients
   with redirect mode and X-Original-To header preservation
 * Added AutoAddressEncoder that switches between IDN and UTF-8 encoding
   based on SMTPUTF8 server capability, with smtputf8 DSN parameter control
 * Added tag and metadata support to SendGrid, Mailgun, PostMark, Brevo,
   Resend, MailPace, MailerSend, Mailjet, Mandrill, and Amazon SES transports
 * Added Swift_SentMessage value object for tracking sent message results

 Security:

 * Added #[\SensitiveParameter] attribute to API key constructor parameters
   to prevent credential exposure in stack traces
 * Prevented API key leakage through Guzzle exception chain by wrapping
   HTTP client exceptions

 Improvements:

 * Updated LoggerPlugin to log SentMessage, FailedMessage, and pre-send
   rejection events with reason
 * Added TLS 1.3 support; disabled TLS 1.0 and 1.1 by default
 * Created TLS/STARTTLS constants for transport configuration
 * Added DES-ECB encryption support
 * Modernized codebase with nullsafe operators and interface type hints
 * Bumped minimum PHP version to 8.1
 * Updated to PHPUnit 9.6 with Symfony PHPUnit Bridge
 * Applied PHP-CS-Fixer code style across entire codebase
 * Removed broken OpenDKIM components
 * Fixed DKIM Signer type hints

 Bug Fixes:

 * Fixed AmazonSes transport naming conflict and class/file alignment
 * Fixed MailGun transport class name to match PSR-0 convention
 * Fixed StreamFilters to only apply needed filters (no duplicates)
 * Fixed SSL connection handling
 * Fixed string/array handling when writing bytes to Streams
 * Fixed deprecated call_user_func_array usage
```

Use `2026-XX-XX` as a placeholder date since this is pre-release; the implementer should replace with the actual release date or use the current date if releasing immediately.

---

## Step 3: Remove the "will stop being maintained" notice

Delete the following block from the CHANGES file:

```
**Swiftmailer will stop being maintained at the end of November 2021.**

Please, move to Symfony Mailer at your earliest convenience.
Symfony Mailer is the next evolution of Swiftmailer.
It provides the same features with support for modern PHP code and support for third-party providers.
See https://symfony.com/doc/current/mailer.html for more information.
```

This notice is no longer accurate since Redrock Software Corporation has assumed maintenance.

---

## Step 4: Add a note about the fork maintainership

Insert a brief maintainership notice at the top of the CHANGES file, between the `Changelog` / `=========` header and the first version entry:

```
Maintained by Redrock Software Corporation since 2022. This fork continues
Swiftmailer development for legacy and enterprise applications that cannot
migrate to Symfony Mailer.
```

This replaces the old deprecation notice with an affirmative statement about continued maintenance.

---

## Step 5: Update Swift::VERSION constant

Update `lib/classes/Swift.php` to change `VERSION` from `'6.3.0'` to `'6.4.0'` to match the new CHANGES entry.

---

## Step 6: Commit

Stage only `CHANGES` and `lib/classes/Swift.php`. Commit with message:

```
docs: overhaul CHANGES file to document Redrock fork additions

Add 6.4.0 version entry covering all new features, security fixes,
improvements, and bug fixes added since the Redrock Software Corporation
fork. Remove the end-of-maintenance notice and add fork maintainership
note. Bump Swift::VERSION to 6.4.0.
```

---

## Verification

After completing all steps, verify:
1. `CHANGES` file no longer contains the "will stop being maintained" notice
2. `CHANGES` file has a `6.4.0` version entry with all categorized changes
3. The maintainership note is present at the top
4. `Swift::VERSION` in `lib/classes/Swift.php` reads `'6.4.0'`
5. The existing `6.3.0` and older entries remain unchanged
6. Run `composer php-cs-fixer` to ensure no formatting issues in `lib/classes/Swift.php`
