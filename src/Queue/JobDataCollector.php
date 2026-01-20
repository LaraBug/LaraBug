<?php

namespace LaraBug\Queue;

use LaraBug\Filters\DataFilter;
use Illuminate\Contracts\Queue\Job;

class JobDataCollector
{
    protected array $config;

    protected DataFilter $filterer;

    public function __construct(array $config)
    {
        $this->config = $config;

        $maxSize = $config['jobs']['max_payload_size'] ?? 10000;

        $this->filterer = new DataFilter($config['blacklist'] ?? [], $maxSize);
    }

    public function collect(Job $job, string $connectionName, string $status, array $extra = []): array
    {
        $payload = json_decode($job->getRawBody(), true) ?? [];

        return array_merge([
            'job_id' => $job->getJobId(),
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
        ], $extra);
    }
}
