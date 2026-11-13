# Changelog

All notable changes to `larabug` will be documented in this file

## 3.8.4

- Send an `X-LaraBug-Sdk` header with every report, naming the SDK, PHP and Laravel versions (`larabug-php/3.8.4 php/8.1.2 laravel/9.52.0`). The SDK version comes from Composer 2's `InstalledVersions`, then from `vendor/composer/installed.json` on Composer 1, and is `unknown` when neither has it. The `User-Agent` stays `LaraBug-Package`.

## 1.3.1 - 2019-10-11

- Cleanups & L6
