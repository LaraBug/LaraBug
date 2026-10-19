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
     * Timestamps by job id, cached for the lifetime of the worker process.
     *
     * @var array<string, string>
     */
    protected static array $reservedAtCache = [];

    /** @var array<string, string> */
    protected static array $availableAtCache = [];

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

        // available_at (when the job became due) and reserved_at (when a worker
        // picked it up) are set on the first processing event and cached, so
        // completed/failed events report the same values.
        if ($status === 'processing' && ! isset(static::$reservedAtCache[$jobId])) {
            $now = now();

            $availableAt = static::$availableAtCache[$jobId] ?? $this->availableAtFromPayload($payload, $now);

            $reservedAt = $now->toIso8601String();

            static::$availableAtCache[$jobId] = $availableAt;
            static::$reservedAtCache[$jobId] = $reservedAt;

            $data['available_at'] = $availableAt;
            $data['reserved_at'] = $reservedAt;
        } elseif (isset(static::$reservedAtCache[$jobId])) {
            $data['available_at'] = static::$availableAtCache[$jobId] ?? null;
            $data['reserved_at'] = static::$reservedAtCache[$jobId];
        }

        if ($status === 'completed') {
            $data['completed_at'] = now()->toIso8601String();

            unset(static::$reservedAtCache[$jobId]);
            unset(static::$availableAtCache[$jobId]);
        }

        if ($status === 'failed') {
            $data['failed_at'] = now()->toIso8601String();

            unset(static::$reservedAtCache[$jobId]);
            unset(static::$availableAtCache[$jobId]);
        }

        return array_merge($data, $extra);
    }

    /**
     * A job released after an exception goes back onto the queue with its
     * payload unchanged, so only the release can say when its next attempt is
     * due: the backoff after it. Without a backoff the next attempt reads the
     * payload again, as an attempt picked up by another worker always does.
     */
    public function released(Job $job, ?int $backoff): void
    {
        $jobId = $job->payload()['uuid'] ?? $job->getJobId();

        unset(static::$reservedAtCache[$jobId]);
        unset(static::$availableAtCache[$jobId]);

        if ($backoff === null) {
            return;
        }

        static::$availableAtCache[$jobId] = now()->addSeconds($backoff)->toIso8601String();
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
