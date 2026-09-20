<?php

namespace LaraBug\Tests\Integration;

use LaraBug\Console\CommandBuffer;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Console\Kernel;
use LaraBug\Console\ScheduledTaskBuffer;
use ExampleApp\Console\ImportOrdersCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Artisan commands and scheduled tasks, by really running them.
 *
 * Both are off by default, and both are the monitors a deployed application
 * turns on first, because a server is where commands and a scheduler live.
 */
#[DefineEnvironment('trackTheConsole')]
class ConsoleMonitoringTest extends IntegrationTestCase
{
    // Laravel does not reroute Symfony's console events while it thinks it is
    // running unit tests, and CommandStarting is one of them. Without this the
    // monitor is listening for something that never fires, which is also why
    // command monitoring could only ever be tested by calling the listener.
    use WithConsoleEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand(new ImportOrdersCommand());
    }

    #[Test]
    public function a_command_that_runs_is_reported(): void
    {
        $this->artisan('example:import-orders sftp --dry-run')->assertExitCode(0);

        $this->flushCommands();

        $sent = $this->transport->first('commands_batch');

        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('integration-project-key', $sent->payload('project'));
        $this->assertSame(1, $sent->payload('count'));

        $record = $sent->payload('commands')[0];

        $this->assertSame('example:import-orders', $record['command']);
        $this->assertSame(0, $record['exit_code']);
        $this->assertSame('testing', $record['environment']);
        $this->assertNotEmpty($record['trace_id']);
        $this->assertStringContainsString('sftp', $record['arguments']);
    }

    #[Test]
    public function a_secret_passed_to_a_command_never_leaves_the_application(): void
    {
        $this->artisan('example:import-orders --token=super-secret')->assertExitCode(0);

        $this->flushCommands();

        $arguments = $this->transport->first('commands_batch')->payload('commands')[0]['arguments'];

        $this->assertStringNotContainsString('super-secret', $arguments);
    }

    #[Test]
    public function the_package_never_reports_its_own_commands(): void
    {
        $this->artisan('larabug:heartbeat')->assertExitCode(0);

        $this->flushCommands();

        $this->transport->assertSentCount(0, 'commands_batch');
    }

    #[Test]
    #[DefineEnvironment('scheduleTheImport')]
    public function a_scheduled_task_is_reported_as_a_task_and_not_as_a_command(): void
    {
        $this->artisan('schedule:run');

        $this->flushCommands();
        $this->flushScheduledTasks();

        $sent = $this->transport->first('scheduled_tasks_batch');

        $record = $sent->payload('scheduled_tasks')[0];

        $this->assertStringContainsString('example:import-orders', $record['task']);
        $this->assertSame('testing', $record['environment']);

        // The command the scheduler ran belongs to the schedule. Reporting it
        // twice would double every scheduled command in the panel.
        $this->transport->assertSentCount(0, 'commands_batch');
    }

    /**
     * @param  Application  $app
     */
    protected function trackTheConsole($app): void
    {
        $app['config']->set('larabug.commands.track_commands', true);
        $app['config']->set('larabug.schedule.track_scheduled_tasks', true);
    }

    /**
     * @param  Application  $app
     */
    protected function scheduleTheImport($app): void
    {
        $app->booted(function () use ($app) {
            $app->make(Schedule::class)->command('example:import-orders')->everyMinute();
        });
    }

    protected function flushCommands(): void
    {
        $this->app->make(CommandBuffer::class)->flush();
    }

    protected function flushScheduledTasks(): void
    {
        $this->app->make(ScheduledTaskBuffer::class)->flush();
    }
}
