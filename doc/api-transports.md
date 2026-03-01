# API Transports

All HTTP API transports extend `Swift_Transport_AbstractHttpApiTransport` (which extends `Swift_Transport_AbstractApiTransport`). They share a common lifecycle: `start()`, `send()`, `stop()`, `ping()`.

Most transports accept `(string $apiKey, ?ClientInterface $httpClient, ?Swift_Events_EventDispatcher $eventDispatcher)`. Exceptions are noted below.

## Common Features

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

| Provider | Tags become | Metadata becomes |
|-|-|-|
| SendGrid | `categories` (array) | `custom_args` in personalizations |
| Postmark | `Tag` (first tag only) | `Metadata` object |
| Mailgun | `o:tag` (multiple) | `v:{key}` variables |
| Brevo | Check transport source | Check transport source |
| MailerSend | Check transport source | Check transport source |
| Mailjet | Check transport source | Check transport source |
| Amazon SES | `EmailTags` with Name=`tag` | `EmailTags` with Name=key |

Other transports: check the `getPayload()` or `getFormData()` method in the transport class.

---

## Transport Reference

### SendGrid

| | |
|-|-|
| **Class** | `Swift_Transport_Api_SendgridTransport` |
| **DSN** | `sendgrid://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_SendgridTransport('SG.your-api-key');
```

### Postmark

| | |
|-|-|
| **Class** | `Swift_Transport_Api_PostMarkTransport` |
| **DSN** | `postmark://SERVER_TOKEN@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_PostMarkTransport('your-server-token');
```

### Mailgun

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailGunTransport` |
| **DSN** | `mailgun://API_KEY@default` (requires domain via constructor) |
| **Constructor** | `(string $apiKey, string $domain, string $host = 'https://api.mailgun.net', ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailGunTransport('key-xxx', 'mg.example.com');

// EU region
$transport = new Swift_Transport_Api_MailGunTransport('key-xxx', 'mg.example.com', 'https://api.eu.mailgun.net');
```

### Brevo (formerly Sendinblue)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_BrevoTransport` |
| **DSN** | `brevo://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_BrevoTransport('your-api-key');
```

### Amazon SES (API -- async-aws)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AmazonSesApiTransport` |
| **DSN** | `amazon+api://...` (not DSN-constructible -- requires SesClient) |
| **Constructor** | `(SesClient $sesClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `async-aws/ses` (included) |

```php
use AsyncAws\Ses\SesClient;

$ses = new SesClient([
    'region' => 'us-east-1',
    'accessKeyId' => 'AKIA...',
    'accessKeySecret' => 'secret',
]);
$transport = new Swift_Transport_Api_AmazonSesApiTransport($ses);
```

### Amazon SES (HTTP)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AmazonSesHttpTransport` |
| **DSN** | `amazon+http://...` (not DSN-constructible -- requires SesClient) |
| **Constructor** | `($sesClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `async-aws/ses` (included) |

```php
$transport = new Swift_Transport_Api_AmazonSesHttpTransport($ses);
```

### Azure Communication Services

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AzureTransport` |
| **DSN** | `azure://CONNECTION_STRING@default` |
| **Constructor** | `(string $connectionString, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$connectionString = 'endpoint=https://your-resource.communication.azure.com/;accesskey=BASE64KEY';
$transport = new Swift_Transport_Api_AzureTransport($connectionString);
```

### Google Gmail API

| | |
|-|-|
| **Class** | `Swift_Transport_Api_GoogleTransport` |
| **DSN** | `gmail+api://...` (not DSN-constructible -- requires Google Client) |
| **Constructor** | `(Google\Client $googleClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `google/apiclient` (included) |

```php
$client = new Google\Client();
$client->setAuthConfig('/path/to/credentials.json');
$client->addScope(Google\Service\Gmail::GMAIL_SEND);
$transport = new Swift_Transport_Api_GoogleTransport($client);
```

### Microsoft Graph

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MicrosoftGraphTransport` |
| **DSN** | `microsoft-graph://...` (not DSN-constructible -- requires GraphServiceClient) |
| **Constructor** | `(GraphServiceClient $client, string $sendingAccountUserId, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `microsoft/microsoft-graph` (included) |

```php
$transport = new Swift_Transport_Api_MicrosoftGraphTransport($graphClient, 'user@company.com');

