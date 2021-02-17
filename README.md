# Laravel Mail Log Channel


[![Latest Stable Version](https://img.shields.io/github/v/release/shaffe-fr/laravel-mail-log-channel.svg)](https://packagist.org/packages/shaffe/laravel-mail-log-channel) [![Total Downloads](https://img.shields.io/packagist/dt/shaffe/laravel-mail-log-channel.svg)](https://packagist.org/packages/shaffe/laravel-mail-log-channel)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

A service provider to add support for logging via email using Laravels built-in mail provider.

This package is a fork of [laravel-log-mailer](https://packagist.org/packages/designmynight/laravel-log-mailer) by Steve Porter.

![image](https://user-images.githubusercontent.com/12199424/45576336-a93c1300-b86e-11e8-9575-d1e4c5ed5dec.png)

## Table of contents

* [Installation](#installation)
* [Configuration](#configuration)

## Installation

You can install this package via composer using this commande:

```sh
composer require shaffe/laravel-mail-log-channel
```

### Laravel version compatibility

 Laravel  | Package |
:---------|:--------|
 8.x      | ^2.0   |
 7.x      | ^2.0   |
 6.x      | ^2.0   |
 5.6.x    | ^1.0   |

The package will automatically register itself if you use Laravel.

For usage with [Lumen](http://lumen.laravel.com), add the service provider in `bootstrap/app.php`.

```php
$app->register(Shaffe\MailLogChannel\MailLogChannelServiceProvider::class);
```

## Configuration

To ensure all unhandled exceptions are mailed:

1. create a `mail` logging channel in `config/logging.php`,
2. add this `mail` channel to your current logging stack,
3. add a `LOG_MAIL_ADDRESS` to your `.env` file to define the recipient.

You can specify multiple channels and individually change the recipients, the subject and the email template.

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        // 2. Add mail to the stack:
        'channels' => ['single', 'mail'],
    ],

    // ...

    // 1. Create a mail logging channel:
    'mail' => [
        'driver' => 'mail',
        'level' => env('LOG_MAIL_LEVEL', 'notice'),

        // Specify mail recipient
        'to' => [
            [
                'address' => env('LOG_MAIL_ADDRESS'),
                'name' => 'Error',
            ],
        ],

        'from' => [
            // Defaults to config('mail.from.address')
            'address' => env('LOG_MAIL_ADDRESS'),
            // Defaults to config('mail.from.name')
            'name' => 'Errors'
        ],

        // Optionally overwrite the subject format pattern
        // 'subject_format' => env('LOG_MAIL_SUBJECT_FORMAT', '[%datetime%] %level_name%: %message%'),

        // Optionally overwrite the mailable template
        // Two variables are sent to the view: `string $content` and `array $records`
        // 'mailable' => NewLogMailable::class
    ],
],
```

### Recipients configuration format

The following `to` config formats are supported:

* single email address:

    ```php
    'to' => env('LOG_MAIL_ADDRESS', ''),
    ```

* array of email addresses:

     ```php
    'to' => explode(',', env('LOG_MAIL_ADDRESS', '')),
    ```

* associative array of email => name addresses:

    ```php
    'to' => [env('LOG_MAIL_ADDRESS', '') => 'Error'],`
    ```

* array of email and name:

    ```php
    'to' => [
         [
             'address' => env('LOG_MAIL_ADDRESS', ''),
             'name' => 'Error',
         ],
     ],
    ```
