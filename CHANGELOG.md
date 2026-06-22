# Changelog

All notable changes to `laravel-mail-log-channel` will be documented in this file.

## Unreleased

### Added

- Optional request payload capture (`log_request_payload`, off by default) with recursive, case-insensitive redaction of authentication and payment fields (Stripe, Braintree, PayPal, generic card forms)
- Configurable redaction (`redact_keys`, merged with built-in defaults) and size limits (`payload_max_value_length`, `payload_max_keys`)
- Uploaded files are described (name, type, size) without dumping their contents
- "Request Payload" and "Uploaded Files" sections in the error email

### Fixed

- Normalize query bindings (DateTime → string, bool → int) so displayed values match what the database receives

## 3.1.0 - 2026-05-21

### Added

- Level-based routing: configure different recipients per log level
- Artisan `mail-log:test` command to send a test notification
- Memory peak and execution time in environment context
- Exception throttling via cache to avoid mail flooding
- Clickable IP address link (whatismyipaddress.com)
- `rel="noopener noreferrer"` on external links
- Integration test suite
- Laravel Pint and Larastan (PHPStan level 5) with composer scripts

### Fixed

- Clone mailable per send and resolve mailer lazily (fixes reuse issues)
- Return null for closure routes in `resolveController`
- Use scoped binding for `QueryCollector` to reset state between queued jobs

### Changed

- Rework README with updated documentation

## 3.0.2 - 2026-03-25

- Fix context processor leaking execution data into other log channels when using a stack

## 3.0.1 - 2026-03-18

- Fix code snippet gutter width for 3-digit line numbers

## 3.0.0 - 2026-03-18

- Redesign error mail with structured sections
- Add execution context (HTTP request, Artisan command, Queue job)
- Add environment info badges (app env, Laravel/PHP versions, hostname)
- Add code snippet with error line highlighting
- Collapse vendor frames in stack trace, show relative paths
- Add SQL queries section with execution time
- Add ContextProcessor Monolog processor
- Add clickable file paths to open in editor (via `app.editor` config)
- Redesign subject format with custom placeholders (`%level_name%`, `%message%`, `%env%`, `%context%`, `%app_name%`, `%channel%`, `%datetime%`)
- Add built-in SQL query collector with bindings (enabled by default, configurable via `log_queries`)
- Display additional context from `Exception::context()` and Monolog record context

### Breaking Changes

- HTML output format completely redesigned
- `HtmlFormatter::addRow()` removed in favor of new section-based rendering
- `subject_format` no longer uses Monolog's `LineFormatter` syntax — uses simple `%placeholder%` replacements instead
- Requires PHP 8.1+ and Laravel 10+ (Monolog 3)

## 2.6.0 - 2026-03-18

- Add support for Laravel 13

## 2.5.0 - 2025-02-24

- Add support for Laravel 12

## 2.4.0 - 2024-03-12

- Add support for Laravel 11

## 2.3.0 - 2023-01-17

- Add support for Laravel 10

## 2.2.1 - 2023-01-11

- Fix issue where subjects were empty on content with >255 characters (thanks to @dev-idsys-mi #5)

## 2.2.0 - 2022-02-09

- Add support for Laravel 9 (thanks to @hetznet #3)

## 2.1.0 - 2021-02-17

- Add support for Laravel 8 (thanks to @jbeales #1)

## 2.0.2 - 2020-02-21

**DEPRECATED RELEASE**
- Add support for Laravel 8 (thanks to @jbeales #1)

## 2.0.1 - 2020-02-21

- Support multiple `to` configuration formats

## 2.0.0 - 2020-01-29

- Add support for Laravel 6 and 7 (thanks to @jbeales https://github.com/designmynight/laravel-log-mailer/pull/7)
- Remove extra configuration and view files
- Improve exception layout in mails

## 1.0.2 - 2018-09-09

- Fix logging levels

## 1.0.1 - 2018-09-05

- Fix dependency

## 1.0.0 - 2018-09-04

- Initial release
