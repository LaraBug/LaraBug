<?php

namespace LaraBug\Tests;

use Illuminate\Support\Str;
use Illuminate\Queue\Jobs\SyncJob;
use LaraBug\Queue\JobDataCollector;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Queue\Events\JobReleasedAfterException;

/**
 * available_at is when the job became due, which the server compares with
 * reserved_at to tell a delayed job from a lost one.
 */
class JobAvailableAtTest extends TestCase
{
    #[Test]
    public function a_job_that_was_not_delayed_is_available_from_the_moment_it_was_pushed()
    {
        $record = $this->collect($this->payload(pushedAt: 1760875200.4, delay: null), 'processing');

        $this->assertSame('2025-10-19T12:00:00+00:00', $record['available_at']);
    }

    #[Test]
    public function a_delayed_job_is_available_once_its_delay_has_passed()
    {
        $record = $this->collect($this->payload(pushedAt: 1760875200.4, delay: 300), 'processing');

        $this->assertSame('2025-10-19T12:05:00+00:00', $record['available_at']);
    }

    #[Test]
    public function a_pushed_at_written_as_a_string_still_takes_the_delay()
    {
        $record = $this->collect($this->payload(pushedAt: '1760875200.4', delay: 300), 'processing');

        $this->assertSame('2025-10-19T12:05:00+00:00', $record['available_at']);
    }

    #[Test]
    public function a_job_without_pushed_at_is_available_from_when_laravel_created_it()
    {
        $record = $this->collect($this->payload(pushedAt: null, delay: null, createdAt: 1760875200), 'processing');

        $this->assertSame('2025-10-19T12:00:00+00:00', $record['available_at']);
    }

    #[Test]
    public function a_delayed_job_without_pushed_at_is_available_once_its_delay_has_passed_since_it_was_created()
    {
        $record = $this->collect($this->payload(pushedAt: null, delay: 300, createdAt: 1760875200), 'processing');

        $this->assertSame('2025-10-19T12:05:00+00:00', $record['available_at']);
    }

    #[Test]
    public function a_created_at_written_as_a_float_or_a_string_still_takes_the_delay()
    {
        $fromFloat = $this->collect($this->payload(pushedAt: null, delay: 300, createdAt: 1760875200.4), 'processing');
        $fromString = $this->collect($this->payload(pushedAt: null, delay: 300, createdAt: '1760875200'), 'processing');

        $this->assertSame('2025-10-19T12:05:00+00:00', $fromFloat['available_at']);
        $this->assertSame('2025-10-19T12:05:00+00:00', $fromString['available_at']);
    }

    #[Test]
    public function pushed_at_is_preferred_over_created_at()
    {
        $record = $this->collect($this->payload(pushedAt: 1760875260.4, delay: null, createdAt: 1760875200), 'processing');

        $this->assertSame('2025-10-19T12:01:00+00:00', $record['available_at']);
    }

    #[Test]
    public function a_job_without_pushed_at_or_created_at_is_available_from_when_a_worker_picked_it_up()
    {
        $this->freezeSecond();

        $record = $this->collect(['uuid' => (string) Str::uuid(), 'job' => 'App\\Jobs\\Legacy', 'delay' => 300], 'processing');

        $this->assertSame(now()->toIso8601String(), $record['available_at']);
        $this->assertSame($record['reserved_at'], $record['available_at']);
    }

    #[Test]
    public function the_completed_record_repeats_the_available_at_of_the_processing_record()
    {
        $payload = $this->payload(pushedAt: 1760875200.4, delay: 300);

        $processing = $this->collect($payload, 'processing');

        $this->travel(10)->minutes();

        $completed = $this->collect($payload, 'completed');

        $this->assertSame('2025-10-19T12:05:00+00:00', $completed['available_at']);
        $this->assertSame($processing['available_at'], $completed['available_at']);
        $this->assertSame($processing['reserved_at'], $completed['reserved_at']);
    }

    #[Test]
    public function a_job_released_after_an_exception_reports_a_new_reserved_at_on_its_next_attempt()
    {
        $this->freezeSecond();

        $payload = $this->payload(pushedAt: 1760875200.4, delay: null);

        $firstAttempt = $this->collect($payload, 'processing');

        $this->release($payload);

        $this->travel(5)->minutes();

        $secondAttempt = $this->collect($payload, 'processing');

        $this->assertSame(now()->toIso8601String(), $secondAttempt['reserved_at']);
        $this->assertNotSame($firstAttempt['reserved_at'], $secondAttempt['reserved_at']);
    }

    #[Test]
    public function a_job_released_after_an_exception_is_available_once_its_backoff_has_passed()
    {
        if (! property_exists(JobReleasedAfterException::class, 'backoff')) {
            $this->markTestSkipped('JobReleasedAfterException carries the backoff from Laravel 12.48.');
        }

        $this->freezeSecond();

        $payload = $this->payload(pushedAt: 1760875200.4, delay: null);

        $this->collect($payload, 'processing');

        $releasedAt = now();

        $this->release($payload, backoff: 30);

        $this->travel(5)->minutes();

        $secondAttempt = $this->collect($payload, 'processing');

        $this->travel(1)->minutes();

        $completed = $this->collect($payload, 'completed');

        $this->assertSame($releasedAt->addSeconds(30)->toIso8601String(), $secondAttempt['available_at']);
        $this->assertSame($secondAttempt['available_at'], $completed['available_at']);
        $this->assertSame($secondAttempt['reserved_at'], $completed['reserved_at']);
    }

    #[Test]
    public function a_job_released_without_a_backoff_is_available_from_its_dispatch_again()
    {
        $payload = $this->payload(pushedAt: 1760875200.4, delay: 300);

        $this->collect($payload, 'processing');

        event(new JobReleasedAfterException('redis', $this->job($payload)));

        $secondAttempt = $this->collect($payload, 'processing');

        $this->assertSame('2025-10-19T12:05:00+00:00', $secondAttempt['available_at']);
    }

    private function payload(float|string|null $pushedAt, ?int $delay, float|int|string|null $createdAt = null): array
    {
        return array_filter([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'App\\Jobs\\SendInvoice',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'pushedAt' => $pushedAt,
            'createdAt' => $createdAt,
            'delay' => $delay,
        ], fn (mixed $value) => $value !== null);
    }

    private function release(array $payload, int $backoff = 0): void
    {
        event(new JobReleasedAfterException('redis', $this->job($payload), $backoff));
    }

    private function collect(array $payload, string $status): array
    {
        return (new JobDataCollector(config('larabug')))->collect($this->job($payload), 'redis', $status);
    }

    private function job(array $payload): SyncJob
    {
        return new SyncJob($this->app, json_encode($payload), 'redis', 'default');
    }
}
