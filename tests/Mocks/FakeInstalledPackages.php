<?php

namespace LaraBug\Tests\Mocks;

use LaraBug\Security\InstalledPackages;

/**
 * Installs packages by saying so.
 */
class FakeInstalledPackages extends InstalledPackages
{
    /**
     * @param  array<string, string>  $packages  Package name => version.
     */
    public function __construct(
        public array $packages = [],
        public ?bool $devInstalled = false,
    ) {
    }

    public function has(string $package): bool
    {
        return isset($this->packages[$package]);
    }

    public function version(string $package): ?string
    {
        return $this->packages[$package] ?? null;
    }

    public function devDependenciesInstalled(): ?bool
    {
        return $this->devInstalled;
    }
}
