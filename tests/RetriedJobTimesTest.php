<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use Workbench\App\Jobs\SendInvoice;
use Illuminate\Support\Facades\Queue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\WithMigration;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * A job that throws is released for another attempt, run by a real worker
 * off the database queue.
 */
#[WithMigration('queue')]
class RetriedJobTimesTest extends TestCase
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

    #[Test]
    public function the_retry_of_a_job_that_threw_reports_its_own_times()
    {
        if (! property_exists(JobReleasedAfterException::class, 'backoff')) {
            $this->markTestSkipped('JobReleasedAfterException carries the backoff from Laravel 12.48.');
        }

        $this->freezeSecond();

        Queue::push(new SendInvoice(sends: false, throwsAfterwards: true));

        $this->work();

        $releasedAt = now();

        $this->travel(31)->seconds();

        $this->work();

        [$firstAttempt, $secondAttempt, $failed] = $this->records();

        $this->assertSame('processing', $firstAttempt['status']);
        $this->assertSame('processing', $secondAttempt['status']);
        $this->assertSame('failed', $failed['status']);

        $this->assertSame(now()->toIso8601String(), $secondAttempt['reserved_at']);
        $this->assertSame($releasedAt->addSeconds(30)->toIso8601String(), $secondAttempt['available_at']);
        $this->assertSame($secondAttempt['reserved_at'], $failed['reserved_at']);
        $this->assertSame($secondAttempt['available_at'], $failed['available_at']);
    }

    protected function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 2,
            '--backoff' => 30,
        ])->run();
    }

    /** @return array<int, array<string, mixed>> */
    protected function records(): array
    {
        return array_column($this->client->requests, 'job');
    }
}
