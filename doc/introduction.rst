Swift Mailer: A feature-rich PHP Mailer
=======================================

Swift Mailer is a component-based library for sending e-mails from PHP
applications, maintained by `Redrock Software Corporation <https://www.go-redrock.com/>`_.

This fork continues Swiftmailer development for legacy and enterprise
applications that cannot migrate to Symfony Mailer. It integrates features
from Symfony Mailer -- including 21 HTTP API transports, a DSN factory,
webhook processing, and more -- while preserving full backward compatibility
with stock SwiftMailer 6.x.

System Requirements
-------------------

Swift Mailer requires PHP 8.2 or later with the following extensions:

* ``iconv``
* ``mbstring``
* ``intl``
* ``openssl``

Installation
------------

The recommended way to install Swiftmailer is via Composer:

.. code-block:: bash

    $ composer require swiftmailer/swiftmailer

Basic Usage
-----------

Here is the simplest way to send emails with Swift Mailer::

    require_once '/path/to/vendor/autoload.php';

    // Create the Transport
    $transport = (new Swift_SmtpTransport('smtp.example.org', 587, 'tls'))
      ->setUsername('your username')
      ->setPassword('your password')
    ;

    // Create the Mailer using your created Transport
    $mailer = new Swift_Mailer($transport);

    // Create a message
    $message = (new Swift_Message('Wonderful Subject'))
      ->setFrom(['john@doe.com' => 'John Doe'])
      ->setTo(['receiver@domain.org', 'other@domain.org' => 'A name'])
      ->setBody('Here is the message itself')
      ;

    // Send the message
    $result = $mailer->send($message);

You can also use an HTTP API transport for faster, more reliable delivery::

    // Direct API call instead of SMTP
    $transport = new Swift_Transport_Api_SendgridTransport('your-api-key');
    $mailer = new Swift_Mailer($transport);
    $mailer->send($message);

Or create transports from a DSN connection string::

    $factory = new Swift_Transport_DsnTransportFactory();
    $transport = $factory->fromDsnString('sendgrid://API_KEY@default');
    $mailer = new Swift_Mailer($transport);

See the `README <../README.md>`_ for the full feature list, or the
``doc/`` directory for detailed guides on API transports, DSN syntax,
webhooks, plugins, and events.

Getting Help
------------

For general support, use `Stack Overflow <https://stackoverflow.com>`_.

For bug reports and feature requests, create a new ticket on
`GitHub <https://github.com/go-redrock-software/swiftmailer/issues>`_.
