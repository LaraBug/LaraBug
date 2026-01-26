<?php

namespace LaraBug;

use LaraBug\Queue\DispatchMacros;
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

        // Register queue monitoring events
        if (config('larabug.jobs.track_jobs', true)) {
            $this->app['events']->subscribe(\LaraBug\Queue\JobEventSubscriber::class);
        }
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larabug.php', 'larabug');

        // Register the HTTP Client as a singleton
        $this->app->singleton(\LaraBug\Http\Client::class, function ($app) {
            // Check if DSN is configured
            if ($dsn = config('larabug.dsn')) {
                $parsed = \LaraBug\Support\Dsn::make($dsn);

                // Override config values with DSN values
                config(['larabug.login_key' => $parsed->getLoginKey()]);
                config(['larabug.project_key' => $parsed->getProjectKey()]);
                config(['larabug.server' => $parsed->getServer()]);

                return new \LaraBug\Http\Client(
                    $parsed->getLoginKey(),
                    $parsed->getProjectKey()
                );
            }

            // Fallback to individual config keys
            return new \LaraBug\Http\Client(
                config('larabug.login_key', 'login_key'),
                config('larabug.project_key', 'project_key')
            );
        });

        // Register the main LaraBug instance
        $this->app->singleton('larabug', function ($app) {
            return new LaraBug($app[\LaraBug\Http\Client::class]);
        });

        if ($this->app['log'] instanceof \Illuminate\Log\LogManager) {
            $this->app['log']->extend('larabug', function ($app, $config) {
                $handler = new \LaraBug\Logger\LaraBugHandler(
                    $app['larabug']
                );

                return new Logger('larabug', [$handler]);
            });
        }

        // Register queue monitoring singleton (always, will be lazy loaded)
        $this->app->singleton(\LaraBug\Queue\JobMonitor::class, function ($app) {
            return new \LaraBug\Queue\JobMonitor(
                $app[\LaraBug\Http\Client::class],
                $app['config']->get('larabug', [])
            );
        });

        // Only register macros if supported (Laravel < 11)
        if (method_exists(\Illuminate\Foundation\Bus\PendingDispatch::class, 'macro')) {
            DispatchMacros::register();
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
