# LaraBug

Laravel 5 package for logging errors to larabug.com

## Installation 

You can install the package through Composer.
```bash
composer require larabug/larabug
```
You must install this service provider. Make this the very first provider in list.
```php
// config/app.php
'providers' => [
    // make this very first provider
    // so fatal exceptions can be catchable by envelope
    LaraBug\ServiceProvider::class,
    //...
    //...
];
```

Then publish the config and migration file of the package using artisan.
```bash
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```

Add to your Exception Handler's (```/app/Exceptions/Handler.php``` by default) ```report``` method these line:
```php

public function report(Exception $e)
{
    if ($this->shouldReport($e)) {
        (new LaraBug)->handle($e);
    }

    return parent::report($e);
}
```