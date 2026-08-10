# Message Headers

Every MIME entity in SwiftMailer -- the message, its attachments, its MIME
parts, and its embedded images -- stores its headers in a single `HeaderSet`
object, retrieved with `getHeaders()`. Most standard headers have named
accessors on the message (see [messages.md](messages.md)); this page covers
working with the `HeaderSet` and individual `Swift_Mime_Header` instances
directly, which you need when adding custom headers or modifying existing ones.

## Header Basics

Fetch the `HeaderSet` from any MIME entity:

```php
$message = new Swift_Message();
$headers = $message->getHeaders();

$attachment = Swift_Attachment::fromPath('document.pdf');
$attachmentHeaders = $attachment->getHeaders();
```

The contents differ by entity -- an attachment's headers are not a message's.
Loop over what's present with `getAll()`:

```php
foreach ($headers->getAll() as $header) {
    printf("%s\n", $header->getFieldName());
}
/*
Content-Transfer-Encoding
Content-Type
MIME-Version
Date
Message-ID
From
Subject
To
*/
```

Or render the whole set with `toString()`:

```php
echo $headers->toString();
/*
Message-ID: <1234869991.499a9ee7f1d5e@swift.generated>
Date: Tue, 17 Feb 2009 22:26:31 +1100
Subject: Awesome subject!
From: sender@example.org
To: recipient@example.org
MIME-Version: 1.0
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable
*/
```

### Header types

Each header is one of a small number of types, because different headers model
different data (text, dates, addresses, IDs). `getFieldType()` returns one of
these constants defined on `Swift_Mime_Header`:

| Constant | Value | Models |
|-|-|-|
| `Swift_Mime_Header::TYPE_TEXT` | 2 | Plain text (e.g. `Subject`) |
| `Swift_Mime_Header::TYPE_PARAMETERIZED` | 6 | Text plus key/value parameters (e.g. `Content-Type`) |
| `Swift_Mime_Header::TYPE_MAILBOX` | 8 | Email addresses (e.g. `From`, `To`) |
| `Swift_Mime_Header::TYPE_DATE` | 16 | Dates (e.g. `Date`) |
| `Swift_Mime_Header::TYPE_ID` | 32 | Identifiers (e.g. `Message-ID`) |
| `Swift_Mime_Header::TYPE_PATH` | 64 | A single bare address (e.g. `Return-Path`) |

```php
foreach ($headers->getAll() as $header) {
    switch ($header->getFieldType()) {
        case Swift_Mime_Header::TYPE_TEXT:          $type = 'text'; break;
        case Swift_Mime_Header::TYPE_PARAMETERIZED: $type = 'parameterized'; break;
        case Swift_Mime_Header::TYPE_MAILBOX:       $type = 'mailbox'; break;
        case Swift_Mime_Header::TYPE_DATE:          $type = 'date'; break;
        case Swift_Mime_Header::TYPE_ID:            $type = 'ID'; break;
        case Swift_Mime_Header::TYPE_PATH:          $type = 'path'; break;
    }
    printf("%s: is a %s header\n", $header->getFieldName(), $type);
}
```

## Header Types

### Text Headers

The simplest type -- textual content with no special structure, such as
`Subject`. Add one with `addTextHeader()` and change it with `setValue()`:

```php
$headers = $message->getHeaders();
$headers->addTextHeader('Your-Header-Name', 'the header value');

$subject = $headers->get('Subject');
$subject->setValue('new subject');
```

Characters outside US-ASCII are RFC 2047 encoded automatically; mail clients
decode them back:

```php
$subject->setValue('contains – dash');
echo $subject->toString();
// Subject: contains =?utf-8?Q?=E2=80=93?= dash
```

> **Header-injection safety.** No header is vulnerable to header injection.
> `setValue()` strips carriage returns and line feeds (`\r`, `\n`) from the
> value outright, and any remaining out-of-range characters are encoded into a
> safe form -- so a value can never break out into a new header line.

### Parameterized Headers

A text header that also carries key/value parameters after the value --
`Content-Type` (with its `charset`) is the canonical example. All text-header
methods apply, plus parameter methods. Add one with `addParameterizedHeader()`:

```php
$headers->addParameterizedHeader('Header-Name', 'header value', ['foo' => 'bar']);
```

Change the parameters with `setParameter()` (single) or `setParameters()`
(whole map); read them with `getParameter()` / `getParameters()`:

```php
$type = $message->getHeaders()->get('Content-Type');

$type->setParameters(['name' => 'file.txt', 'charset' => 'iso-8859-1']);
$type->setParameter('charset', 'iso-8859-1');

$type->setValue('text/html');
$type->setParameter('charset', 'utf-8');
echo $type->toString();
// Content-Type: text/html; charset=utf-8
```

Non-ASCII parameter values are RFC 2231 encoded so they transmit safely:

```php
$attachment = new Swift_Attachment();
$disp = $attachment->getHeaders()->get('Content-Disposition');
$disp->setValue('attachment');
$disp->setParameter('filename', 'report–may.pdf');
echo $disp->toString();
// Content-Disposition: attachment; filename*=utf-8''report%E2%80%93may.pdf
```

### Date Headers

Modeled on a `DateTimeImmutable` and rendered as an RFC 2822 date with timezone
(e.g. `Tue, 17 Feb 2009 22:26:31 +1100`). The message's `Date` header is one.
Add with `addDateHeader()` and change with `setDateTime()`:

