<?php

namespace LaraBug;

use Monolog\Logger;
use LaraBug\Commands\TestCommand;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        // Publish configuration file
        if (function_exists('config_path')) {
            $this->publishes([
                __DIR__ . '/../config/larabug.php' => config_path('larabug.php'),
            ]);
        }

        // Register views
        $this->app['view']->addNamespace('larabug', __DIR__ . '/../resources/views');

        // Register facade
        if (class_exists(\Illuminate\Foundation\AliasLoader::class)) {
            $loader = \Illuminate\Foundation\AliasLoader::getInstance();
            $loader->alias('LaraBug', 'LaraBug\Facade');
        }

        // Register commands
        $this->commands([
            TestCommand::class,
        ]);

        // Map any routes
        $this->mapLaraBugApiRoutes();

        // Create an alias to the larabug-js-client.blade.php include
        Blade::include('larabug::larabug-js-client', 'larabugJavaScriptClient');
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larabug.php', 'larabug');

        $this->app->singleton('larabug', function ($app) {
            return new LaraBug(new \LaraBug\Http\Client(
                config('larabug.login_key', 'login_key'),
                config('larabug.project_key', 'project_key')
            ));
        });

        if ($this->app['log'] instanceof \Illuminate\Log\LogManager) {
            $this->app['log']->extend('larabug', function ($app, $config) {
                $handler = new \LaraBug\Logger\LaraBugHandler(
                    $app['larabug']
                );

                return new Logger('larabug', [$handler]);
            });
        }
    }

    protected function mapLaraBugApiRoutes()
    {
        Route::group(
            [
                'namespace' => '\LaraBug\Http\Controllers',
                'prefix' => 'larabug-api'
            ],
            function ($router) {
                require __DIR__ . '/../routes/api.php';
            }
        );
    }
}
