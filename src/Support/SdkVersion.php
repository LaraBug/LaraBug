<?php

namespace LaraBug\Support;

use Throwable;
use ReflectionClass;
use Composer\Autoload\ClassLoader;

class SdkVersion
{
    protected static string $packageName = 'larabug/larabug';

    protected static ?string $header = null;

    public static function header(): string
    {
        if (static::$header === null) {
            static::$header = implode(' ', [
                'larabug-php/'.static::packageVersion(),
                'php/'.PHP_VERSION,
                'laravel/'.static::laravelVersion(),
            ]);
        }

        return static::$header;
    }

    public static function packageVersion(
        string $installedVersionsClass = 'Composer\InstalledVersions',
        ?string $installedJsonPath = null
    ): string {
        return static::fromInstalledVersions($installedVersionsClass)
            ?? static::fromInstalledJson($installedJsonPath ?? static::installedJsonPath())
            ?? 'unknown';
    }

    public static function laravelVersion(): string
    {
        try {
            $version = app()->version();
        } catch (Throwable $exception) {
            return 'unknown';
        }

        return static::usableOrNull($version) ?? 'unknown';
    }

    public static function flush(): void
    {
        static::$header = null;
    }

    /** Composer 2 only. */
    protected static function fromInstalledVersions(string $installedVersionsClass): ?string
    {
        if (! class_exists($installedVersionsClass)) {
            return null;
        }

        try {
            if (! $installedVersionsClass::isInstalled(static::$packageName)) {
                return null;
            }

            return static::usableOrNull($installedVersionsClass::getPrettyVersion(static::$packageName));
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** Composer 1 writes a list of packages, Composer 2 nests them under "packages". */
    protected static function fromInstalledJson(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (! is_file($path)) {
            return null;
        }

        $installed = json_decode((string) @file_get_contents($path), true);

        if (! is_array($installed)) {
            return null;
        }

        $packages = $installed['packages'] ?? $installed;

        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            if (($package['name'] ?? null) !== static::$packageName) {
                continue;
            }

            return static::usableOrNull($package['version'] ?? null);
        }

        return null;
    }

    protected static function installedJsonPath(): ?string
    {
        if (! class_exists(ClassLoader::class)) {
            return null;
        }

        $classLoaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();

        if ($classLoaderFile === false) {
            return null;
        }

        return dirname($classLoaderFile).'/installed.json';
    }

    /** @param mixed $version */
    protected static function usableOrNull($version): ?string
    {
        if (! is_string($version)) {
            return null;
        }

        if ($version === '') {
            return null;
        }

        if (preg_match('/\s/', $version)) {
            return null;
        }

        return $version;
    }
}
