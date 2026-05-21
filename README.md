# Laravel Mail Log Channel

[![Latest Stable Version](https://img.shields.io/github/v/release/shaffe-fr/laravel-mail-log-channel.svg)](https://packagist.org/packages/shaffe/laravel-mail-log-channel)
[![Total Downloads](https://img.shields.io/packagist/dt/shaffe/laravel-mail-log-channel.svg)](https://packagist.org/packages/shaffe/laravel-mail-log-channel)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Receive detailed error emails from your Laravel application. Plug it into Laravel's logging stack and get notified when things break — with full context, stack trace, SQL queries, and more.

<details>
<summary>📸 Example email</summary>

![screenshot](docs/screenshot.png)

</details>

## Features

- **Rich error emails** — structured HTML with clear, readable sections
- **Execution context** — HTTP request (method, URL, route, controller, authenticated user), Artisan command, or Queue job
- **Environment info** — app environment, PHP/Laravel versions, server hostname
- **Code snippet** — source code around the error line, highlighted
- **Smart stack trace** — application frames expanded, vendor frames collapsed
- **SQL queries** — last 10 queries with bindings and execution time
- **Throttling** — identical errors are deduplicated to avoid inbox flooding
- **Editor links** — clickable file paths that open in your IDE
- **Previous exceptions** — full chain display
- **Additional context** — from `Exception::context()` and log record context

## Installation

```sh
composer require shaffe/laravel-mail-log-channel
```

The package auto-registers its service provider.

### Compatibility

| Laravel            | Package |
|:-------------------|:--------|
| 10, 11, 12, 13    | ^3.0    |
| 5.6 – 13          | ^2.0    |
| 5.6                | ^1.0    |

## Quick Start

Add a `mail` channel to `config/logging.php` and include it in your stack:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'mail'],
    ],

    'mail' => [
        'driver' => 'mail',
        'level' => env('LOG_MAIL_LEVEL', 'error'),
        'to' => env('LOG_MAIL_ADDRESS'),
    ],
],
```

Add the recipient to your `.env`:

```env
LOG_MAIL_ADDRESS=errors@yourapp.com
```

That's it. Unhandled exceptions at or above the configured level will now arrive in your inbox.

## Configuration Reference

All options with their defaults:

```php
'mail' => [
    'driver' => 'mail',
    'level' => 'error',

    // Recipients (see formats below)
    'to' => env('LOG_MAIL_ADDRESS'),

    // Sender (defaults to mail.from config)
    'from' => [
        'address' => env('LOG_MAIL_FROM_ADDRESS'),
        'name' => env('LOG_MAIL_FROM_NAME', 'Errors'),
    ],

    // Subject line pattern
    // Placeholders: %level_name%, %message%, %env%, %context%, %app_name%, %channel%, %datetime%
    'subject_format' => '[%level_name%] [%env%] %context% — %message%',

    // Throttle identical errors (seconds). Set to 0 or false to disable.
    'throttle' => 60,

    // Cache store for throttle state (null = default store)
    'throttle_cache_store' => null,

    // Include last N SQL queries in the email
    'log_queries' => true,

    // Collapse vendor frames in stack trace
    'collapse_vendor_frames' => true,

    // Custom Mailable class (receives $content and $records)
    // 'mailable' => \App\Mail\CustomLogMail::class,
],
```

### Recipient Formats

The `to` option accepts several formats:

```php
// Simple string
'to' => 'dev@example.com',

// Multiple addresses
'to' => ['dev@example.com', 'ops@example.com'],

// With names
'to' => ['dev@example.com' => 'Dev Team'],

// Structured
'to' => [
    ['address' => 'dev@example.com', 'name' => 'Dev Team'],
    ['address' => 'ops@example.com', 'name' => 'Ops'],
],
```

## Throttling

Identical errors are automatically deduplicated to prevent inbox flooding. When the same error occurs multiple times within the throttle window, only the first occurrence sends an email.

**Enabled by default** with a 60-second window.

### Fingerprinting

Each log record gets a fingerprint to determine uniqueness:

| Record type | Fingerprint components |
|:------------|:-----------------------|
| Exception   | class + code + message + file + line |
| Plain message | channel + level + message |

### Suppressed Occurrences Counter

When an error is throttled and then reappears after the window expires, the next email includes a notice indicating how many times the error has occurred since it first appeared, along with the timestamp of the first occurrence.

For example: *"⚠️ This error has occurred 47 times since 15 Mar 2025 14:30:00 UTC."*

This gives you immediate visibility into the scale of a recurring issue without flooding your inbox.

### Configuration

```php
// Throttle window in seconds (default: 60)
'throttle' => 60,

// Disable throttling
'throttle' => 0,

// Use a specific cache store (useful for multi-server setups)
'throttle_cache_store' => 'redis',
```

### Good to Know

- Messages with dynamic content (e.g. `"User 42 not found"`) produce distinct fingerprints — they won't be incorrectly grouped together.
- For multi-server deployments, use a shared cache store (Redis, Memcached) so throttling works across all instances.

## SQL Query Logging

The last 10 SQL queries leading up to the error are included in the email, with bindings and execution time. This helps understand the database state at the time of failure.

Disable with:

```php
'log_queries' => false,
```

## Editor Links

File paths in error emails are clickable when `app.editor` is configured. Clicking opens the file at the correct line in your IDE.

```php
// config/app.php
'editor' => 'phpstorm',
```

Or via `.env`:

```env
APP_EDITOR=phpstorm
```

Examples: `phpstorm`, `vscode`, `vscode-insiders`, `cursor`, `sublime`, `kiro`, `nova`, `idea`.

Custom URL scheme:

```php
'editor' => [
    'href' => 'custom://open?file={file}&line={line}',
],
```

Remote path remapping (when server paths differ from local):

```php
'editor' => [
    'name' => 'phpstorm',
    'base_path' => '/local/path/to/project',
],
```

## Upgrading

### v2 → v3

- Requires PHP 8.1+ and Laravel 10+
- Complete redesign of the email HTML output
- `HtmlFormatter::addRow()` has been removed
- Configuration API is unchanged — no config migration needed

If you extended `HtmlFormatter` or parsed the HTML output, review the new format.

## Credits

This package is a fork of [laravel-log-mailer](https://packagist.org/packages/designmynight/laravel-log-mailer) by Steve Porter.

## License

MIT — see [LICENSE](LICENSE).
