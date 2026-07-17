<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use LaraBug\Tests\Mocks\CveClient;

class CveScanCommandTest extends TestCase
{
    /** @var string */
    protected $lockPath;

    public function setUp(): void
    {
        parent::setUp();

        $this->lockPath = sys_get_temp_dir() . '/larabug-scan-cmd-' . uniqid() . '.lock';
        file_put_contents($this->lockPath, json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v12.0.1'],
                ['name' => 'monolog/monolog', 'version' => '3.5.0'],
            ],
        ]));

        $this->app['config']['larabug.cve.enabled'] = true;
        $this->app['config']['larabug.cve.lock_path'] = $this->lockPath;
    }

    public function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }

        parent::tearDown();
    }

    protected function bindClient(CveClient $client): CveClient
    {
        $this->app->instance(Client::class, $client);

        return $client;
    }

    /** @test */
    public function it_refuses_to_run_while_the_feature_is_disabled()
    {
        $this->app['config']['larabug.cve.enabled'] = false;

        $client = $this->bindClient(new CveClient());

        $this->artisan('larabug:scan')
            ->expectsOutput('CVE scanning is disabled. Set LB_CVE_ENABLED=true to enable.')
            ->assertExitCode(2);

        $client->assertRequestsSent(0);
    }

    /** @test */
    public function it_reports_a_lockfile_it_cannot_read()
    {
        $this->bindClient(new CveClient());

        $this->artisan('larabug:scan --lock=/tmp/larabug-nope.lock')
            ->expectsOutput('Could not read composer.lock at /tmp/larabug-nope.lock')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_reports_a_queued_scan_with_its_snapshot_id()
    {
        $this->bindClient(new CveClient(202, '{"queued":true,"snapshot_id":"snap-42"}'));

        $this->artisan('larabug:scan')
            ->expectsOutput('Scanning 2 packages against LaraBug CVE database...')
            ->expectsOutput('Scan queued. Snapshot ID: snap-42')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_reports_an_unchanged_lockfile_as_skipped()
    {
        $this->bindClient(new CveClient(200, '{"skipped":"unchanged","snapshot_id":"snap-1"}'));

        $this->artisan('larabug:scan')
            ->expectsOutput('composer.lock unchanged since last scan — skipped.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_explains_a_403_as_the_feature_being_off_for_the_project()
    {
        $this->bindClient(new CveClient(403, '{"error":"feature_disabled","feature":"cve"}'));

        $this->artisan('larabug:scan')
            ->expectsOutput('CVE scanning is not enabled on this project in LaraBug. Enable it on the project settings page.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_surfaces_any_other_server_error()
    {
        $this->bindClient(new CveClient(503, 'service unavailable'));

        $this->artisan('larabug:scan')
            ->expectsOutput('LaraBug returned HTTP 503: service unavailable')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_only_includes_dev_packages_when_told_to()
    {
        file_put_contents($this->lockPath, json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.1']],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '11.0.0']],
        ]));

        $this->bindClient(new CveClient());

        $this->artisan('larabug:scan')
            ->expectsOutput('Scanning 1 packages against LaraBug CVE database...')
            ->assertExitCode(0);

        $this->bindClient(new CveClient());

        $this->artisan('larabug:scan --include-dev')
            ->expectsOutput('Scanning 2 packages against LaraBug CVE database...')
            ->assertExitCode(0);
    }
}
