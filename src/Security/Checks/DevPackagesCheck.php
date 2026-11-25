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
     * Package name => the service provider that means it is loaded.
     *
     * @var array<string, class-string>
     */
    protected array $devTools = [
        'spatie/laravel-ignition' => 'Spatie\LaravelIgnition\IgnitionServiceProvider',
        'facade/ignition' => 'Facade\Ignition\IgnitionServiceProvider',
        'barryvdh/laravel-debugbar' => 'Barryvdh\Debugbar\ServiceProvider',
        'fruitcake/laravel-debugbar' => 'Fruitcake\LaravelDebugbar\ServiceProvider',
        'itsgoingd/clockwork' => 'Clockwork\Support\Laravel\ClockworkServiceProvider',
        'spatie/laravel-web-tinker' => 'Spatie\WebTinker\WebTinkerServiceProvider',
        'beyondcode/laravel-query-detector' => 'BeyondCode\QueryDetector\QueryDetectorServiceProvider',
        'laravel/dusk' => 'Laravel\Dusk\DuskServiceProvider',
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

        foreach ($this->devTools as $package => $provider) {
            if (app()->getProviders($provider) === []) {
                continue;
            }

            $loaded[] = [
                'package' => $package,
                'version' => $this->packages->version($package),
            ];
        }

        return [
            'composer_dev_installed' => $this->packages->devDependenciesInstalled(),
            'loaded' => $loaded,
        ];
    }
}
