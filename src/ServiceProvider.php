<?php

declare(strict_types=1);

namespace LaraBug;

use LaraBug\Logger\LaraBugHandler;
use Monolog\Logger;
use LaraBug\Http\Client;
use Illuminate\Log\LogManager;
use LaraBug\Commands\TestCommand;
use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('larabug')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommand(TestCommand::class)
            ->hasRoute('api')
        ;
    }

    public function packageBooted(): void
    {
        // Create an alias to the larabug-js-client.blade.php include
        Blade::include('larabug::larabug-js-client', 'larabugJavaScriptClient');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('larabug', function ($app) {
            return new LaraBug(new Client(
                config('larabug.login_key', 'login_key'),
                config('larabug.project_key', 'project_key')
            ));
        });

        if ($this->app['log'] instanceof LogManager) {
            $this->app['log']->extend('larabug', function ($app, $config) {
                $handler = new LaraBugHandler(
                    $app['larabug']
                );

                return new Logger('larabug', [$handler]);
            });
        }
    }
}
