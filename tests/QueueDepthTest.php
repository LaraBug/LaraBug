<?php

namespace LaraBug\Tests;

use Carbon\Carbon;
use LaraBug\Http\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LaraBug\Support\LimitBackoff;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Queue\DatabaseQueue;
use LaraBug\Tests\Mocks\SizedQueue;
use LaraBug\Queue\QueueDepthSampler;
use Illuminate\Support\Facades\Queue;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Schema;
use LaraBug\Tests\Mocks\MeasuredQueue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Schema\Blueprint;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class QueueDepthTest extends TestCase
{
    protected MeteredClient $client;

    public function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 12:01:00');

        $this->client = new MeteredClient();

        $this->app->instance(Client::class, $this->client);

        config([
            'app.env' => 'testing',
            'larabug.environments' => ['testing'],
            'queue.default' => 'redis',
            'queue.connections.redis' => ['driver' => 'redis', 'queue' => 'default'],
            'queue.connections.database' => ['driver' => 'database', 'queue' => 'jobs'],
            'queue.connections.sync' => ['driver' => 'sync'],
            'queue.connections.null' => ['driver' => 'null'],
            'queue.connections.deferred' => ['driver' => 'deferred'],
            'queue.connections.background' => ['driver' => 'background'],
        ]);
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_samples_the_default_connections_own_queue()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $this->assertSame(
            [['connection' => 'redis', 'queue' => 'default']],
            $this->sampler()->queues()
        );
    }

    #[Test]
    public function it_adds_every_queue_horizon_supervises_in_this_environment()
    {
        config(['horizon' => [
            'defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default']],
            ],
            'environments' => [
                'production' => [
                    'supervisor-1' => ['queue' => ['production-only']],
                ],
                'testing' => [
                    'supervisor-1' => ['queue' => ['default', 'emails']],
                    'supervisor-2' => ['connection' => 'database', 'queue' => 'reports,exports'],
                ],
            ],
        ]]);

        $this->assertSame([
            ['connection' => 'redis', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'emails'],
            ['connection' => 'database', 'queue' => 'reports'],
            ['connection' => 'database', 'queue' => 'exports'],
        ], $this->sampler()->queues());
    }

    #[Test]
    public function it_matches_horizon_environments_the_way_horizon_does()
    {
        config(['horizon.environments' => [
            '*' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['notifications']],
            ],
        ]]);

        $this->assertSame([
            ['connection' => 'redis', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'notifications'],
        ], $this->sampler()->queues());
    }

    #[Test]
    public function an_explicit_list_replaces_discovery()
    {
        config([
            'horizon.environments.testing.supervisor-1' => ['connection' => 'redis', 'queue' => ['emails']],
            'larabug.jobs.depth.queues' => [
                'database:reports',
                'sqs:https://sqs.eu-west-1.amazonaws.com/123/jobs',
                'not-a-pair',
            ],
        ]);

        $this->assertSame([
            ['connection' => 'database', 'queue' => 'reports'],
            ['connection' => 'sqs', 'queue' => 'https://sqs.eu-west-1.amazonaws.com/123/jobs'],
        ], $this->sampler()->queues());
    }

    #[Test]
    public function it_skips_drivers_that_have_no_queue_to_measure()
    {
        config([
            'queue.connections.failover-to-sync' => ['driver' => 'failover', 'connections' => ['sync', 'redis']],
            'larabug.jobs.depth.queues' => [
                'sync:default',
                'null:default',
                'deferred:default',
                'background:default',
                'failover-to-sync:default',
                'missing:default',
            ],
        ]);

        $this->assertSame([], $this->sampler()->sample());
    }

    #[Test]
    public function a_failover_connection_is_sampled_when_it_sends_to_a_sizable_one_first()
    {
        config([
            'queue.connections.failover' => ['driver' => 'failover', 'connections' => ['redis', 'sync']],
            'larabug.jobs.depth.queues' => ['failover:default'],
        ]);

        $this->driver('failover', new SizedQueue(['default' => 2]));

        $samples = $this->sampler()->sample();

        $this->assertCount(1, $samples);
        $this->assertSame('failover', $samples[0]['driver']);
        $this->assertSame(2, $samples[0]['pending']);
    }

    #[Test]
    public function a_queue_that_cannot_be_sized_does_not_fail_the_others()
    {
        config(['larabug.jobs.depth.queues' => ['redis:default', 'redis:unreachable', 'database:jobs']]);

        $this->driver('redis', new SizedQueue(['default' => 4]));
        $this->driver('database', new SizedQueue(['jobs' => 9]));

        $samples = $this->sampler()->sample();

        $this->assertSame(['default', 'jobs'], array_column($samples, 'queue'));
        $this->assertSame([4, 9], array_column($samples, 'pending'));
    }

    #[Test]
    public function it_measures_everything_a_recent_queue_can_tell_apart()
    {
        $this->driver('redis', new MeasuredQueue(
            sizes: ['default' => 16],
            pending: 12,
            delayed: 3,
            reserved: 1,
            oldestPendingAt: Carbon::parse('2026-10-19 12:00:00')->getTimestamp(),
        ));

        $this->assertSame([[
            'connection' => 'redis',
            'queue' => 'default',
            'driver' => 'redis',
            'pending' => 12,
            'delayed' => 3,
            'reserved' => 1,
            'oldest_pending_at' => '2026-10-19T12:00:00+00:00',
            'sampled_at' => '2026-10-19T12:01:00+00:00',
        ]], $this->sampler()->sample());
    }

    #[Test]
    public function it_falls_back_to_size_on_a_queue_without_the_finer_methods()
    {
        $this->driver('redis', new SizedQueue(['default' => 7]));

        $this->assertSame([[
            'connection' => 'redis',
            'queue' => 'default',
            'driver' => 'redis',
            'pending' => 7,
            'delayed' => null,
            'reserved' => null,
            'oldest_pending_at' => null,
            'sampled_at' => '2026-10-19T12:01:00+00:00',
        ]], $this->sampler()->sample());
    }

    #[Test]
    public function it_measures_a_real_database_queue()
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        config([
            'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'jobs'],
            'larabug.jobs.depth.queues' => ['database:jobs'],
        ]);

        $queue = Queue::connection('database');

        Carbon::setTestNow('2026-10-19 11:58:00');
        $queue->pushRaw('{"uuid":"first"}', 'jobs');

        Carbon::setTestNow('2026-10-19 12:01:00');
        $queue->pushRaw('{"uuid":"second"}', 'jobs');
        DB::table('jobs')->insert([
            'queue' => 'jobs',
            'payload' => '{"uuid":"later"}',
            'attempts' => 0,
            'available_at' => now()->addMinute()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
        $queue->pop('jobs');

        $sample = $this->sampler()->sample()[0];

        $this->assertSame('database', $sample['driver']);

        if (! method_exists(DatabaseQueue::class, 'pendingSize')) {
            $this->assertSame(3, $sample['pending']);
            $this->assertNull($sample['delayed']);
            $this->assertNull($sample['reserved']);
            $this->assertNull($sample['oldest_pending_at']);

            return;
        }

        $this->assertSame(1, $sample['pending']);
        $this->assertSame(1, $sample['delayed']);
        $this->assertSame(1, $sample['reserved']);
        $this->assertSame('2026-10-19T12:01:00+00:00', $sample['oldest_pending_at']);
    }

    #[Test]
    public function an_empty_queue_has_no_oldest_pending_job()
    {
        $this->driver('redis', new MeasuredQueue(sizes: ['default' => 0]));

        $this->assertNull($this->sampler()->sample()[0]['oldest_pending_at']);
    }

    #[Test]
    public function it_sends_the_agreed_payload()
    {
        $this->driver('redis', new MeasuredQueue(
            sizes: ['default' => 16],
            pending: 12,
            delayed: 3,
            reserved: 1,
            oldestPendingAt: Carbon::parse('2026-10-19 12:00:00')->getTimestamp(),
        ));

        $sent = [];

        $this->app->instance(Client::class, $this->guzzleBackedClient([new Response(201, [], '{}')], $sent));

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] Sent the depth of 1 queue')
            ->assertExitCode(0);

        $this->assertCount(1, $sent);

        /** @var Request $request */
        $request = $sent[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(config('larabug.server'), (string) $request->getUri());

        $this->assertSame([
            'type' => 'queue_depth_batch',
            'project' => 'project-key',
            'count' => 1,
            'samples' => [[
                'connection' => 'redis',
                'queue' => 'default',
                'driver' => 'redis',
                'pending' => 12,
                'delayed' => 3,
                'reserved' => 1,
                'oldest_pending_at' => '2026-10-19T12:00:00+00:00',
                'sampled_at' => '2026-10-19T12:01:00+00:00',
            ]],
        ], json_decode((string) $request->getBody(), true));
    }

    #[Test]
    public function it_sends_nothing_when_no_queue_could_be_sampled()
    {
        config(['larabug.jobs.depth.queues' => ['sync:default']]);

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] No queue could be sampled, nothing sent')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function it_sends_nothing_from_an_environment_that_does_not_report()
    {
        config(['larabug.environments' => ['production']]);

        $this->driver('redis', new SizedQueue(['default' => 4]));

        $this->artisan('larabug:queue-depth')->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function it_sends_nothing_while_the_telemetry_stream_is_held()
    {
        $queue = new SizedQueue(['default' => 4]);

        $this->driver('redis', $queue);

        LimitBackoff::hold(LimitBackoff::TELEMETRY, 60);

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] Telemetry is on hold for now, nothing sent')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
        $this->assertSame([], $queue->asked);
    }

    #[Test]
    public function a_held_issue_stream_does_not_stop_it()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        LimitBackoff::hold(LimitBackoff::ISSUES, 60);

        $this->artisan('larabug:queue-depth')->assertExitCode(0);

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_refusal_holds_the_telemetry_stream()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $this->client->willRefuse(LimitBackoff::TELEMETRY, '120');

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] The telemetry limit is reached, so the samples were refused')
            ->assertExitCode(0);

        $this->assertFalse(LimitBackoff::allows(LimitBackoff::TELEMETRY));
        $this->assertTrue(LimitBackoff::allows(LimitBackoff::ISSUES));
    }

    #[Test]
    public function a_rate_limit_holds_the_telemetry_stream_briefly()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $this->client->willRateLimit('20');

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] This project is sending faster than its plan\'s rate limit, so the samples were refused')
            ->assertExitCode(0);

        $this->assertTrue(LimitBackoff::isRateLimited(LimitBackoff::TELEMETRY));

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] Telemetry is on hold for now, nothing sent')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_server_that_does_not_know_the_type_is_handled_quietly()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $sent = [];

        $this->app->instance(Client::class, $this->guzzleBackedClient([
            new Response(422, [], '{"message":"The selected type is invalid."}'),
        ], $sent));

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] The server does not accept queue depth samples yet')
            ->assertExitCode(0);

        $this->assertCount(1, $sent);
    }

    #[Test]
    public function it_fails_when_the_server_answers_with_an_error()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $this->client->willRespondWith(500);

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] Server answered 500')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_when_the_server_cannot_be_reached()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $sent = [];

        $this->app->instance(Client::class, $this->guzzleBackedClient([
            new ConnectException('Connection refused', new Request('POST', 'https://www.larabug.com/api/log')),
        ], $sent));

        $this->artisan('larabug:queue-depth')
            ->expectsOutput('[LaraBug] Could not reach the server')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_prints_the_samples_without_sending_them()
    {
        $this->driver('redis', new SizedQueue(['default' => 4]));

        $this->artisan('larabug:queue-depth --show')
            ->expectsOutputToContain('"pending": 4')
            ->assertExitCode(0);

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function it_is_scheduled_every_minute_on_one_server_without_overlapping()
    {
        $events = $this->scheduledSamplers();

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->onOneServer);
    }

    #[Test]
    #[DefineEnvironment('disableQueueDepth')]
    public function it_is_not_scheduled_when_disabled()
    {
        $this->assertCount(0, $this->scheduledSamplers());
    }

    protected function disableQueueDepth($app): void
    {
        $app['config']->set('larabug.jobs.depth.enabled', false);
    }

    /**
     * @return array<int, \Illuminate\Console\Scheduling\Event>
     */
    protected function scheduledSamplers(): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, 'larabug:queue-depth')
        ));
    }

    protected function sampler(): QueueDepthSampler
    {
        return $this->app->make(QueueDepthSampler::class);
    }

    protected function driver(string $connection, object $queue): void
    {
        $this->app['queue']->addConnector(
            (string) config("queue.connections.{$connection}.driver"),
            fn () => new class ($queue) implements ConnectorInterface {
                public function __construct(protected object $queue)
                {
                }

                public function connect(array $config)
                {
                    return $this->queue;
                }
            }
        );
    }

    /**
     * @param  array<int, mixed>  $answers
     * @param  array<int, array<string, mixed>>  $sent
     */
    protected function guzzleBackedClient(array $answers, array &$sent): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($sent));

        return (new Client('login-key', 'project-key'))
            ->setGuzzleHttpClient(new GuzzleClient(['handler' => $stack]));
    }
}
