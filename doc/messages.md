# Creating Messages

A message is a container for everything you want to send to someone else. In
SwiftMailer a message -- and every part nested inside it -- is a *MIME entity*:
the `Swift_Message` itself, each attachment, each embedded image, and each
alternative body part. Every MIME entity is made up of a set of **headers** and
a **body**. Complex messages are assembled from these entities with a fluent
builder API.

For working with individual headers directly, see [headers.md](headers.md). For
how a finished message is delivered, see [sending.md](sending.md).

## Quick Reference

Building a message is like composing an email in your mail client: give it a
subject, some recipients, a body, and any attachments.

```php
$message = (new Swift_Message())
    // Subject line
    ->setSubject('Your subject')

    // From address (associative array attaches a display name)
    ->setFrom(['john@doe.com' => 'John Doe'])

    // To addresses (setTo / setCc / setBcc all accept the same formats)
    ->setTo(['receiver@domain.org', 'other@domain.org' => 'A name'])

    // Plain-text body
    ->setBody('Here is the message itself')

    // Optional alternative HTML body
    ->addPart('<q>Here is the message itself</q>', 'text/html')

    // Optional attachment
    ->attach(Swift_Attachment::fromPath('my-document.pdf'));
```

The constructor also accepts the common fields as arguments:
`new Swift_Message($subject, $body, $contentType, $charset)` -- all optional.

> There is no `Swift_Message::newInstance()` factory. Use `new Swift_Message()`.

## Message Structure

A message carries a number of standard headers that describe it to the
recipient's mail client. You rarely touch these directly -- each has an accessor
that hides the strict RFC formatting (for example you pass a
`DateTimeInterface` to `setDate()` and the header renders the correct RFC 2822
string for you).

| Header | Description | Accessors |
|-|-|-|
| `Message-ID` | Unique identifier for the message (domain + generated id) | `getId()` / `setId()` |
| `Return-Path` | Where bounces are sent | `getReturnPath()` / `setReturnPath()` |
| `From` | Who wrote the message (may be multiple people) | `getFrom()` / `setFrom()` / `addFrom()` |
| `Sender` | Who physically sent it (higher precedence than `From`) | `getSender()` / `setSender()` |
| `To` | Intended recipients | `getTo()` / `setTo()` / `addTo()` |
| `Cc` | Recipients copied in | `getCc()` / `setCc()` / `addCc()` |
| `Bcc` | Blind-copied recipients (hidden from others) | `getBcc()` / `setBcc()` / `addBcc()` |
| `Reply-To` | Where replies should go | `getReplyTo()` / `setReplyTo()` / `addReplyTo()` |
| `Subject` | Subject line | `getSubject()` / `setSubject()` |
| `Date` | When the message was created | `getDate()` / `setDate()` |
| `Content-Type` | Body format (e.g. `text/plain`, `text/html`) | `getContentType()` / `setContentType()` |
| `Content-Transfer-Encoding` | Body encoding scheme | `getEncoder()` / `setEncoder()` |

On construction, a `Swift_Message` automatically sets `MIME-Version: 1.0`, a
`Date` (now), a generated `Message-ID`, and an empty `From` header. `Date`,
`Message-ID`, and `From` are always rendered even when empty.

Every MIME entity has a `toString()` method so you can inspect exactly what will
be sent:

```php
echo $message->toString();

/*
Message-ID: <1230173678.4952f5eeb1432@swift.generated>
Date: Thu, 25 Dec 2008 13:54:38 +1100
Subject: Example subject
From: Chris Corbyn <chris@w3style.co.uk>
To: Receiver Name <recipient@example.org>
MIME-Version: 1.0
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Here is the message
*/
```

## Subject and Body

The subject is set with `setSubject()` or the first constructor argument:

```php
$message = new Swift_Message('My amazing subject');
// or
$message->setSubject('My amazing subject');
```

The body is set with `setBody($body, $contentType = null, $charset = null)`.
When sending HTML, always include a plain-text alternative with `addPart()` so
clients that prefer plain text can display it. The mail client shows the "best"
part it supports.

```php
// Body as a constructor argument
$message = new Swift_Message('Subject here', 'My amazing body');

// HTML body
$message->setBody('My <em>amazing</em> body', 'text/html');

// Plain-text alternative
$message->addPart('My amazing body in plain text', 'text/plain');
```

`addPart()` inherits the message's encoder and adds the part as an alternative
MIME part.

## Attachments

Attachments are downloadable parts added with `attach()`. For everyday MIME
types (images, PDFs, office documents) you do not need to set the content type;
for uncommon formats you should.

### From an existing file

`Swift_Attachment::fromPath()` attaches a file from disk, or from a URL if
`allow_url_fopen` is enabled in `php.ini`. The recipient sees it with the
original filename.

```php
// Content type is optional; it is auto-detected from the extension
$message->attach(Swift_Attachment::fromPath('/path/to/image.jpg'));

// Explicit content type
$message->attach(Swift_Attachment::fromPath('/path/to/image.jpg', 'image/jpeg'));

// From a URL (requires allow_url_fopen)
$message->attach(Swift_Attachment::fromPath('http://site.tld/logo.png'));
```

