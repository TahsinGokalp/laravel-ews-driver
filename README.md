Laravel Exchange Web Services Driver
====

[![Tests](https://github.com/TahsinGokalp/laravel-ews-driver/actions/workflows/tests.yml/badge.svg)](https://github.com/TahsinGokalp/laravel-ews-driver/actions/workflows/tests.yml)

A Laravel mail transport that sends mail through Exchange Web Services (EWS). Mailables, notifications and the `Mail` facade work exactly as with any other Laravel mailer.

This library uses the [php-ews](https://github.com/jamesiarmes/php-ews/) library to connect to the EWS host and requires:

* PHP 8.1 or greater (Laravel 13 requires PHP 8.3, Symfony 8 requires PHP 8.4)
* Laravel 10, 11, 12 or 13
* The PHP `soap` and `curl` extensions, with cURL NTLM support (7.30.0+ recommended)
* Exchange 2007 or later

> [!WARNING]
> **Exchange Online (Microsoft 365) is not supported.** This driver authenticates with a username and password, which Exchange Online no longer accepts for EWS, and Microsoft is retiring EWS in Exchange Online: blocking started on 1 October 2026 and access is removed permanently on 1 April 2027. On-premises Exchange Server is not affected. For Exchange Online, use a Microsoft Graph based mailer instead.

# Install

```bash
composer require tahsingokalp/laravel-ews-driver
```

The service provider is registered automatically through package auto-discovery.

# Configure

Add an `exchange` mailer to `config/mail.php`:

```php
'mailers' => [
    // ...

    'exchange' => [
        'transport' => 'exchange',
        'host' => env('MAIL_HOST'),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        'version' => env('MAIL_VERSION'),
        'messageDispositionType' => env('MAIL_MESSAGE_DISPOSITION_TYPE'),
        'verifySsl' => env('MAIL_VERIFY_SSL', false),
    ],
],
```

and set it up in `.env`:

```
MAIL_MAILER=exchange
MAIL_HOST=webmail.example.com
MAIL_USERNAME=examplemail
MAIL_PASSWORD=examplepassword
MAIL_VERSION=Exchange2016
MAIL_MESSAGE_DISPOSITION_TYPE=SendAndSaveCopy
MAIL_VERIFY_SSL=false
MAIL_FROM_ADDRESS=examplemail@example.com
```

| Key | Required | Default | Description |
| --- | --- | --- | --- |
| `host` | yes | | EWS host. |
| `username` | yes | | EWS username. |
| `password` | yes | | EWS password. |
| `version` | no | `Exchange2013` | One of the `jamesiarmes\PhpEws\Client::VERSION_*` values, e.g. `Exchange2010`, `Exchange2013`, `Exchange2016`. |
| `messageDispositionType` | no | `SendAndSaveCopy` | `SendAndSaveCopy` (send and keep a copy in Sent Items), `SendOnly` or `SaveOnly` (save as a draft without sending). [More information](https://github.com/jamesiarmes/php-ews/blob/master/src/Enumeration/MessageDispositionType.php). |
| `verifySsl` | no | `false` | Verify the EWS host's SSL certificate. Enable it whenever the host has a valid certificate. |

A missing required value or an unknown `messageDispositionType` throws a `TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException` when the mailer is resolved.

## Sender

The message's own `from` address is used as the sender: the mailable's `from`, or Laravel's global `mail.from.address`. The EWS account must have "Send As" permission for any address other than its own mailbox.

## Failover

Exchange errors are thrown as `EwsException`, which is a Symfony `TransportException`, so the driver works with Laravel's `failover` and `roundrobin` mailers:

```php
'failover' => [
    'transport' => 'failover',
    'mailers' => ['exchange', 'smtp'],
],
```

# Supported message features

| Message feature | Sent to Exchange as |
| --- | --- |
| To, Cc, Bcc (and other envelope recipients) | `ToRecipients`, `CcRecipients`, `BccRecipients` |
| HTML or plain text body | `Body` (HTML is preferred when both are set) |
| Attachments | `FileAttachment` |
| Embedded images (`$message->embed()`) | Inline `FileAttachment` with its Content-ID |
| Reply-To | `ReplyTo` |
| Priority (`X-Priority`) | `Importance` |
| Read / delivery receipt (`Disposition-Notification-To`, `Return-Receipt-To`) | `IsReadReceiptRequested`, `IsDeliveryReceiptRequested` |
| `In-Reply-To`, `References` | Threading fields |
| `Message-ID` | `PidTagInternetMessageId` |
| Other headers (e.g. `X-*`, `List-Unsubscribe`) | Internet message headers |

# Testing

```bash
composer test
```

# Acknowledgments

* [David Adeboye](https://github.com/adeboyed)
* [arvaipeti](https://github.com/arvaipeti)
* [Bryan Tan](https://github.com/bryanthw1020)
* [Jordy Sinke](https://github.com/jordysinke)
