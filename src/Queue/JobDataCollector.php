<?php

namespace LaraBug\Queue;

use Exception;
use Carbon\Carbon;
use LaraBug\Filters\DataFilter;
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
            'created_at' => now()->toIso8601String(),
        ];

        // available_at (when the job was pushed) and reserved_at (when a worker
        // picked it up) are set on the first processing event and cached, so
        // completed/failed events report the same values.
        if ($status === 'processing' && ! isset(static::$reservedAtCache[$jobId])) {
            $now = now();

            $availableAt = isset($payload['pushedAt'])
                ? $this->convertTimestamp($payload['pushedAt'])
                : $now->toIso8601String();

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
