<?php

namespace LaraBug\Tests;

use ReflectionProperty;
use LaraBug\Http\Client;
use LaraBug\Requests\TraceContext;
use LaraBug\Queue\JobDataCollector;
use Workbench\App\Jobs\SendInvoice;
use Workbench\App\Jobs\AwaitPayment;
use Illuminate\Support\Facades\Queue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * Processing records are off by default, so the record an attempt ends with
 * is the only one that can say how long the job waited.
 */
#[WithMigration('queue')]
class DefaultJobTimesTest extends TestCase
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
    public function a_completed_job_reports_when_it_was_due_and_picked_up_without_a_processing_record()
    {
        $this->freezeSecond();

        Queue::push(new SendInvoice(sends: false));

        $this->travel(2)->minutes();

        $this->work();

        [$completed] = $this->records();

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(now()->toIso8601String(), $completed['reserved_at']);
        $this->assertNotNull($completed['available_at']);
    }

    #[Test]
    public function a_failed_job_reports_when_it_was_due_and_picked_up_without_a_processing_record()
    {
        $this->freezeSecond();

        Queue::push(new SendInvoice(sends: false, throwsAfterwards: true));

        $this->travel(2)->minutes();

        $this->work(tries: 1);

        [$failed] = $this->records();

        $this->assertSame('failed', $failed['status']);
        $this->assertSame(now()->toIso8601String(), $failed['reserved_at']);
        $this->assertNotNull($failed['available_at']);
    }

    #[Test]
    #[DefineEnvironment('sendOnlyProcessingRecords')]
    public function the_times_of_an_attempt_are_forgotten_when_its_record_is_not_sent()
    {
        Queue::push(new SendInvoice(sends: false));
        Queue::push(new SendInvoice(sends: false, throwsAfterwards: true));
        Queue::push(new AwaitPayment());

        $this->work(tries: 1);
        $this->work(tries: 1);
        $this->work(tries: 1);

        $this->assertSame(['processing', 'processing', 'processing', 'released'], array_column($this->records(), 'status'));
        $this->assertSame([], (new ReflectionProperty(JobDataCollector::class, 'attemptTimes'))->getValue());
    }

    protected function sendOnlyProcessingRecords($app): void
    {
        $app['config']->set('larabug.jobs.track_processing', true);
        $app['config']->set('larabug.jobs.sample_rate', 0.0);
        $app['config']->set('larabug.jobs.track_failed', false);
    }

    protected function work(int $tries = 3): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => $tries,
        ])->run();
    }

    /** @return array<int, array<string, mixed>> */
    protected function records(): array
    {
        return array_column($this->client->requests, 'job');
    }
}
