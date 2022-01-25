<p align="center">
    <a href="https://www.larabug.com" target="_blank"><img width="130" src="https://www.larabug.com/images/larabug-logo-small.png"></a>
</p>

# LaraBug
Laravel 6.x/7.x/8.x/9.x package for logging errors to [larabug.com](https://www.larabug.com)

[![Software License](https://poser.pugx.org/larabug/larabug/license.svg)](LICENSE.md)
[![Latest Version on Packagist](https://poser.pugx.org/larabug/larabug/v/stable.svg)](https://packagist.org/packages/larabug/larabug)
[![Build Status](https://github.com/larabug/larabug/workflows/tests/badge.svg)](https://github.com/larabug/larabug/actions)
[![Total Downloads](https://poser.pugx.org/larabug/larabug/d/total.svg)](https://packagist.org/packages/larabug/larabug)

## Installation on laravel
You can install the package through Composer.
```bash
composer require larabug/larabug
```

Then publish the config and migration file of the package using the vendor publish command.
```bash
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```
And adjust config file (`config/larabug.php`) with your desired settings.

Note: by default only production environments will report errors. To modify this edit your LaraBug configuration.

## Installation on lumen
You can install the package through Composer.
```bash
composer require larabug/larabug
```

Copy the config file (`larabug.php`) to lumen config directory.
```bash
php -r "file_exists('config/') || mkdir('config/'); copy('vendor/larabug/larabug/config/larabug.php', 'config/larabug.php');"
```
And adjust config file (`config/larabug.php`) with your desired settings.

In `bootstrap/app.php` you will need to:
- Uncomment this line:
    ```php
    $app->withFacades();
    ```
- Register the larabug config file:
    ```php
    $app->configure('larabug');
    ```
- Register larabug service provider:
    ```php
    $app->register(LaraBug\ServiceProvider::class);
    ```

## Configuration variables
All that is left to do is to define two env configuration variables.
```
LB_KEY=
LB_PROJECT_KEY=
```
`LB_KEY` is your profile key which authorises your account to the API.

`LB_PROJECT_KEY` is your project API key which you've received when creating a project.

Get the variables at [larabug.com](https://www.larabug.com)

## Reporting unhandled exceptions
You can use LaraBug as a log-channel by adding the following config to the `channels` section in `config/logging.php`:
```php
'channels' => [
    // ...
    'larabug' => [
        'driver' => 'larabug',
    ],
],
```
After that you can add it to the stack section:
```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'larabug'],
    ],
    //...
],
```

PS: If you're using lumen, it could be that you don't have the `logging.php` file. So, you can use default logging file from
framework core and make changes above.
```bash
php -r "file_exists('config/') || mkdir('config/'); copy('vendor/laravel/lumen-framework/config/logging.php', 'config/logging.php');"
```

## License
The LaraBug package is open source software licensed under the [license MIT](http://opensource.org/licenses/MIT)
