<?php

namespace LaraBug\Scanners;

use Generator;
use Throwable;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\Log;

/**
 * Parses the host application's JavaScript lockfile into the npm_lock part
 * of a cve_scan payload. The first of package-lock.json, pnpm-lock.yaml and
 * yarn.lock in the directory is read. None of them needs a new dependency:
 * pnpm-lock.yaml is only read when the application has symfony/yaml.
 */
class NpmLockScanner
{
    public function __construct(
        protected ?string $directory = null,
    ) {
    }

    /**
     * @return array{
     *     content_hash: string,
     *     lockfile: string,
     *     packages: array<int, array{name: string, version: string, dev: bool}>,
     *     node_version: ?string,
     *     environment: ?string,
     *     source: string,
     * }|null
     */
    public function scan(?string $environment = null): ?array
    {
        $directory = $this->directory();

        $lockfile = $this->locate($directory);

        if ($lockfile === null) {
            return null;
        }

        $raw = file_get_contents("{$directory}/{$lockfile}");

        if ($raw === false) {
            return null;
        }

        $manifest = $this->readManifest($directory);

        try {
            $entries = match ($lockfile) {
                'package-lock.json' => $this->fromPackageLock($raw),
                'pnpm-lock.yaml' => $this->fromPnpmLock($raw, $this->devDependencyNames($manifest)),
                'yarn.lock' => $this->fromYarnLock($raw, $this->devDependencyNames($manifest)),
            };

            $packages = $entries === null ? [] : $this->unique($entries);
        } catch (Throwable) {
            return null;
        }

        if ($packages === []) {
            return null;
        }

        return [
            'content_hash' => hash('sha256', $raw),
            'lockfile' => $lockfile,
            'packages' => $packages,
            'node_version' => $this->nodeVersion($directory, $manifest),
            'environment' => $environment ?: config('app.env'),
            'source' => 'php-sdk',
        ];
    }

    /**
     * The same hash scan() puts in content_hash, without parsing the lockfile.
     */
    public function hash(): ?string
    {
        $directory = $this->directory();

        $lockfile = $this->locate($directory);

        if ($lockfile === null) {
            return null;
        }

        $hash = hash_file('sha256', "{$directory}/{$lockfile}");

        return $hash === false ? null : $hash;
    }

    protected function directory(): string
    {
        return rtrim($this->directory ?: base_path(), '/');
    }

    protected function locate(string $directory): ?string
    {
        foreach (['package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'] as $lockfile) {
            if (! is_file("{$directory}/{$lockfile}") || ! is_readable("{$directory}/{$lockfile}")) {
                continue;
            }

            if ($lockfile === 'pnpm-lock.yaml' && ! $this->canParseYaml()) {
                Log::debug('LaraBug skipped pnpm-lock.yaml in the CVE scan, because reading it needs symfony/yaml.');

                continue;
            }

            return $lockfile;
        }

        return null;
    }

    protected function canParseYaml(): bool
    {
        return class_exists(Yaml::class);
    }

