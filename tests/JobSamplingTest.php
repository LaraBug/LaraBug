<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use LaraBug\Queue\JobMonitor;
use LaraBug\Requests\TraceContext;
use Workbench\App\Jobs\SendInvoice;
use Workbench\App\Jobs\ReserveStock;
use Illuminate\Support\Facades\Queue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * The server keeps the last record it was sent for a job, so sampling must
 * never drop a completed record after another record of the job was sent.
 */
#[WithMigration('queue')]
class JobSamplingTest extends TestCase
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
        $app['config']->set('larabug.jobs.track_processing', true);
    }

    protected function tearDown(): void
    {
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    #[DefineEnvironment('sampleNoCompletedJobs')]
    public function a_sampled_out_attempt_sends_no_processing_record_either()
    {
        Queue::push(new SendInvoice(sends: false));

        $this->work();

        $this->assertSame([], $this->records());
    }

    #[Test]
    #[DefineEnvironment('sampleHalfOfTheJobs')]
    public function the_processing_and_completed_records_of_an_attempt_are_kept_together()
    {
        foreach (range(1, 20) as $ignored) {
            Queue::push(new SendInvoice(sends: false));
        }

        foreach (range(1, 20) as $ignored) {
            $this->work();
        }

        $statusesByJob = collect($this->records())
            ->groupBy('job_id')
            ->map(fn ($records) => $records->pluck('status')->all());

        $this->assertNotEmpty($statusesByJob);

        foreach ($statusesByJob as $statuses) {
            $this->assertSame(['processing', 'completed'], $statuses);
        }
    }

    #[Test]
    #[DefineEnvironment('sampleNoCompletedJobs')]
    public function the_retry_of_a_job_that_threw_is_not_sampled_away()
    {
        Queue::push(new ReserveStock());

        $this->work();

        $this->travel(31)->seconds();

        $this->work();

        $records = $this->records();

        $this->assertSame(['processing', 'completed'], array_column($records, 'status'));
        $this->assertSame([2, 2], array_column($records, 'attempts'));
        $this->assertSame(1.0, $records[1]['sample_rate']);
    }

    protected function sampleNoCompletedJobs($app): void
    {
        $app['config']->set('larabug.jobs.sample_rate', 0.0);
    }

    protected function sampleHalfOfTheJobs($app): void
    {
        $app['config']->set('larabug.jobs.sample_rate', 0.5);
    }

    protected function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 3,
            '--backoff' => 30,
        ])->run();
    }

    /**
     * Past ten jobs a minute the worker batches its records, so a busy test
     * gets some of them one by one and the rest in a batch, the last of which
     * a worker run with --once leaves in the buffer.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function records(): array
    {
        $this->app->make(JobMonitor::class)->flush();

        return collect($this->client->requests)
            ->flatMap(fn ($request) => $request['jobs'] ?? [$request['job']])
            ->all();
    }
}
