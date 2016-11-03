# LaraBug

Laravel 5 package for logging errors to larabug.com

## Installation 

You can install the package through Composer.
```bash
composer require larabug/larabug
```
You must install this service provider. Make this the very *first* provider in list.
```php
// config/app.php
'providers' => [
    // Make this very first provider
    LaraBug\ServiceProvider::class,
    
    //...
    //...
];
```

Then publish the config and migration file of the package using artisan.
```bash
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```

Add to your Exception Handler's (```/app/Exceptions/Handler.php``` by default) ```report``` method these line and add the use line:
```
use LaraBug\LaraBug;
...

public function report(Exception $e)
{
    if ($this->shouldReport($e)) {
        (new LaraBug)->handle($e);
    }

    return parent::report($e);
}
```