### Renaming the attachment

By default the filename of the attached file is used. Override it with
`setFilename()`:

```php
$message->attach(
    Swift_Attachment::fromPath('/path/to/image.jpg')->setFilename('cool.jpg')
);
```

> **Filename sanitization.** `setFilename()` hardens the name before it reaches
> the `Content-Disposition` / `Content-Type` headers. It strips path separators
> (`/` and `\`), NUL bytes, control characters (`\x00`-`\x1F`, `\x7F`), and
> Unicode bidirectional-override characters (U+202A-U+202E, U+2066-U+2069) that
> could be used to spoof the displayed extension. Names longer than 255
> characters are truncated, preserving the extension.

### From dynamic content

Content generated at runtime (a PDF, a GD image) can be attached without writing
it to disk by constructing `Swift_Attachment` directly:

```php
$data = create_my_pdf_data();

$attachment = new Swift_Attachment($data, 'my-file.pdf', 'application/pdf');
$message->attach($attachment);

// Or build it with method chaining
$attachment = (new Swift_Attachment())
    ->setFilename('my-file.pdf')
    ->setContentType('application/pdf')
    ->setBody($data);
```

> If you would write the file to disk anyway, prefer
> `Swift_Attachment::fromPath()` -- it streams the file and uses less memory.

### Inline disposition

Attachments default to a disposition of `attachment`. Use `setDisposition('inline')`
to have the client display the file within the message window where it can:

```php
$message->attach(
    Swift_Attachment::fromPath('/path/to/image.jpg')->setDisposition('inline')
);
```

For a non-displayable type (e.g. a ZIP), the client will still present it as a
normal attachment.

## Embedding Inline Media

To show an image *inside* an HTML message (rather than as a download), embed it.
`$message->embed()` attaches the entity and returns a `cid:` reference to use in
a `src` or `href` attribute. Embedded files are sent as a special attachment
with a unique Content-ID; clients that cannot render them fall back to showing
them as attachments.

`Swift_Image` extends `Swift_EmbeddedFile` and behaves identically -- it exists
for semantic clarity when embedding images.

### From an existing file

```php
$message = new Swift_Message('My subject');

$message->setBody(
    '<html><body>'.
    '  Here is an image <img src="'.
         $message->embed(Swift_Image::fromPath('image.png')).
       '" alt="Image" />'.
    '  Rest of message'.
    '</body></html>',
    'text/html'
);
```

URLs work too when `allow_url_fopen` is on:
`$message->embed(Swift_Image::fromPath('http://site.tld/logo.png'))`.

If inlining the `embed()` call is awkward, capture the CID first:

```php
$cid = $message->embed(Swift_Image::fromPath('image.png'));
$message->setBody('<img src="'.$cid.'" alt="Image" />', 'text/html');
```

### From dynamic content

```php
$imgData = create_my_image_data();

$cid = $message->embed(new Swift_Image($imgData, 'image.jpg', 'image/jpeg'));
$message->setBody('<img src="'.$cid.'" alt="Image" />', 'text/html');
```

## Recipients

Recipients live on the message itself; the transport reads them at send time.
There are three types:

- **`To:`** -- the primary recipients (required)
- **`Cc:`** -- copied recipients (optional)
- **`Bcc:`** -- hidden recipients; each only sees their own address (optional)

### Address syntax

A single address is a string; a named address or a list uses an array:

```php
// Single address
$message->setFrom('some@address.tld');

// Named address
$message->setFrom(['some@address.tld' => 'The Name']);

// Multiple addresses, mixing named and plain
$message->setTo([
    'recipient-with-name@example.org' => 'Recipient Name One',
    'no-name@example.org', // no key => no display name
    'named-recipient@example.org'     => 'Recipient Name Two',
]);
```

Invalid addresses throw `Swift_RfcComplianceException`. If you build recipient
lists from untrusted data, validate first with `egulias/email-validator` or wrap
the `set*`/`add*` call in a try/catch:

```php
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

$validator = new EmailValidator();
if ($validator->isValid('example@example.com', new RFCValidation())) {
    $message->addTo('example@example.com');
}
```

### Setting recipients

Each type has a `set*` method (replaces the whole list) and an `add*` method
(appends one address, with an optional name as the second argument). **Calling
`setTo()` again overrides the previous list -- use `addTo()` to accumulate.**

```php
// Set the full list at once
$message->setTo([
    'person1@example.org',
    'person2@otherdomain.org' => 'Person 2 Name',
    'person5@example.org'     => 'Person 5 Name',
]);

// Or add iteratively
$message->addTo('person1@example.org');
$message->addTo('person2@example.org', 'Person 2 Name');
```

`setCc()`/`addCc()` and `setBcc()`/`addBcc()` work the same way.

> To send an individually-addressed copy to each recipient (rather than one
> message with everyone in the `To:` header), use the batch-send helper covered
> in [sending.md](sending.md).

### Internationalized addresses

Non-ASCII characters may appear in an address's domain (internationalized domain
names). By default SwiftMailer encodes such domains as Punycode
(e.g. `xn--xample-ova.invalid`), which every mail server accepts.

To send to addresses with non-ASCII characters on *both* sides of the `@`
(RFC 6531), your outbound SMTP server must advertise the `SMTPUTF8` extension.
Switch the transport to the UTF-8 address encoder and enable the handler -- see
[sending.md](sending.md) for transport setup:

```php
$transport->setExtensionHandlers([new Swift_Transport_Esmtp_SmtpUtf8Handler()]);
$transport->setAddressEncoder(new Swift_AddressEncoder_Utf8AddressEncoder());
```

## Sender Details

Three headers describe who sent a message:

- **`From:`** -- who wrote it (required; may list several authors)
- **`Sender:`** -- the single person who physically sent it (optional)
- **`Return-Path:`** -- where bounces go (optional)

`From:` is used as the default `Return-Path:` unless a `Sender:` (which takes
precedence) or an explicit return path is set.

```php
// A single From: address
$message->setFrom('your@address.tld');

// With a display name
$message->setFrom(['your@address.tld' => 'Your Name']);

// Multiple authors -- you MUST also set a Sender when From has more than one
$message->setFrom([
    'person1@example.org' => 'Sender One',
    'person2@example.org' => 'Sender Two',
]);
$message->setSender('person1@example.org');
```

The `Return-Path:` holds a single bare address (no display name):

```php
$message->setReturnPath('bounces@address.tld');
```

## Read Receipt

Request a read receipt (a `Disposition-Notification-To` header) with
`setReadReceiptTo()`:

```php
$message->setReadReceiptTo('your@address.tld');
```

> Most mail clients disable read receipts or prompt the user, so do not rely on
> receiving one.

## Character Set

The default charset is UTF-8, which covers most needs. You can change it
globally or per message/part.

```php
// Globally (recommended)
Swift_Preferences::getInstance()->setCharset('iso-8859-2');

// Per message
$message = (new Swift_Message())->setCharset('iso-8859-2');

// When setting the body
$message->setBody('My body', 'text/html', 'iso-8859-2');

// Per added part
$message->addPart('My part', 'text/plain', 'iso-8859-2');
```

Knowing the correct charset matters -- an incorrect one produces garbled mail.

## Encoding

Each MIME part's body is encoded before transport. Binary attachments use
base64 (`Swift_Mime_ContentEncoder_Base64ContentEncoder`); text parts default to
quoted-printable (`Swift_Mime_ContentEncoder_QpContentEncoder`, or
`Swift_Mime_ContentEncoder_NativeQpContentEncoder`). Set the encoder with
`setEncoder()`.

Quoted-printable converts 8-bit text to 7-bit and is the safe default. If your
outbound SMTP server advertises `8BITMIME` and can downgrade on delivery, you
may prefer `Swift_Mime_ContentEncoder_PlainContentEncoder` in `8bit` mode for
more compact, readable output (especially for non-Western languages):

```php
$transport->setExtensionHandlers([new Swift_Transport_Esmtp_EightBitMimeHandler()]);
$message->setEncoder(new Swift_Mime_ContentEncoder_PlainContentEncoder('8bit'));
```

## Line Length

Bodies wrap at 78 characters per line by default (for readability in plain-text
terminals). Change it with `setMaxLineLength()`:

```php
$message->setMaxLineLength(1000);
```

> Never exceed 1000 characters per line (RFC 2822). Longer lines may be
> truncated by SMTP servers in transit.

## Priority

`setPriority()` sets an advisory `X-Priority` header (it does not change how the
message is delivered). It takes an integer from 1 (highest) to 5 (lowest);
out-of-range values are clamped.

| Constant | Value |
|-|-|
| `Swift_Mime_SimpleMessage::PRIORITY_HIGHEST` | 1 |
| `Swift_Mime_SimpleMessage::PRIORITY_HIGH` | 2 |
| `Swift_Mime_SimpleMessage::PRIORITY_NORMAL` | 3 |
| `Swift_Mime_SimpleMessage::PRIORITY_LOW` | 4 |
| `Swift_Mime_SimpleMessage::PRIORITY_LOWEST` | 5 |

```php
$message->setPriority(2);
// or, more explicitly
$message->setPriority(Swift_Mime_SimpleMessage::PRIORITY_HIGH);
```

## Signing and Encryption

A message can be signed and/or encrypted by attaching a signer with
`attachSigner()`. For example, S/MIME signs the entire message (including
attachments) using the OpenSSL extension, with a PEM-encoded certificate and
private key:

```php
$message = new Swift_Message();

$smimeSigner = new Swift_Signers_SMimeSigner();
$smimeSigner->setSignCertificate('/path/to/certificate.pem', '/path/to/private-key.pem');
$message->attachSigner($smimeSigner);
```

S/MIME can also encrypt, and DKIM signing is available too. See
[signers.md](signers.md) for full coverage of the available signers and their
options.
