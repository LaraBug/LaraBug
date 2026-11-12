<?php

namespace LaraBug\Tests;

use Exception;
use LaraBug\LaraBug;
use LaraBug\Http\Client;
use GuzzleHttp\Middleware;
use LaraBug\Http\FlushStop;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LaraBug\Http\RetryBuffer;
use LaraBug\Logger\LogBuffer;
use LaraBug\Support\LimitBackoff;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;

class RetryBufferTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $sent = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'larabug.environments' => ['testing'],
            'larabug.server' => 'https://larabug.test/api/log',
        ]);
    }

    #[Test]
    public function an_exception_refused_with_a_503_is_kept()
    {
        $client = $this->client([new Response(503, [], '{"message":"Service Unavailable"}')]);

        (new LaraBug($client))->handle(new Exception('Kept through the outage'));

        $kept = $this->kept();

        $this->assertCount(1, $kept);
        $this->assertSame('Kept through the outage', $kept[0]['exception']['exception']);
        $this->assertSame('project-key', $kept[0]['project']);
    }

    #[Test]
    public function an_exception_that_cannot_reach_the_server_is_kept()
    {
        $client = $this->client([$this->connectionFailure()]);

        $this->assertFalse((new LaraBug($client))->handle(new Exception('Server unreachable')));

        $this->assertCount(1, $this->kept());
        $this->assertSame('Server unreachable', $this->kept()[0]['exception']['exception']);
    }

    #[Test]
    public function a_telemetry_batch_that_cannot_reach_the_server_is_kept()
    {
        $client = $this->client([$this->connectionFailure()]);

        $this->assertNull($client->reportRequests([['uri' => '/orders']]));

        $this->assertSame('requests_batch', $this->kept()[0]['type']);
        $this->assertSame([['uri' => '/orders']], $this->kept()[0]['requests']);
    }

    #[Test]
    public function a_log_batch_refused_with_a_5xx_is_kept_once_instead_of_retried()
    {
        $client = $this->client([
            new Response(503),
            new Response(503),
            new Response(503),
        ]);

        $logs = new LogBuffer($client, ['project_key' => 'project-key', 'logs' => ['max_retries' => 3]]);
        $logs->add(['message' => 'Line one']);
        $logs->flush();

        $this->assertCount(1, $this->sent);
        $this->assertCount(1, $this->kept());
        $this->assertSame('Line one', $this->kept()[0]['logs'][0]['message']);
    }

    #[Test]
    #[DataProvider('refusalsThatWillNotGetBetter')]
    public function a_4xx_is_never_kept(int $status)
    {
        $client = $this->client([new Response($status, [], '{}')]);

        (new LaraBug($client))->handle(new Exception('Refused'));

        $this->assertSame([], $this->kept());
    }

    /** @return array<string, array{0: int}> */
    public static function refusalsThatWillNotGetBetter(): array
    {
        return [
            'bad request' => [400],
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'unprocessable' => [422],
        ];
    }

    #[Test]
    public function a_rate_limit_and_a_reached_limit_are_left_to_the_backoff()
    {
        $client = $this->client([
            new Response(429, ['Retry-After' => '20'], '{}'),
            new Response(402, ['Retry-After' => '300'], '{"stream":"issues"}'),
        ]);

        $laraBug = new LaraBug($client);

        $laraBug->handle(new Exception('Too fast'));

        $this->assertTrue(LimitBackoff::isRateLimited(LimitBackoff::ISSUES));

        LimitBackoff::clear();

        $laraBug->handle(new Exception('Over the limit'));

        $this->assertFalse(LimitBackoff::allows(LimitBackoff::ISSUES));
        $this->assertFalse(LimitBackoff::isRateLimited(LimitBackoff::ISSUES));
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function cve_scans_and_queue_depth_samples_are_never_kept()
    {
        $client = $this->client([new Response(503), new Response(503)]);

        $client->report(['type' => 'cve_scan', 'composer_lock' => []]);
        $client->reportQueueDepth([['queue' => 'default', 'size' => 4]]);

        $this->assertCount(2, $this->sent);
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function nothing_the_data_filters_removed_and_no_credential_is_written()
    {
        $secret = bin2hex(random_bytes(8));

        $this->app['request']->merge(['password' => $secret, 'plain' => 'visible']);

        $login = bin2hex(random_bytes(8));

        $client = $this->client([new Response(503)], $login);

        (new LaraBug($client))->handle(new Exception('Filtered'));

        $written = (string) file_get_contents(glob("{$this->retryBufferPath}/*.json")[0]);

        $this->assertStringContainsString('visible', $written);
        $this->assertStringNotContainsString($secret, $written);
        $this->assertStringNotContainsString($login, $written);
    }

    #[Test]
    public function past_the_count_bound_the_oldest_events_are_dropped()
    {
        config(['larabug.retry_buffer.max_events' => 3]);

        $buffer = RetryBuffer::fromConfig();

        foreach (range(1, 5) as $number) {
            $buffer->keep(['type' => 'logs_batch', 'number' => $number]);
        }

        $this->assertSame([3, 4, 5], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function past_the_size_bound_the_oldest_events_are_dropped()
    {
        config(['larabug.retry_buffer.max_bytes' => 2500]);

        $buffer = RetryBuffer::fromConfig();

        foreach (range(1, 5) as $number) {
            $buffer->keep(['type' => 'logs_batch', 'number' => $number, 'line' => str_repeat('a', 1000)]);
        }

        $this->assertSame([4, 5], array_column($this->kept(), 'number'));
        $this->assertLessThanOrEqual(2500, array_sum(array_map(filesize(...), glob("{$this->retryBufferPath}/*.json"))));
    }

    #[Test]
    public function an_event_bigger_than_the_whole_buffer_is_not_kept()
    {
        config(['larabug.retry_buffer.max_bytes' => 100]);

        $buffer = RetryBuffer::fromConfig();

        $buffer->keep(['type' => 'logs_batch', 'number' => 1]);
        $buffer->keep(['type' => 'logs_batch', 'line' => str_repeat('a', 200)]);

        $this->assertSame([1], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function events_past_the_age_bound_are_dropped_and_never_sent()
    {
        config(['larabug.retry_buffer.max_age' => 3600]);

        $this->writeEntry(time() - 7200, ['type' => 'logs_batch', 'number' => 1]);
        $this->writeEntry(time() - 60, ['type' => 'logs_batch', 'number' => 2]);

        $client = $this->client([new Response(200)]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->discarded);
        $this->assertSame([2], $this->sentPayloadsColumn('number'));
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function writing_an_event_drops_those_past_the_age_bound()
    {
        config(['larabug.retry_buffer.max_age' => 3600]);

        $this->writeEntry(time() - 7200, ['type' => 'logs_batch', 'number' => 1]);

        RetryBuffer::fromConfig()->keep(['type' => 'logs_batch', 'number' => 2]);

        $this->assertSame([2], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function a_flush_sends_the_oldest_event_first()
    {
        $this->writeEntry(time() - 30, ['type' => 'logs_batch', 'number' => 2]);
        $this->writeEntry(time() - 60, ['type' => 'logs_batch', 'number' => 1]);
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 3]);

        $client = $this->client([new Response(200), new Response(200), new Response(200)]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(3, $result->sent);
        $this->assertNull($result->stoppedBy);
        $this->assertSame([1, 2, 3], $this->sentPayloadsColumn('number'));
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function a_flush_sends_the_payload_exactly_as_it_was_kept()
    {
        $client = $this->client([new Response(503), new Response(200)]);

        $client->report(['type' => 'logs_batch', 'logs' => [['message' => 'GET /orders failed for café', 'context' => new \stdClass()]]]);

        RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(
            (string) $this->sent[0]['request']->getBody(),
            (string) $this->sent[1]['request']->getBody(),
        );
    }

    #[Test]
    public function the_next_successful_send_flushes_what_was_kept()
    {
        $client = $this->client([
            new Response(503),
            new Response(200, [], '{"id":"second"}'),
            new Response(200, [], '{"id":"first"}'),
        ]);

        $laraBug = new LaraBug($client);
        $laraBug->handle(new Exception('First, during the outage'));
        $laraBug->handle(new Exception('Second, once it is over'));

        $this->assertSame([
            'First, during the outage',
            'Second, once it is over',
            'First, during the outage',
        ], array_map(fn (array $payload) => $payload['exception']['exception'], $this->sentPayloads()));

        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function a_flush_stops_at_the_first_5xx_and_keeps_the_rest()
    {
        foreach (range(1, 3) as $number) {
            $this->writeEntry(time() - 60 + $number, ['type' => 'logs_batch', 'number' => $number]);
        }

        $client = $this->client([new Response(200), new Response(502)]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(1, $result->sent);
        $this->assertSame(2, $result->remaining);
        $this->assertSame(FlushStop::ServerError, $result->stoppedBy);
        $this->assertSame([1, 2], $this->sentPayloadsColumn('number'));
        $this->assertSame([2, 3], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function a_flush_stops_when_the_server_cannot_be_reached()
    {
        $this->writeEntry(time() - 20, ['type' => 'logs_batch', 'number' => 1]);
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 2]);

        $client = $this->client([$this->connectionFailure()]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(FlushStop::Unreachable, $result->stoppedBy);
        $this->assertCount(1, $this->sent);
        $this->assertSame([1, 2], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function a_flush_drops_an_event_refused_with_a_4xx_and_carries_on()
    {
        $this->writeEntry(time() - 20, ['type' => 'logs_batch', 'number' => 1]);
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 2]);

        $client = $this->client([new Response(422), new Response(200)]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->discarded);
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function a_flush_stops_at_a_rate_limit_and_keeps_the_event()
    {
        $this->writeEntry(time() - 20, ['type' => 'logs_batch', 'number' => 1]);

        $client = $this->client([new Response(429, ['Retry-After' => '30'], '{}')]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(FlushStop::RateLimited, $result->stoppedBy);
        $this->assertTrue(LimitBackoff::isRateLimited(LimitBackoff::TELEMETRY));
        $this->assertSame([1], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function a_5xx_with_retry_after_sends_nothing_until_then()
    {
        $client = $this->client([new Response(503, ['Retry-After' => '120'])]);

        $laraBug = new LaraBug($client);
        $laraBug->handle(new Exception('Down for maintenance'));
        $laraBug->handle(new Exception('Still down'));

        $buffer = RetryBuffer::fromConfig();

        $this->assertCount(1, $this->sent);
        $this->assertCount(2, $this->kept());
        $this->assertEqualsWithDelta(time() + 120, $buffer->pausedUntil(), 1);

        $result = $buffer->flush($client);

        $this->assertSame(FlushStop::Paused, $result->stoppedBy);
        $this->assertCount(1, $this->sent);
    }

    #[Test]
    public function a_long_retry_after_is_capped_at_ten_minutes()
    {
        $client = $this->client([new Response(503, ['Retry-After' => '86400'])]);

        $client->reportCommands([['command' => 'inspire']]);

        $this->assertEqualsWithDelta(time() + 600, RetryBuffer::fromConfig()->pausedUntil(), 1);
    }

    #[Test]
    public function a_flush_does_nothing_while_another_process_holds_the_lock()
    {
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 1]);

        $lock = fopen("{$this->retryBufferPath}/.lock", 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $client = $this->client([new Response(200)]);

        $result = RetryBuffer::fromConfig()->flush($client);

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertSame(FlushStop::Locked, $result->stoppedBy);
        $this->assertSame([], $this->sent);
        $this->assertSame([1], array_column($this->kept(), 'number'));
    }

    #[Test]
    public function a_flush_started_while_another_is_running_never_sends_an_event_twice()
    {
        foreach (range(1, 3) as $number) {
            $this->writeEntry(time() - 60 + $number, ['type' => 'logs_batch', 'number' => $number]);
        }

        $client = new class ('login-key', 'project-key') extends Client {
            /** @var array<int, mixed> */
            public array $resent = [];

            /** @var array<int, FlushStop|null> */
            public array $concurrentFlushes = [];

            public function resend(string $json): Response
            {
                $this->resent[] = json_decode($json, true)['number'];

                $this->concurrentFlushes[] = RetryBuffer::fromConfig()->flush($this)->stoppedBy;

                return new Response(200);
            }
        };

        RetryBuffer::fromConfig()->flush($client);

        $this->assertSame([1, 2, 3], $client->resent);
        $this->assertSame([FlushStop::Locked, FlushStop::Locked, FlushStop::Locked], $client->concurrentFlushes);
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function a_buffer_that_cannot_be_written_never_reaches_the_application()
    {
        file_put_contents($this->retryBufferPath, 'a file where the directory should be');

        try {
            $client = $this->client([new Response(503), $this->connectionFailure()]);

            $laraBug = new LaraBug($client);
            $laraBug->handle(new Exception('Not kept'));
            $laraBug->handle(new Exception('Not kept either'));

            $result = RetryBuffer::fromConfig()->flush($client);

            $this->assertSame(0, $result->sent);
            $this->assertCount(2, $this->sent);
        } finally {
            unlink($this->retryBufferPath);
        }
    }

    #[Test]
    public function a_read_only_buffer_never_reaches_the_application()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root writes to a read-only directory regardless of its mode.');
        }

        mkdir($this->retryBufferPath);
        chmod($this->retryBufferPath, 0555);

        try {
            $client = $this->client([new Response(503, ['Retry-After' => '60'])]);

            $client->reportCommands([['command' => 'inspire']]);

            $this->assertSame([], glob("{$this->retryBufferPath}/*"));
        } finally {
            chmod($this->retryBufferPath, 0755);
        }
    }

    #[Test]
    public function an_unreadable_event_is_dropped_without_stopping_the_flush()
    {
        $this->writeEntry(time() - 20, ['type' => 'logs_batch', 'number' => 1]);
        file_put_contents(sprintf('%s/%010d.000000-corrupt.json', $this->retryBufferPath, time() - 30), '{not json');

        $client = $this->client([new Response(200)]);

        $result = RetryBuffer::fromConfig()->flush($client);

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->discarded);
        $this->assertSame([], $this->kept());
    }

    #[Test]
    public function nothing_is_kept_when_the_buffer_is_disabled()
    {
        config(['larabug.retry_buffer.enabled' => false]);

        $client = $this->client([new Response(503)]);

        (new LaraBug($client))->handle(new Exception('Dropped as before'));

        $this->assertFalse(is_dir($this->retryBufferPath));
        $this->assertFalse($client->keepsFailedSends());
    }

    #[Test]
    public function the_flush_command_sends_what_was_kept()
    {
        $this->writeEntry(time() - 20, ['type' => 'logs_batch', 'number' => 1]);
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 2]);

        $this->app->instance(Client::class, $this->client([new Response(200), new Response(200)]));

        $this->artisan('larabug:flush')
            ->expectsOutput('[LaraBug] Sending 2 kept events...')
            ->expectsOutput('[LaraBug] Sent 2, 0 still waiting')
            ->assertExitCode(0);

        $this->assertSame([1, 2], $this->sentPayloadsColumn('number'));
    }

    #[Test]
    public function the_flush_command_does_not_fail_when_the_server_is_still_down()
    {
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 1]);

        $this->app->instance(Client::class, $this->client([new Response(500)]));

        $this->artisan('larabug:flush')
            ->expectsOutput('[LaraBug] Sent 0, 1 still waiting')
            ->expectsOutput('[LaraBug] The server answered with an error again, the rest is kept for the next run')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_flush_command_says_when_another_process_is_flushing()
    {
        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 1]);

        $lock = fopen("{$this->retryBufferPath}/.lock", 'c');
        flock($lock, LOCK_EX);

        $this->app->instance(Client::class, $this->client([]));

        $this->artisan('larabug:flush')
            ->expectsOutput('[LaraBug] Another process is sending them right now')
            ->assertExitCode(0);

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    #[Test]
    public function the_flush_command_says_when_nothing_is_waiting()
    {
        $this->artisan('larabug:flush')
            ->expectsOutput('[LaraBug] Nothing is waiting to be sent')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_flush_is_scheduled_every_minute_on_every_server_while_something_is_waiting()
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, 'larabug:flush')
        ));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertFalse($events[0]->onOneServer);
        $this->assertFalse($events[0]->withoutOverlapping);
        $this->assertFalse($events[0]->filtersPass($this->app));

        $this->writeEntry(time() - 10, ['type' => 'logs_batch', 'number' => 1]);

        $this->assertTrue($events[0]->filtersPass($this->app));
    }

    /**
     * @param  array<int, mixed>  $answers
     */
    protected function client(array $answers, string $login = 'login-key'): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->sent));

        return new Client($login, 'project-key', new GuzzleClient(['handler' => $stack]));
    }

    protected function connectionFailure(): ConnectException
    {
        return new ConnectException(
            'cURL error 7: Failed to connect to larabug.test',
            new Request('POST', 'https://larabug.test/api/log')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeEntry(int $writtenAt, array $payload): void
    {
        if (! is_dir($this->retryBufferPath)) {
            mkdir($this->retryBufferPath, 0775, true);
        }

        file_put_contents(
            sprintf('%s/%010d.000000-%s.json', $this->retryBufferPath, $writtenAt, bin2hex(random_bytes(4))),
            json_encode($payload)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function kept(): array
    {
        $files = glob("{$this->retryBufferPath}/*.json") ?: [];

        sort($files);

        return array_map(fn (string $file) => json_decode((string) file_get_contents($file), true), $files);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentPayloads(): array
    {
        return array_map(
            fn (array $transaction) => json_decode((string) $transaction['request']->getBody(), true),
            $this->sent
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function sentPayloadsColumn(string $key): array
    {
        return array_column($this->sentPayloads(), $key);
    }
}
