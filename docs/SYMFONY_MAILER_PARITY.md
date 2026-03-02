# Symfony Mailer Parity Audit

Comparison of Symfony Mailer 7.x (up to 7.4 / 8.x) features against the current Swiftmailer fork maintained by Redrock Software Corporation.

Last updated: 2026-03-02

Sources: [Symfony Mailer 7.4 Docs](https://symfony.com/doc/7.4/mailer.html), [Symfony Mailer GitHub](https://github.com/symfony/mailer), [Symfony 8.1 Transport.php](https://github.com/symfony/symfony/blob/8.1/src/Symfony/Component/Mailer/Transport.php)

---

## Features Ported

### Transport Mapping

| Symfony Mailer Provider | Symfony DSN Scheme(s) | Swiftmailer Equivalent | Swiftmailer DSN Scheme | Status |
|-|-|-|-|-|
| **SMTP (ESMTP)** | `smtp://` | `Swift_Transport_EsmtpTransport` | `smtp`, `smtp+tls`, `smtp+ssl` | Ported |
| **Sendmail** | `sendmail://default` | `Swift_Transport_SendmailTransport` | (no DSN entry) | Ported (no DSN) |
| **Null** | `null://` | `Swift_Transport_NullTransport` | `null` | Ported |
| **Amazon SES (API)** | `ses+api://` | `Swift_Transport_Api_AmazonSesApiTransport` | `amazon+api` | Ported |
| **Amazon SES (HTTP)** | `ses+https://` | `Swift_Transport_Api_AmazonSesHttpTransport` | `amazon+http` | Ported |
| **Azure** | `azure+api://` | `Swift_Transport_Api_AzureTransport` | `azure` | Ported |
| **Brevo** | `brevo+api://`, `brevo+smtp://` | `Swift_Transport_Api_BrevoTransport` | `brevo` | Ported (API only) |
| **Google Gmail** | `gmail+smtp://` | `Swift_Transport_Api_GoogleTransport` | `gmail+api`, `gmail+smtp` | Ported |
| **Infobip** | `infobip+api://`, `infobip+smtp://` | `Swift_Transport_Api_InfoBipTransport` | `infobip` | Ported (API only) |
| **Mailchimp (Mandrill)** | `mandrill+api://`, `mandrill+https://`, `mandrill+smtp://` | `Swift_Transport_Api_MailChimpTransport` | `mailchimp` | Ported (API only) |
| **MailerSend** | `mailersend+api://`, `mailersend+smtp://` | `Swift_Transport_Api_MailerSendTransport` | `mailersend` | Ported (API only) |
| **Mailgun** | `mailgun+api://`, `mailgun+https://`, `mailgun+smtp://` | `Swift_Transport_Api_MailGunTransport` | `mailgun` | Ported (API only) |
| **Mailjet** | `mailjet+api://`, `mailjet+smtp://` | `Swift_Transport_Api_MailJetTransport` | `mailjet` | Ported (API only) |
| **Mailomat** | `mailomat+api://`, `mailomat+smtp://` | `Swift_Transport_Api_MailomatTransport` | `mailomat` | Ported (API only) |
| **MailPace** | `mailpace+api://` | `Swift_Transport_Api_MailPaceTransport` | `mailpace` | Ported |
| **Mailtrap** | `mailtrap+api://`, `mailtrap+smtp://` | `Swift_Transport_Api_MailtrapTransport` | `mailtrap` | Ported (API only) |
| **Microsoft Graph** | `microsoftgraph+api://` | `Swift_Transport_Api_MicrosoftGraphTransport` | `microsoft-graph` | Ported |
| **Postal** | `postal+api://` | `Swift_Transport_Api_PostalTransport` | `postal` | Ported |
| **Postmark** | `postmark+api://`, `postmark+smtp://` | `Swift_Transport_Api_PostMarkTransport` | `postmark` | Ported (API only) |
| **Resend** | `resend+api://`, `resend+smtp://` | `Swift_Transport_Api_ResendTransport` | `resend` | Ported (API only) |
| **Scaleway** | `scaleway+api://`, `scaleway+smtp://` | `Swift_Transport_Api_ScalewayTransport` | `scaleway` | Ported (API only) |
| **SendGrid** | `sendgrid+api://`, `sendgrid+smtp://` | `Swift_Transport_Api_SendgridTransport` | `sendgrid` | Ported (API only) |
| **Sweego** | `sweego+api://`, `sweego+smtp://` | `Swift_Transport_Api_SweegoTransport` | `sweego` | Ported (API only) |
| **AhaSend** | `ahasend+api://`, `ahasend+smtp://` | `Swift_Transport_Api_AhaSendTransport` | `ahasend` | Ported (API only) |

### Architectural Features Ported

| Symfony Mailer Feature | Swiftmailer Equivalent | Notes |
|-|-|-|
| Failover transport | `Swift_Transport_FailoverTransport` | Wraps multiple transports; tries next on failure |
| Round-robin / load-balanced transport | `Swift_Transport_LoadBalancedTransport` | Distributes load across transports |
| DKIM signing | `Swift_Signers_DKIMSigner` | Full DKIM support with body/header canonicalization |
| S/MIME signing | `Swift_Signers_SMimeSigner` | Certificate-based signing |
| DomainKeys signing | `Swift_Signers_DomainKeySigner` | Legacy signing (not in Symfony Mailer) |
| Event system: SendEvent | `Swift_Events_SendEvent` | Fired before sending |
| Event system: SentMessageEvent | `Swift_Events_SentMessageEvent` | Fired after successful send |
| Event system: FailedMessageEvent | `Swift_Events_FailedMessageEvent` | Fired on send failure |
| Event system: TransportChangeEvent | `Swift_Events_TransportChangeEvent` | Transport start/stop events |
| Event system: CommandEvent / ResponseEvent | `Swift_Events_CommandEvent` / `Swift_Events_ResponseEvent` | SMTP-level events |
| Event system: TransportExceptionEvent | `Swift_Events_TransportExceptionEvent` | Transport error events |
| Anti-flood plugin | `Swift_Plugins_AntiFloodPlugin` | Restart after N messages (like `restart_threshold`) |
| Throttler plugin | `Swift_Plugins_ThrottlerPlugin` | Rate limiting (like `max_per_second`) |
| Redirecting plugin | `Swift_Plugins_RedirectingPlugin` | Redirect all mail to specific address |
| Allowlist plugin | `Swift_Plugins_AllowlistPlugin` | Only allow mail to certain addresses |
| Decorator plugin | `Swift_Plugins_DecoratorPlugin` | Per-recipient message customization |
| Logger plugin | `Swift_Plugins_LoggerPlugin` | Transport logging |
| CSS inliner plugin | `Swift_Plugins_CssInlinerPlugin` | Inline CSS styles into HTML |
| Impersonate plugin | `Swift_Plugins_ImpersonatePlugin` | Set sender impersonation |
| Spool transport | `Swift_Transport_SpoolTransport` | Queue messages for deferred sending |
| Retry transport | `Swift_Transport_RetryTransport` | Automatic retry with configurable max retries |
| Null transport | `Swift_Transport_NullTransport` | No-op transport for testing |
| DSN parsing | `Swift_Dsn` + `Swift_Transport_DsnTransportFactory` | Parses DSN strings into transport instances |
| Message builder pattern | `Swift_Message::newInstance()` | Fluent message construction |
| Embedded images | `Swift_Message::embed()` | Inline image attachments |
| MIME parts | `Swift_Mime_SimpleMessage` | Multipart message construction |

---

## Features Missing

### High Priority

| Symfony Mailer Feature | Description | Complexity | Notes |
|-|-|-|-|
| Native transport (`native://default`) | Uses PHP's `sendmail_path` from php.ini | Low | Symfony ships this as a built-in; Swiftmailer has SendmailTransport but no `native` DSN scheme |
| Sendmail DSN scheme | `sendmail://default` in DSN map | Low | `Swift_Transport_SendmailTransport` exists but has no DSN entry in `TRANSPORT_CLASS_MAP` |
| Provider SMTP variants in DSN map | Many providers support `+smtp` schemes (e.g., `brevo+smtp`, `sendgrid+smtp`) that route through provider SMTP servers | Medium | Swiftmailer only maps API schemes for most providers; users must manually configure SMTP |
| Amazon SES SMTP variant | `ses+smtp://` | Low | SES API/HTTP exist but `ses+smtp` (or `amazon+smtp`) is missing from DSN map |
| Mailtrap sandbox mode | `mailtrap+sandbox://` DSN for testing environments | Low | Mailtrap API transport exists but no sandbox variant |
| Tags and metadata headers | `TagHeader` / `MetadataHeader` for provider-specific tagging | Medium | Supported by Sendgrid, Mailgun, Postmark, Brevo, Mandrill, MailPace, Resend, Mailtrap in Symfony |

### Medium Priority

| Symfony Mailer Feature | Description | Complexity | Notes |
|-|-|-|-|
| S/MIME encryption | Encrypt entire message with recipient certificates | Medium | Swiftmailer has S/MIME signing but not encryption (`SMimeEncrypter`) |
| Global envelope configuration | Default sender/recipients applied to all outgoing messages | Low | Symfony configures via framework YAML; Swiftmailer would need equivalent |
| Webhook / RemoteEvent integration | Receive delivery/engagement webhooks from providers | High | Symfony uses `RemoteEvent` component + `MailerDeliveryEvent` / `MailerEngagementEvent`; 11 providers support it |
| `retry_period` for failover/round-robin | Configurable period before retrying a failed transport | Low | Symfony 7.3+; Swiftmailer's FailoverTransport does not expose this |
| `source_ip` binding | Bind SMTP connection to specific IPv4/IPv6 address | Low | Symfony 7.3+; useful for multi-homed servers |
| Non-ASCII email address support | UTF-8 characters in email addresses | Medium | Symfony 7.2+; RFC 6531 (SMTPUTF8) |
| Draft email support | `DraftEmail` class for `.eml` download | Low | Niche feature; build emails for download without sending |

### Low Priority

| Symfony Mailer Feature | Description | Complexity | Notes |
|-|-|-|-|
| Twig templating integration | `TemplatedEmail` for Twig-based email templates | N/A | Framework-specific; Swiftmailer is framework-agnostic |
| Inky email framework | `inky_to_html` filter for responsive email | N/A | Twig integration; framework-specific |
| Markdown-to-HTML | `markdown_to_html` Twig filter | N/A | Twig integration; framework-specific |
| Custom SMTP authenticators | `XOAuth2Authenticator` and pluggable auth | Medium | Swiftmailer's ESMTP auth handlers may already cover this partially |
| `X-Transport` header routing | Select named transport via message header | Low | Symfony's multi-transport config concept |

---

## Features Not Applicable

These Symfony Mailer features are tied to the Symfony Framework and are not appropriate for Swiftmailer's framework-agnostic design.

| Symfony Feature | Reason Not Applicable |
|-|-|
| Symfony Messenger / MessageBus async | Requires Symfony Messenger component; Swiftmailer uses `SpoolTransport` for deferred sending instead |
| `SendEmailMessage` dispatching | Messenger-specific message class for async email routing |
| `DelayStamp` / `DispatchAfterCurrentBusStamp` | Messenger envelope stamps for controlling dispatch timing |
| `X-Bus-Transport` header | Messenger-specific header for selecting async transport |
| Symfony Flex recipes | Auto-configuration recipes for Symfony Framework projects |
| Framework YAML configuration | `framework.mailer` config block; Swiftmailer uses programmatic/DSN configuration |
| Twig email rendering / `TemplatedEmail` | Requires Twig + Symfony integration; not suitable for standalone library |
| Profiler / Web Debug Toolbar integration | Symfony profiler-specific |
| Dependency injection container auto-wiring | Symfony DI-specific; Swiftmailer uses `Swift_DependencyContainer` |

---

## Key Symfony Mailer Concepts

### Envelope

In Symfony Mailer, the `Envelope` class (`Symfony\Component\Mailer\Envelope`) represents the SMTP envelope -- the actual sender and recipients used at the SMTP protocol level, which can differ from the `From` and `To` headers in the message body.

- **Sender**: The MAIL FROM address (bounce address / return path)
- **Recipients**: The RCPT TO addresses (actual delivery targets)
- Symfony allows configuring a global envelope with default sender/recipients that apply to all outgoing messages

**Swiftmailer equivalent**: `Swift_Message` handles return-path via `setReturnPath()` and recipients are derived from To/Cc/Bcc headers. There is no standalone Envelope class, but the concept is embedded in `Swift_Transport_AbstractSmtpTransport` which extracts envelope information from the message during SMTP delivery.

### MessageBus / Async Sending

Symfony Mailer integrates with the Symfony Messenger component to provide asynchronous email sending:

1. When `$mailer->send()` is called and a message bus is configured, the email is wrapped in a `SendEmailMessage` object
2. This message is dispatched to the bus, which can route it to an async transport (database, Redis, AMQP, etc.)
3. A worker process later consumes the message and performs actual email delivery
4. This decouples the HTTP request from email sending, improving response times

**Swiftmailer equivalent**: `Swift_Transport_SpoolTransport` provides deferred sending. Messages are queued in a spool (file or memory) and flushed later via `$transport->getSpool()->flushQueue()`. This is synchronous queuing, not true message-bus async, but achieves a similar goal of decoupling send from request.

### DKIM Signing

Both libraries support DKIM (DomainKeys Identified Mail) signing:

- **Symfony Mailer**: `DkimSigner` class in `symfony/mime`; configured with private key, domain, and selector; supports body/header canonicalization options via `DkimOptions`
- **Swiftmailer**: `Swift_Signers_DKIMSigner`; same capabilities with body/header canonicalization; additionally supports the legacy `DomainKeySigner`

Both sign messages by adding a `DKIM-Signature` header with a cryptographic hash of the message body and selected headers.

### S/MIME Signing and Encryption

- **Symfony Mailer**: Provides both `SMimeSigner` (signing with certificate + private key) and `SMimeEncrypter` (encryption with recipient certificates, including multi-recipient support)
- **Swiftmailer**: Provides `Swift_Signers_SMimeSigner` for signing. S/MIME encryption is **not yet implemented**.

### Webhook / RemoteEvent

Symfony 6.3+ introduced the Webhook and RemoteEvent components for processing delivery and engagement notifications from email providers:

- **`MailerDeliveryEvent`**: Tracks delivery status (delivered, bounced, dropped, deferred)
- **`MailerEngagementEvent`**: Tracks recipient engagement (opened, clicked, unsubscribed, complained)
- Providers authenticate webhooks via signature verification
- 11 providers support webhooks in Symfony 7.4: AhaSend, Brevo, Mailchimp, MailerSend, Mailgun, Mailjet, Mailomat, Mailtrap, Postmark, Resend, Sweego

**Swiftmailer equivalent**: No webhook support. The event system (`Swift_Events_*`) only covers local send lifecycle events, not remote provider callbacks.

---

## Summary Statistics

| Metric | Count |
|-|-|
| Symfony Mailer third-party providers | 21 (19 bridge packages + SMTP + Sendmail) |
| Swiftmailer API transports | 21 |
| Providers with full parity | 21 (API mode) |
| Providers missing SMTP DSN variants | ~15 |
| Missing DSN scheme entries | 2 (sendmail, native) |
| High-priority missing features | 6 |
| Medium-priority missing features | 7 |
| Not-applicable features | 9 |
