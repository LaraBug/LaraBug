<?php

namespace LaraBug\Queue;

use Throwable;
use LaraBug\Http\Client;
use Illuminate\Contracts\Queue\Job;

class JobMonitor
{
    protected Client $client;

    protected array $config;

    protected JobDataCollector $collector;

    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->collector = new JobDataCollector($config);
    }

    public function trackJobStarted(Job $job, string $connectionName): void
    {
        if (!$this->shouldTrack($job)) {
            return;
        }

        $data = $this->collector->collect($job, $connectionName, 'processing');

        $this->send($data);
    }

    public function trackJobCompleted(Job $job, string $connectionName, ?float $durationMs, ?int $memoryUsed): void
    {
        if (!$this->shouldTrack($job)) {
            return;
        }

        $data = $this->collector->collect($job, $connectionName, 'completed', [
            'duration_ms' => $durationMs,
            'memory_usage' => $memoryUsed,
        ]);

        $this->send($data);
    }

    public function trackJobFailed(Job $job, string $connectionName, Throwable $exception, ?float $durationMs): void
    {
        if (!$this->shouldTrack($job)) {
            return;
        }
        
        // Build storage data similar to regular exceptions
        $storage = [
            'SERVER' => [
                'USER' => $_SERVER['USER'] ?? null,
                'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                'SERVER_PROTOCOL' => $_SERVER['SERVER_PROTOCOL'] ?? null,
                'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'PHP_VERSION' => PHP_VERSION,
            ],
            'HEADERS' => getallheaders() ?: [],
        ];
        
        // ALWAYS track failures
        $data = $this->collector->collect($job, $connectionName, 'failed', [
            'duration_ms' => $durationMs,
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'error' => $exception->getTraceAsString(), // Stack trace goes in error field
                'storage' => array_filter($storage), // Server/headers data goes in storage field
                'environment' => config('app.env', 'production'),
            ],
        ]);

        $this->send($data);
    }

    /**
     * Check if this specific job status should be tracked (success/processing)
     * Jobs can opt-in by calling ->track() when dispatching
     */
    protected function shouldTrackStatus(Job $job): bool
    {
        // Check if job explicitly wants to be tracked via ->track()
        $jobInstance = $this->getJobInstance($job);
        
        if ($jobInstance && property_exists($jobInstance, 'trackInLaraBug') && $jobInstance->trackInLaraBug === true) {
            return true;
        }

        // Otherwise respect global track_all_jobs setting
        return $this->config['jobs']['track_all_jobs'] ?? false;
    }

    /**
     * Get the actual job instance from the Job container
     */
    protected function getJobInstance(Job $job)
    {
        try {
            $payload = json_decode($job->getRawBody(), true);
            
            if (isset($payload['data']['command'])) {
                return unserialize($payload['data']['command']);
            }
        } catch (\Throwable $e) {
            // Silently fail - don't break jobs
        }

        return null;
    }

    /**
     * Check if job should be tracked based on filters
     */
    protected function shouldTrack(Job $job): bool
    {
        $jobClass = $job->resolveName();

        // Check ignore list
        foreach ($this->config['jobs']['ignore_jobs'] ?? [] as $ignoredJob) {
            if ($jobClass === $ignoredJob || is_subclass_of($jobClass, $ignoredJob)) {
                return false;
            }
        }

        // Check queue filters
        $jobQueue = $job->getQueue();
        $onlyQueues = $this->config['jobs']['only_queues'] ?? [];
        $ignoreQueues = $this->config['jobs']['ignore_queues'] ?? [];

        if (!empty($onlyQueues) && !in_array($jobQueue, $onlyQueues)) {
            return false;
        }

        if (in_array($jobQueue, $ignoreQueues)) {
            return false;
        }

        return true;
    }

    protected function send(array $data): void
    {
        try {
            $payload = [
                'type' => 'queue_job',
                'project' => $this->config['project_key'],
                'job' => $data,
            ];
            
            $this->client->report($payload);
        } catch (Throwable $e) {
            // Silent fail - never break user's jobs
        }
    }
}
