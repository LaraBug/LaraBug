<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use LaraBug\Requests\TraceContext;
use Workbench\App\Jobs\AwaitPayment;
use Illuminate\Support\Facades\Queue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * A job that calls $this->release() ends its attempt with the processed event
 * a completed job ends with, though it went back onto the queue.
 */
#[WithMigration('queue')]
class SelfReleasedJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected MeteredClient $client;

    protected function getEnvironmentSetUp($app): void
    {
        $this->client = new MeteredClient();

        $app->extend(Client::class, fn () => $this->client);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.connection', 'testing');
        $app['config']->set('larabug.project_key', 'project');
    }

    protected function tearDown(): void
    {
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_job_that_releases_itself_is_reported_as_released()
    {
        $this->freezeSecond();

        Queue::push(new AwaitPayment(releasesFor: 60));

        $this->travel(5)->minutes();

        $this->work();

        [$released] = $this->records();

        $this->assertSame('released', $released['status']);
        $this->assertSame(1, $released['attempts']);
        $this->assertIsFloat($released['duration_ms']);
        $this->assertArrayNotHasKey('completed_at', $released);
        $this->assertSame(now()->toIso8601String(), $released['reserved_at']);
        $this->assertSame(now()->toIso8601String(), $released['available_at']);
    }

    #[Test]
    public function the_attempt_after_a_release_reports_its_own_times()
    {
        $this->freezeSecond();

        Queue::push(new AwaitPayment(releasesFor: 60));

        $this->work();

        $this->travel(61)->seconds();

        $this->work();

        [$released, $completed] = $this->records();

        $this->assertSame('released', $released['status']);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(2, $completed['attempts']);
        $this->assertSame(now()->toIso8601String(), $completed['reserved_at']);
        $this->assertNotSame($released['reserved_at'], $completed['reserved_at']);
    }

    #[Test]
    #[DefineEnvironment('sampleNoCompletedJobs')]
    public function a_released_job_is_not_sampled_away()
    {
        Queue::push(new AwaitPayment(releasesFor: 60));

        $this->work();

        $this->travel(61)->seconds();

        $this->work();

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame('released', $records[0]['status']);
        $this->assertSame(1.0, $records[0]['sample_rate']);
    }

    protected function sampleNoCompletedJobs($app): void
    {
        $app['config']->set('larabug.jobs.sample_rate', 0.0);
    }

    protected function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 3,
        ])->run();
    }

    /** @return array<int, array<string, mixed>> */
    protected function records(): array
    {
        return array_column($this->client->requests, 'job');
    }
}
