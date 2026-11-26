<?php

namespace LaraBug\Security\Checks;

use LaraBug\Security\InstalledPackages;

/**
 * Development tools running in this environment: whether Composer installed
 * the require-dev packages at all, and which known dev tools have their
 * service provider loaded.
 *
 * Telescope is left to the dashboard check, since running it behind a gate
 * in production is a choice, not a mistake.
 */
class DevPackagesCheck implements PostureCheck
{
    /**
     * The service provider that means a tool is loaded => the packages it
     * ships in. Debugbar 4 has its Fruitcake classes in both its new package
     * and its old name, which is still the one most applications require.
     *
     * @var array<class-string, array<int, string>>
     */
    protected array $devTools = [
        'Spatie\LaravelIgnition\IgnitionServiceProvider' => ['spatie/laravel-ignition'],
        'Facade\Ignition\IgnitionServiceProvider' => ['facade/ignition'],
        'Fruitcake\LaravelDebugbar\ServiceProvider' => ['barryvdh/laravel-debugbar', 'fruitcake/laravel-debugbar'],
        'Barryvdh\Debugbar\ServiceProvider' => ['barryvdh/laravel-debugbar'],
        'Clockwork\Support\Laravel\ClockworkServiceProvider' => ['itsgoingd/clockwork'],
        'Spatie\WebTinker\WebTinkerServiceProvider' => ['spatie/laravel-web-tinker'],
        'BeyondCode\QueryDetector\QueryDetectorServiceProvider' => ['beyondcode/laravel-query-detector'],
        'Laravel\Dusk\DuskServiceProvider' => ['laravel/dusk'],
    ];

    public function __construct(protected readonly InstalledPackages $packages)
    {
    }

    /**
     * @return array{composer_dev_installed: ?bool, loaded: array<int, array{package: string, version: ?string}>}
     */
    public function collect(): array
    {
        $loaded = [];

        foreach ($this->devTools as $provider => $packages) {
            if (app()->getProviders($provider) === []) {
                continue;
            }

            $package = $this->installedOf($packages);

            $loaded[$package] = [
                'package' => $package,
                'version' => $this->packages->version($package),
            ];
        }

        return [
            'composer_dev_installed' => $this->packages->devDependenciesInstalled(),
            'loaded' => array_values($loaded),
        ];
    }

    /**
     * The one of these packages Composer installed, the first when none is.
     *
     * @param  array<int, string>  $packages
     */
    protected function installedOf(array $packages): string
    {
        foreach ($packages as $package) {
            if ($this->packages->has($package)) {
                return $package;
            }
        }

        return $packages[0];
    }
}
