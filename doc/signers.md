# Message Signing

SwiftMailer can cryptographically sign and/or encrypt a message before it is sent. Signers are attached to a `Swift_Message` and applied automatically every time the message is rendered. Three signers ship with the library:

| Signer | Type | Standard | Adds / transforms |
|-|-|-|-|
| `Swift_Signers_DKIMSigner` | Header signer | DKIM (RFC 6376, RFC 8463) | `DKIM-Signature` header |
| `Swift_Signers_DomainKeySigner` | Header signer | DomainKeys (RFC 4870, legacy) | `DomainKey-Signature` header |
| `Swift_Signers_SMimeSigner` | Body signer | S/MIME (RFC 5751) | Rewrites the MIME body (sign, encrypt, or both) |

A **header signer** leaves the body untouched and appends a signature header. A **body signer** rewrites the message body itself. The distinction matters when you attach more than one signer -- see [How signers are applied](#how-signers-are-applied).

## How signers are applied

Signers are attached to the message, not to the mailer:

```php
$message = new Swift_Message('Subject', 'Body');
$message->attachSigner($signer);   // route by interface: header vs body signer
$mailer->send($message);
```

`Swift_Message::attachSigner()` inspects the signer: a `Swift_Signers_HeaderSigner` is queued as a header signer, a `Swift_Signers_BodySigner` as a body signer. Related methods:

| Method | Effect |
|-|-|
| `attachSigner(Swift_Signer $signer)` | Attach a header or body signer |
| `detachSigner(Swift_Signer $signer)` | Remove a previously-attached signer instance |
| `clearSigners()` | Remove all attached signers |

### Re-rendering is non-destructive

Signing happens lazily inside `toString()` / `toByteStream()`. When at least one signer is attached, the message:

1. saves its current body/children/headers (`saveMessage()`),
2. runs every signer (`doSign()`),
3. renders the signed output,
4. restores itself to the pre-signing state (`restoreMessage()`).

Because the message is restored after each render, **a fresh signature is computed on every send** and the message object is left unchanged. You can attach a signer once and send the same message repeatedly (e.g. to many recipients) without accumulating stale signatures. Cloning a message also clones its attached signers.

### Order: body signers run before header signers

`doSign()` applies **all body signers first**, then all header signers. This is deliberate: a body signer (S/MIME) rewrites the body, and a header signer (DKIM) must sign the body *as it will actually be transmitted*. So if you attach both an S/MIME signer and a DKIM signer, the message is S/MIME-transformed first and DKIM then signs over the transformed result -- the DKIM signature stays valid.

---

## Swift_Signers_DKIMSigner

DKIM adds a `DKIM-Signature` header that a receiving server verifies against a public key published in DNS.

### Constructor

```php
public function __construct(
    string $privateKey,
    string $domainName,
    string $selector,
    #[\SensitiveParameter] string $passphrase = '',
)
```

| Argument | Meaning |
|-|-|
| `$privateKey` | PEM-encoded RSA private key, **or** a raw 64-byte Ed25519 secret key |
| `$domainName` | The `d=` domain, e.g. `example.com` |
| `$selector` | The `s=` selector, e.g. `mail` (selects the DNS record) |
| `$passphrase` | Optional RSA key passphrase (marked `#[\SensitiveParameter]` so it is redacted from stack traces) |

The constructor auto-detects the key type: if the sodium extension is available and `$privateKey` is exactly 64 bytes with no `-----BEGIN` marker, it is treated as a raw Ed25519 secret key; otherwise it is loaded as an RSA key via `openssl_pkey_get_private()`. An invalid key or wrong passphrase throws `Swift_SwiftException`.

### Hash algorithms -- SHA-1 removed

The default algorithm is **`rsa-sha256`**. Supported values for `setHashAlgorithm()`:

| Value | Notes |
|-|-|
| `rsa-sha256` | Default. Recommended (RFC 6376 §3.3). |
| `ed25519-sha256` | Requires the sodium extension; use a raw 64-byte secret key (RFC 8463). |
| `rsa-sha1` | **Rejected** -- throws `InvalidArgumentException`. Removed per RFC 8301. |

```php
$signer->setHashAlgorithm('rsa-sha256');     // default
$signer->setHashAlgorithm('ed25519-sha256');  // modern; needs sodium + raw key
$signer->setHashAlgorithm('rsa-sha1');        // throws InvalidArgumentException
```

### Canonicalization

Canonicalization controls how tolerant the signature is to whitespace/formatting changes in transit. `simple` is exact; `relaxed` normalizes whitespace and is more robust through intermediate MTAs.

| Method | Values | Default |
|-|-|-|
| `setHeaderCanon($canon)` | `simple` \| `relaxed` | `simple` |
| `setBodyCanon($canon)` | `simple` \| `relaxed` | `simple` |

The pair is emitted as the `c=` tag (`headerCanon/bodyCanon`). `relaxed/relaxed` is the most interoperable choice:

```php
$signer->setHeaderCanon('relaxed')->setBodyCanon('relaxed');
```

### Header selection and oversigning

By default the signer signs every header except a small ignore list (`return-path`, `x-transport`). Add more with `ignoreHeader()`:

```php
$signer->ignoreHeader('X-Internal-Route');
```

The `From` header can never be ignored -- `ignoreHeader('from')` is a no-op, because an unsigned `From` would defeat DKIM's purpose.

**Oversigning is enabled by default.** When on, the following headers -- if present -- are listed a second time in the `h=` tag: `from`, `to`, `subject`, `date`, `cc`, `reply-to`, `message-id`. Signing a header "one more time than it appears" means that if an attacker *adds* another instance of that header after signing (a header-injection / replay trick), verification breaks. Toggle it with:

```php
$signer->setOversigning(false);   // disable (not recommended)
```

> Note: the set of oversigned headers is fixed; there is no `setOversignedHeaders()` method. Use `setOversigning(bool)` to turn the behaviour on or off.

### Body length limit (deprecated)

`setBodySignedLen()` emitted the DKIM `l=` tag, which caps how many body bytes are signed. It is **deprecated** and triggers `E_USER_DEPRECATED`: the `l=` tag lets an attacker append unsigned content to a validly-signed body (RFC 8301 §5). By default no `l=` tag is produced (the full body is signed). Do not use it.

### Other tags

| Method | Tag | Purpose |
|-|-|-|
| `setSignerIdentity($identity)` | `i=` | Agent/user identity, defaults to `@domain` |
| `setSignatureTimestamp($ts)` | `t=` | Signing time (defaults to `time()`) |
| `setSignatureExpiration($ts)` | `x=` | Expiry time |
| `setDebugHeaders(true)` | `z=` + `X-DebugHash` | Emit diagnostic headers |

### DNS setup

Publish the **public** key as a TXT record at `<selector>._domainkey.<domain>`:

```
# RSA
mail._domainkey.example.com.  IN TXT  "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0B..."

# Ed25519
mailed._domainkey.example.com.  IN TXT  "v=DKIM1; k=ed25519; p=<base64 public key>"
```

The `selector` you pass to the signer must match the DNS label (`mail`, `mailed`, ...), and `d=` must match `<domain>`.

### Runnable example (RSA)

```php
$privateKey = file_get_contents('/etc/mail/dkim/example.com.private.pem');

$signer = new Swift_Signers_DKIMSigner($privateKey, 'example.com', 'mail');
$signer
    ->setHeaderCanon('relaxed')
    ->setBodyCanon('relaxed')
    ->setSignatureExpiration(time() + 3600);   // optional x=

$message = (new Swift_Message('Hello'))
    ->setFrom(['no-reply@example.com' => 'Example'])
    ->setTo('user@recipient.test')
    ->setBody('Signed with DKIM.');

$message->attachSigner($signer);
$mailer->send($message);
```

### Runnable example (Ed25519)

```php
// $secretKey is the raw 64-byte secret key from sodium_crypto_sign_keypair()
$signer = new Swift_Signers_DKIMSigner($secretKey, 'example.com', 'mailed');
$signer->setHashAlgorithm('ed25519-sha256');
$message->attachSigner($signer);
```

---

## Swift_Signers_DomainKeySigner (legacy)

DomainKeys (RFC 4870) is the historical predecessor to DKIM and adds a `DomainKey-Signature` header. It is still functional but superseded -- **use `Swift_Signers_DKIMSigner` for new deployments.**

```php
$signer = new Swift_Signers_DomainKeySigner($privateKey, 'example.com', 'mail');
$signer->setCanon('nofws');        // 'simple' (default) | 'nofws'
$message->attachSigner($signer);
```

Differences from the DKIM signer:

| Aspect | DomainKeySigner |
|-|-|
| Constructor | `($privateKey, $domainName, $selector)` -- no passphrase argument |
| Default hash | `rsa-sha256` |
| `setHashAlgorithm('rsa-sha1')` | Accepted but **deprecated** (`E_USER_DEPRECATED`); prefer `rsa-sha256` |
| Canonicalization | `setCanon('simple' \| 'nofws')` |
| `ignoreHeader('from')` | Throws `Swift_SwiftException` (From must be signed) |

---

## Swift_Signers_SMimeSigner

S/MIME uses X.509 certificates to sign and/or encrypt the message body. Unlike DKIM, it rewrites the MIME structure, so it is a **body signer**.

### Constructor

```php
public function __construct(
    $signCertificate = null,
    $signPrivateKey = null,
    $encryptCertificate = null,
)
```

You can pass everything up front, or configure it afterwards with the setters below. Certificate/key paths are resolved with `realpath()` and passed to OpenSSL as `file://` URIs.

### Signing

```php
$signer = new Swift_Signers_SMimeSigner();
$signer->setSignCertificate('/path/signer-cert.pem', '/path/signer-key.pem');
$message->attachSigner($signer);
```

`setSignCertificate($certificate, $privateKey = null, $signOptions = PKCS7_DETACHED, $extraCerts = null)`:

- `$privateKey` may be a path, or `['/path/key.pem', 'passphrase']` if the key is encrypted.
- `$signOptions` defaults to `PKCS7_DETACHED` (bitwise flags for `openssl_pkcs7_sign()`).
- `$extraCerts` is an optional file of intermediate certificates.

```php
// Encrypted private key with a passphrase
$signer->setSignCertificate('/path/cert.pem', ['/path/key.pem', 's3cr3t']);
```

### Encryption

```php
$signer = new Swift_Signers_SMimeSigner();
$signer->setEncryptCertificate('/path/recipient-cert.pem');
$message->attachSigner($signer);
```

`setEncryptCertificate($recipientCerts, $cipher = null)`:

- `$recipientCerts` is a single certificate path, or an **array** of paths to encrypt for multiple recipients.
- `$cipher` defaults to `OPENSSL_CIPHER_AES_256_CBC`.

### Sign and encrypt

Provide both a signing certificate and an encryption certificate. By default the message is **signed, then encrypted** (`signThenEncrypt = true`):

```php
$signer = new Swift_Signers_SMimeSigner();
$signer->setSignCertificate('/path/cert.pem', '/path/key.pem');
$signer->setEncryptCertificate('/path/recipient-cert.pem');
$message->attachSigner($signer);
```

Some legacy clients (e.g. Outlook 2000) require the reverse. Only flip this when you must target such clients:

```php
$signer->setSignThenEncrypt(false);   // encrypt, then sign
```

### Protecting header fields

By default S/MIME only protects the body. To also protect header fields (`Subject`, `To`, `From`, `Cc`), wrap the whole message in a `message/rfc822` part (RFC 5751 §3.1):

```php
$signer->setWrapFullMessage(true);
```

The S/MIME signer alters `Content-Type`, `Content-Transfer-Encoding`, and `Content-Disposition`.

---

## Interfaces

Both signer families extend the base `Swift_Signer` interface (a single `reset()` method).

### Swift_Signers_HeaderSigner

Implemented by `DKIMSigner` and `DomainKeySigner`. It extends `Swift_Signer` **and** `Swift_InputByteStream` (the signer receives the body as a stream while it is rendered):

```php
interface Swift_Signers_HeaderSigner extends Swift_Signer, Swift_InputByteStream
{
    public function ignoreHeader($header_name);
    public function startBody();
    public function endBody();
    public function setHeaders(Swift_Mime_SimpleHeaderSet $headers);
    public function addSignature(Swift_Mime_SimpleHeaderSet $headers);
    public function getAlteredHeaders();
}
```

During `doSign()` the message drives a header signer as: `reset()` -> `setHeaders()` -> `startBody()` -> body streamed in -> `endBody()` -> `addSignature()`.

### Swift_Signers_BodySigner

Implemented by `SMimeSigner`:

```php
interface Swift_Signers_BodySigner extends Swift_Signer
{
    public function signMessage(Swift_Message $message);
    public function getAlteredHeaders();
}
```

`getAlteredHeaders()` tells the message which headers the signer will change, so the message can save and restore them around signing.

## See also

- [DSN Transport Factory](dsn.md) -- constructing transports, including TLS options.
- [Security](security.md) -- header-injection stripping, credential protection, and other defensive features.
