<?php

namespace LaraBug;

use Throwable;
use Monolog\Logger;
use SplObjectStorage;
use LaraBug\Http\Client;
use LaraBug\Support\Dsn;
use InvalidArgumentException;
use LaraBug\Logger\LogBuffer;
use LaraBug\Queue\JobMonitor;
use LaraBug\Requests\Sampler;
use Illuminate\Log\LogManager;
use LaraBug\Cve\RequestTrigger;
use LaraBug\Commands\ScanCommand;
use LaraBug\Commands\TestCommand;
use LaraBug\Queue\DispatchMacros;
use LaraBug\Console\CommandBuffer;
use LaraBug\Logger\LaraBugHandler;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Requests\RequestMonitor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use LaraBug\Console\CommandListeners;
use LaraBug\Logger\LaraBugLogHandler;
use LaraBug\Queue\JobEventSubscriber;
use Illuminate\Foundation\AliasLoader;
use LaraBug\Commands\HeartbeatCommand;
use LaraBug\Requests\RequestListeners;
use Illuminate\Console\Scheduling\Event;
use LaraBug\Console\ScheduledTaskBuffer;
use Illuminate\Console\Scheduling\Schedule;
use LaraBug\Console\ScheduledTaskListeners;
use LaraBug\Http\Middleware\CaptureRequest;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /** Handlers that already carry our reportable callback. */
    protected SplObjectStorage $handlersRegistered;

    public function boot(): void
    {
        if (function_exists('config_path')) {
            $this->publishes([
                __DIR__ . '/../config/larabug.php' => config_path('larabug.php'),
            ]);
        }

        $this->app['view']->addNamespace('larabug', __DIR__ . '/../resources/views');

        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('LaraBug', Facade::class);
        }

        $this->commands([
            TestCommand::class,
            ScanCommand::class,
            HeartbeatCommand::class,
        ]);

        $this->mapLaraBugApiRoutes();

        Blade::include('larabug::larabug-js-client', 'larabugJavaScriptClient');

        // Report exceptions without every application having to wire LaraBug
        // into its own exception handler, which is silently forgotten often
        // enough that an application looks healthy while reporting nothing.
        if (config('larabug.register_exception_handler', true)) {
            $this->registerExceptionHandler();
        }

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
            if ($this->app->resolved(LogBuffer::class)) {
                $this->app[LogBuffer::class]->flush();
            }
        });

        // Request monitoring. Pushed rather than prepended: the stage either
        // side of this middleware is meant to be the application's own stack,
        // and running first would fold every other middleware into the action.
        if (config('larabug.requests.track_requests', false) && ! $this->app->runningInConsole()) {
            try {
                $this->app->make(Kernel::class)->pushMiddleware(CaptureRequest::class);

                $this->app['events']->subscribe(RequestListeners::class);
            } catch (Throwable) {
                // An application with no HTTP kernel, or one that resolves it
                // differently, simply does not get request monitoring.
            }
        }

        if (config('larabug.jobs.track_jobs', true)) {
            $this->app['events']->subscribe(JobEventSubscriber::class);
        }

        // Command monitoring. The inverse of request monitoring: a command runs
        // in the console, so this is not gated behind runningInConsole.
        if (config('larabug.commands.track_commands', false)) {
            $this->app['events']->subscribe(CommandListeners::class);
        }

        // Scheduled task monitoring, the same context as commands.
        if (config('larabug.schedule.track_scheduled_tasks', false)) {
            $this->app['events']->subscribe(ScheduledTaskListeners::class);
        }

        // The heartbeat only has a job to do where the scheduler runs, which is
        // also the only place it can be registered from.
        if (config('larabug.heartbeat.enabled', true)) {
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);

                $event = $schedule->command('larabug:heartbeat')
                    ->withoutOverlapping()
                    // Not onOneServer: each server runs its own workers, and a
                    // heartbeat from one of them says nothing about the rest.
                    ->runInBackground();

                $this->applyHeartbeatCadence($event, config('larabug.heartbeat.schedule', 'everyMinute'));
            });
        }

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
                        $this->app->make(RequestTrigger::class)->maybeTrigger();
                    } catch (Throwable) {
                        // Never let CVE scanning break the user's app.
                    }
                });
            }
        }
    }

    protected function applyHeartbeatCadence(Event $event, string $cadence): void
    {
        match (strtolower($cadence)) {
            'everytwominutes' => $event->everyTwoMinutes(),
            'everyfiveminutes' => $event->everyFiveMinutes(),
            'everytenminutes' => $event->everyTenMinutes(),
            default => $event->everyMinute(),
        };
    }

    protected function applyCadence(Event $event, string $cadence): void
    {
        match (strtolower($cadence)) {
            'hourly' => $event->hourly(),
            'twice-daily', 'twicedaily' => $event->twiceDaily(),
            'daily' => $event->daily(),
            default => $event->cron($cadence),
        };
    }

    /**
     * Report every reported exception to LaraBug.
     */
    protected function registerExceptionHandler(): void
    {
        $this->handlersRegistered = new SplObjectStorage();

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            // A handler that does not extend Laravel's own has no reportable(),
            // in which case the application keeps calling handle() itself.
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            // Resolving the handler more than once is normal, Collision wraps it
            // for one. Without this guard every resolution adds another callback
            // and one exception is reported once per resolution.
            if ($this->handlersRegistered->contains($handler)) {
                return;
            }

            $this->handlersRegistered->attach($handler);

            $handler->reportable(function (Throwable $exception) {
                // Deliberately not returning handle()'s value. A reportable
                // callback that returns false stops the exception reaching the
                // application's own logging, and handle() returns false for
                // everything it skips.
                $this->app['larabug']->handle($exception);
            });
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larabug.php', 'larabug');

        $this->app->singleton(Client::class, function () {
            $dsn = config('larabug.dsn');

            if ($dsn && is_string($dsn) && trim($dsn) !== '' && Dsn::isValid($dsn)) {
                try {
                    $parsed = Dsn::make($dsn);

                    // The DSN wins over the individual config keys.
                    config(['larabug.login_key' => $parsed->getLoginKey()]);
                    config(['larabug.project_key' => $parsed->getProjectKey()]);
                    config(['larabug.server' => $parsed->getServer()]);

                    return new Client(
                        $parsed->getLoginKey(),
                        $parsed->getProjectKey()
                    );
                } catch (InvalidArgumentException) {
                    // DSN parsing failed, fall back to the individual config keys.
                }
            }

            return new Client(
                config('larabug.login_key', 'login_key'),
                config('larabug.project_key', 'project_key')
            );
        });

        $this->app->singleton('larabug', fn ($app) => new LaraBug($app[Client::class]));

        // Log shipping buffer. Bound lazily, so an app that never adds the
        // channel never builds one.
        $this->app->singleton(LogBuffer::class, fn ($app) => new LogBuffer(
            $app[Client::class],
            $app['config']->get('larabug', [])
        ));

        if ($this->app['log'] instanceof LogManager) {
            $this->app['log']->extend('larabug', fn ($app, $config) => new Logger('larabug', [
                new LaraBugHandler($app['larabug']),
            ]));

            $this->app['log']->extend('larabug-logs', function ($app, $config) {
                $larabug = $app['config']->get('larabug', []);

                $handler = new LaraBugLogHandler(
                    $app[LogBuffer::class],
                    [
                        'logs' => $larabug['logs'] ?? [],
                        'environment' => $app['config']->get('app.env', ''),
                        'release' => $larabug['logs']['release'] ?? '',
                    ],
                    // The channel's own level wins, so a stack can ship warnings
                    // to us while writing everything to disk.
                    Logger::toMonologLevel($config['level'] ?? $larabug['logs']['level'] ?? 'info')
                );

                return new Logger('larabug-logs', [$handler]);
            });
        }

        // Request monitoring. One of each per execution: the monitor holds the
        // state of the request being served, the sampler holds the decision
        // made about it, and the buffer outlives both to flush on shutdown.
        $this->app->singleton(RequestMonitor::class);
        $this->app->singleton(Sampler::class);

        $this->app->singleton(RequestBuffer::class, fn ($app) => new RequestBuffer(
            $app->make(Client::class),
            config('larabug')
        ));

        $this->app->singleton(CommandBuffer::class, fn ($app) => new CommandBuffer(
            $app->make(Client::class),
            config('larabug')
        ));

        $this->app->singleton(ScheduledTaskBuffer::class, fn ($app) => new ScheduledTaskBuffer(
            $app->make(Client::class),
            config('larabug')
        ));

        // Always bound; only built when job tracking first touches it.
        $this->app->singleton(JobMonitor::class, fn ($app) => new JobMonitor(
            $app[Client::class],
            $app['config']->get('larabug', [])
        ));

        // Only register macros if supported (Laravel < 11)
        if (method_exists(PendingDispatch::class, 'macro')) {
            DispatchMacros::register();
        }
    }

    protected function mapLaraBugApiRoutes(): void
    {
        Route::group(
            [
                'namespace' => '\LaraBug\Http\Controllers',
                'prefix' => 'larabug-api',
            ],
            function ($router) {
                require __DIR__ . '/../routes/api.php';
            }
        );
    }
}
