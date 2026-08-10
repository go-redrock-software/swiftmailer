# SwiftMailer Documentation

Maintained by [Redrock Software Corporation](https://www.go-redrock.com/). This is the complete documentation for the fork -- there is no external docs site, and none is needed.

## Getting Started

| Guide | Contents |
|-|-|
| [introduction.md](introduction.md) | Requirements, installation, first email |
| [upgrading.md](upgrading.md) | Migrating from stock SwiftMailer 6.x |

## Using the Library

| Guide | Contents |
|-|-|
| [messages.md](messages.md) | Building messages: bodies, attachments, embedded files, MIME parts |
| [headers.md](headers.md) | Header types and the header set API |
| [sending.md](sending.md) | Transports (SMTP, sendmail, meta), `send()`, envelopes, spooling, retries, message limits |
| [plugins.md](plugins.md) | All 14 bundled plugins and how to write your own |
| [signers.md](signers.md) | DKIM (RSA + Ed25519), DomainKeys, S/MIME signing and encryption |
| [japanese.md](japanese.md) | Sending in Japanese (ISO-2022-JP) |

## Transports & Configuration

| Guide | Contents |
|-|-|
| [api-transports.md](api-transports.md) | All 21 HTTP API transports: constructors, auth, tags/metadata mapping |
| [microsoft-graph.md](microsoft-graph.md) | Microsoft Graph: auth modes, large attachments, calendar invite conversion |
| [dsn.md](dsn.md) | DSN connection strings: every scheme, query parameters, wrappers |
| [cli.md](cli.md) | `bin/swiftmailer-test` -- validate a transport from the command line |

## Integration & Operations

| Guide | Contents |
|-|-|
| [events.md](events.md) | Event lifecycle, listener interfaces, dispatch points |
| [webhooks.md](webhooks.md) | Inbound delivery/engagement webhooks from 14 providers, signature verification |
| [security.md](security.md) | What is hardened by default and what is opt-in |

## Internals & Contributing

| Guide | Contents |
|-|-|
| [architecture.md](architecture.md) | How the codebase fits together: DI container, transport hierarchy, MIME pipeline, testing |
| [../CONTRIBUTING.md](../CONTRIBUTING.md) | Dev setup, tests, code style, static analysis, CI |
| [../docs/SYMFONY_MAILER_PARITY.md](../docs/SYMFONY_MAILER_PARITY.md) | Feature mapping against Symfony Mailer |

Historical RFC reference material lives in [notes/](notes/).