```php
$headers->addDateHeader('Your-Header', new DateTimeImmutable('3 days ago'));

$date = $message->getHeaders()->get('Date');
$date->setDateTime(new DateTimeImmutable());
echo $date->toString();
// Date: Wed, 18 Feb 2009 13:35:02 +1100
```

> `setDateTime()` accepts any `DateTimeInterface`; a mutable `DateTime` is
> converted to `DateTimeImmutable` internally.

### Mailbox Headers

Hold one or more email addresses, optionally with display names, modeled as an
associative `address => name` array. Every address header except `Return-Path`
(`To`, `From`, `Cc`, ...) is a mailbox header. Add with `addMailboxHeader()`:

```php
$headers->addMailboxHeader('Your-Header-Name', [
    'person1@example.org' => 'Person Name One',
    'person2@example.org',
    'person3@example.org' => 'Another named person',
]);
```

Use `setNameAddresses()` for named addresses, or `setAddresses()` when you only
have bare addresses. A single address may be passed as a string:

```php
$to = $message->getHeaders()->get('To');

$to->setNameAddresses([
    'joe@example.org'  => 'Joe Bloggs',
    'john@example.org' => 'John Doe',
    'no-name@example.org',
]);

$to->setAddresses(['joe@example.org', 'john@example.org']);
$to->setAddresses('joe-bloggs@example.org');
```

Rendering produces RFC 2822 name-addr strings, folded across lines as needed:

```php
$to->setNameAddresses([
    'person1@example.org' => 'Name of Person',
    'person2@example.org',
    'person3@example.org' => 'Another Person',
]);
echo $to->toString();
/*
To: Name of Person <person1@example.org>, person2@example.org, Another Person
 <person3@example.org>
*/
```

Internationalized domains are converted to IDN (Punycode) automatically:

```php
$to->setAddresses('joe@ëxämple.org');
echo $to->toString();
// To: joe@xn--xmple-gra1c.org
```

Other useful methods: `getNameAddresses()`, `getAddresses()`,
`getNameAddressStrings()`, and `removeAddresses()`. Invalid addresses throw
`Swift_RfcComplianceException`.

### ID Headers

Contain identifiers such as `Message-ID`. An ID looks like an email address --
`<1234955437.499becad62ec2@example.org>` -- and **must** conform to that
structure or `setId()` throws `Swift_RfcComplianceException`. Add with
`addIdHeader()`:

```php
$headers->addIdHeader('Your-Header-Name', '123456.unique@example.org');

$msgId = $message->getHeaders()->get('Message-ID');
$msgId->setId(time().'.'.uniqid('thing').'@example.org');
echo $msgId->toString();
// Message-ID: <1234955437.499becad62ec2@example.org>
```

An ID header may hold several IDs -- use `setIds()` / `getIds()` for the full
list; `getId()` returns the first.

### Path Headers

A very restricted mailbox header: a single address, no display name.
`Return-Path` is one. Add with `addPathHeader()` and change with
`setAddress()`:

```php
$headers->addPathHeader('Your-Header-Name', 'person@example.org');

$return = $message->getHeaders()->get('Return-Path');
$return->setAddress('person@example.org');
echo $return->toString();
// Return-Path: <person@example.org>
```

## Header Operations

### Adding headers

Use the matching `add*Header()` method for the type you need. The header appears
on the entity when it is sent:

```php
$message->getHeaders()->addTextHeader('X-Mine', 'something here');

$attachment = Swift_Attachment::fromPath('/path/to/doc.pdf');
$attachment->getHeaders()->addDateHeader('X-Created-Time', new DateTimeImmutable());
```

### Retrieving headers

`get()` returns a single header by name (case-insensitive); `getAll()` returns
an array. Both accept an optional zero-based index because some headers (e.g.
`Received`) may appear multiple times.

```php
$toHeader = $headers->get('To');        // first To header
$foo      = $headers->get('X-Foo', 1);  // second X-Foo header
$allFoo   = $headers->getAll('X-Foo');  // every X-Foo header
$all      = $headers->getAll();         // every header present
```

If you don't know a header's type, check it with `getFieldType()` before calling
type-specific setters.

### Checking a header exists

`has()` returns a bool, with the same optional index:

```php
if ($headers->has('To')) {
    // ...
}

if ($headers->has('X-Foo', 1)) {
    // a second X-Foo header exists
}
```

### Removing headers

`remove()` deletes one header (optionally by index); `removeAll()` deletes every
header with the given name. Neither errors if the header is absent.

```php
$headers->remove('Subject');
$headers->remove('X-Foo', 1);  // only the second X-Foo
$headers->removeAll('X-Foo');  // all X-Foo headers
```

### Modifying a header's content

Call the type-specific setter (`setValue()`, `setNameAddresses()`,
`setDateTime()`, ...). If you don't want to branch on type, every header also
has `setFieldBodyModel()`, which accepts a mixed value and delegates to the
correct setter:

```php
$headers = $message->getHeaders();

$headers->get('Subject')->setValue('new subject here');

$to = $headers->get('To');
$to->setNameAddresses(['person@example.org' => 'Person', 'thing@example.org']);

// Equivalent -- setFieldBodyModel() delegates to setNameAddresses() for a
// mailbox header
$to->setFieldBodyModel(['person@example.org' => 'Person', 'thing@example.org']);
```
