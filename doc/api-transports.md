# API Transports

Swiftmailer's API transports send email via provider HTTP APIs instead of SMTP. There are 21 concrete transport classes covering 19 distinct providers (Amazon SES has two variants).

## Architecture

All HTTP API transports share a two-level inheritance chain:

```
Swift_Transport (interface)
  └─ Swift_Transport_AbstractApiTransport (abstract)
       ├─ Swift_Transport_AbstractHttpApiTransport (abstract -- Guzzle-based)
       │    ├─ SendgridTransport
       │    ├─ PostMarkTransport
       │    ├─ MailGunTransport
       │    ├─ BrevoTransport
       │    ├─ ResendTransport
       │    ├─ AzureTransport
       │    ├─ ScalewayTransport
       │    ├─ InfoBipTransport
       │    ├─ MailPaceTransport
       │    ├─ MailChimpTransport
       │    ├─ MailerSendTransport
       │    ├─ MailJetTransport
       │    ├─ AhaSendTransport
       │    ├─ MailomatTransport
       │    ├─ MailtrapTransport
       │    ├─ PostalTransport
       │    └─ SweegoTransport
       ├─ AmazonSesApiTransport   (uses async-aws/ses directly)
       ├─ AmazonSesHttpTransport  (uses async-aws/ses directly)
       ├─ GoogleTransport         (uses google/apiclient directly)
       └─ MicrosoftGraphTransport (uses microsoft/microsoft-graph directly)
```

**AbstractHttpApiTransport** (17 transports) provides the standard constructor `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` and shared lifecycle, event dispatching, and helper methods. Subclasses with extra parameters call `parent::__construct()` and add their own.

**Non-HTTP transports** (4 transports) extend `AbstractApiTransport` directly and use their provider's SDK instead of Guzzle.

---

## Common Lifecycle Methods

All API transports implement the `Swift_Transport` interface:

| Method | Description |
|-|-|
| `start()` | Initializes the transport; fires `beforeTransportStarted` / `transportStarted` events |
| `stop()` | Shuts down the transport; fires `beforeTransportStopped` event |
| `isStarted()` | Returns whether the transport is active |
| `ping()` | Lightweight connectivity check (provider-specific) |
| `send($message, &$failedRecipients, $envelope)` | Sends the message; returns recipient count |
| `registerPlugin($plugin)` | Binds an event listener plugin |

## Helper Methods (AbstractHttpApiTransport)

These protected methods are available to all HTTP API transports and are useful when creating custom transports:

| Method | Description |
|-|-|
| `getMessageBody($message)` | Returns `['text' => ?string, 'html' => ?string]` |
| `getMessageAttachments($message)` | Returns array of `['filename', 'content', 'contentType', 'disposition', 'contentId']` |
| `formatAddress($email, $name)` | Returns `"Name <email>"` or just `"email"` |
| `formatAddresses($addresses)` | Formats all addresses from a SwiftMailer address array |
| `countRecipients($message)` | Counts To + CC + BCC (or envelope recipients) |
| `collectRecipients($message)` | Collects all recipient email addresses |
| `getEnvelopeSender($message)` | Gets the envelope sender, preferring explicit envelope over From header |
| `extractTags($message)` | Extracts and removes `X-Mailer-Tag` headers; returns `string[]` |
| `extractMetadata($message)` | Extracts and removes `X-Mailer-Metadata-*` headers; returns `array<string, string>` |

---

## Tags and Metadata

### Tags

Add tags to messages using `X-Mailer-Tag` headers. Multiple tags are supported. Tags are extracted and removed before sending -- they never reach the recipient's inbox.

```php
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'welcome-email');
$message->getHeaders()->addTextHeader('X-Mailer-Tag', 'onboarding');
```

### Metadata

Add key-value metadata using `X-Mailer-Metadata-{key}` headers. Metadata is extracted and removed before sending.

```php
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-user_id', '12345');
$message->getHeaders()->addTextHeader('X-Mailer-Metadata-campaign', 'feb-2026');
```

### How Tags and Metadata Map to Each Provider

