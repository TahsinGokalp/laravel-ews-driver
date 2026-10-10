# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Support for Laravel 13 and Symfony 8.
- Reply-To addresses are sent as `ReplyTo`.
- Message priority (`X-Priority`) is sent as `Importance`: priorities 1–2 become High, 3 Normal, and 4–5 Low.
- Read and delivery receipt requests (`Disposition-Notification-To`, `Return-Receipt-To`) are sent to Exchange.
- `In-Reply-To` and `References` headers are sent, so replies stay in the same conversation.
- Other headers, for example `X-*` or `List-Unsubscribe`, are forwarded as internet message headers.
- The delivered `Message-ID` now matches the one Laravel and Symfony report for the sent message.
- Embedded images (`$message->embed()`) are sent as inline attachments, and `cid:` references in the HTML body point at them.
- HTML and text bodies given as streams are supported.
- GitHub Actions runs the tests on PHP 8.2–8.4 with Laravel 12 and 13.

### Changed
- `EwsException` now extends Symfony's `TransportException`, and errors from the EWS client (HTTP, SOAP or cURL) are wrapped in it. As a result, Laravel's `failover` and `roundrobin` mailers move on to the next mailer when Exchange fails.
- `EwsServiceProvider` is now a plain service provider. It no longer extends Laravel's `MailServiceProvider` or re-registers Laravel's mail services.
- Envelope recipients that are not in To or Cc are sent as Bcc.
- Regular (non-inline) attachments are no longer sent with a generated Content-ID.

### Fixed
- Messages with only Bcc recipients no longer send an empty To list.
- The EWS error message no longer ends with a newline.

## [1.0.5] - 2026-10-10

### Added
- PHPUnit test suite.
- Config validation. A missing `host`, `username` or `password`, or an unknown `messageDispositionType`, throws an `EwsException` with a clear message instead of a `TypeError`.
- `verifySsl` mailer option, defaulting to `false`, to verify the EWS host's SSL certificate.
- Default values for optional config: `version` defaults to `Exchange2013`, and `messageDispositionType` defaults to `SendAndSaveCopy`.
- `illuminate/mail`, `illuminate/support`, `symfony/mailer` and `symfony/mime` are declared as dependencies.

### Changed
- **The sender is now the message's own `from` address.** `mail.from.address` is only used when the message has none. Mailables that set their own `from` address now send from that address, which needs "Send As" permission on the Exchange account.
- `mail.from.address` is no longer required.

### Fixed
- Recipients without a display name were sent with an empty name.

## [1.0.4] - 2023-10-11

### Changed
- SSL certificate verification for the EWS host is turned off (`CURLOPT_SSL_VERIFYPEER`).

## [1.0.3] - 2022-05-19

### Fixed
- To, Cc and Bcc recipients are read from the original message.

## [1.0.2] - 2022-05-19

### Changed
- README updates.

## [1.0.1] - 2022-05-19

- Re-tag of 1.0.0 with no code changes.

## [1.0.0] - 2022-05-19

- First release: a Laravel mail transport that sends mail through Exchange Web Services, using php-ews.

[Unreleased]: https://github.com/TahsinGokalp/laravel-ews-driver/compare/v1.0.5...HEAD
[1.0.5]: https://github.com/TahsinGokalp/laravel-ews-driver/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/TahsinGokalp/laravel-ews-driver/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/TahsinGokalp/laravel-ews-driver/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/TahsinGokalp/laravel-ews-driver/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/TahsinGokalp/laravel-ews-driver/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/TahsinGokalp/laravel-ews-driver/releases/tag/v1.0.0
