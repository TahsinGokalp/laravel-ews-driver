Laravel Exchange Web Services Driver
====


A Mail Driver with support for Exchange Web Services, using the original Laravel API. This library extends the original Laravel classes, so it uses exactly the same methods.
This package requires a access to a EWS host.

This library uses the [php-ews](https://github.com/jamesiarmes/php-ews/) library to connect to the exchange web services host.
Therefore requires the following dependencies:

* Composer
* PHP 8.1 or greater
* cURL with NTLM support (7.30.0+ recommended)
* Exchange 2007 or later

For more information, visit that [repo](https://github.com/jamesiarmes/php-ews/)

# Install (Laravel)

Add the package to your composer.json and run composer update.
```json
"require": {
    "tahsingokalp/laravel-ews-driver": "~1.0"
},
```

or install with composer
```
$ composer require tahsingokalp/laravel-ews-driver
```

Add the Exchange service provider in config/app.php:
(Laravel 5.5+ uses Package Auto-Discovery, so doesn't require you to manually add the ServiceProvider.)
```php
'providers' => [
    TahsinGokalp\LaravelEwsDriver\EwsServiceProvider::class
];
```

Add mail config to config file.

config/mail.php
```php
<?php
return [
    'mailers' => [
        .
        .
        .
        'exchange' => [
            'transport' => 'exchange',
            'host' => env('MAIL_HOST'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'version' => env('MAIL_VERSION'),
            'messageDispositionType' => env('MAIL_MESSAGE_DISPOSITION_TYPE'),
            'verifySsl' => env('MAIL_VERIFY_SSL', false),
        ],
        .
        .
        .
    ],

```

## Configure

.env
```
MAIL_DRIVER=exchange
MAIL_HOST=webmail.example.com
MAIL_USERNAME=examplemail
MAIL_PASSWORD=examplepassword
MAIL_VERSION=Exchange2010
MAIL_MESSAGE_DISPOSITION_TYPE=SaveOnly|SendAndSaveCopy|SendOnly
MAIL_VERIFY_SSL=false
```

| Key | Required | Default | Description |
| --- | --- | --- | --- |
| `host` | yes | | EWS host. |
| `username` | yes | | EWS username. |
| `password` | yes | | EWS password. |
| `version` | no | `Exchange2013` | One of the `jamesiarmes\PhpEws\Client::VERSION_*` values. |
| `messageDispositionType` | no | `SendAndSaveCopy` | `SaveOnly`, `SendAndSaveCopy` or `SendOnly`. |
| `verifySsl` | no | `false` | Verify the EWS host's SSL certificate. Enable it whenever the host has a valid certificate. |

A missing required value or an unknown `messageDispositionType` throws a `TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException` when the mailer is resolved.

### Sender

The message's `from` address (e.g. `Mail::to(...)->send(...)` with a mailable's `from`, or `mail.from.address`) is used as the sender. The global `mail.from.address` is only a fallback for messages without a `from` address. The EWS account must have "Send As" permission for any address other than its own mailbox.

For more information on the Message Disposition Type, [view more here](https://github.com/jamesiarmes/php-ews/blob/master/src/Enumeration/MessageDispositionType.php)

## Testing

```
composer test
```

## Acknowledgments

* [David Adeboye](https://github.com/adeboyed)
* [arvaipeti](https://github.com/arvaipeti)
* [Bryan Tan](https://github.com/bryanthw1020)
* [Jordy Sinke](https://github.com/jordysinke)
