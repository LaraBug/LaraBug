<?php

namespace LaraBug\Queue;

use Exception;
use Carbon\Carbon;
use LaraBug\Filters\DataFilter;
use LaraBug\Requests\TraceContext;
use Illuminate\Contracts\Queue\Job;

class JobDataCollector
{
    protected readonly array $config;

    protected readonly DataFilter $filterer;

    /**
     * When the current attempt of each job became due and was picked up, by
     * job id. Recorded when the attempt starts and forgotten when it ends.
     *
     * @var array<string, array{available_at: ?string, reserved_at: string}>
     */
    protected static array $attemptTimes = [];

    /**
     * When the next attempt of a job released after an exception is due, by
     * job id, kept until that attempt starts.
     *
     * @var array<string, string>
     */
    protected static array $nextDueAt = [];

    public function __construct(array $config)
    {
        $this->config = $config;

        $maxSize = $config['jobs']['max_payload_size'] ?? 10000;

        $this->filterer = new DataFilter($config['blacklist'] ?? [], $maxSize);
    }

    public function collect(Job $job, string $connectionName, string $status, array $extra = []): array
    {
        $payload = json_decode($job->getRawBody(), true) ?? [];

        $jobId = $payload['uuid'] ?? $job->getJobId();

        $data = [
            'job_id' => $jobId,
            'job_class' => $job->resolveName(),
            'display_name' => $payload['displayName'] ?? $job->resolveName(),
            'connection' => $connectionName,
            'queue' => $job->getQueue(),
            'status' => $status,
            'attempts' => $job->attempts(),
            'max_tries' => $payload['maxTries'] ?? $payload['tries'] ?? null,
            'timeout' => $payload['timeout'] ?? null,
            'payload' => $this->filterer->filterPayload($payload),
            'tags' => $payload['tags'] ?? [],
            'trace_id' => TraceContext::id(),
            'parent_trace_id' => TraceContext::parentId() ?? '',
            'created_at' => now()->toIso8601String(),
        ];

        if (isset(static::$attemptTimes[$jobId])) {
            $data['available_at'] = static::$attemptTimes[$jobId]['available_at'];
            $data['reserved_at'] = static::$attemptTimes[$jobId]['reserved_at'];
        }

        if ($status === 'completed') {
            $data['completed_at'] = now()->toIso8601String();
        }

        if ($status === 'failed') {
            $data['failed_at'] = now()->toIso8601String();
        }

        // A job that released itself goes back onto the queue with a delay
        // Laravel does not keep, so all that is known of when it is due again
        // is when it was released.
        if ($status === 'released') {
            $data['available_at'] = now()->toIso8601String();
        }

        return array_merge($data, $extra);
    }

    /**
     * Records when the attempt that is starting became due and was picked up,
     * so every record of the attempt carries the same times. An attempt always
     * starts fresh: only the backoff of a release after an exception carries
     * over from the attempt before it.
     */
    public function started(Job $job): void
    {
        $jobId = $this->jobId($job);

        $now = now();

        $availableAt = static::$nextDueAt[$jobId] ?? $this->availableAtFromPayload($job->payload() ?? [], $now);

        unset(static::$nextDueAt[$jobId]);

        static::$attemptTimes[$jobId] = [
            'available_at' => $availableAt,
            'reserved_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Called whenever an attempt ends, reported or not, so a long running
     * worker does not keep the times of every job it ever ran.
     */
    public function forget(Job $job): void
    {
        unset(static::$attemptTimes[$this->jobId($job)]);
    }

    /**
     * For the attempts that ended without saying so, which only the start of
     * the next job can tell.
     */
    public function forgetAll(): void
    {
        static::$attemptTimes = [];
    }

    /**
     * A job released after an exception goes back onto the queue with its
     * payload unchanged, so only the release can say when its next attempt is
     * due: the backoff after it. Without a backoff the next attempt reads the
     * payload again, as an attempt picked up by another worker always does.
     */
    public function released(Job $job, ?int $backoff): void
    {
        $this->forget($job);

        if ($backoff === null) {
            return;
        }

        static::$nextDueAt[$this->jobId($job)] = now()->addSeconds($backoff)->toIso8601String();

        // The retry may well be picked up by another worker, leaving its due
        // time here unclaimed, so only the most recent releases are kept.
        $maxPendingReleases = 1000;

        if (count(static::$nextDueAt) > $maxPendingReleases) {
            static::$nextDueAt = array_slice(static::$nextDueAt, -$maxPendingReleases, preserve_keys: true);
        }
    }

    protected function jobId(Job $job): string
    {
        return (string) ($job->payload()['uuid'] ?? $job->getJobId());
    }

    /**
     * The dispatch time is Horizon's pushedAt, else the createdAt Laravel
     * itself writes from 12.11. A job carrying neither is taken to be due from
     * the moment a worker picked it up.
     */
    protected function availableAtFromPayload(array $payload, Carbon $now): ?string
    {
        $dispatchedAt = $payload['pushedAt'] ?? $payload['createdAt'] ?? null;

        if ($dispatchedAt === null) {
            return $now->toIso8601String();
        }

        return $this->availableAt($dispatchedAt, $payload['delay'] ?? null);
    }

    /**
     * A delayed job only became due its delay after it was dispatched. The
     * dispatch time is in seconds, so the delay is added before it becomes a
     * date.
     */
    protected function availableAt(mixed $dispatchedAt, mixed $delay): ?string
    {
        if (is_numeric($dispatchedAt) && is_numeric($delay)) {
            return $this->convertTimestamp((float) $dispatchedAt + (float) $delay);
        }

        return $this->convertTimestamp($dispatchedAt);
    }

    protected function convertTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp($timestamp)->toIso8601String();
        }

        try {
            return Carbon::parse($timestamp)->toIso8601String();
        } catch (Exception $e) {
            return null;
        }
    }
}