| Provider | Tags become | Metadata becomes | Multi-tag |
|-|-|-|-|
| SendGrid | `categories` (array) | `custom_args` in personalizations | Yes |
| Postmark | `Tag` (first tag only) | `Metadata` object | No |
| Mailgun | `o:tag` (multiple) | `v:{key}` variables | Yes |
| Brevo | `tags` (array) | `headers` as `X-Metadata-{key}` | Yes |
| MailerSend | `tags` (array) | Not supported | Yes |
| Mailjet | `CustomCampaign` (first tag only) | `Properties` object | No |
| MailChimp | `tags` (array) | `metadata` object | Yes |
| Amazon SES (API) | `EmailTags` with `Name=tag` | `EmailTags` with `Name=key` | Yes |
| Amazon SES (HTTP) | `Tags` with `Name=tag` | Not extracted | Yes |
| Resend | `tags` as `[{name, value}]` | `headers` object | Yes |
| MailPace | `tags` (array) | `metadata` object | Yes |
| Mailtrap | `category` (first tag only) | `custom_variables` object | No |
| Postal | `tag` (first tag only) | Not supported | No |
| AhaSend | Not supported | Not supported | -- |
| Mailomat | Not supported | Not supported | -- |
| Scaleway | Not supported | Not supported | -- |
| Sweego | Not supported | Not supported | -- |
| InfoBip | Not supported | Not supported | -- |
| Azure | Not supported | Not supported | -- |
| Google | Not supported | Not supported | -- |
| Microsoft Graph | Not supported | Not supported | -- |

---

## DSN-Based Instantiation

Most simple-constructor transports can be instantiated via DSN strings using `Swift_Dsn`. The DSN scheme maps to a transport class, and the user/password/host/parameters carry credentials.

```php
use Nyholm\Dsn\DsnParser;

$dsn = new Swift_Dsn(DsnParser::parse('sendgrid://SG.your-api-key@default'));
$transportClass = $dsn->getTransportClass();
// Returns: Swift_Transport_Api_SendgridTransport
```

> **Note:** Transports that require SDK client objects (Amazon SES, Google, Microsoft Graph) are listed in the DSN map but cannot be fully constructed from a DSN string alone -- they need their SDK client injected manually.

### Supported DSN Schemes

| Scheme | Transport Class |
|-|-|
| `sendgrid` | `SendgridTransport` |
| `postmark` | `PostMarkTransport` |
| `mailgun` | `MailGunTransport` |
| `brevo` | `BrevoTransport` |
| `resend` | `ResendTransport` |
| `azure` | `AzureTransport` |
| `scaleway` | `ScalewayTransport` |
| `infobip` | `InfoBipTransport` |
| `mailpace` | `MailPaceTransport` |
| `mailchimp` | `MailChimpTransport` |
| `mailersend` | `MailerSendTransport` |
| `mailjet` | `MailJetTransport` |
| `ahasend` | `AhaSendTransport` |
| `mailomat` | `MailomatTransport` |
| `mailtrap` | `MailtrapTransport` |
| `postal` | `PostalTransport` |
| `sweego` | `SweegoTransport` |
| `amazon+api` | `AmazonSesApiTransport` |
| `amazon+http` | `AmazonSesHttpTransport` |
| `gmail+api` | `GoogleTransport` |
| `microsoft-graph` | `MicrosoftGraphTransport` |

---

## Transport Reference

### SendGrid

| | |
|-|-|
| **Class** | `Swift_Transport_Api_SendgridTransport` |
| **DSN** | `sendgrid://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.sendgrid.com/v3/mail/send` |
| **Auth method** | Bearer token (`Authorization: Bearer {apiKey}`) |
| **Tags** | Yes -- `categories` (array, multiple tags) |
| **Metadata** | Yes -- `custom_args` in personalizations |

```php
$transport = new Swift_Transport_Api_SendgridTransport('SG.your-api-key');
$mailer = new Swift_Mailer($transport);
```

---

### Postmark

