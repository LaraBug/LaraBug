<?php

namespace LaraBug;

use LaraBug\Queue\DispatchMacros;
use Monolog\Logger;
use LaraBug\Commands\ScanCommand;
use LaraBug\Commands\TestCommand;
use Illuminate\Console\Scheduling\Schedule;
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
            ScanCommand::class,
        ]);

        // Map any routes
        $this->mapLaraBugApiRoutes();

        // Create an alias to the larabug-js-client.blade.php include
        Blade::include('larabug::larabug-js-client', 'larabugJavaScriptClient');

        // Define the channel so applications only have to name it in their
        // stack, never copy a block into config/logging.php. An application
        // that does define its own keeps it: theirs wins.
        if (! config('logging.channels.larabug-logs')) {
            config([
                'logging.channels.larabug-logs' => [
                    'driver' => 'larabug-logs',
                    'level' => config('larabug.logs.level', 'info'),
                ],
            ]);
        }

        // Send whatever is still buffered at the end of the request. Without
        // this a request that logs less than one batch ships nothing, which is
        // most requests. Monolog's own close() covers the CLI case.
        $this->app->terminating(function () {
            if ($this->app->resolved(\LaraBug\Logger\LogBuffer::class)) {
                $this->app[\LaraBug\Logger\LogBuffer::class]->flush();
            }
        });

        // Register queue monitoring events
        if (config('larabug.jobs.track_jobs', true)) {
            $this->app['events']->subscribe(\LaraBug\Queue\JobEventSubscriber::class);
        }

        // CVE triggers
        if (config('larabug.cve.enabled', false)) {
            $trigger = strtolower((string) config('larabug.cve.trigger', 'both'));

            // Scheduler trigger: safety net for apps without inbound traffic.
            if (in_array($trigger, ['schedule', 'both'], true)) {
                $this->app->booted(function () {
                    $schedule = $this->app->make(Schedule::class);
                    $cadence = config('larabug.cve.schedule', 'daily');

                    $event = $schedule->command('larabug:scan')
                        ->withoutOverlapping()
                        ->onOneServer();

                    $this->applyCadence($event, $cadence);
                });
            }

            // Request-piggyback trigger: fires after the response is sent.
            // Detects composer.lock changes ~immediately and works without cron.
            if (in_array($trigger, ['request', 'both'], true) && ! $this->app->runningInConsole()) {
                $this->app->terminating(function () {
                    try {
                        $this->app->make(\LaraBug\Cve\RequestTrigger::class)->maybeTrigger();
                    } catch (\Throwable $e) {
                        // Never let CVE scanning break the user's app.
                    }
                });
            }
        }
    }

    protected function applyCadence($event, string $cadence): void
    {
        // switch, not match: this package still supports PHP 7.4, where a match
        // expression is a parse error. The provider loads in every test, so it
        // would take the whole suite down rather than just this path.
        switch (strtolower($cadence)) {
            case 'hourly':
                $event->hourly();
                break;
            case 'twice-daily':
            case 'twicedaily':
                $event->twiceDaily();
                break;
            case 'daily':
                $event->daily();
                break;
            default:
                $event->cron($cadence);
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
            // Check if DSN is configured and valid
            $dsn = config('larabug.dsn');
            
            if ($dsn && is_string($dsn) && trim($dsn) !== '' && \LaraBug\Support\Dsn::isValid($dsn)) {
                try {
                    $parsed = \LaraBug\Support\Dsn::make($dsn);

                    // Override config values with DSN values
                    config(['larabug.login_key' => $parsed->getLoginKey()]);
                    config(['larabug.project_key' => $parsed->getProjectKey()]);
                    config(['larabug.server' => $parsed->getServer()]);

                    return new \LaraBug\Http\Client(
                        $parsed->getLoginKey(),
                        $parsed->getProjectKey()
                    );
                } catch (\InvalidArgumentException $e) {
                    // DSN parsing failed, fall back to individual config keys
                }
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

        // Log shipping buffer. Bound lazily, so an app that never adds the
        // channel never builds one.
        $this->app->singleton(\LaraBug\Logger\LogBuffer::class, function ($app) {
            return new \LaraBug\Logger\LogBuffer(
                $app[\LaraBug\Http\Client::class],
                $app['config']->get('larabug', [])
            );
        });

        if ($this->app['log'] instanceof \Illuminate\Log\LogManager) {
            $this->app['log']->extend('larabug', function ($app, $config) {
                $handler = new \LaraBug\Logger\LaraBugHandler(
                    $app['larabug']
                );

                return new Logger('larabug', [$handler]);
            });

            $this->app['log']->extend('larabug-logs', function ($app, $config) {
                $larabug = $app['config']->get('larabug', []);

                $handler = new \LaraBug\Logger\LaraBugLogHandler(
                    $app[\LaraBug\Logger\LogBuffer::class],
                    [
                        'logs' => isset($larabug['logs']) ? $larabug['logs'] : [],
                        'environment' => $app['config']->get('app.env', ''),
                        'release' => isset($larabug['logs']['release']) ? $larabug['logs']['release'] : '',
                    ],
                    // The channel's own level wins, so a stack can ship warnings
                    // to us while writing everything to disk.
                    Logger::toMonologLevel(
                        isset($config['level'])
                            ? $config['level']
                            : (isset($larabug['logs']['level']) ? $larabug['logs']['level'] : 'info')
                    )
                );

                return new Logger('larabug-logs', [$handler]);
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
