<?php

namespace LaraBug\Support;

use OutOfBoundsException;
use Composer\InstalledVersions;
use Illuminate\Foundation\Application;

/**
 * What the SDK tells the server about itself on every request. None of it
 * changes while a process runs, so it is worked out once per process.
 */
class SdkVersion
{
    protected static ?string $version = null;

    protected static ?string $header = null;

    /**
     * The X-LaraBug-Sdk header, as space separated product tokens:
     * `larabug-php/4.0.2 php/8.4.27 laravel/13.42.0`.
     */
    public static function header(): string
    {
        return static::$header ??= implode(' ', [
            'larabug-php/'.static::version(),
            'php/'.PHP_VERSION,
            'laravel/'.static::laravelVersion(),
        ]);
    }

    /** The installed version of this package, as Composer has it. */
    public static function version(): string
    {
        return static::$version ??= static::installedVersion('larabug/larabug');
    }

    /**
     * A package Composer does not list, as when the SDK was loaded some other
     * way, is `unknown` rather than an exception in the middle of a send.
     */
    public static function installedVersion(string $package): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }

    protected static function laravelVersion(): string
    {
        if (! class_exists(Application::class)) {
            return 'unknown';
        }

        return Application::VERSION;
    }
}