| | |
|-|-|
| **Class** | `Swift_Transport_Api_PostMarkTransport` |
| **DSN** | `postmark://SERVER_TOKEN@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.postmarkapp.com/email` |
| **Auth method** | Server token header (`X-Postmark-Server-Token: {apiKey}`) |
| **Tags** | Yes -- `Tag` (first tag only, single string) |
| **Metadata** | Yes -- `Metadata` object |

```php
$transport = new Swift_Transport_Api_PostMarkTransport('your-server-token');
```

---

### Mailgun

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailGunTransport` |
| **DSN** | `mailgun://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, string $domain, string $host = 'https://api.mailgun.net', ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `{host}/v3/{domain}/messages` |
| **Auth method** | HTTP Basic Auth (`api:{apiKey}`) |
| **Payload format** | `multipart/form-data` |
| **Tags** | Yes -- `o:tag` (multiple values) |
| **Metadata** | Yes -- `v:{key}` variables |

```php
// US region (default)
$transport = new Swift_Transport_Api_MailGunTransport('key-xxx', 'mg.example.com');

// EU region
$transport = new Swift_Transport_Api_MailGunTransport('key-xxx', 'mg.example.com', 'https://api.eu.mailgun.net');
```

---

### Brevo (formerly Sendinblue)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_BrevoTransport` |
| **DSN** | `brevo://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.brevo.com/v3/smtp/email` |
| **Auth method** | API key header (`api-key: {apiKey}`) |
| **Tags** | Yes -- `tags` (array, multiple tags) |
| **Metadata** | Yes -- `headers` as `X-Metadata-{key}` custom headers |

```php
$transport = new Swift_Transport_Api_BrevoTransport('your-api-key');
```

---

### Amazon SES (API -- async-aws)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AmazonSesApiTransport` |
| **DSN** | `amazon+api://...` (not fully DSN-constructible -- requires SesClient) |
| **Base class** | `AbstractApiTransport` (directly, no Guzzle) |
| **Constructor** | `(SesClient $sesClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `async-aws/ses` |
| **Auth method** | AWS SDK credentials (IAM access key / secret) |
| **Tags** | Yes -- `EmailTags` with `Name=tag` |
| **Metadata** | Yes -- `EmailTags` with `Name=key` |

Uses the SES v2 `SendEmail` API with the `Simple` content format. Supports SES-specific headers:

- `X-SES-CONFIGURATION-SET` -- set a configuration set
- `X-SES-SOURCE-ARN` -- specify source ARN for cross-account sending
- `X-SES-LIST-MANAGEMENT-OPTIONS` -- subscription management

```php
use AsyncAws\Ses\SesClient;

$ses = new SesClient([
    'region' => 'us-east-1',
    'accessKeyId' => 'AKIA...',
    'accessKeySecret' => 'secret',
]);
$transport = new Swift_Transport_Api_AmazonSesApiTransport($ses);
```

---

### Amazon SES (HTTP)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AmazonSesHttpTransport` |
| **DSN** | `amazon+http://...` (not fully DSN-constructible -- requires SesClient) |
| **Base class** | `AbstractApiTransport` (directly, no Guzzle) |
| **Constructor** | `($sesClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `async-aws/ses` |
| **Auth method** | AWS SDK credentials (IAM access key / secret) |
| **Tags** | Yes -- `Tags` with `Name=tag` |
| **Metadata** | Not extracted |

Uses the SES v1 `SendEmail` API with raw message format. Also supports `X-SES-CONFIGURATION-SET`, `X-SES-SOURCE-ARN`, and `X-SES-LIST-MANAGEMENT-OPTIONS` headers. Adds the `X-SES-Message-ID` response header to the message after sending.

```php
$transport = new Swift_Transport_Api_AmazonSesHttpTransport($ses);
```

---

### Azure Communication Services

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AzureTransport` |
| **DSN** | `azure://CONNECTION_STRING@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $connectionString, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `{endpoint}/emails:send?api-version=2024-07-01-preview` |
| **Auth method** | HMAC-SHA256 request signing (derived from connection string access key) |
| **Tags** | No |
| **Metadata** | No |

The connection string format is: `endpoint=https://your-resource.communication.azure.com/;accesskey=BASE64KEY`

