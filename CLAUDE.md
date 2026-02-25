# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Swiftmailer — a component-based PHP mailing library, now maintained by Redrock Software Corporation. The fork integrates features from Symfony Mailer back into Swiftmailer for legacy/enterprise applications that cannot migrate. Uses underscore-prefixed class names (e.g. `Swift_Message`) with PSR-0 autoloading, not PSR-4 namespaces.

## Commands

| Action | Command |
|-|-|
| Install dependencies | `composer install` |
| Run all tests | `vendor/bin/simple-phpunit --verbose` |
| Run a single test file | `vendor/bin/simple-phpunit tests/unit/Swift/SomeTest.php` |
| Run a specific test suite | `vendor/bin/simple-phpunit --testsuite="SwiftMailer unit tests"` |
| Fix code style | `composer php-cs-fixer` |

Test suites defined in `phpunit.xml.dist`: unit (`tests/unit`), acceptance (`tests/acceptance`), bug (`tests/bug`), smoke (`tests/smoke`).

## Architecture

### Core Flow

`Swift_Mailer` orchestrates sending. It takes a `Swift_Transport` and a `Swift_Message`, dispatches events, and delegates delivery to the transport.

### Transport Layer

The transport hierarchy is the most important architectural concept:

- **`Swift_Transport`** — interface all transports implement (`start`, `stop`, `send`, `ping`)
- **`Swift_Transport_AbstractSmtpTransport`** — base for SMTP-based transports (ESMTP, Sendmail)
- **`Swift_Transport_AbstractApiTransport`** — base for HTTP API-based transports; handles event dispatching, start/stop lifecycle, and delegates actual sending to subclasses
- **`Swift_Transport_Api/`** — 17 concrete API transports (Sendgrid, Mailgun, PostMark, Brevo, AWS SES, Azure, Google, Microsoft Graph, Resend, etc.)
- **Meta-transports**: `FailoverTransport` and `LoadBalancedTransport` wrap multiple transports for redundancy

### DSN System

`Swift_Dsn` parses DSN strings into transport instances. The `$transport_class_map` maps scheme names (e.g. `gmail+api`, `microsoft-graph`) to transport classes. Many transports are still TODO in this map — check it when adding new transports.

### MIME / Message

`Swift_Message` extends `Swift_Mime_SimpleMessage`. Message construction uses a builder pattern: `Swift_Message::newInstance()`. Attachments, embedded files, and MIME parts are added via `attach()` / `embed()`.

### Event System

`Swift_Events_EventDispatcher` fires events (`SendEvent`, `ResponseEvent`, `TransportChangeEvent`) consumed by plugins. Plugins (e.g. `AntiFloodPlugin`, `ThrottlerPlugin`, `LoggerPlugin`) hook into events for cross-cutting concerns.

### Key Conventions

- Classes use `Swift_` prefix with underscores (PSR-0), mapped from `lib/classes/`
- Tests mirror the source tree under `tests/unit/Swift/` using the same underscore naming
- Autoloading entry point: `lib/swift_required.php`
- Dependencies managed via `Swift_DependencyContainer` (internal service locator)
- PHP 8.1+ required; uses typed properties and return types throughout
