<?php

namespace LaraBug\Tests\Integration;

use Throwable;
use ExampleApp\Jobs\FailingJob;
use ExampleApp\Jobs\RecordedJob;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Queue monitoring, driven by really running a job rather than by handing the
 * subscriber an event assembled in a test.
 *
 * It is on by default in every application that installs the package, and
 * until now nothing checked that Laravel's queue events fire the way the
 * subscriber expects. The configuration is read once, when the monitor is
 * built at boot, so each variation below sets it before the app exists.
 */
class QueueMonitoringTest extends IntegrationTestCase
{
    #[Test]
    public function a_job_that_finishes_is_reported(): void
    {
        RecordedJob::dispatch('nightly-export');

        $sent = $this->transport->first('queue_job');

        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('integration-project-key', $sent->payload('project'));
        $this->assertSame(RecordedJob::class, $sent->payload('job.job_class'));
        $this->assertSame('completed', $sent->payload('job.status'));
        $this->assertSame('sync', $sent->payload('job.connection'));
        $this->assertNotNull($sent->payload('job.duration_ms'));
    }

    #[Test]
    public function a_job_that_throws_is_reported_as_failed(): void
    {
        $this->dispatchAndSwallow(new FailingJob());

        $failed = $this->transport->first('queue_job');

        $this->assertSame('failed', $failed->payload('job.status'));
        $this->assertSame(FailingJob::class, $failed->payload('job.job_class'));
        $this->assertSame(
            'RuntimeException',
            $failed->payload('job.exception.class')
        );
        $this->assertSame('the job blew up', $failed->payload('job.exception.message'));
        $this->assertSame('testing', $failed->payload('job.exception.environment'));
    }

    #[Test]
    public function the_start_of_a_job_is_not_reported_by_default(): void
    {
        RecordedJob::dispatch();

        $this->transport->assertSentCount(1, 'queue_job');

        $this->assertSame('completed', $this->transport->first('queue_job')->payload('job.status'));
    }

    #[Test]
    #[DefineEnvironment('trackTheStartOfJobs')]
    public function the_start_of_a_job_is_reported_when_that_is_asked_for(): void
    {
        RecordedJob::dispatch();

        $statuses = array_map(
            fn ($sent) => $sent->payload('job.status'),
            $this->transport->sent('queue_job')
        );

        $this->assertSame(['processing', 'completed'], $statuses);
    }

    #[Test]
    #[DefineEnvironment('ignoreTheExampleJob')]
    public function a_job_named_in_the_ignore_list_is_never_reported(): void
    {
        RecordedJob::dispatch();

        $this->transport->assertNothingSent();
    }

    #[Test]
    #[DefineEnvironment('stopTrackingJobs')]
    public function job_tracking_can_be_switched_off_entirely(): void
    {
        RecordedJob::dispatch();
        $this->dispatchAndSwallow(new FailingJob());

        $this->transport->assertNothingSent();
    }

    /**
     * @param  Application  $app
     */
    protected function trackTheStartOfJobs($app): void
    {
        $app['config']->set('larabug.jobs.track_processing', true);
    }

    /**
     * @param  Application  $app
     */
    protected function ignoreTheExampleJob($app): void
    {
        $app['config']->set('larabug.jobs.ignore_jobs', [RecordedJob::class]);
    }

    /**
     * @param  Application  $app
     */
    protected function stopTrackingJobs($app): void
    {
        $app['config']->set('larabug.jobs.track_jobs', false);
    }

    protected function dispatchAndSwallow(object $job): void
    {
        try {
            dispatch($job);
        } catch (Throwable) {
            // The sync driver reports the failure and then rethrows it, which
            // is what a worker swallows for you.
        }
    }
}