```php
$connectionString = 'endpoint=https://your-resource.communication.azure.com/;accesskey=BASE64KEY';
$transport = new Swift_Transport_Api_AzureTransport($connectionString);
```

---

### Google Gmail API

| | |
|-|-|
| **Class** | `Swift_Transport_Api_GoogleTransport` |
| **DSN** | `gmail+api://...` (not DSN-constructible -- requires Google Client) |
| **Base class** | `AbstractApiTransport` (directly, no Guzzle) |
| **Constructor** | `(Google\Client $googleClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `google/apiclient` |
| **Auth method** | Google OAuth2 / Service Account |
| **Tags** | No |
| **Metadata** | No |

Sends the entire message as a base64url-encoded RFC 2822 string via the Gmail API `users.messages.send` method.

```php
$client = new Google\Client();
$client->setAuthConfig('/path/to/credentials.json');
$client->addScope(Google\Service\Gmail::GMAIL_SEND);
$transport = new Swift_Transport_Api_GoogleTransport($client);
```

---

### Microsoft Graph

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MicrosoftGraphTransport` |
| **DSN** | `microsoft-graph://...` (not DSN-constructible -- requires GraphServiceClient) |
| **Base class** | `AbstractApiTransport` (directly, no Guzzle) |
| **Constructor** | `(GraphServiceClient $client, string $sendingAccountUserId, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `microsoft/microsoft-graph` |
| **Auth method** | Microsoft Graph SDK (OAuth2 / app credentials) |
| **Tags** | No |
| **Metadata** | No |

The `$sendingAccountUserId` identifies which user's mailbox to send from (typically an email address or user object ID).

```php
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user@company.com');

// Or dynamically use the message's From address as the sending account
$transport->useFromAddressAsSendingAccountUserId();

// Or set it manually later
$transport->setSendingAccountUserId('other-user@company.com');
```

---

### Resend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_ResendTransport` |
| **DSN** | `resend://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.resend.com/emails` |
| **Auth method** | Bearer token (`Authorization: Bearer {apiKey}`) |
| **Tags** | Yes -- `tags` as array of `{name, value}` objects |
| **Metadata** | Yes -- `headers` object |

```php
$transport = new Swift_Transport_Api_ResendTransport('re_xxxxxxxxxxxx');
```

---

### Scaleway

| | |
|-|-|
| **Class** | `Swift_Transport_Api_ScalewayTransport` |
| **DSN** | `scaleway://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, string $projectId, string $region = 'fr-par', ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.scaleway.com/transactional-email/v1alpha1/regions/{region}/emails` |
| **Auth method** | Auth token header (`X-Auth-Token: {apiKey}`) |
| **Tags** | No |
| **Metadata** | No |

```php
$transport = new Swift_Transport_Api_ScalewayTransport('scw-key', 'project-id-xxx', 'fr-par');
```

---

### InfoBip

| | |
|-|-|
| **Class** | `Swift_Transport_Api_InfoBipTransport` |
| **DSN** | `infobip://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, string $baseUrl, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://{baseUrl}/email/3/send` |
| **Auth method** | App key header (`Authorization: App {apiKey}`) |
| **Payload format** | `multipart/form-data` |
| **Tags** | No |
| **Metadata** | No |

> **Important:** The `$baseUrl` parameter should be the hostname only, without the `https://` protocol prefix. The transport prepends `https://` automatically.

```php
$transport = new Swift_Transport_Api_InfoBipTransport('your-api-key', 'xxx.api.infobip.com');
```

---

### MailPace

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailPaceTransport` |
| **DSN** | `mailpace://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://app.mailpace.com/api/v1/send` |
| **Auth method** | Server token header (`MailPace-Server-Token: {apiKey}`) |
| **Tags** | Yes -- `tags` (array) |
| **Metadata** | Yes -- `metadata` object |

> **Note:** MailPace has no dedicated health/ping endpoint; `ping()` always returns `true`.

```php
$transport = new Swift_Transport_Api_MailPaceTransport('your-api-token');
```

---

