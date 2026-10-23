<?php

namespace LaraBug\Tests;

use Illuminate\Support\Facades\Log;
use LaraBug\Scanners\NpmLockScanner;
use PHPUnit\Framework\Attributes\Test;

class NpmLockScannerTest extends TestCase
{
    protected string $directory;

    public function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/larabug-npm-' . uniqid();

        mkdir($this->directory);
    }

    public function tearDown(): void
    {
        foreach (glob("{$this->directory}/{,.}*", GLOB_BRACE) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    /** @return array<string, mixed>|null */
    protected function scanFixture(string $fixture): ?array
    {
        return (new NpmLockScanner(__DIR__ . "/Fixtures/npm/{$fixture}"))->scan();
    }

    /** @return array<string, mixed>|null */
    protected function scanDirectory(?NpmLockScanner $scanner = null): ?array
    {
        return ($scanner ?? new NpmLockScanner($this->directory))->scan();
    }

    protected function scannerWithoutYaml(): NpmLockScanner
    {
        return new class ($this->directory) extends NpmLockScanner {
            protected function canParseYaml(): bool
            {
                return false;
            }
        };
    }

    protected function copyFixture(string $fixture, string $file): void
    {
        copy(__DIR__ . "/Fixtures/npm/{$fixture}/{$file}", "{$this->directory}/{$file}");
    }

    /**
     * @param  array<int, array{name: string, version: string, dev: bool}>  $packages
     * @return array<int, string>
     */
    protected function pairs(array $packages): array
    {
        $pairs = array_map(
            fn (array $package): string => $package['name'] . '@' . $package['version'] . ($package['dev'] ? ' (dev)' : ''),
            $packages,
        );

        sort($pairs);

        return $pairs;
    }

    #[Test]
    public function it_returns_null_when_there_is_no_lockfile()
    {
        $this->assertNull($this->scanDirectory());
    }

    #[Test]
    public function it_flattens_the_dependency_tree_of_a_v1_package_lock()
    {
        $result = $this->scanFixture('package-lock-v1');

        $this->assertSame('package-lock.json', $result['lockfile']);
        $this->assertSame([
            '@vue/shared@3.4.21',
            'axios@1.6.7',
            'follow-redirects@1.15.4',
            'follow-redirects@1.15.6',
            'laravel-vite-plugin@1.0.2 (dev)',
            'picocolors@1.0.0 (dev)',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_reads_the_packages_map_of_a_v2_package_lock_over_its_legacy_tree()
    {
        $result = $this->scanFixture('package-lock-v2');

        $this->assertSame([
            'lodash@4.17.21',
            'vite@5.1.4 (dev)',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_reads_the_packages_map_of_a_v3_package_lock()
    {
        $result = $this->scanFixture('package-lock-v3');

        $this->assertSame([
            '@babel/parser@7.24.0 (dev)',
            '@inertiajs/vue3@1.0.14',
            'debug@4.3.4',
            'ms@2.1.2',
            'ms@2.1.3 (dev)',
            'string-width@4.2.3',
            'vite@5.1.4 (dev)',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_sends_a_pair_found_in_production_and_development_once_as_production()
    {
        $packages = $this->scanFixture('package-lock-v3')['packages'];

        $debug = array_values(array_filter($packages, fn (array $package): bool => $package['name'] === 'debug'));

        $this->assertSame([['name' => 'debug', 'version' => '4.3.4', 'dev' => false]], $debug);
    }

    #[Test]
    public function it_reads_a_yarn_v1_lockfile_and_marks_dev_from_package_json()
    {
        $result = $this->scanFixture('yarn-v1');

        $this->assertSame('yarn.lock', $result['lockfile']);
        $this->assertSame([
            '@babel/parser@7.24.0',
            'axios@1.6.7',
            'follow-redirects@1.15.4',
            'follow-redirects@1.15.6',
            'laravel-vite-plugin@1.0.2 (dev)',
            'string-width@4.2.3',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_reads_a_yarn_berry_lockfile_without_its_workspace_or_patches()
    {
        $result = $this->scanFixture('yarn-berry');

        $this->assertSame('yarn.lock', $result['lockfile']);
        $this->assertSame([
            '@babel/parser@7.24.0',
            'lodash@4.17.21',
            'resolve@1.22.8',
            'vite@5.1.4 (dev)',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_reads_a_v9_pnpm_lockfile_and_marks_dev_from_package_json()
    {
        $result = $this->scanFixture('pnpm-v9');

        $this->assertSame('pnpm-lock.yaml', $result['lockfile']);
        $this->assertSame([
            '@vue/shared@3.4.21',
            'typescript@5.3.3',
            'vite@5.1.4 (dev)',
            'vue@3.4.21',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_reads_a_v6_pnpm_lockfile_and_its_dev_flags()
    {
        $result = $this->scanFixture('pnpm-v6');

        $this->assertSame([
            '@vue/shared@3.4.21',
            'vite@5.1.4 (dev)',
            'vue@3.4.21',
        ], $this->pairs($result['packages']));
    }

    #[Test]
    public function it_reads_the_slash_separated_keys_of_a_v5_pnpm_lockfile()
    {
        file_put_contents("{$this->directory}/pnpm-lock.yaml", <<<'YAML'
        lockfileVersion: 5.4

        packages:

          /@babel/parser/7.24.0:
            resolution: {integrity: sha512-abc}
            dev: true

          /vue/3.4.21_typescript@5.3.3:
            resolution: {integrity: sha512-def}
            dev: false
        YAML);

        $this->assertSame([
            '@babel/parser@7.24.0 (dev)',
            'vue@3.4.21',
        ], $this->pairs($this->scanDirectory()['packages']));
    }

    #[Test]
    public function it_skips_pnpm_with_a_debug_line_when_symfony_yaml_is_missing()
    {
        Log::spy();

        $this->copyFixture('pnpm-v9', 'pnpm-lock.yaml');

        $this->assertNull($this->scanDirectory($this->scannerWithoutYaml()));

        Log::shouldHaveReceived('debug')->once()->withArgs(fn (string $message): bool => str_contains($message, 'symfony/yaml'));
    }

    #[Test]
    public function it_falls_through_to_yarn_when_pnpm_cannot_be_read()
    {
        $this->copyFixture('pnpm-v9', 'pnpm-lock.yaml');
        $this->copyFixture('yarn-v1', 'yarn.lock');

        $this->assertSame('yarn.lock', $this->scanDirectory($this->scannerWithoutYaml())['lockfile']);
    }

    #[Test]
    public function it_prefers_package_lock_then_pnpm_then_yarn()
    {
        $this->copyFixture('yarn-v1', 'yarn.lock');

        $this->assertSame('yarn.lock', $this->scanDirectory()['lockfile']);

        $this->copyFixture('pnpm-v9', 'pnpm-lock.yaml');

        $this->assertSame('pnpm-lock.yaml', $this->scanDirectory()['lockfile']);

        $this->copyFixture('package-lock-v3', 'package-lock.json');

        $this->assertSame('package-lock.json', $this->scanDirectory()['lockfile']);
    }

    #[Test]
    public function it_returns_null_for_a_malformed_lockfile_instead_of_throwing()
    {
        file_put_contents("{$this->directory}/package-lock.json", '{"packages": not json');

        $this->assertNull($this->scanDirectory());

        unlink("{$this->directory}/package-lock.json");
        file_put_contents("{$this->directory}/pnpm-lock.yaml", "packages:\n  - [unclosed");

        $this->assertNull($this->scanDirectory());

        unlink("{$this->directory}/pnpm-lock.yaml");
        file_put_contents("{$this->directory}/yarn.lock", "\x00\x01 garbage without a single block");

        $this->assertNull($this->scanDirectory());
    }

    #[Test]
    public function it_returns_null_for_a_lockfile_without_packages()
    {
        file_put_contents("{$this->directory}/package-lock.json", json_encode([
            'lockfileVersion' => 3,
            'packages' => ['' => ['name' => 'app']],
        ]));

        $this->assertNull($this->scanDirectory());
    }

    #[Test]
    public function it_takes_the_node_version_from_nvmrc_before_package_json()
    {
        $this->assertSame('22.12.0', $this->scanFixture('yarn-v1')['node_version']);

        $this->copyFixture('yarn-v1', 'yarn.lock');
        $this->copyFixture('yarn-v1', 'package.json');

        $this->assertSame('>=20', $this->scanDirectory()['node_version']);

        unlink("{$this->directory}/package.json");

        $this->assertNull($this->scanDirectory()['node_version']);
    }

    #[Test]
    public function it_hashes_the_raw_lockfile()
    {
        $result = $this->scanFixture('package-lock-v3');

        $this->assertSame(
            hash_file('sha256', __DIR__ . '/Fixtures/npm/package-lock-v3/package-lock.json'),
            $result['content_hash'],
        );
    }

    #[Test]
    public function it_hashes_the_lockfile_it_would_scan_without_parsing_it()
    {
        $this->assertSame(
            $this->scanFixture('pnpm-v9')['content_hash'],
            (new NpmLockScanner(__DIR__ . '/Fixtures/npm/pnpm-v9'))->hash(),
        );

        $this->copyFixture('pnpm-v9', 'pnpm-lock.yaml');
        $this->copyFixture('yarn-v1', 'yarn.lock');

        $this->assertSame(
            hash_file('sha256', "{$this->directory}/yarn.lock"),
            $this->scannerWithoutYaml()->hash(),
        );

        $this->assertNull((new NpmLockScanner(__DIR__ . '/Fixtures/npm/does-not-exist'))->hash());
    }

    #[Test]
    public function it_sends_the_agreed_payload_shape_and_nothing_more()
    {
        $this->app['config']['app.env'] = 'local';

        $result = $this->scanFixture('yarn-v1');

        $this->assertSame([
            'content_hash',
            'lockfile',
            'packages',
            'node_version',
            'environment',
            'source',
        ], array_keys($result));

        $this->assertSame('local', $result['environment']);
        $this->assertSame('php-sdk', $result['source']);
        $this->assertSame(['name', 'version', 'dev'], array_keys($result['packages'][0]));
        $this->assertSame(array_keys($result['packages']), range(0, count($result['packages']) - 1));
    }

    #[Test]
    public function it_takes_the_environment_it_is_given_over_the_configured_one()
    {
        $this->app['config']['app.env'] = 'local';

        $result = (new NpmLockScanner(__DIR__ . '/Fixtures/npm/yarn-v1'))->scan('production');

        $this->assertSame('production', $result['environment']);
    }
}
