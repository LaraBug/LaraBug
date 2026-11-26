<?php

namespace LaraBug\Security;

use Throwable;
use Composer\InstalledVersions;

/**
 * What Composer installed, as far as the posture snapshot needs to know.
 *
 * Its own class, resolved from the container, so a test can say what is
 * installed without installing it.
 */
class InstalledPackages
{
    public function has(string $package): bool
    {
        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
        } catch (Throwable) {
            return false;
        }
    }

    public function version(string $package): ?string
    {
        if (! $this->has($package)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion($package);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether `composer install` installed the root package's require-dev,
     * which is what leaves the dev tools on a production server. Null when
     * Composer does not say.
     */
    public function devDependenciesInstalled(): ?bool
    {
        try {
            if (! class_exists(InstalledVersions::class)) {
                return null;
            }

            $root = InstalledVersions::getRootPackage();

            return isset($root['dev']) ? (bool) $root['dev'] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
