<?php

namespace LaraBug\Tests\Integration;

use RuntimeException;
use LaraBug\Http\Client;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * Where the package sends what it collects.
 *
 * This is the part that decides whether an install other than larabug.com
 * works at all, and until now nothing checked that a configured host is the
 * host a request goes to.
 */
class TargetConfigurationTest extends IntegrationTestCase
{
    #[Test]
    public function reports_go_to_the_configured_server(): void
    {
        $this->app['config']->set('larabug.server', 'https://larabug-app.test/api/log');

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('somewhere else'));

        $this->assertSame('https://larabug-app.test/api/log', $this->transport->first()->url);
    }

    #[Test]
    public function a_dsn_sets_the_keys_and_the_server_together(): void
    {
        $this->app['config']->set('larabug.dsn', 'https://dsn-login:dsn-project@larabug-app.test/ingest');

        $this->rebuildClient();

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('via dsn'));

        $sent = $this->transport->first();

        $this->assertSame('https://larabug-app.test/ingest', $sent->url);
        $this->assertSame('Bearer dsn-login', $sent->header('Authorization'));
        $this->assertSame('dsn-project', $sent->payload('project'));
    }

    #[Test]
    public function a_dsn_that_cannot_be_parsed_leaves_the_separate_keys_alone(): void
    {
        $this->app['config']->set('larabug.dsn', 'not-a-dsn');

        $this->rebuildClient();

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('via keys'));

        $sent = $this->transport->first();

        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('Bearer integration-login-key', $sent->header('Authorization'));
    }

    #[Test]
    public function the_heartbeat_follows_the_reporting_server(): void
    {
        $this->app['config']->set('larabug.server', 'https://larabug-app.test/api/log');

        $this->artisan('larabug:heartbeat')->assertExitCode(0);

        $this->assertSame('https://larabug-app.test/api/heartbeat', $this->transport->first()->url);
    }

    #[Test]
    public function the_heartbeat_follows_a_server_that_is_not_an_api_log_url(): void
    {
        $this->app['config']->set('larabug.server', 'https://larabug-app.test/collect/');

        $this->artisan('larabug:heartbeat')->assertExitCode(0);

        $this->assertSame('https://larabug-app.test/collect/heartbeat', $this->transport->first()->url);
    }

    #[Test]
    public function a_heartbeat_server_set_on_its_own_wins(): void
    {
        $this->app['config']->set('larabug.heartbeat.server', 'https://larabug-app.test/beats');

        $this->artisan('larabug:heartbeat')->assertExitCode(0);

        $this->assertSame('https://larabug-app.test/beats', $this->transport->first()->url);
    }

    #[Test]
    public function the_heartbeat_says_which_queues_it_measured(): void
    {
        $this->artisan('larabug:heartbeat')->assertExitCode(0);

        $sent = $this->transport->first();

        $this->assertSame('integration-project-key', $sent->payload('project'));
        $this->assertSame('testing', $sent->payload('environment'));
        $this->assertNotEmpty($sent->payload('queues'));
        $this->assertFalse($sent->payload('horizon.installed'));
    }

    #[Test]
    public function turning_ssl_verification_off_reaches_the_request(): void
    {
        $this->app['config']->set('larabug.verify_ssl', false);

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('self signed'));

        $this->assertFalse($this->transport->first()->options['verify']);
    }

    #[Test]
    public function ssl_verification_is_on_unless_it_is_turned_off(): void
    {
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('verified'));

        $this->assertTrue($this->transport->first()->options['verify']);
    }

    #[Test]
    public function the_client_is_one_instance_for_the_whole_application(): void
    {
        $this->assertSame($this->app->make(Client::class), $this->app->make(Client::class));
    }
}