    /**
     * @return Generator<int, array{0: mixed, 1: mixed, 2: bool}>|null
     */
    protected function fromPackageLock(string $raw): ?Generator
    {
        $lock = json_decode($raw, true);

        if (! is_array($lock)) {
            return null;
        }

        // Lockfile v2 carries both, and its packages map is the complete one.
        if (is_array($lock['packages'] ?? null)) {
            return $this->fromPackageMap($lock['packages']);
        }

        if (is_array($lock['dependencies'] ?? null)) {
            return $this->fromDependencyTree($lock['dependencies']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return Generator<int, array{0: mixed, 1: mixed, 2: bool}>
     */
    protected function fromPackageMap(array $packages): Generator
    {
        $marker = 'node_modules/';

        foreach ($packages as $path => $entry) {
            // The root "" entry and a workspace's own folder are the app's
            // code, not something installed from the registry.
            if (! str_contains((string) $path, $marker)) {
                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            if (($entry['link'] ?? false) === true) {
                continue;
            }

            yield [
                $entry['name'] ?? substr($path, strrpos($path, $marker) + strlen($marker)),
                $entry['version'] ?? null,
                ($entry['dev'] ?? false) === true,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $dependencies
     * @return Generator<int, array{0: mixed, 1: mixed, 2: bool}>
     */
    protected function fromDependencyTree(array $dependencies): Generator
    {
        foreach ($dependencies as $name => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            yield [$name, $entry['version'] ?? null, ($entry['dev'] ?? false) === true];

            if (is_array($entry['dependencies'] ?? null)) {
                yield from $this->fromDependencyTree($entry['dependencies']);
            }
        }
    }

    /**
     * Reads both the v1 format and berry's YAML. Each block starts with an
     * unindented header listing the descriptors that resolve to it, and holds
     * `version "x"` (v1) or `version: x` (berry) one level in.
     *
     * @param  array<string, true>  $devNames
     * @return Generator<int, array{0: mixed, 1: mixed, 2: bool}>
     */
    protected function fromYarnLock(string $raw, array $devNames): Generator
    {
        $descriptor = null;

        foreach (preg_split('/\R/', $raw) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_starts_with($line, ' ')) {
                $descriptor = str_ends_with($line, ':')
                    ? trim(explode(',', substr($line, 0, -1))[0], " \"'")
                    : null;

                continue;
            }

            if ($descriptor === null) {
                continue;
            }

            if (! preg_match('/^  version:? "?([^"\s]+)"?\s*$/', $line, $matches)) {
                continue;
            }

            $name = $this->yarnPackageName($descriptor);

            $descriptor = null;

            if ($name === null) {
                continue;
            }

            yield [$name, $matches[1], isset($devNames[$name])];
        }
    }

    protected function yarnPackageName(string $descriptor): ?string
    {
        $at = strpos($descriptor, '@', 1);

        if ($at === false) {
            return null;
        }

        $name = substr($descriptor, 0, $at);
        $range = substr($descriptor, $at + 1);

        if (str_starts_with($range, 'npm:')) {
            $target = substr($range, strlen('npm:'));
            $aliasAt = strpos($target, '@', 1);

            return $aliasAt === false ? $name : substr($target, 0, $aliasAt);
        }

        // workspace:, patch:, git and file ranges are not registry packages.
        // A patched package is listed under its npm: descriptor as well.
        if (str_contains($range, ':')) {
            return null;
        }

        return $name;
    }

    /**
     * @param  array<string, true>  $devNames
     * @return Generator<int, array{0: mixed, 1: mixed, 2: bool}>|null
     */
    protected function fromPnpmLock(string $raw, array $devNames): ?Generator
    {
        $lock = Yaml::parse($raw);

        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            return null;
        }

        // Before lockfile v6 a key reads /name/version, from v6 on name@version.
        $slashSeparated = (float) ($lock['lockfileVersion'] ?? 0) < 6;

        return $this->fromPnpmPackages($lock['packages'], $slashSeparated, $devNames);
    }

    /**
     * @param  array<string, mixed>  $packages
     * @param  array<string, true>  $devNames
     * @return Generator<int, array{0: mixed, 1: mixed, 2: bool}>
     */
    protected function fromPnpmPackages(array $packages, bool $slashSeparated, array $devNames): Generator
    {
        foreach ($packages as $key => $entry) {
            $package = $this->splitPnpmKey((string) $key, $slashSeparated);

            if ($package === null) {
                continue;
            }

            [$name, $version] = $package;

            // Lockfiles up to v6 flag dev packages, v9 no longer does.
            $dev = is_array($entry) && is_bool($entry['dev'] ?? null)
                ? $entry['dev']
                : isset($devNames[$name]);

            yield [$name, $version, $dev];
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function splitPnpmKey(string $key, bool $slashSeparated): ?array
    {
        $key = explode('(', ltrim($key, '/'), 2)[0];

        if ($slashSeparated) {
            $slash = strrpos($key, '/');

            if ($slash === false) {
                return null;
            }

            return [substr($key, 0, $slash), explode('_', substr($key, $slash + 1), 2)[0]];
        }

        $at = strpos($key, '@', 1);

        if ($at === false) {
            return null;
        }

        return [substr($key, 0, $at), substr($key, $at + 1)];
    }

    /**
     * Folds the entries into unique pairs of name and version. A pair is only
     * dev when every copy of it is, since one production copy ships it. A
     * version that is not plain semver (a git url, a file or link path) is
     * left out: no advisory matches it, and it can name a private repository.
     *
     * @param  iterable<int, array{0: mixed, 1: mixed, 2: bool}>  $entries
     * @return array<int, array{name: string, version: string, dev: bool}>
     */
    protected function unique(iterable $entries): array
    {
        $packages = [];

        foreach ($entries as [$name, $version, $dev]) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.+-]+)?$/', $version)) {
                continue;
            }

            $key = "{$name}@{$version}";

            $packages[$key] = [
                'name' => $name,
                'version' => $version,
                'dev' => $dev && ($packages[$key]['dev'] ?? true),
            ];
        }

        return array_values($packages);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readManifest(string $directory): array
    {
        $path = "{$directory}/package.json";

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * The top level names the app only depends on for development. A lockfile
     * without dev flags can only mark those, not what they pull in.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, true>
     */
    protected function devDependencyNames(array $manifest): array
    {
        $dependencyNames = fn (string $section): array => is_array($manifest[$section] ?? null)
            ? array_keys($manifest[$section])
            : [];

        $devOnly = array_diff(
            $dependencyNames('devDependencies'),
            $dependencyNames('dependencies'),
            $dependencyNames('optionalDependencies'),
        );

        return array_fill_keys($devOnly, true);
    }

    /**
     * .nvmrc comes first, because it names the version the app runs on where
     * engines.node is usually a range.
     *
     * @param  array<string, mixed>  $manifest
     */
    protected function nodeVersion(string $directory, array $manifest): ?string
    {
        $nvmrc = "{$directory}/.nvmrc";

        if (is_file($nvmrc) && is_readable($nvmrc)) {
            $version = trim((string) file_get_contents($nvmrc));

            if ($version !== '') {
                return preg_replace('/^v(?=\d)/', '', $version);
            }
        }

        $engine = $manifest['engines']['node'] ?? null;

        if (is_string($engine) && trim($engine) !== '') {
            return trim($engine);
        }

        return null;
    }
}
