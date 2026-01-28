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
    
    protected ?EventBuffer $buffer = null;

    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->collector = new JobDataCollector($config);
        
        // Initialize buffer for dynamic batching
        $this->buffer = new EventBuffer($client, $config);
    }

    public function trackJobStarted(Job $job, string $connectionName): void
    {
        if (!$this->shouldTrack($job)) {
            return;
        }
        
        // Check if we should track processing events
        if (!($this->config['jobs']['track_processing'] ?? false)) {
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
        
        // Check if we should track completed events
        if (!($this->config['jobs']['track_completed'] ?? true)) {
            return;
        }
        
        // Apply sampling rate
        if (!$this->shouldSample()) {
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
        
        // ALWAYS track failures (unless explicitly disabled)
        if (!($this->config['jobs']['track_failed'] ?? true)) {
            return;
        }
        
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
     * Check if job should be tracked based on filters and configuration
     */
    protected function shouldTrack(Job $job): bool
    {
        // Check if tracking is globally disabled
        if (!($this->config['jobs']['track_jobs'] ?? true)) {
            return false;
        }

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
            // Use buffer for dynamic batching
            if ($this->buffer) {
                $this->buffer->add($data);
                return;
            }
            
            // Fall back to direct sending if buffer not initialized
            $payload = [
                'type' => 'queue_job',
                'project' => $this->config['project_key'],
                'job' => $data,
            ];
            
            $this->client->report($payload);
        } catch (Throwable $e) {
            // Silent fail - never break user's jobs
            // But report the error if configured
            $this->reportError($e);
        }
    }
    
    /**
     * Apply sampling rate for successful job completions
     */
    protected function shouldSample(): bool
    {
        $sampleRate = $this->config['jobs']['sample_rate'] ?? 1.0;
        
        // Always sample at 100%
        if ($sampleRate >= 1.0) {
            return true;
        }
        
        // Never sample
        if ($sampleRate <= 0.0) {
            return false;
        }
        
        // Random sampling
        return (mt_rand() / mt_getrandmax()) <= $sampleRate;
    }
    
    /**
     * Report SDK errors (for debugging)
     */
    protected function reportError(Throwable $e): void
    {
        try {
            if ($this->config['jobs']['report_sdk_errors'] ?? false) {
                $this->client->report([
                    'type' => 'sdk_error',
                    'exception' => [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ]);
            }
        } catch (Throwable $ignored) {
            // Never let error reporting break the app
        }
    }
    
    /**
     * Manually flush the buffer (useful for testing or long-running processes)
     */
    public function flush(): void
    {
        if ($this->buffer) {
            $this->buffer->flush();
        }
    }
}
