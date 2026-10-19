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
use LaraBug\Mail\MailListeners;
use LaraBug\Commands\ScanCommand;
use LaraBug\Commands\TestCommand;
use LaraBug\Queue\DispatchMacros;
use LaraBug\Console\CommandBuffer;
use LaraBug\Logger\LaraBugHandler;
use LaraBug\Queue\JobTracePayload;
use LaraBug\Requests\RequestBuffer;
use Illuminate\Queue\Events\Looping;
use LaraBug\Requests\PropagateTrace;
use LaraBug\Requests\QueryExplainer;
use LaraBug\Requests\RequestMonitor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use LaraBug\Console\CommandListeners;
use LaraBug\Livewire\LivewireContext;
use LaraBug\Logger\DeprecationBuffer;
use LaraBug\Logger\LaraBugLogHandler;
use LaraBug\Queue\JobEventSubscriber;
use Illuminate\Foundation\AliasLoader;
use LaraBug\Requests\RequestListeners;
use LaraBug\Commands\QueueDepthCommand;
use LaraBug\Livewire\LivewireListeners;
use Illuminate\Console\Scheduling\Event;
use LaraBug\Console\ScheduledTaskBuffer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\WorkerStopping;
use LaraBug\Console\ScheduledTaskListeners;
use LaraBug\Http\Middleware\CaptureRequest;
use LaraBug\Logger\LaraBugDeprecationHandler;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
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
            QueueDepthCommand::class,
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

        // Every deprecation Laravel hands over is taken, whatever logs.level
        // says: Laravel writes them all as warnings, and they have an opt-out
        // of their own.
        if (! config('logging.channels.larabug-deprecations')) {
            config([
                'logging.channels.larabug-deprecations' => [
                    'driver' => 'larabug-deprecations',
                    'level' => 'debug',
                ],
            ]);
        }

        // Send whatever is still buffered at the end of the request. Without
        // this a request that logs less than one batch ships nothing, which is
        // most requests. Monolog's own close() covers the CLI case.
        $this->app->terminating(function () {
            if ($this->app->resolved(DeprecationBuffer::class)) {
                $this->app[DeprecationBuffer::class]->flushIfDue();
            }

            if ($this->app->resolved(LogBuffer::class)) {
                $this->app[LogBuffer::class]->flush();
            }
        });

        // Request monitoring. Pushed rather than prepended: the stage either
        // side of this middleware is meant to be the application's own stack,
        // and running first would fold every other middleware into the action.
        if ($this->tracksRequests()) {
            try {
                $this->app->make(Kernel::class)->pushMiddleware(CaptureRequest::class);

                $this->app['events']->subscribe(RequestListeners::class);
            } catch (Throwable) {
                // An application with no HTTP kernel, or one that resolves it
                // differently, simply does not get request monitoring.
            }
        }

        // The other half of the traceparent CaptureRequest reads: the
        // application's own calls carry this execution's trace, to the hosts
        // it was told to send it to. Not gated on request monitoring, because
        // a job or a command calling another application is a parent too.
        $this->callAfterResolving(HttpFactory::class, function (HttpFactory $factory) {
            $factory->globalRequestMiddleware(new PropagateTrace());
        });

        $this->registerDeprecationFlushes();

        $this->registerLivewireListeners();

        if (config('larabug.jobs.track_jobs', true)) {
            $this->app['events']->subscribe(JobEventSubscriber::class);

            // A job is processed by another process long after the execution
            // that dispatched it has ended, so the trace it came out of is
            // written into the job's payload while that execution is still up.
            JobTracePayload::register();
        }

        // A queue whose workers have stopped is one no worker reports on, so its
        // depth is read from the scheduler instead. On one server, because the
        // queues are shared and one sample a minute says all there is to say.
        // Released after five minutes rather than a day, so a sampler that died
        // holding the lock does not stop sampling until tomorrow.
        if (config('larabug.jobs.depth.enabled', true)) {
            $this->app->booted(function () {
                $this->app->make(Schedule::class)
                    ->command('larabug:queue-depth')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->onOneServer();
            });
        }

        // Command monitoring. The inverse of request monitoring: a command runs
        // in the console, so this is not gated behind runningInConsole.
        if (config('larabug.commands.track_commands', true)) {
            $this->app['events']->subscribe(CommandListeners::class);
        }

        // Mail, recorded against whichever of those executions sent it. Once,
        // whichever of them is on: a request running a sync job and a command
        // running a worker both need it, and the innermost execution is the
        // one that gets the message.
        if ($this->tracksRequests() || config('larabug.jobs.track_jobs', true) || config('larabug.commands.track_commands', true)) {
            $this->app['events']->subscribe(MailListeners::class);
        }

        // Scheduled task monitoring, the same context as commands.
        if (config('larabug.schedule.track_scheduled_tasks', true)) {
            $this->app['events']->subscribe(ScheduledTaskListeners::class);
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

    /**
     * Whether this execution is a request being recorded. A console process
     * never is, however the config reads: nothing would ever flush it.
     */
    protected function tracksRequests(): bool
    {
        return config('larabug.requests.track_requests', true) && ! $this->app->runningInConsole();
    }

    /**
     * Put our log channel into the stack the application actually logs through.
     *
     * Defining the channel is not the same as using it. Until this existed the
     * opt-in was an edit to the host's logging config, usually
     * `LOG_STACK=single,larabug-logs`, so `logs.enabled` could be true while
     * nothing whatsoever was shipped. Every installation that never read that
     * far had logs off and no way to tell.
     *
     * In `register()` rather than `boot()`: the config is already loaded here,
     * and the log manager resolves and caches a channel the first time
     * something writes to it. A provider that logs during boot would resolve
     * the stack before a change made in our boot could reach it.
     *
     * Decided on the default channel's driver, not its name, so an application
     * whose stack is called something else is still handled. Idempotent, so an
     * application that already named the channel itself gets nothing added and
     * an Octane worker rebooting the container does not stack up copies.
     */
    protected function addLogChannelToTheApplicationsStack(): void
    {
        $config = $this->app['config'];

        if (! $config->get('larabug.logs.enabled', true)) {
            return;
        }

        $default = $config->get('logging.default');

        if (! is_string($default) || $default === '' || $default === 'larabug-logs') {
            return;
        }

        $channel = $config->get("logging.channels.{$default}");

        // An application whose default names no channel at all is misconfigured
        // in a way that is not ours to repair.
        if (! is_array($channel)) {
            return;
        }

        if (($channel['driver'] ?? null) === 'stack') {
            $channels = $channel['channels'] ?? [];

            if (in_array('larabug-logs', $channels, true)) {
                return;
            }

            $config->set("logging.channels.{$default}.channels", [...$channels, 'larabug-logs']);

            return;
        }

        // A single channel, so there is no stack to join. Wrap it in one rather
        // than replacing it: the application's own logging has to carry on
        // writing exactly where it was writing before.
        $config->set('logging.channels.larabug-stack', [
            'driver' => 'stack',
            'channels' => [$default, 'larabug-logs'],
            'ignore_exceptions' => false,
        ]);

        $config->set('logging.default', 'larabug-stack');
    }

    /**
     * Point Laravel's deprecations channel at LaraBug.
     *
     * Laravel writes deprecations to a channel of their own, named by
     * `logging.deprecations.channel`, which is null unless the application
     * sets LOG_DEPRECATIONS_CHANNEL. So they are discarded before the default
     * stack, and with it `larabug-logs`, ever sees them.
     *
     * An application that sends them nowhere gets our channel. One that sends
     * them somewhere keeps that destination, with ours stacked next to it. In
     * `register()` for the same reason the default stack is extended there,
     * and idempotent for the same reasons too. `logging.deprecations.trace` is
     * left alone: Laravel reads it when it writes the line, and the handler
     * understands the line either way.
     */
    protected function addLogChannelToTheDeprecationsChannel(): void
    {
        $config = $this->app['config'];

        if (! $config->get('larabug.logs.enabled', true)) {
            return;
        }

        if (! $config->get('larabug.logs.deprecations.enabled', true)) {
            return;
        }

        // Laravel only reads the setting when the application has not defined
        // a channel called `deprecations` itself, so that one is extended.
        $defined = $config->get('logging.channels.deprecations');

        if (is_array($defined)) {
            $this->addLogChannelToTheDefinedDeprecationsChannel($defined);

            return;
        }

        // Older applications set it as a bare channel name, not an array.
        $setting = is_string($config->get('logging.deprecations'))
            ? 'logging.deprecations'
            : 'logging.deprecations.channel';

        $channel = $config->get($setting);

        if (in_array($channel, ['larabug-deprecations', 'larabug-deprecations-stack'], true)) {
            return;
        }

        $destinations = $this->deprecationDestinations($channel);

        if ($destinations === []) {
            $config->set($setting, 'larabug-deprecations');

            return;
        }

        $config->set('logging.channels.larabug-deprecations-stack', [
            'driver' => 'stack',
            'channels' => [...$destinations, 'larabug-deprecations'],
            'ignore_exceptions' => false,
        ]);

        $config->set($setting, 'larabug-deprecations-stack');
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    protected function addLogChannelToTheDefinedDeprecationsChannel(array $channel): void
    {
        $config = $this->app['config'];

        if (($channel['driver'] ?? null) === 'stack') {
            $channels = array_values(array_diff($this->stackedChannels($channel), ['larabug-logs', 'larabug-deprecations']));

            $config->set('logging.channels.deprecations.channels', [...$channels, 'larabug-deprecations']);

            return;
        }

        // A stack cannot name the channel it replaces, so the application's
        // channel moves under a name of our own and keeps writing as before.
        $config->set('logging.channels.larabug-deprecations-destination', $channel);

        $config->set('logging.channels.deprecations', array_filter([
            'driver' => 'stack',
            'name' => $channel['name'] ?? null,
            'channels' => ['larabug-deprecations-destination', 'larabug-deprecations'],
            'ignore_exceptions' => false,
        ], fn ($value) => $value !== null));
    }

    /**
     * The channels an application sends its deprecations to, none when it
     * sends them nowhere. Our own `larabug-logs` is never one of them: it would
     * ship every occurrence of a deprecation as a line of its own, which is
     * what our deprecations channel exists to prevent.
     *
     * @return array<int, string>
     */
    protected function deprecationDestinations(mixed $channel): array
    {
        if (! is_string($channel)) {
            return [];
        }

        if (in_array($channel, ['', 'null', 'larabug-logs'], true)) {
            return [];
        }

        $config = $this->app['config']->get("logging.channels.{$channel}");

        if (! is_array($config)) {
            return [];
        }

        if (($config['driver'] ?? null) !== 'stack') {
            return [$channel];
        }

        $channels = $this->stackedChannels($config);

        if (! in_array('larabug-logs', $channels, true)) {
            return [$channel];
        }

        return array_values(array_diff($channels, ['larabug-logs']));
    }

    /**
     * @param  array<string, mixed>  $stack
     * @return array<int, string>
     */
    protected function stackedChannels(array $stack): array
    {
        $channels = $stack['channels'] ?? [];

        // LOG_STACK=single,larabug-logs reaches the config as one string.
        if (is_string($channels)) {
            $channels = explode(',', $channels);
        }

        return array_values(array_filter($channels, 'is_string'));
    }

    /**
     * A queue worker never terminates the application, so what has been
     * coalesced is sent from its loop, and all of it once it stops. A worker
     * killing itself over a timed out job skips the shutdown function.
     */
    protected function registerDeprecationFlushes(): void
    {
        if (! config('larabug.logs.deprecations.enabled', true)) {
            return;
        }

        $this->app['events']->listen(Looping::class, function () {
            if ($this->app->resolved(DeprecationBuffer::class)) {
                $this->app[DeprecationBuffer::class]->flushIfDue();
            }
        });

        $this->app['events']->listen(WorkerStopping::class, function () {
            if ($this->app->resolved(DeprecationBuffer::class)) {
                $this->app[DeprecationBuffer::class]->flush();
            }
        });
    }

    /**
     * Listen to Livewire's lifecycle, in the applications that have Livewire.
     *
     * Livewire is not a dependency of this package and most applications using
     * it do not have it installed. Nothing below loads a Livewire class, calls
     * a Livewire method or costs anything at all when it is absent: the
     * container is asked whether the manager is bound, which is the same
     * question Livewire's own optional integrations ask.
     */
    protected function registerLivewireListeners(): void
    {
        if (! config('larabug.livewire.track_livewire', true)) {
            return;
        }

        if (! $this->app->bound('livewire')) {
            return;
        }

        try {
            $this->app->make(LivewireListeners::class)->subscribe($this->app->make('livewire'));
        } catch (Throwable) {
            // An application whose 'livewire' binding is something else
            // entirely simply does not get component monitoring.
        }
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

        $this->addLogChannelToTheApplicationsStack();

        $this->addLogChannelToTheDeprecationsChannel();

        // Log shipping buffer. Bound lazily, so an app that never adds the
        // channel never builds one.
        $this->app->singleton(LogBuffer::class, fn ($app) => new LogBuffer(
            $app[Client::class],
            $app['config']->get('larabug', [])
        ));

        $this->app->singleton(DeprecationBuffer::class, fn ($app) => new DeprecationBuffer(
            $app[LogBuffer::class],
            (int) $app['config']->get('larabug.logs.deprecations.interval', 3600)
        ));

        if ($this->app['log'] instanceof LogManager) {
            // A local closure rather than a method: the log manager binds the
            // drivers below to itself, so $this inside them is not this class.
            $handlerConfig = fn ($app) => [
                'logs' => $app['config']->get('larabug.logs', []),
                'environment' => $app['config']->get('app.env', ''),
                'release' => $app['config']->get('larabug.logs.release') ?? '',
            ];

            $this->app['log']->extend('larabug', fn ($app, $config) => new Logger('larabug', [
                new LaraBugHandler($app['larabug']),
            ]));

            $this->app['log']->extend('larabug-logs', fn ($app, $config) => new Logger('larabug-logs', [
                new LaraBugLogHandler(
                    $app[LogBuffer::class],
                    $handlerConfig($app),
                    // The channel's own level wins, so a stack can ship warnings
                    // to us while writing everything to disk.
                    Logger::toMonologLevel($config['level'] ?? $app['config']->get('larabug.logs.level') ?? 'info')
                ),
            ]));

            $this->app['log']->extend('larabug-deprecations', fn ($app, $config) => new Logger('deprecations', [
                new LaraBugDeprecationHandler(
                    $app[LogBuffer::class],
                    $app[DeprecationBuffer::class],
                    $handlerConfig($app),
                    Logger::toMonologLevel($config['level'] ?? 'debug')
                ),
            ]));
        }

        // Request monitoring. One of each per execution: the monitor holds the
        // state of the request being served, the sampler holds the decision
        // made about it, and the buffer outlives both to flush on shutdown.
        $this->app->singleton(RequestMonitor::class);
        $this->app->singleton(Sampler::class);

        // One per monitor, since it hooks the monitor's flush, and it keeps the
        // statements it explained for as long as the process lives.
        $this->app->singleton(QueryExplainer::class);

        // One per process, shared by the job and command listeners that open
        // executions on it. The request is only handed over when one is being
        // recorded, so mail sent outside every job and command of a worker is
        // dropped rather than collected into a record nobody sends.
        $this->app->singleton(MailListeners::class, fn ($app) => new MailListeners(
            $this->tracksRequests() ? $app->make(RequestMonitor::class) : null
        ));

        // Scoped rather than singleton: under Octane a worker serves one
        // request after another in the same process, and a component addressed
        // by one request must not still be attached to the next one's
        // exception report.
        $this->app->scoped(LivewireContext::class);

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
                // Anyone can reach this endpoint: the bundled client posts to
                // it from the browser of every visitor, before anybody is
                // logged in. A limit is the only thing between a broken asset,
                // or someone with curl, and the project's whole quota. The
                // `web` group is deliberately not here, since its CSRF check
                // would reject the very client that posts these reports.
                'middleware' => ['throttle:' . config('larabug.report_throttle', '60,1')],
            ],
            function ($router) {
                require __DIR__ . '/../routes/api.php';
            }
        );
    }
}
