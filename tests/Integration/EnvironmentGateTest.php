<?php

namespace LaraBug\Tests\Integration;

use RuntimeException;
use ExampleApp\Jobs\RecordedJob;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\WithEnv;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * Which environments report.
 *
 * The one setting that decides whether a freshly deployed server says anything
 * at all, and the one a server that is not production gets wrong. Every test
 * here drives it from the environment the way a deployed application does,
 * while the application itself runs as 'testing'.
 */
class EnvironmentGateTest extends IntegrationTestCase
{
    /** Left to LB_ENVIRONMENTS, which is the subject here. */
    protected ?array $reportingEnvironments = null;

    #[Test]
    public function only_production_reports_unless_told_otherwise(): void
    {
        $this->assertSame(['production'], config('larabug.environments'));
    }

    #[Test]
    #[WithEnv('LB_ENVIRONMENTS', 'production, staging ,acceptance')]
    public function the_environments_that_report_can_be_set_without_publishing_the_config(): void
    {
        $this->assertSame(
            ['production', 'staging', 'acceptance'],
            config('larabug.environments')
        );
    }

    #[Test]
    #[WithEnv('LB_ENVIRONMENTS', 'testing')]
    public function an_environment_on_the_list_reports(): void
    {
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('heard'));

        $this->transport->assertSentCount(1);
    }

    #[Test]
    public function an_exception_from_an_environment_that_does_not_report_is_dropped(): void
    {
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('quiet'));

        $this->transport->assertNothingSent();
    }

    #[Test]
    public function the_heartbeat_says_so_rather_than_sending(): void
    {
        $this->artisan('larabug:heartbeat')
            ->expectsOutputToContain('is not configured to report')
            ->assertExitCode(0);

        $this->transport->assertNothingSent();
    }

    #[Test]
    public function telemetry_is_not_held_back_by_the_environment_list(): void
    {
        // Only exceptions and the heartbeat consult the list. Jobs, requests,
        // commands and logs are sent from wherever they happen, so an
        // application reporting no exceptions still spends its quota.
        RecordedJob::dispatch();

        $this->transport->assertSentCount(1, 'queue_job');
    }
}
