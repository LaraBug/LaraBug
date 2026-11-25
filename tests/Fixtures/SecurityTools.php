<?php

/*
 * Stand-ins for the dev and ops tools the security posture looks at, under
 * their real class names and reduced to what the checks read: the service
 * provider that marks the tool as loaded, the routes it registers with the
 * middleware it registers them with, and its gate. Loaded by the posture tests
 * only, so no other test finds a Telescope or a Debugbar.
 */

namespace Laravel\Telescope {
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\ServiceProvider;

    class Telescope
    {
        public static $authUsing;

        public static function auth($callback): void
        {
            static::$authUsing = $callback;
        }

        public static function check($request)
        {
            return (static::$authUsing ?: fn () => app()->environment('local'))($request);
        }
    }

    class TelescopeServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            if (! config('telescope.enabled')) {
                return;
            }

            Route::middlewareGroup('telescope', config('telescope.middleware', ['web']));

            Route::group(['prefix' => config('telescope.path', 'telescope'), 'middleware' => 'telescope'], function () {
                Route::get('/{view?}', fn () => 'telescope')->where('view', '(.*)')->name('telescope');
            });
        }
    }
}

namespace Laravel\Telescope\Http\Middleware {
    use Laravel\Telescope\Telescope;

    class Authorize
    {
        public function handle($request, $next)
        {
            return Telescope::check($request) ? $next($request) : abort(403);
        }
    }
}

namespace Laravel\Horizon {
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\ServiceProvider;

    class Horizon
    {
        public static $authUsing;

        public static function auth($callback): void
        {
            static::$authUsing = $callback;
        }

        public static function check($request)
        {
            return (static::$authUsing ?: fn () => app()->environment('local'))($request);
        }
    }

    class HorizonServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            Route::middlewareGroup('horizon', config('horizon.middleware', ['web']));

            Route::group(['prefix' => config('horizon.path', 'horizon'), 'middleware' => 'horizon'], function () {
                Route::get('/{view?}', fn () => 'horizon')->where('view', '(.*)')->name('horizon.index');
            });
        }
    }
}

namespace Laravel\Pulse {
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\ServiceProvider;

    class PulseServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            if (! Gate::has('viewPulse')) {
                Gate::define('viewPulse', fn ($user = null) => $this->app->environment('local'));
            }

            if (! config('pulse.enabled')) {
                return;
            }

            Route::middlewareGroup('pulse', config('pulse.middleware', []));

            Route::group(['prefix' => config('pulse.path', 'pulse'), 'middleware' => 'pulse'], function () {
                Route::get('/', fn () => 'pulse')->name('pulse');
            });
        }
    }
}

namespace Laravel\Pulse\Http\Middleware {
    use Illuminate\Support\Facades\Gate;

    class Authorize
    {
        public function handle($request, $next)
        {
            Gate::authorize('viewPulse');

            return $next($request);
        }
    }
}

namespace Fruitcake\LaravelDebugbar {
    use Illuminate\Support\ServiceProvider as BaseServiceProvider;

    class ServiceProvider extends BaseServiceProvider
    {
    }

    class LaravelDebugbar
    {
        public static function canBeEnabled(): bool
        {
            if (config('debugbar.force_allow_enable', false)) {
                return true;
            }

            return app()->hasDebugModeEnabled() && ! app()->environment('testing', 'production');
        }
    }
}

namespace Barryvdh\Debugbar {
    use Illuminate\Support\ServiceProvider as BaseServiceProvider;

    class ServiceProvider extends BaseServiceProvider
    {
    }

    class LaravelDebugbar
    {
    }
}

namespace Spatie\LaravelIgnition {
    use Illuminate\Support\ServiceProvider;

    class IgnitionServiceProvider extends ServiceProvider
    {
    }
}

namespace Laravel\Dusk {
    use Illuminate\Support\ServiceProvider;

    class DuskServiceProvider extends ServiceProvider
    {
    }
}
