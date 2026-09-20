<a href="https://www.larabug.com" target="_blank"><img width="150" src="assets/logo.png" alt="LaraBug"></a>

# LaraBug Laravel SDK

Official Laravel SDK for [larabug.com](https://www.larabug.com). Captures exceptions, queued jobs, requests, commands, scheduled tasks, logs and known vulnerabilities from Laravel 11, 12 and 13 on PHP 8.2 and newer.

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

That's it. Every unhandled exception, and every failed queue job, now reports to LaraBug automatically. The package registers itself with your exception handler, so there is nothing to wire up.

Reporting is scoped to environments, and only `production` reports by default. Name the others you want to hear from:

```
LB_ENVIRONMENTS=production,staging
```

Check the wiring from the application itself:

```bash
php artisan larabug:test
```

## Pointing at your own install

Self hosted installs, and staging or acceptance servers running their own copy, set the endpoint instead of the keys:

```
LB_DSN=https://login-key:project-key@larabug.example.com/api/log
```

Or set the parts separately:

```
LB_SERVER=https://larabug.example.com/api/log
LB_KEY=your-login-key
LB_PROJECT_KEY=your-project-key
```

The heartbeat endpoint follows the reporting server, so there is nothing else to configure. Set `LB_HEARTBEAT_SERVER` if it lives somewhere else. `LB_VERIFY_SSL=false` skips certificate verification, which local installs with a self signed certificate need and nothing else should use.

## Documentation

Full documentation (configuration, exception capturing, queue and job monitoring, user context, testing, and troubleshooting) lives at **[larabug.com/docs](https://www.larabug.com/docs)**.

## Related

- [LaraBug JavaScript SDK](https://github.com/LaraBug/larabug-js). Frontend error tracking for vanilla JavaScript, React, Vue 3, and Inertia.js.
- [LaraBug Mobile](https://github.com/LaraBug/larabug-mobile). The iOS and Android app.

## License

The LaraBug Laravel SDK is open source software licensed under the [MIT license](http://opensource.org/licenses/MIT).
