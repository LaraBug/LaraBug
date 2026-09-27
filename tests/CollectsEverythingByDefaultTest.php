<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use LaraBug\ServiceProvider;
use LaraBug\Logger\LogBuffer;
use Illuminate\Support\Facades\Log;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * An installation collects every stream, with nothing set.
 *
 * Requests, commands, scheduled tasks and logs each used to be off until the
 * host application went looking for a switch it had no reason to know about.
 * What that produced was an install reporting errors only, looking healthy,
 * with four streams silently discarded. Each switch is still a switch: what
 * changed is which way it points when nobody has touched it.
 */
class CollectsEverythingByDefaultTest extends TestCase
{
    #[Test]
    public function every_stream_collects_with_nothing_configured()
    {
        $this->assertTrue(config('larabug.requests.track_requests'));
        $this->assertTrue(config('larabug.commands.track_commands'));
        $this->assertTrue(config('larabug.schedule.track_scheduled_tasks'));
        $this->assertTrue(config('larabug.logs.enabled'));
        $this->assertTrue(config('larabug.jobs.track_jobs'));
    }

    #[Test]
    public function the_switches_still_turn_a_stream_off()
    {
        config([
            'larabug.requests.track_requests' => false,
            'larabug.commands.track_commands' => false,
            'larabug.schedule.track_scheduled_tasks' => false,
        ]);

        $this->assertFalse(config('larabug.requests.track_requests'));
        $this->assertFalse(config('larabug.commands.track_commands'));
        $this->assertFalse(config('larabug.schedule.track_scheduled_tasks'));
    }

    /**
     * Sampling is a separate decision from collection, and it is the thing that
     * keeps request monitoring affordable. Raising it for every installation
     * that updates would spend their quota without being asked, so the stream
     * is on and the rate is untouched.
     */
    #[Test]
    public function requests_are_collected_at_the_sampled_rate_not_whole()
    {
        $this->assertSame(0.1, (float) config('larabug.requests.sample_rate'));
    }

    #[Test]
    public function the_log_channel_joins_the_stack_the_application_logs_through()
    {
        $this->assertSame('stack', config('logging.default'));
        $this->assertContains('larabug-logs', config('logging.channels.stack.channels'));
    }

    #[Test]
    public function joining_the_stack_happens_once()
    {
        $before = config('logging.channels.stack.channels');

        (new ServiceProvider($this->app))->register();
        (new ServiceProvider($this->app))->register();

        $this->assertSame($before, config('logging.channels.stack.channels'));
        $this->assertSame(1, array_count_values($before)['larabug-logs']);
    }

    #[Test]
    public function a_single_channel_is_wrapped_in_a_stack_rather_than_replaced()
    {
        $this->registerAgainstHostConfig(['logging.default' => 'single']);

        $this->assertSame('larabug-stack', config('logging.default'));
        $this->assertSame(
            ['single', 'larabug-logs'],
            config('logging.channels.larabug-stack.channels')
        );

        // The application's own channel is still there, untouched, so it keeps
        // writing where it was writing before.
        $this->assertSame('single', config('logging.channels.single.driver'));
    }

    #[Test]
    public function a_wrapped_single_channel_still_writes_to_disk_and_ships()
    {
        $this->registerAgainstHostConfig(['logging.default' => 'single']);

        $client = new LaraBugClient('login', 'project');
        $this->app->instance(Client::class, $client);
        config(['larabug.project_key' => 'project']);

        $log = config('logging.channels.single.path');
        @unlink($log);

        Log::info('Both halves of the stack');
        $this->app[LogBuffer::class]->flush();

        $client->assertRequestsSent(1);
        $this->assertStringContainsString('Both halves of the stack', file_get_contents($log));

        @unlink($log);
    }

    #[Test]
    public function nothing_is_wired_in_when_logs_are_turned_off()
    {
        $this->registerAgainstHostConfig(['larabug.logs.enabled' => false]);

        $this->assertNotContains('larabug-logs', config('logging.channels.stack.channels'));
    }

    #[Test]
    public function a_kill_switch_ships_no_lines_even_if_the_channel_is_reached()
    {
        $this->registerAgainstHostConfig(['larabug.logs.enabled' => false]);

        $client = new LaraBugClient('login', 'project');
        $this->app->instance(Client::class, $client);
        config(['larabug.project_key' => 'project']);

        Log::channel('larabug-logs')->error('Refused');
        $this->app[LogBuffer::class]->flush();

        $client->assertRequestsSent(0);
    }

    /**
     * An application that named the channel itself asked for a particular
     * position in its stack. Appending a second copy would ship every line
     * twice and quietly double what the account is charged for.
     */
    #[Test]
    public function a_hand_wired_stack_is_left_exactly_as_the_application_wrote_it()
    {
        $this->registerAgainstHostConfig([
            'logging.channels.stack.channels' => ['larabug-logs', 'single'],
        ]);

        $this->assertSame(
            ['larabug-logs', 'single'],
            config('logging.channels.stack.channels')
        );
    }

    #[Test]
    public function a_stack_that_is_not_called_stack_is_found_by_its_driver()
    {
        $this->registerAgainstHostConfig([
            'logging.default' => 'everything',
            'logging.channels.everything' => ['driver' => 'stack', 'channels' => ['single']],
        ]);

        $this->assertSame('everything', config('logging.default'));
        $this->assertContains('larabug-logs', config('logging.channels.everything.channels'));
    }

    /**
     * Register the provider once more, with the host's logging config in place.
     *
     * Testbench registers package providers before it calls defineEnvironment,
     * so a logging config written there lands after our register() has already
     * looked at it. A real application loads its config files first and does
     * see it. Resetting the config and registering again is how a test gets the
     * order a real application has.
     */
    protected function registerAgainstHostConfig(array $host): void
    {
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
        ]);

        config($host);

        (new ServiceProvider($this->app))->register();
    }
}
