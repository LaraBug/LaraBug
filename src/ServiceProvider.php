<?php namespace LaraBug;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{

    protected $defer = false;

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        /*
         * Publish configuration file
         */
        $this->publishes([
            __DIR__ . '/../config/larabug.php' => config_path('larabug.php'),
        ]);

        $this->app['view']->addNamespace('larabug', __DIR__ . '/../resources/views');

        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('LaraBug', 'LaraBug\Facade');
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        config([
            'config/larabug.php',
        ]);

        $this->app['larabug'] = $this->app->share(function ($app) {
            return new LaraBug();
        });
    }
}
