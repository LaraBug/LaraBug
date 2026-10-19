<?php

namespace LaraBug\Tests;

use Illuminate\Support\Str;
use Illuminate\Queue\Jobs\SyncJob;
use LaraBug\Queue\JobDataCollector;
use PHPUnit\Framework\Attributes\Test;

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
    public function a_job_without_pushed_at_is_available_from_when_a_worker_picked_it_up()
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

    private function payload(float|string $pushedAt, ?int $delay): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'displayName' => 'App\\Jobs\\SendInvoice',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'pushedAt' => $pushedAt,
            'delay' => $delay,
        ];
    }

    private function collect(array $payload, string $status): array
    {
        $job = new SyncJob($this->app, json_encode($payload), 'redis', 'default');

        return (new JobDataCollector(config('larabug')))->collect($job, 'redis', $status);
    }
}
