<?php

namespace LaraBug\Tests\Mocks;

use OutOfBoundsException;

class InstalledVersions
{
    public static function isInstalled(string $packageName): bool
    {
        return $packageName === 'larabug/larabug';
    }

    public static function getPrettyVersion(string $packageName): ?string
    {
        if (! static::isInstalled($packageName)) {
            throw new OutOfBoundsException("Package \"{$packageName}\" is not installed");
        }

        return '3.8.4';
    }
}
