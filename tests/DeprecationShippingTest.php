<?php

namespace LaraBug\Tests;

use Mockery;
use ErrorException;
use RuntimeException;
use LaraBug\Http\Client;
use LaraBug\ServiceProvider;
use LaraBug\Logger\LogBuffer;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use LaraBug\Logger\DeprecationBuffer;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Queue\Events\WorkerStopping;
use Orchestra\Testbench\Bootstrap\HandleExceptions;

/**
 * Deprecations as Laravel hands them over: through its own error handler, to
 * the channel named by `logging.deprecations.channel`.
 */
class DeprecationShippingTest extends TestCase
{
    protected LaraBugClient $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new LaraBugClient('login', 'project');
        $this->app->instance(Client::class, $this->client);

        $this->app['config']['larabug.project_key'] = 'project';
    }

    #[Test]
    public function an_unconfigured_deprecations_channel_gets_larabug()
    {
        $this->assertSame('larabug-deprecations', config('logging.deprecations.channel'));

        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecations()->flush();

        $this->client->assertRequestsSent(1);
        $this->assertSame('logs_batch', $this->client->requests()[0]['type']);
        $this->assertSame('deprecations', $this->records()[0]['channel']);
    }

    #[Test]
    public function a_null_deprecations_channel_gets_larabug()
    {
        $this->registerAgainstHostConfig(['logging.deprecations.channel' => 'null']);

        $this->assertSame('larabug-deprecations', config('logging.deprecations.channel'));
    }

    #[Test]
    public function a_deprecations_channel_that_does_not_exist_gets_larabug()
    {
        $this->registerAgainstHostConfig(['logging.deprecations.channel' => 'nowhere']);

        $this->assertSame('larabug-deprecations', config('logging.deprecations.channel'));
    }

    #[Test]
    public function a_configured_deprecations_channel_keeps_its_destination_and_gets_larabug()
    {
        $this->registerAgainstHostConfig(['logging.deprecations.channel' => 'single']);

        $this->assertSame('larabug-deprecations-stack', config('logging.deprecations.channel'));
        $this->assertSame(
            ['single', 'larabug-deprecations'],
            config('logging.channels.larabug-deprecations-stack.channels')
        );

        $log = config('logging.channels.single.path');
        @unlink($log);

        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecations()->flush();

        $this->assertStringContainsString('Old thing is deprecated', file_get_contents($log));
        $this->assertSame('Old thing is deprecated', $this->records()[0]['message']);

        @unlink($log);
    }

    #[Test]
    public function the_legacy_string_setting_keeps_its_destination_and_gets_larabug()
    {
        $this->registerAgainstHostConfig(['logging.deprecations' => 'single']);

        $this->assertSame('larabug-deprecations-stack', config('logging.deprecations'));
        $this->assertSame(
            ['single', 'larabug-deprecations'],
            config('logging.channels.larabug-deprecations-stack.channels')
        );
    }

    #[Test]
    public function a_configured_stack_that_ships_logs_does_not_ship_every_occurrence_as_a_line()
    {
        $this->registerAgainstHostConfig(['logging.deprecations.channel' => 'stack']);

        $this->assertContains('larabug-logs', config('logging.channels.stack.channels'));
        $this->assertSame(
            ['single', 'larabug-deprecations'],
            config('logging.channels.larabug-deprecations-stack.channels')
        );

        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecations()->flushIfDue();
        $this->app[LogBuffer::class]->flush();

        $this->assertCount(1, $this->records());

        @unlink(config('logging.channels.single.path'));
    }

    #[Test]
    public function a_defined_deprecations_channel_keeps_writing_and_gets_larabug()
    {
        $channel = ['driver' => 'single', 'path' => storage_path('logs/deprecations.log')];

        $this->registerAgainstHostConfig(['logging.channels.deprecations' => $channel]);

        $this->assertSame($channel, config('logging.channels.larabug-deprecations-destination'));
        $this->assertSame('stack', config('logging.channels.deprecations.driver'));
        $this->assertSame(
            ['larabug-deprecations-destination', 'larabug-deprecations'],
            config('logging.channels.deprecations.channels')
        );
    }

    #[Test]
    public function a_defined_deprecations_stack_gets_larabug_appended()
    {
        $this->registerAgainstHostConfig([
            'logging.channels.deprecations' => ['driver' => 'stack', 'channels' => ['single']],
        ]);

        $this->assertSame(['single', 'larabug-deprecations'], config('logging.channels.deprecations.channels'));
    }

    #[Test]
    public function joining_the_deprecations_channel_happens_once()
    {
        $this->registerAgainstHostConfig(['logging.deprecations.channel' => 'single']);

        $before = config('logging.channels.larabug-deprecations-stack.channels');

        (new ServiceProvider($this->app))->register();

        $this->assertSame('larabug-deprecations-stack', config('logging.deprecations.channel'));
        $this->assertSame($before, config('logging.channels.larabug-deprecations-stack.channels'));
    }

    #[Test]
    public function deprecations_can_be_turned_off()
    {
        $this->registerAgainstHostConfig(['larabug.logs.deprecations.enabled' => false]);

        $this->assertNull(config('logging.deprecations.channel'));
    }

    #[Test]
    public function deprecations_are_off_when_logs_are()
    {
        $this->registerAgainstHostConfig(['larabug.logs.enabled' => false]);

        $this->assertNull(config('logging.deprecations.channel'));
    }

    #[Test]
    public function the_record_matches_the_contract()
    {
        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecations()->flush();

        $record = $this->records()[0];

        $this->assertSame('warning', $record['level']);
        $this->assertSame('deprecations', $record['channel']);
        $this->assertSame('Old thing is deprecated', $record['message']);
        $this->assertSame([
            'fingerprint' => md5('Old thing is deprecated|app/Legacy.php|12'),
            'file' => 'app/Legacy.php',
            'line' => 12,
            'count' => 1,
            'source' => 'php',
        ], $record['extra']['larabug_deprecation']);
    }

    #[Test]
    public function the_trace_setting_is_respected()
    {
        config(['logging.deprecations.trace' => true]);

        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecations()->flush();

        $record = $this->records()[0];

        $this->assertSame('Old thing is deprecated', $record['message']);
        $this->assertSame(md5('Old thing is deprecated|app/Legacy.php|12'), $record['extra']['larabug_deprecation']['fingerprint']);
        $this->assertStringContainsString('#0', $record['context']['trace']);
        $this->assertStringNotContainsString(base_path().'/', $record['context']['trace']);
    }

    #[Test]
    public function the_fingerprint_is_the_same_however_laravel_words_the_line()
    {
        $file = base_path('app/Legacy.php');

        Log::channel('larabug-deprecations')->warning("Old thing is deprecated in {$file} on line 12");
        Log::channel('larabug-deprecations')->warning((string) new ErrorException('Old thing is deprecated', 0, E_USER_DEPRECATED, $file, 12));
        Log::channel('larabug-deprecations')->warning('Old thing is deprecated', [
            'exception' => new ErrorException('Old thing is deprecated', 0, E_USER_DEPRECATED, $file, 12),
        ]);
        $this->deprecations()->flush();

        [$first, $coalesced] = $this->records();

        $this->assertSame(md5('Old thing is deprecated|app/Legacy.php|12'), $first['extra']['larabug_deprecation']['fingerprint']);
        $this->assertSame($first['extra']['larabug_deprecation']['fingerprint'], $coalesced['extra']['larabug_deprecation']['fingerprint']);
        $this->assertSame('Old thing is deprecated', $coalesced['message']);
        $this->assertSame(2, $coalesced['extra']['larabug_deprecation']['count']);
    }

    #[Test]
    public function repeats_are_coalesced_and_counted()
    {
        foreach (range(1, 5) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        $this->deprecate('Other thing is deprecated', base_path('app/Legacy.php'), 20);

        $this->app->terminate();

        $this->assertSame([1, 1], $this->counts());

        $this->deprecations()->flush();

        $this->assertSame([1, 1, 4], $this->counts());
        $this->assertSame('Old thing is deprecated', $this->records()[2]['message']);
    }

    #[Test]
    public function nothing_is_sent_again_before_the_interval_has_passed()
    {
        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);

        event(new Looping('redis', 'default'));

        $this->assertSame([1], $this->counts());
    }

    #[Test]
    public function the_interval_sends_the_count_again_on_the_next_occurrence()
    {
        foreach (range(1, 3) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        $this->travel(3600)->seconds();

        $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        $this->app[LogBuffer::class]->flush();

        $this->assertSame([1, 3], $this->counts());
    }

    #[Test]
    public function an_idle_worker_sends_the_count_once_the_interval_has_passed()
    {
        foreach (range(1, 3) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        $this->travel(3600)->seconds();

        event(new Looping('redis', 'default'));

        $this->assertSame([1, 2], $this->counts());
    }

    #[Test]
    public function a_stopping_worker_sends_what_was_coalesced()
    {
        foreach (range(1, 3) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        event(new WorkerStopping());

        $this->assertSame([1, 2], $this->counts());
    }

    #[Test]
    public function paths_are_relative_to_the_application()
    {
        $this->deprecate('In the app', base_path('app/Legacy.php'), 1);
        $this->deprecate('In a package', base_path('vendor/acme/legacy/src/Old.php'), 2);
        $this->deprecate('In a package installed elsewhere', '/opt/shared/vendor/acme/legacy/src/Old.php', 3);
        $this->deprecate('Outside the app', '/usr/share/php/Old.php', 4);
        $this->deprecations()->flush();

        $this->assertSame(
            ['app/Legacy.php', 'vendor/acme/legacy/src/Old.php', 'vendor/acme/legacy/src/Old.php', 'Old.php'],
            array_map(fn ($record) => $record['extra']['larabug_deprecation']['file'], $this->records())
        );
    }

    #[Test]
    public function a_line_written_to_the_channel_directly_is_located_at_its_caller()
    {
        $line = __LINE__ + 1;
        Log::channel('larabug-deprecations')->warning('Pass a string instead');
        $this->deprecations()->flush();

        $deprecation = $this->records()[0]['extra']['larabug_deprecation'];

        $this->assertSame('php', $deprecation['source']);
        $this->assertSame('DeprecationShippingTest.php', $deprecation['file']);
        $this->assertSame($line, $deprecation['line']);
    }

    #[Test]
    public function a_deprecation_repeated_across_requests_is_sent_once_per_interval()
    {
        $this->shareTheCache();

        $this->request(3);
        $this->request(2);

        $this->assertSame([1], $this->counts());

        $this->travel(3600)->seconds();

        $this->request(1);

        $this->assertSame([1, 5], $this->counts());
    }

    #[Test]
    public function a_request_that_repeats_a_deprecation_sends_one_record()
    {
        $this->shareTheCache();

        $this->request(50);
        $this->deprecations()->flush();

        $this->assertSame([1], $this->counts());
    }

    #[Test]
    public function a_worker_that_takes_the_lock_sends_what_requests_counted()
    {
        $this->shareTheCache();

        $this->request(2);

        $this->newProcess();

        foreach (range(1, 3) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        event(new Looping('redis', 'default'));

        $this->assertSame([1], $this->counts());

        $this->travel(3600)->seconds();

        event(new Looping('redis', 'default'));

        $this->assertSame([1, 4], $this->counts());
    }

    #[Test]
    public function a_stopping_worker_hands_its_count_to_the_next_record()
    {
        $this->shareTheCache();

        $this->request(1);

        $this->newProcess();

        foreach (range(1, 3) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        event(new WorkerStopping());

        $this->assertSame([1], $this->counts());

        $this->travel(3600)->seconds();

        $this->request(1);

        $this->assertSame([1, 4], $this->counts());
    }

    #[Test]
    public function without_a_shared_cache_each_process_sends_its_own_record()
    {
        $this->request(1);
        $this->deprecations()->flush();

        $this->newProcess();

        $this->request(1);

        $this->assertSame([1, 1], $this->counts());
    }

    #[Test]
    public function a_cache_that_fails_leaves_the_process_counting_for_itself()
    {
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('add')->andThrow(new RuntimeException('Cache is down'));

        $this->app->instance(DeprecationBuffer::class, new DeprecationBuffer($this->app[LogBuffer::class], 3600, $cache));

        foreach (range(1, 3) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        $this->deprecations()->flush();

        $this->assertSame([1, 2], $this->counts());
    }

    protected function shareTheCache(): void
    {
        config(['larabug.logs.deprecations.cache_store' => 'file']);

        Cache::store('file')->flush();

        $this->beforeApplicationDestroyed(fn () => Cache::store('file')->flush());
    }

    /**
     * A request served by a PHP-FPM process of its own, which forgets
     * everything in memory once it ends.
     */
    protected function request(int $occurrences): void
    {
        $this->newProcess();

        foreach (range(1, $occurrences) as $occurrence) {
            $this->deprecate('Old thing is deprecated', base_path('app/Legacy.php'), 12);
        }

        $this->deprecations()->terminate();
    }

    protected function newProcess(): void
    {
        $this->app->forgetInstance(DeprecationBuffer::class);

        $this->app['log']->forgetChannel('deprecations');
    }

    protected function deprecate(string $message, string $file, int $line): void
    {
        (new HandleExceptions())->handleDeprecationError($message, $file, $line, E_USER_DEPRECATED);
    }

    protected function deprecations(): DeprecationBuffer
    {
        return $this->app[DeprecationBuffer::class];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function records(): array
    {
        return array_merge(...array_map(fn ($request) => $request['logs'], $this->client->requests()));
    }

    /**
     * @return array<int, int>
     */
    protected function counts(): array
    {
        return array_map(fn ($record) => $record['extra']['larabug_deprecation']['count'], $this->records());
    }

    /**
     * Register the provider once more with the host's logging config in place,
     * for the reason CollectsEverythingByDefaultTest gives.
     */
    protected function registerAgainstHostConfig(array $host): void
    {
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
            'logging.deprecations' => ['channel' => null, 'trace' => false],
        ]);

        config($host);

        (new ServiceProvider($this->app))->register();

        // Registering binds the client afresh.
        $this->app->instance(Client::class, $this->client);
    }
}
