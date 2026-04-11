<a href="https://www.larabug.com" target="_blank"><img width="150" src="assets/logo.png" alt="LaraBug"></a>

# LaraBug Laravel SDK

Official Laravel SDK for [larabug.com](https://www.larabug.com). Captures unhandled exceptions and queued job failures from Laravel 6 through 13 on PHP 7.4 and newer.

[![Software License](https://poser.pugx.org/larabug/larabug/license.svg)](../LICENSE.md)
[![Latest Version on Packagist](https://poser.pugx.org/larabug/larabug/v/stable.svg)](https://packagist.org/packages/larabug/larabug)
[![Build Status](https://github.com/larabug/larabug/workflows/tests/badge.svg)](https://github.com/larabug/larabug/actions)
[![Total Downloads](https://poser.pugx.org/larabug/larabug/d/total.svg)](https://packagist.org/packages/larabug/larabug)

## Installation

```bash
composer require larabug/larabug
```

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

## Documentation

Full documentation — configuration, exception capturing, queue and job monitoring, user context, testing, and troubleshooting — lives at **[larabug.com/docs](https://www.larabug.com/docs)**.

## Related

- [LaraBug JavaScript SDK](https://github.com/LaraBug/larabug-js) — frontend error tracking for Vanilla JavaScript, React, Vue 3, and Inertia.js.

## License

The LaraBug Laravel SDK is open source software licensed under the [MIT license](http://opensource.org/licenses/MIT).
