<?php

namespace LaraBug\Tests;

use LaraBug\Scanners\ComposerLockScanner;

class ComposerLockScannerTest extends TestCase
{
    /** @var string */
    protected $lockPath;

    public function setUp(): void
    {
        parent::setUp();

        $this->lockPath = sys_get_temp_dir() . '/larabug-composer-' . uniqid() . '.lock';
    }

    public function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed>|null */
    protected function scan(array $lock, bool $includeDev = false, ?string $environment = null)
    {
        file_put_contents($this->lockPath, json_encode($lock));

        return (new ComposerLockScanner())->scan($this->lockPath, $includeDev, $environment);
    }

    /** @test */
    public function it_returns_null_when_the_lockfile_does_not_exist()
    {
        $scanner = new ComposerLockScanner();

        $this->assertNull($scanner->scan('/tmp/a-lockfile-that-is-not-there.lock'));
    }

    /** @test */
    public function it_returns_null_when_the_lockfile_is_not_json()
    {
        file_put_contents($this->lockPath, 'this is not json');

        $this->assertNull((new ComposerLockScanner())->scan($this->lockPath));
    }

    /** @test */
    public function it_returns_null_when_there_are_no_packages()
    {
        $this->assertNull($this->scan(['packages' => []]));
    }

    /** @test */
    public function it_extracts_package_names_and_versions()
    {
        $result = $this->scan([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v12.0.1'],
                ['name' => 'monolog/monolog', 'version' => '3.5.0'],
            ],
        ]);

        $this->assertSame([
            'laravel/framework' => 'v12.0.1',
            'monolog/monolog' => '3.5.0',
        ], $result['packages']);
    }

    /** @test */
    public function it_skips_packages_missing_a_name_or_version()
    {
        $result = $this->scan([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v12.0.1'],
                ['name' => 'broken/package'],
                ['version' => '1.0.0'],
            ],
        ]);

        $this->assertSame(['laravel/framework' => 'v12.0.1'], $result['packages']);
    }

    /** @test */
    public function it_ignores_dev_packages_unless_asked_for_them()
    {
        $lock = [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.1']],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '11.0.0']],
        ];

        $this->assertArrayNotHasKey('phpunit/phpunit', $this->scan($lock)['packages']);
        $this->assertArrayHasKey('phpunit/phpunit', $this->scan($lock, true)['packages']);
    }

    /** @test */
    public function it_hashes_the_raw_lockfile_so_an_unchanged_lockfile_is_recognisable()
    {
        $lock = ['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.1']]];

        $first = $this->scan($lock);
        $second = $this->scan($lock);
        $changed = $this->scan(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.2']]]);

        $this->assertSame($first['content_hash'], $second['content_hash']);
        $this->assertNotSame($first['content_hash'], $changed['content_hash']);
        $this->assertSame(64, strlen($first['content_hash']));
    }

    /** @test */
    public function it_detects_the_framework_and_its_version()
    {
        $laravel = $this->scan(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.1']]]);

        $this->assertSame('laravel', $laravel['framework']);
        $this->assertSame('v12.0.1', $laravel['framework_version']);

        $symfony = $this->scan(['packages' => [['name' => 'symfony/framework-bundle', 'version' => 'v7.0.0']]]);

        $this->assertSame('symfony', $symfony['framework']);
        $this->assertNull($symfony['framework_version']);

        $neither = $this->scan(['packages' => [['name' => 'monolog/monolog', 'version' => '3.5.0']]]);

        $this->assertNull($neither['framework']);
    }

    /** @test */
    public function it_prefers_the_php_version_the_lockfile_pins()
    {
        $pinned = $this->scan([
            'packages' => [['name' => 'monolog/monolog', 'version' => '3.5.0']],
            'platform' => ['php' => '8.2.0'],
        ]);

        $this->assertSame('8.2.0', $pinned['php_version']);

        $unpinned = $this->scan(['packages' => [['name' => 'monolog/monolog', 'version' => '3.5.0']]]);

        $this->assertSame(PHP_VERSION, $unpinned['php_version']);
    }

    /** @test */
    public function it_takes_the_environment_it_is_given_over_the_configured_one()
    {
        $this->app['config']['app.env'] = 'local';

        $lock = ['packages' => [['name' => 'monolog/monolog', 'version' => '3.5.0']]];

        $this->assertSame('local', $this->scan($lock)['environment']);
        $this->assertSame('production', $this->scan($lock, false, 'production')['environment']);
    }

    /** @test */
    public function it_never_sends_anything_beyond_names_versions_and_a_hash()
    {
        $result = $this->scan([
            'packages' => [['name' => 'monolog/monolog', 'version' => '3.5.0', 'source' => ['url' => 'git@github.com:secret/repo.git']]],
        ]);

        $this->assertSame([
            'content_hash',
            'packages',
            'php_version',
            'framework',
            'framework_version',
            'environment',
        ], array_keys($result));

        $this->assertSame(['monolog/monolog' => '3.5.0'], $result['packages']);
    }
}