### MailChimp (Mandrill)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailChimpTransport` |
| **DSN** | `mailchimp://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://mandrillapp.com/api/1.0/messages/send` |
| **Auth method** | API key in JSON body (`key` field) |
| **Tags** | Yes -- `tags` (array, multiple tags) |
| **Metadata** | Yes -- `metadata` object |

> **Note:** This transport uses the Mandrill API (MailChimp's transactional email service). The API key is passed in the request body, not in headers. The `ping()` method calls `/api/1.0/users/ping` and expects `"PONG!"` as the response.

```php
$transport = new Swift_Transport_Api_MailChimpTransport('your-mandrill-api-key');
```

---

### MailerSend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailerSendTransport` |
| **DSN** | `mailersend://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.mailersend.com/v1/email` |
| **Auth method** | Bearer token (`Authorization: Bearer {apiKey}`) |
| **Tags** | Yes -- `tags` (array, multiple tags) |
| **Metadata** | No |

> **Note:** MailerSend expects HTTP 202 for success (not 200). The message ID is returned in the `x-message-id` response header.

```php
$transport = new Swift_Transport_Api_MailerSendTransport('mlsn.xxxxxxxxxxxx');
```

---

### Mailjet

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailJetTransport` |
| **DSN** | `mailjet://PUBLIC_KEY@default` (requires both keys -- see note) |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $publicKey, string $privateKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.mailjet.com/v3.1/send` |
| **Auth method** | HTTP Basic Auth (`publicKey:privateKey`) |
| **Tags** | Yes -- `CustomCampaign` (first tag only, single string) |
| **Metadata** | Yes -- `Properties` object |

> **Note:** Mailjet requires both a public (API) key and a secret (private) key. The `$publicKey` is stored as `$apiKey` internally.

```php
$transport = new Swift_Transport_Api_MailJetTransport('public-key', 'private-key');
```

---

### AhaSend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AhaSendTransport` |
| **DSN** | `ahasend://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.ahasend.com/v1/email/send` |
| **Auth method** | API key header (`X-Api-Key: {apiKey}`) |
| **Tags** | No |
| **Metadata** | No |

```php
$transport = new Swift_Transport_Api_AhaSendTransport('your-api-key');
```

---

### Mailomat

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailomatTransport` |
| **DSN** | `mailomat://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.mailomat.swiss/message` |
| **Auth method** | Bearer token (`Authorization: Bearer {apiKey}`) |
| **Tags** | No |
| **Metadata** | No |

```php
$transport = new Swift_Transport_Api_MailomatTransport('your-api-key');
```

---

### Mailtrap

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailtrapTransport` |
| **DSN** | `mailtrap://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, bool $sandbox = false, ?string $inboxId = null, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | Production: `https://send.api.mailtrap.io/api/send`; Sandbox: `https://sandbox.api.mailtrap.io/api/send/{inboxId}` |
| **Auth method** | Bearer token (`Authorization: Bearer {apiKey}`) |
| **Tags** | Yes -- `category` (first tag only, single string) |
| **Metadata** | Yes -- `custom_variables` object |

```php
// Production
$transport = new Swift_Transport_Api_MailtrapTransport('your-api-key');

// Sandbox mode (for testing)
$transport = new Swift_Transport_Api_MailtrapTransport('your-api-key', true, 'inbox-id');
```

---

### Postal

| | |
|-|-|
| **Class** | `Swift_Transport_Api_PostalTransport` |
| **DSN** | `postal://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, string $host, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://{host}/api/v1/send/message` |
| **Auth method** | Server API key header (`X-Server-API-Key: {apiKey}`) |
| **Tags** | Yes -- `tag` (first tag only, single string) |
| **Metadata** | No |

> **Important:** The `$host` parameter should be the hostname only, without the `https://` protocol prefix. The transport prepends `https://` automatically.

```php
$transport = new Swift_Transport_Api_PostalTransport('your-api-key', 'postal.example.com');
```

---

### Sweego

| | |
|-|-|
| **Class** | `Swift_Transport_Api_SweegoTransport` |
| **DSN** | `sweego://API_KEY@default` |
| **Base class** | `AbstractHttpApiTransport` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **API endpoint** | `https://api.sweego.io/send` |
| **Auth method** | API key header (`Api-Key: {apiKey}`) |
| **Tags** | No |
| **Metadata** | No |

> **Note:** Sweego payloads include fixed fields `channel: email`, `provider: sweego`, and `campaign-type: transac`.

```php
$transport = new Swift_Transport_Api_SweegoTransport('your-api-key');
```

---

## Comparison Table

| Transport | Constructor extras | Auth style | Payload format | Tags | Metadata | SDK-based |
|-|-|-|-|-|-|-|
| SendGrid | -- | Bearer token | JSON | Yes (multi) | Yes | No |
| Postmark | -- | Server token header | JSON | Yes (single) | Yes | No |
| Mailgun | `$domain`, `$host` | HTTP Basic | multipart/form-data | Yes (multi) | Yes | No |
| Brevo | -- | `api-key` header | JSON | Yes (multi) | Yes | No |
| Amazon SES (API) | `SesClient` | AWS SDK | SDK call | Yes (multi) | Yes | Yes |
| Amazon SES (HTTP) | `$sesClient` | AWS SDK | SDK call | Yes (multi) | No | Yes |
| Azure | `$connectionString` | HMAC-SHA256 | JSON | No | No | No |
| Google | `Google\Client` | OAuth2 | RFC 2822 raw | No | No | Yes |
| Microsoft Graph | `GraphServiceClient`, `$userId` | Graph SDK | Graph objects | No | No | Yes |
| Resend | -- | Bearer token | JSON | Yes (multi) | Yes | No |
| Scaleway | `$projectId`, `$region` | `X-Auth-Token` | JSON | No | No | No |
| InfoBip | `$baseUrl` (no protocol) | `App` token | multipart/form-data | No | No | No |
| MailPace | -- | Server token header | JSON | Yes (multi) | Yes | No |
| MailChimp | -- | Key in body | JSON | Yes (multi) | Yes | No |
| MailerSend | -- | Bearer token | JSON | Yes (multi) | No | No |
| Mailjet | `$privateKey` | HTTP Basic | JSON | Yes (single) | Yes | No |
| AhaSend | -- | `X-Api-Key` header | JSON | No | No | No |
| Mailomat | -- | Bearer token | JSON | No | No | No |
| Mailtrap | `$sandbox`, `$inboxId` | Bearer token | JSON | Yes (single) | Yes | No |
| Postal | `$host` (no protocol) | `X-Server-API-Key` | JSON | Yes (single) | No | No |
| Sweego | -- | `Api-Key` header | JSON | No | No | No |

---

## Error Handling

All API transports throw `Swift_TransportException` on failure. The exception message includes the provider name and the provider's error response when available.

```php
try {
    $numSent = $mailer->send($message, $failedRecipients);
} catch (Swift_TransportException $e) {
    // e.g. "SendGrid API error: The from address does not match a verified Sender Identity."
    // e.g. "Mailgun API error: Forbidden"
    // e.g. "Brevo API error (unauthorized): Your API key is wrong"
    echo $e->getMessage();
}
```

The event system fires a `failedMessage` event before throwing, allowing plugins to react to failures. Failed recipient addresses are collected into the `$failedRecipients` array.

For transports extending `AbstractHttpApiTransport`, the `send()` method:

1. Fires `beforeSendPerformed` event (cancellable)
2. Calls `doSend()` (provider-specific HTTP request)
3. On success: fires `sentMessage` event, returns recipient count
4. On failure: fires `failedMessage` event, throws `Swift_TransportException`
5. Always fires `sendPerformed` event in `finally` block

---

## Creating a Custom API Transport

Extend `Swift_Transport_AbstractHttpApiTransport` and implement five abstract methods:

```php
class Swift_Transport_Api_MyProviderTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope = null): array
    {
        $tags     = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);
        $body     = $this->getMessageBody($message);

        // Build payload, call API via $this->httpClient
        // Return ['message_id' => ..., 'recipients' => ...]
    }

    protected function getEndpoint(): string
    {
        return 'https://api.myprovider.com/v1/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return 'https://api.myprovider.com/v1/health';
    }
}
```
