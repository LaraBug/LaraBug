<?php

namespace LaraBug\Queue;

use LaraBug\Filters\DataFilter;
use Illuminate\Contracts\Queue\Job;

class JobDataCollector
{
    protected array $config;

    protected DataFilter $filterer;

    /**
     * Cache of timestamps by job_id
     * Persists for the lifetime of the worker process
     */
    protected static array $reservedAtCache = [];
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
        
        // Use UUID from payload if available, otherwise fall back to job ID
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

        // Add timestamps based on status
        // Set timestamps on first event (processing)
        if ($status === 'processing' && !isset(static::$reservedAtCache[$jobId])) {
            $now = now();
            
            // available_at: When job was pushed to queue (use pushedAt from payload if available)
            $availableAt = isset($payload['pushedAt']) 
                ? $this->convertTimestamp($payload['pushedAt'])
                : $now->toIso8601String();
            
            // reserved_at: When worker picked up the job (now)
            $reservedAt = $now->toIso8601String();
            
            // Cache both timestamps
            static::$availableAtCache[$jobId] = $availableAt;
            static::$reservedAtCache[$jobId] = $reservedAt;
            
            $data['available_at'] = $availableAt;
            $data['reserved_at'] = $reservedAt;
        } elseif (isset(static::$reservedAtCache[$jobId])) {
            // Use cached timestamps for completed/failed states
            $data['available_at'] = static::$availableAtCache[$jobId] ?? null;
            $data['reserved_at'] = static::$reservedAtCache[$jobId];
        }

        // completed_at: When job finished successfully
        if ($status === 'completed') {
            $data['completed_at'] = now()->toIso8601String();
            // Clean up cache
            unset(static::$reservedAtCache[$jobId]);
            unset(static::$availableAtCache[$jobId]);
        }

        // failed_at: When job failed
        if ($status === 'failed') {
            $data['failed_at'] = now()->toIso8601String();
            // Clean up cache
            unset(static::$reservedAtCache[$jobId]);
            unset(static::$availableAtCache[$jobId]);
        }

        return array_merge($data, $extra);
    }

    /**
     * Convert timestamp to ISO8601 string format
     */
    protected function convertTimestamp($timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        // If it's already a timestamp integer
        if (is_numeric($timestamp)) {
            return \Carbon\Carbon::createFromTimestamp($timestamp)->toIso8601String();
        }

        // If it's a date string, try to parse it
        try {
            return \Carbon\Carbon::parse($timestamp)->toIso8601String();
        } catch (\Exception $e) {
            return null;
        }
    }
}
