<?php

namespace LaraBug\Tests;

use Exception;
use LaraBug\LaraBug;
use ReflectionClass;
use LaraBug\Logger\LogBuffer;
use LaraBug\Queue\EventBuffer;
use LaraBug\Support\LimitBackoff;
use LaraBug\Console\CommandBuffer;
use Illuminate\Support\Facades\Log;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Http\Client as HttpClient;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Console\ScheduledTaskBuffer;

class RateLimitBackoffTest extends TestCase
{
    protected MeteredClient $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new MeteredClient();
        $this->app->instance(HttpClient::class, $this->client);

        $this->app['config']['larabug.project_key'] = 'project';
        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['logging.default'] = 'larabug-logs';
    }

    #[Test]
    public function a_429_holds_the_stream_for_as_long_as_retry_after_asks()
    {
        $this->client->willRateLimit('20');

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertTrue(LimitBackoff::isRateLimited(LimitBackoff::TELEMETRY));
        $this->assertEqualsWithDelta(time() + 20, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);
    }

    #[Test]
    public function a_429_holds_the_stream_until_the_date_retry_after_names()
    {
        $this->client->willRateLimit($this->httpDate(time() + 40));

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(time() + 40, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);
    }

    #[Test]
    public function a_429_without_retry_after_waits_until_the_rate_limit_resets()
    {
        $this->client->willRateLimit(reset: (string) (time() + 15));

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(time() + 15, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);
    }

    #[Test]
    public function a_rate_limit_reset_given_in_seconds_is_read_as_seconds()
    {
        $this->client->willRateLimit(reset: '25');

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(time() + 25, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);
    }

    #[Test]
    public function a_429_that_does_not_say_how_long_waits_a_short_while()
    {
        $this->client->willRateLimit();

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + LimitBackoff::DEFAULT_RATE_LIMIT_COOLDOWN,
            LimitBackoff::resumesAt(LimitBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function a_429_never_holds_a_stream_for_longer_than_a_minute()
    {
        $this->client->willRateLimit('3600');

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + LimitBackoff::MAX_RATE_LIMIT_COOLDOWN,
            LimitBackoff::resumesAt(LimitBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function a_429_asking_for_no_wait_still_holds_the_stream_for_a_second()
    {
        $this->client->willRateLimit('0');

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(time() + 1, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);
    }

    #[Test]
    public function log_lines_refused_with_a_429_are_sent_once_the_hold_has_passed()
    {
        $this->client->willRateLimit('20');

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertSame(1, $this->logs()->bufferedCount());

        Log::info('Written during the hold');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);
        $this->assertSame(2, $this->logs()->bufferedCount());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $this->logs()->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame('logs_batch', $this->client->lastRequest()['type']);
        $this->assertSame(
            ['Rate limited', 'Written during the hold'],
            array_column($this->client->lastRequest()['logs'], 'message')
        );
        $this->assertSame(0, $this->logs()->bufferedCount());
    }

    #[Test]
    public function queue_jobs_refused_with_a_429_are_sent_once_the_hold_has_passed()
    {
        $this->app['config']['larabug.jobs.auto_batch_threshold'] = 1;
        $this->app['config']['larabug.jobs.batch_size'] = 2;

        $this->client->willRateLimit('20');

        $jobs = new EventBuffer($this->client, $this->config());
        $jobs->add(['job' => 'SendInvoice']);
        $jobs->add(['job' => 'SendReminder']);

        $this->client->assertRequestsSent(1);
        $this->assertSame(2, $jobs->count());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $jobs->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame('queue_jobs_batch', $this->client->lastRequest()['type']);
        $this->assertSame(
            ['SendInvoice', 'SendReminder'],
            array_column($this->client->lastRequest()['jobs'], 'job')
        );
        $this->assertSame(0, $jobs->count());
    }

    #[Test]
    public function a_queue_job_sent_on_its_own_is_kept_when_it_gets_a_429()
    {
        $this->client->willRateLimit('20');

        $jobs = new EventBuffer($this->client, $this->config());
        $jobs->add(['job' => 'SendInvoice']);

        $this->client->assertRequestsSent(1);
        $this->assertSame([['job' => 'SendInvoice']], $jobs->getBufferedEvents());

        $jobs->add(['job' => 'SendReminder']);

        $this->client->assertRequestsSent(1);
        $this->assertSame(2, $jobs->count());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $jobs->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame(
            ['SendInvoice', 'SendReminder'],
            array_column($this->client->lastRequest()['jobs'], 'job')
        );
    }

    #[Test]
    public function requests_refused_with_a_429_are_sent_once_the_hold_has_passed()
    {
        $this->client->willRateLimit('20');

        $requests = new RequestBuffer($this->client, $this->config());
        $requests->add(['uri' => '/checkout']);
        $requests->flush();

        $this->assertSame([['uri' => '/checkout']], $requests->pending());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $requests->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame([['uri' => '/checkout']], $this->client->lastRequest()['requests']);
        $this->assertSame(0, $requests->count());
    }

    #[Test]
    public function commands_refused_with_a_429_are_sent_once_the_hold_has_passed()
    {
        $this->client->willRateLimit('20');

        $commands = new CommandBuffer($this->client, $this->config());
        $commands->add(['command' => 'migrate']);
        $commands->flush();

        $this->assertSame(1, $commands->count());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $commands->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame([['command' => 'migrate']], $this->client->lastRequest()['commands']);
    }

    #[Test]
    public function scheduled_tasks_refused_with_a_429_are_sent_once_the_hold_has_passed()
    {
        $this->client->willRateLimit('20');

        $tasks = new ScheduledTaskBuffer($this->client, $this->config());
        $tasks->add(['task' => 'backup:run']);
        $tasks->flush();

        $this->assertSame(1, $tasks->count());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $tasks->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame([['task' => 'backup:run']], $this->client->lastRequest()['scheduled_tasks']);
    }

    #[Test]
    public function a_rate_limited_buffer_keeps_at_most_ten_batches_and_reports_what_it_dropped()
    {
        $this->app['config']['larabug.jobs.auto_batch_threshold'] = 1;
        $this->app['config']['larabug.jobs.batch_size'] = 2;
        $this->app['config']['larabug.jobs.report_buffer_errors'] = true;

        $this->client->willRateLimit('20');

        $jobs = new EventBuffer($this->client, $this->config());

        foreach (range(0, 24) as $index) {
            $jobs->add(['job' => "Job{$index}"]);
        }

        $this->client->assertRequestsSent(1);
        $this->assertSame(20, $jobs->count());
        $this->assertSame('Job5', $jobs->getBufferedEvents()[0]['job']);

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $jobs->flush();

        $this->client->assertRequestsSent(3);

        [, $batch, $report] = $this->client->requests();

        $this->assertSame(20, $batch['count']);
        $this->assertSame('Job24', end($batch['jobs'])['job']);
        $this->assertSame('buffer_error', $report['type']);
        $this->assertSame(5, $report['exception']['lost_events']);
    }

    #[Test]
    public function dropped_records_are_reported_only_once()
    {
        $this->app['config']['larabug.logs.batch_size'] = 1;
        $this->app['config']['larabug.jobs.report_buffer_errors'] = true;

        $this->client->willRateLimit('20');

        $logs = new LogBuffer($this->client, $this->config());

        foreach (range(0, 11) as $index) {
            $logs->add(['message' => "Line {$index}"]);
        }

        $this->assertSame(10, $logs->bufferedCount());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $logs->flush();

        $logs->add(['message' => 'After the hold']);

        $reports = array_filter($this->client->requests(), fn (array $request) => $request['type'] === 'buffer_error');

        $this->assertCount(1, $reports);
        $this->assertSame(2, array_values($reports)[0]['exception']['lost_events']);
    }

    #[Test]
    public function a_402_still_gives_the_batch_up_and_collects_nothing_during_the_hold()
    {
        $this->app['config']['larabug.jobs.auto_batch_threshold'] = 1;
        $this->app['config']['larabug.jobs.batch_size'] = 2;

        $this->client->willRefuse(LimitBackoff::TELEMETRY, '90');

        $jobs = new EventBuffer($this->client, $this->config());
        $jobs->add(['job' => 'SendInvoice']);
        $jobs->add(['job' => 'SendReminder']);

        $this->client->assertRequestsSent(1);
        $this->assertSame(0, $jobs->count());
        $this->assertFalse(LimitBackoff::isRateLimited(LimitBackoff::TELEMETRY));
        $this->assertEqualsWithDelta(time() + 90, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);

        $jobs->add(['job' => 'SendReceipt']);

        $this->assertSame(0, $jobs->count());

        $this->windowHasPassed(LimitBackoff::TELEMETRY);
        $jobs->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_402_still_stops_collecting_log_lines_during_the_hold()
    {
        $this->client->willRefuse(LimitBackoff::TELEMETRY);

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertFalse($this->logs()->enabled());
        $this->assertSame(0, $this->logs()->bufferedCount());
        $this->assertEqualsWithDelta(time() + 300, LimitBackoff::resumesAt(LimitBackoff::TELEMETRY), 1);
    }

    #[Test]
    public function log_lines_are_still_collected_during_a_429_hold()
    {
        $this->client->willRateLimit('20');

        Log::info('Rate limited');
        $this->logs()->flush();

        $this->assertTrue($this->logs()->enabled());
    }

    #[Test]
    public function exceptions_keep_being_reported_while_telemetry_is_rate_limited()
    {
        $this->client->willRateLimit('20');

        Log::info('Rate limited');
        $this->logs()->flush();

        (new LaraBug($this->client))->handle(new Exception('The one that took the site down'));

        $this->client->assertRequestsSent(2);
        $this->assertArrayHasKey('exception', $this->client->lastRequest());
        $this->assertNull(LimitBackoff::resumesAt(LimitBackoff::ISSUES));
    }

    #[Test]
    public function telemetry_keeps_being_sent_while_exceptions_are_rate_limited()
    {
        $this->client->willRateLimit('20');

        (new LaraBug($this->client))->handle(new Exception('Rate limited'));

        Log::info('Still shipping');
        $this->logs()->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame('logs_batch', $this->client->lastRequest()['type']);
        $this->assertTrue(LimitBackoff::isRateLimited(LimitBackoff::ISSUES));
        $this->assertNull(LimitBackoff::resumesAt(LimitBackoff::TELEMETRY));
    }

    #[Test]
    public function a_rate_limited_exception_holds_exception_reporting_for_at_most_a_minute()
    {
        $this->client->willRateLimit('600');

        $larabug = new LaraBug($this->client);
        $larabug->handle(new Exception('Rate limited'));
        $larabug->handle(new Exception('Not even attempted'));

        $this->client->assertRequestsSent(1);
        $this->assertEqualsWithDelta(
            time() + LimitBackoff::MAX_RATE_LIMIT_COOLDOWN,
            LimitBackoff::resumesAt(LimitBackoff::ISSUES),
            1
        );

        $this->windowHasPassed(LimitBackoff::ISSUES);
        $larabug->handle(new Exception('Reported again'));

        $this->client->assertRequestsSent(2);
    }

    #[Test]
    public function the_test_command_tells_a_rate_limit_apart_from_a_reached_limit()
    {
        $this->client->willRateLimit('20');

        $this->app['larabug'] = new LaraBug($this->client);

        $this->artisan('larabug:test')
            ->expectsOutput('✗ [LaraBug] This project is sending faster than its plan\'s rate limit, so the exception was refused')
            ->doesntExpectOutput('✗ [LaraBug] This project\'s issue limit is reached, so the exception was refused')
            ->assertExitCode(0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return $this->app['config']->get('larabug', []);
    }

    protected function httpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
    }

    protected function logs(): LogBuffer
    {
        return $this->app[LogBuffer::class];
    }

    /**
     * Move a stream's window into the past rather than sleeping through it.
     */
    protected function windowHasPassed(string $stream): void
    {
        $property = (new ReflectionClass(LimitBackoff::class))->getProperty('resumeAt');

        $resumeAt = $property->getValue();
        $resumeAt[$stream] = time() - 1;

        $property->setValue(null, $resumeAt);
    }
}