// Or use the From address dynamically
$transport->useFromAddressAsSendingAccountUserId();
```

### Resend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_ResendTransport` |
| **DSN** | `resend://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_ResendTransport('re_xxxxxxxxxxxx');
```

### Scaleway

| | |
|-|-|
| **Class** | `Swift_Transport_Api_ScalewayTransport` |
| **DSN** | `scaleway://API_KEY@default` (requires projectId via constructor) |
| **Constructor** | `(string $apiKey, string $projectId, string $region = 'fr-par', ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_ScalewayTransport('scw-key', 'project-id-xxx', 'fr-par');
```

### InfoBip

| | |
|-|-|
| **Class** | `Swift_Transport_Api_InfoBipTransport` |
| **DSN** | `infobip://API_KEY@default` (requires baseUrl via constructor) |
| **Constructor** | `(string $apiKey, string $baseUrl, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_InfoBipTransport('your-api-key', 'https://xxx.api.infobip.com');
```

### MailPace

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailPaceTransport` |
| **DSN** | `mailpace://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailPaceTransport('your-api-token');
```

### MailChimp (Mandrill)

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailChimpTransport` |
| **DSN** | `mailchimp://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailChimpTransport('your-mandrill-api-key');
```

### MailerSend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailerSendTransport` |
| **DSN** | `mailersend://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailerSendTransport('mlsn.xxxxxxxxxxxx');
```

### Mailjet

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailJetTransport` |
| **DSN** | `mailjet://PUBLIC_KEY@default` (not fully DSN-constructible -- needs both keys) |
| **Constructor** | `(string $publicKey, string $privateKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailJetTransport('public-key', 'private-key');
```

### AhaSend

| | |
|-|-|
| **Class** | `Swift_Transport_Api_AhaSendTransport` |
| **DSN** | `ahasend://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_AhaSendTransport('your-api-key');
```

### Mailomat

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailomatTransport` |
| **DSN** | `mailomat://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_MailomatTransport('your-api-key');
```

### Mailtrap

| | |
|-|-|
| **Class** | `Swift_Transport_Api_MailtrapTransport` |
| **DSN** | `mailtrap://API_KEY@default` |
| **Constructor** | `(string $apiKey, bool $sandbox = false, ?string $inboxId = null, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
// Production
$transport = new Swift_Transport_Api_MailtrapTransport('your-api-key');

// Sandbox mode
$transport = new Swift_Transport_Api_MailtrapTransport('your-api-key', true, 'inbox-id');
```

### Postal

| | |
|-|-|
| **Class** | `Swift_Transport_Api_PostalTransport` |
| **DSN** | `postal://API_KEY@default` (requires host via constructor) |
| **Constructor** | `(string $apiKey, string $host, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_PostalTransport('your-api-key', 'https://postal.example.com');
```

### Sweego

| | |
|-|-|
| **Class** | `Swift_Transport_Api_SweegoTransport` |
| **DSN** | `sweego://API_KEY@default` |
| **Constructor** | `(string $apiKey, ?ClientInterface $httpClient, ?EventDispatcher $eventDispatcher)` |
| **Dependency** | `guzzlehttp/guzzle` (included) |

```php
$transport = new Swift_Transport_Api_SweegoTransport('your-api-key');
```

---

## Creating a Custom API Transport

Extend `Swift_Transport_AbstractHttpApiTransport` and implement five methods:

```php
class Swift_Transport_Api_MyProviderTransport extends Swift_Transport_AbstractHttpApiTransport
{
    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        // Build payload, call API, return ['message_id' => ..., 'recipients' => ...]
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

Use `$this->extractTags($message)` and `$this->extractMetadata($message)` in `doSend()` to support tags and metadata.
