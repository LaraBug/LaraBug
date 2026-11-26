<?php

namespace LaraBug\Security\Checks;

use LaraBug\Security\InstalledPackages;

/**
 * Laravel Debugbar, as fruitcake/laravel-debugbar or the older
 * barryvdh/laravel-debugbar. It has no gate: when it is on, it is in every
 * HTML response, for every visitor, with the queries, the request and the
 * session of that page in it.
 *
 * `isEnabled()` is false in any console process, which is where this runs,
 * so it is worked out the way Debugbar works it out for a request: its
 * production guard where it has one, then `debugbar.enabled`, which falls
 * back to `app.debug`.
 */
class DebugbarCheck implements PostureCheck
{
    /**
     * Package name => [service provider, main class].
     *
     * @var array<string, array{0: class-string, 1: class-string}>
     */
    protected array $variants = [
        'fruitcake/laravel-debugbar' => ['Fruitcake\LaravelDebugbar\ServiceProvider', 'Fruitcake\LaravelDebugbar\LaravelDebugbar'],
        'barryvdh/laravel-debugbar' => ['Barryvdh\Debugbar\ServiceProvider', 'Barryvdh\Debugbar\LaravelDebugbar'],
    ];

    public function __construct(protected readonly InstalledPackages $packages)
    {
    }

    /**
     * @return array{installed: bool, enabled: bool, open_storage: bool, reachable_without_auth: bool}
     */
    public function collect(): array
    {
        $installed = false;
        $enabled = false;

        foreach ($this->variants as $package => [$provider, $debugbar]) {
            $installed = $installed || $this->packages->has($package);

            if (app()->getProviders($provider) !== []) {
                $enabled = $enabled || $this->enabled($debugbar);
            }
        }

        return [
            'installed' => $installed,
            'enabled' => $enabled,
            // Anyone can read back the stored requests of everyone else.
            'open_storage' => $enabled
                && (bool) config('debugbar.storage.enabled', true)
                && config('debugbar.storage.open') === true,
            'reachable_without_auth' => $enabled,
        ];
    }

    protected function enabled(string $debugbar): bool
    {
        if (method_exists($debugbar, 'canBeEnabled')) {
            if (! $debugbar::canBeEnabled()) {
                return false;
            }
        } elseif (app()->environment('testing')) {
            // The older Debugbar's only guard of its own.
            return false;
        }

        return (bool) (value(config('debugbar.enabled')) ?? config('app.debug'));
    }
}
