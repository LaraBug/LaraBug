<?php

namespace LaraBug\Scanners;

/**
 * Parses the host application's composer.lock into a payload suitable for
 * /api/log with type=cve_scan. Pure data, no I/O beyond reading the file —
 * easy to test and reason about.
 */
class ComposerLockScanner
{
    /**
     * @return array{
     *     content_hash: string,
     *     packages: array<string, string>,
     *     php_version: ?string,
     *     framework: ?string,
     *     framework_version: ?string,
     *     environment: ?string,
     * }|null
     */
    public function scan(?string $lockPath = null, bool $includeDev = false, ?string $environment = null): ?array
    {
        $path = $lockPath ?: base_path('composer.lock');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        $packages = $this->extractPackages($data, $includeDev);

        if (empty($packages)) {
            return null;
        }

        return [
            'content_hash' => hash('sha256', $raw),
            'packages' => $packages,
            'php_version' => $this->extractPhpVersion($data),
            'framework' => $this->detectFramework($packages),
            'framework_version' => $packages['laravel/framework'] ?? null,
            'environment' => $environment ?: config('app.env'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function extractPackages(array $lock, bool $includeDev): array
    {
        $packages = [];

        foreach ($lock['packages'] ?? [] as $pkg) {
            if (isset($pkg['name'], $pkg['version'])) {
                $packages[$pkg['name']] = (string) $pkg['version'];
            }
        }

        if ($includeDev) {
            foreach ($lock['packages-dev'] ?? [] as $pkg) {
                if (isset($pkg['name'], $pkg['version'])) {
                    $packages[$pkg['name']] = (string) $pkg['version'];
                }
            }
        }

        return $packages;
    }

    protected function extractPhpVersion(array $lock): ?string
    {
        $platform = $lock['platform'] ?? [];

        if (isset($platform['php'])) {
            return (string) $platform['php'];
        }

        if (defined('PHP_VERSION')) {
            return PHP_VERSION;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $packages
     */
    protected function detectFramework(array $packages): ?string
    {
        if (isset($packages['laravel/framework'])) {
            return 'laravel';
        }

        if (isset($packages['symfony/framework-bundle'])) {
            return 'symfony';
        }

        return null;
    }
}
