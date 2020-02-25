<p align="center">
    <a href="https://www.larabug.com" target="_blank"><img width="130" src="https://www.larabug.com/images/larabug-logo-small.png"></a>
</p>

# LaraBug
Laravel 5.8/6.x/7.x package for logging errors to [larabug.com](https://www.larabug.com)

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/larabug/larabug.svg?style=flat-square)](https://packagist.org/packages/larabug/larabug)
[![Build Status](https://img.shields.io/travis/larabug/larabug/master.svg?style=flat-square)](https://travis-ci.org/larabug/larabug)
[![Total Downloads](https://img.shields.io/packagist/dt/larabug/larabug.svg?style=flat-square)](https://packagist.org/packages/larabug/larabug)

## Installation 
You can install the package through Composer.
```bash
composer require larabug/larabug
```

Then publish the config and migration file of the package using artisan.
```bash
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```
And adjust config file (`config/larabug.php`) with your desired settings.

Note: by default only production environments will report errors. To modify this edit your larabug configuration.

## Configuration variables
All that is left to do is to define 2 env configuration variables.
```
LB_KEY=
LB_PROJECT_KEY=
```
`LB_KEY` is your profile key which authorises your account to the API.

`LB_PROJECT_KEY` is your project API key which you receive when creating a project.

Get these variables at [larabug.com](https://www.larabug.com)

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
After that you have configured the LaraBug channel you can add it to the stack section:
```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'larabug'],
    ],
    //...
],
```
## License
The larabug package is open source software licensed under the [license MIT](http://opensource.org/licenses/MIT)
