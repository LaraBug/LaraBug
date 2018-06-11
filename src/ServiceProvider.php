<?php

namespace LaraBug;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        if(function_exists('config_path')) {
            /*
             * Publish configuration file
             */
            $this->publishes( [
                __DIR__ . '/../config/larabug.php' => config_path( 'larabug.php' ),
            ] );
        }

        $this->app['view']->addNamespace('larabug', __DIR__ . '/../resources/views');

        if(class_exists(\Illuminate\Foundation\AliasLoader::class)) {
            $loader = \Illuminate\Foundation\AliasLoader::getInstance();
            $loader->alias( 'LaraBug', 'LaraBug\Facade' );
        }
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larabug.php', 'larabug');

        $this->app->singleton(LaraBug::SERVICE, function ($app) {
            return new LaraBug;
        });
    }
}
