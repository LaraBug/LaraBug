<?php

namespace LaraBug\Tests\Integration;

use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Log shipping, driven through Laravel's logger rather than by calling the
 * handler.
 *
 * The channel is defined by the package itself, so an application only names
 * it in its stack. That naming is the opt-in, and it is the piece a consumer
 * gets wrong most easily.
 */
class LogShippingTest extends IntegrationTestCase
{
    #[Test]
    public function the_package_defines_the_channel_so_an_application_only_has_to_name_it(): void
    {
        $this->assertSame(
            'larabug-logs',
            $this->app['config']->get('logging.channels.larabug-logs.driver')
        );
    }

    #[Test]
    public function a_line_written_to_the_channel_is_shipped_when_the_request_ends(): void
    {
        Log::channel('larabug-logs')->error('payment gateway timed out', ['order' => 4711]);

        // Nothing has gone yet: a batch below the threshold waits for the end
        // of the request, which is most requests.
        $this->transport->assertNothingSent();

        $this->app->terminate();

        $sent = $this->transport->first('logs_batch');

        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('integration-project-key', $sent->payload('project'));
        $this->assertSame(1, $sent->payload('count'));

        $line = $sent->payload('logs')[0];

        $this->assertSame('error', $line['level']);
        $this->assertSame('larabug-logs', $line['channel']);
        $this->assertSame('payment gateway timed out', $line['message']);
        $this->assertSame(4711, $line['context']['order']);
        $this->assertNotEmpty($line['trace_id']);
    }

    #[Test]
    public function a_line_below_the_configured_level_is_not_shipped(): void
    {
        Log::channel('larabug-logs')->debug('chatty');

        $this->app->terminate();

        $this->transport->assertNothingSent();
    }

    #[Test]
    public function a_stack_naming_the_channel_ships_what_the_application_logs(): void
    {
        $this->app['config']->set('logging.channels.stack.channels', ['larabug-logs']);
        $this->app['config']->set('logging.default', 'stack');

        Log::error('through the stack');

        $this->app->terminate();

        $this->assertSame(
            'through the stack',
            $this->transport->first('logs_batch')->payload('logs')[0]['message']
        );
    }

    #[Test]
    #[DefineEnvironment('stopShippingLogs')]
    public function the_kill_switch_stops_shipping_without_touching_the_log_stack(): void
    {
        Log::channel('larabug-logs')->error('not going anywhere');

        $this->app->terminate();

        $this->transport->assertNothingSent();
    }

    /**
     * @param  Application  $app
     */
    protected function stopShippingLogs($app): void
    {
        $app['config']->set('larabug.logs.enabled', false);
    }
}
