# Using Swift Mailer for Japanese Emails

To send emails in Japanese, you need to tweak the default configuration so that
headers are Base64-encoded and the `iso-2022-jp` charset is used.

Call the `Swift::init()` method with the following code as early as possible in
your application (before any message is built):

```php
Swift::init(function () {
    Swift_DependencyContainer::getInstance()
        ->register('mime.qpheaderencoder')
        ->asAliasOf('mime.base64headerencoder');

    Swift_Preferences::getInstance()->setCharset('iso-2022-jp');
});

/* rest of code goes here */
```

The first call aliases the quoted-printable header encoder to the Base64 header
encoder (Japanese charsets require Base64 header encoding), and
`Swift_Preferences::setCharset()` sets the default message charset to
`iso-2022-jp`.

That's all!
