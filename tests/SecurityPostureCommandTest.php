<?php

namespace LaraBug\Tests;

use Carbon\Carbon;
use RuntimeException;
use LaraBug\Http\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LaraBug\Support\LimitBackoff;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Filesystem\Filesystem;
use LaraBug\Security\PostureSchedule;
use LaraBug\Security\PostureSnapshot;
use GuzzleHttp\Client as GuzzleClient;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Console\Scheduling\Event;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class SecurityPostureCommandTest extends TestCase
{
    protected MeteredClient $client;

    protected string $cachePath;

    protected bool $cacheForgets = false;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    protected array $sent = [];

    public function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 12:00:00');

        $this->client = new MeteredClient();

        $this->app->instance(Client::class, $this->client);

        $this->inEnvironment('production');

        config(['larabug.environments' => ['production']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        (new Filesystem())->deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    /**
     * A cache that outlives the process, as an application's does, in a
     * directory of its own per test.
     */
    protected function defineEnvironment($app): void
    {
        $this->cachePath = sys_get_temp_dir().'/larabug-posture-cache-'.bin2hex(random_bytes(6));

        $app['config']->set('cache.default', $this->cacheForgets ? 'array' : 'file');
        $app['config']->set('cache.stores.file', ['driver' => 'file', 'path' => $this->cachePath]);
    }

    #[Test]
    public function it_sends_the_snapshot_as_a_security_posture_payload()
    {
        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Sent the security posture')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(1);

        $payload = $this->client->lastRequest();

        $this->assertSame('security_posture', $payload['type']);
        $this->assertSame('deploy', $payload['posture']['trigger']);
        $this->assertSame([
            'debug_mode', 'session_cookie', 'trusted_proxies', 'cors', 'dev_packages', 'dev_tools',
        ], array_keys($payload['posture']['checks']));
        $this->assertSame(['telescope', 'horizon', 'pulse', 'debugbar'], array_keys($payload['posture']['checks']['dev_tools']));
    }

    #[Test]
    public function the_payload_goes_over_the_wire_in_the_envelope_every_other_type_uses()
    {
        $this->app->instance(Client::class, $this->realClient([new Response(200, [], '{}')]));

        $this->artisan('larabug:security-posture')->assertExitCode(0);

        $this->assertCount(1, $this->sent);

        $request = $this->sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame(['type', 'project', 'posture'], array_keys($body));
        $this->assertSame('security_posture', $body['type']);
        $this->assertSame('project-key', $body['project']);
        $this->assertStringStartsWith('larabug-php/', $request->getHeaderLine('X-LaraBug-Sdk'));
    }

    #[Test]
    public function it_is_due_after_a_deploy_then_daily()
    {
        $schedule = $this->schedule();

        $this->assertSame('deploy', $schedule->due());

        $this->artisan('larabug:security-posture');

        $this->assertNull($schedule->due());

        Carbon::setTestNow('2026-11-26 11:59:00');

        $this->assertNull($schedule->due());

        Carbon::setTestNow('2026-11-26 12:00:00');

        $this->assertSame('daily', $schedule->due());

        $this->artisan('larabug:security-posture');

        $this->assertSame('daily', $this->client->lastRequest()['posture']['trigger']);
        $this->assertNull($schedule->due());
    }

    #[Test]
    public function a_new_release_is_due_straight_away()
    {
        config(['larabug.project_version' => 'v1.0.0']);

        $this->artisan('larabug:security-posture');

        $this->assertNull($this->schedule()->due());

        config(['larabug.project_version' => 'v1.0.1']);

        $this->assertSame('deploy', $this->schedule()->due());
    }

    #[Test]
    public function a_change_to_the_configuration_it_checks_is_due_straight_away()
    {
        config(['app.debug' => false]);

        $this->artisan('larabug:security-posture');

        $this->assertNull($this->schedule()->due());

        config(['app.debug' => true]);

        $this->assertSame('deploy', $this->schedule()->due());
    }

    #[Test]
    #[DefineEnvironment('runInProduction')]
    public function the_schedule_only_starts_the_command_when_a_snapshot_is_due()
    {
        $event = $this->scheduledPosture();

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->filtersPass($this->app));

        $this->artisan('larabug:security-posture');

        $this->assertFalse($event->filtersPass($this->app));
    }

    #[Test]
    #[DefineEnvironment('useACacheThatForgets')]
    public function with_a_cache_that_forgets_it_is_sent_once_a_day()
    {
        $event = $this->scheduledPosture();

        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    #[Test]
    #[DefineEnvironment('switchOff')]
    public function it_is_not_scheduled_when_switched_off()
    {
        $this->assertSame([], $this->scheduledPostures());
    }

    #[Test]
    #[DefineEnvironment('runLocally')]
    public function it_is_not_scheduled_in_the_local_environment_by_default()
    {
        $this->assertSame([], $this->scheduledPostures());
    }

    #[Test]
    #[DefineEnvironment('runTests')]
    public function it_is_not_scheduled_in_the_testing_environment_by_default()
    {
        $this->assertSame([], $this->scheduledPostures());
    }

    #[Test]
    #[DefineEnvironment('runInStagingWithoutReporting')]
    public function it_is_not_scheduled_in_an_environment_laravel_does_not_report_from()
    {
        $this->assertSame([], $this->scheduledPostures());
    }

    #[Test]
    #[DefineEnvironment('runInProduction')]
    public function it_is_scheduled_in_production_by_default()
    {
        $this->assertCount(1, $this->scheduledPostures());
    }

    #[Test]
    public function it_is_off_in_local_and_testing_unless_switched_on()
    {
        config(['larabug.environments' => ['production', 'local', 'testing']]);

        foreach (['local', 'testing'] as $environment) {
            $this->inEnvironment($environment);

            config(['larabug.security.posture.enabled' => null]);

            $this->artisan('larabug:security-posture')
                ->expectsOutput('[LaraBug] The security posture is switched off in this environment, nothing sent');

            config(['larabug.security.posture.enabled' => true]);

            $this->artisan('larabug:security-posture')->expectsOutput('[LaraBug] Sent the security posture');
        }

        $this->client->assertRequestsSent(2);
    }

    #[Test]
    public function it_sends_nothing_when_switched_off()
    {
        config(['larabug.security.posture.enabled' => false]);

        $this->artisan('larabug:security-posture')->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function it_sends_nothing_from_an_environment_that_does_not_report()
    {
        config(['larabug.security.posture.enabled' => true]);

        $this->inEnvironment('staging');

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Environment (staging) is not configured to report, nothing sent')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function show_prints_the_snapshot_without_sending_it_even_when_switched_off()
    {
        config(['larabug.security.posture.enabled' => false]);

        $this->artisan('larabug:security-posture', ['--show' => true])
            ->expectsOutputToContain('"debug_mode"')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function it_sends_nothing_while_issues_are_on_hold()
    {
        LimitBackoff::hold(LimitBackoff::ISSUES, 300);

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Issues are on hold for now, nothing sent')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function a_reached_limit_holds_the_next_snapshot_until_it_lifts()
    {
        $this->client->willRefuse(LimitBackoff::ISSUES, '600');

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] The issue limit is reached, so the snapshot was refused')
            ->assertExitCode(0);

        $this->assertNull($this->schedule()->due());

        Carbon::setTestNow(now()->addSeconds(601));

        $this->assertSame('deploy', $this->schedule()->due());
    }

    #[Test]
    public function a_rate_limit_holds_the_next_snapshot_for_a_minute()
    {
        $this->client->willRateLimit('20');

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] This project is sending faster than its plan\'s rate limit, so the snapshot was refused')
            ->assertExitCode(0);

        $this->assertNull($this->schedule()->due());

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame('deploy', $this->schedule()->due());
    }

    #[Test]
    public function a_server_that_does_not_know_the_type_is_not_asked_again_until_tomorrow()
    {
        $this->client->willRespondWith(422);

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] The server did not accept the snapshot (422), it is sent again tomorrow')
            ->assertExitCode(0);

        $this->assertNull($this->schedule()->due());
    }

    #[Test]
    public function a_telemetry_hold_does_not_stop_the_snapshot()
    {
        LimitBackoff::hold(LimitBackoff::TELEMETRY, 300);

        $this->artisan('larabug:security-posture')->expectsOutput('[LaraBug] Sent the security posture');

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function an_accepted_snapshot_is_reported_as_sent()
    {
        $this->client->willAnswer(new Response(202, [], json_encode(['accepted' => true, 'snapshot_id' => 12])));

        $this->artisan('larabug:security-posture')->expectsOutput('[LaraBug] Sent the security posture');

        $this->assertNull($this->schedule()->due());
    }

    #[Test]
    public function a_plan_without_security_insights_is_said_and_not_asked_again_until_tomorrow()
    {
        $this->client->willAnswer(new Response(200, [], json_encode(['skipped' => 'plan'])));

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Sent the security posture, but this project\'s plan does not include security insights')
            ->assertExitCode(0);

        $this->assertNull($this->schedule()->due());
    }

    #[Test]
    public function a_snapshot_from_outside_production_is_said_to_be_stored_without_checks()
    {
        $this->client->willAnswer(new Response(200, [], json_encode(['skipped' => 'not_production'])));

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Sent the security posture, its checks only run against production');
    }

    #[Test]
    public function an_unreachable_server_leaves_the_snapshot_to_the_retry_buffer()
    {
        $this->app->instance(Client::class, $this->realClient([$this->connectionFailure()]));

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Could not reach the server, the snapshot is kept to be sent later')
            ->assertExitCode(0);

        $kept = glob($this->retryBufferPath.'/*.json');

        $this->assertCount(1, $kept);
        // Metered with the issues it opens, so it is kept as long as an exception.
        $this->assertStringEndsWith('-issues.json', $kept[0]);
        $this->assertSame('security_posture', json_decode(file_get_contents($kept[0]), true)['type']);

        $this->assertNull($this->schedule()->due());
    }

    #[Test]
    public function a_server_error_leaves_the_snapshot_to_the_retry_buffer()
    {
        $this->app->instance(Client::class, $this->realClient([new Response(503, [], '{}')]));

        $this->artisan('larabug:security-posture')->assertExitCode(0);

        $this->assertCount(1, glob($this->retryBufferPath.'/*.json'));
        $this->assertNull($this->schedule()->due());
    }

    #[Test]
    public function without_the_retry_buffer_an_unreachable_server_is_tried_again_in_five_minutes()
    {
        config(['larabug.retry_buffer.enabled' => false]);

        $this->app->instance(Client::class, $this->realClient([$this->connectionFailure()]));

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] Could not reach the server, the snapshot was not sent')
            ->assertExitCode(0);

        $this->assertNull($this->schedule()->due());

        Carbon::setTestNow(now()->addSeconds(301));

        $this->assertSame('deploy', $this->schedule()->due());
    }

    #[Test]
    public function nothing_it_does_reaches_the_application()
    {
        $this->app->bind(PostureSnapshot::class, fn () => throw new RuntimeException('Surprise'));

        $this->artisan('larabug:security-posture')
            ->expectsOutput('[LaraBug] The security posture could not be taken, nothing sent')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    protected function useACacheThatForgets($app): void
    {
        $this->runInProduction($app);

        // Whichever of this and defineEnvironment() runs last.
        $this->cacheForgets = true;

        $app['config']->set('cache.default', 'array');
    }

    protected function switchOff($app): void
    {
        $this->runInProduction($app);

        $app['config']->set('larabug.security.posture.enabled', false);
    }

    protected function runLocally($app): void
    {
        $app['env'] = 'local';
        $app['config']->set('app.env', 'local');
        $app['config']->set('larabug.environments', ['local', 'production']);
    }

    protected function runTests($app): void
    {
        $app['env'] = 'testing';
        $app['config']->set('app.env', 'testing');
        $app['config']->set('larabug.environments', ['testing', 'production']);
    }

    protected function runInStagingWithoutReporting($app): void
    {
        $app['env'] = 'staging';
        $app['config']->set('app.env', 'staging');
        $app['config']->set('larabug.environments', ['production']);
    }

    protected function runInProduction($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('app.env', 'production');
        $app['config']->set('larabug.environments', ['production']);
    }

    protected function inEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;

        config(['app.env' => $environment]);
    }

    protected function schedule(): PostureSchedule
    {
        return $this->app->make(PostureSchedule::class);
    }

    protected function scheduledPosture(): Event
    {
        $events = $this->scheduledPostures();

        $this->assertCount(1, $events);

        return $events[0];
    }

    /**
     * @return array<int, Event>
     */
    protected function scheduledPostures(): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'larabug:security-posture')
        ));
    }

    /**
     * @param  array<int, mixed>  $answers
     */
    protected function realClient(array $answers): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->sent));

        return new Client('login-key', 'project-key', new GuzzleClient(['handler' => $stack]));
    }

    protected function connectionFailure(): ConnectException
    {
        return new ConnectException('cURL error 7: Failed to connect', new Request('POST', 'https://larabug.test/api/log'));
    }
}
