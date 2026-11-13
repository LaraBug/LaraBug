<a href="https://www.larabug.com" target="_blank"><img width="150" src="assets/logo.png" alt="LaraBug"></a>

# LaraBug Laravel SDK

Official Laravel SDK for [larabug.com](https://www.larabug.com). Captures unhandled exceptions and queued job failures from Laravel 11, 12 and 13 on PHP 8.2 and newer.

[![Software License](https://poser.pugx.org/larabug/larabug/license.svg)](../LICENSE.md)
[![Latest Version on Packagist](https://poser.pugx.org/larabug/larabug/v/stable.svg)](https://packagist.org/packages/larabug/larabug)
[![Build Status](https://img.shields.io/github/checks-status/LaraBug/LaraBug/v4?label=tests)](https://github.com/LaraBug/LaraBug/commits/v4)
[![Total Downloads](https://poser.pugx.org/larabug/larabug/d/total.svg)](https://packagist.org/packages/larabug/larabug)

## Installation

```bash
composer require larabug/larabug
```

The package works with Guzzle 7 and Guzzle 8, so it installs on a new Laravel 13 application as is. Laravel 10 and older, or PHP 8.1 and older, need the 3.x line (`composer require larabug/larabug:^3.0`).

Publish the config file:

```bash
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```

Set your credentials in `.env`:

```
LB_KEY=your-login-key
LB_PROJECT_KEY=your-project-key
```

Get both keys from your project at [larabug.com](https://www.larabug.com).

Finally, add `larabug` to your default log stack in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'larabug'],
        'ignore_exceptions' => false,
    ],

    'larabug' => [
        'driver' => 'larabug',
    ],
],
```

That's it. Every unhandled exception, and every failed queue job, now reports to LaraBug automatically.

## Retry buffer

When the LaraBug server answers with a 5xx or cannot be reached (a deploy, maintenance mode, an outage), the SDK keeps what it was sending on local disk instead of dropping it. Kept events go out again, oldest first, on the next send that succeeds and from `php artisan larabug:flush`. The package schedules that command every minute while something is waiting, so it only needs `schedule:run`. A flush holds a lock, so two processes never send the same event, and it stops at the first server error. A `Retry-After` header on a 5xx is honoured for up to 10 minutes, and a server that cannot be reached holds sends for 30 seconds, so only the first request pays the timeout.

A 4xx is never kept, since it will not get better. A 429 or 402 is handled by the backoff as before. CVE scans and queue depth samples are never kept either. Only the payload that would have been sent is written, after the data filters ran, and never your login key. Nothing the buffer does can throw into your application: if it cannot write, the event is lost as it was before.

```
LB_RETRY_BUFFER_ENABLED=true
LB_RETRY_BUFFER_PATH=/path/on/local/disk
LB_RETRY_BUFFER_MAX_EVENTS=500
LB_RETRY_BUFFER_MAX_BYTES=5242880
LB_RETRY_BUFFER_MAX_AGE=86400
```

The path defaults to `storage/larabug/retry-buffer` and the age is in seconds. Past the age bound an event is dropped. Past the count or size bound the oldest telemetry goes first, and exceptions only once no telemetry is left. Every server keeps its own buffer, so the path has to be writable by both the web server and the user the scheduler runs as.

## SDK version

Every request the SDK sends carries an `X-LaraBug-Sdk` header naming the SDK, PHP and Laravel versions, for example `larabug-php/4.0.2 php/8.4.27 laravel/13.42.0`. The SDK version is read from Composer and is `unknown` when Composer does not list the package. An event flushed from the retry buffer carries the version that sent it, not the one that kept it.

## Documentation

Full documentation (configuration, exception capturing, queue and job monitoring, user context, testing, and troubleshooting) lives at **[larabug.com/docs](https://www.larabug.com/docs)**.

## Related

- [LaraBug JavaScript SDK](https://github.com/LaraBug/larabug-js). Frontend error tracking for vanilla JavaScript, React, Vue 3, and Inertia.js.
- [LaraBug Mobile](https://github.com/LaraBug/larabug-mobile). The iOS and Android app.

## License

The LaraBug Laravel SDK is open source software licensed under the [MIT license](http://opensource.org/licenses/MIT).
