<?php

namespace LaraBug\Queue;

use Throwable;
use LaraBug\Http\Client;
use Illuminate\Contracts\Queue\Job;

class JobMonitor
{
    protected readonly Client $client;

    protected readonly array $config;

    protected readonly JobDataCollector $collector;

    protected readonly ?EventBuffer $buffer;

    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->collector = new JobDataCollector($config);
        $this->buffer = new EventBuffer($client, $config);
    }

    public function trackJobStarted(Job $job, string $connectionName): void
    {
        if (! $this->shouldTrack($job)) {
            return;
        }

        if (! ($this->config['jobs']['track_processing'] ?? false)) {
            return;
        }

        $data = $this->collector->collect($job, $connectionName, 'processing');

        $this->send($data);
    }

    public function trackJobCompleted(Job $job, string $connectionName, ?float $durationMs, ?int $memoryUsed): void
    {
        if (! $this->shouldTrack($job)) {
            return;
        }

        if (! ($this->config['jobs']['track_completed'] ?? true)) {
            return;
        }

        if (! $this->shouldSample()) {
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
        if (! $this->shouldTrack($job)) {
            return;
        }

        if (! ($this->config['jobs']['track_failed'] ?? true)) {
            return;
        }

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

        $data = $this->collector->collect($job, $connectionName, 'failed', [
            'duration_ms' => $durationMs,
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'error' => $exception->getTraceAsString(), // Stack trace goes in the error field
                'storage' => array_filter($storage), // Server/headers data goes in the storage field
                'environment' => config('app.env', 'production'),
            ],
        ]);

        $this->send($data);
    }

    protected function shouldTrack(Job $job): bool
    {
        if (! ($this->config['jobs']['track_jobs'] ?? true)) {
            return false;
        }

        // Don't track queue events while a regular exception capture is in flight —
        // prevents re-entering the send pipeline while LaraBug::handle() is using it.
        if (\LaraBug\LaraBug::isCapturing()) {
            return false;
        }

        $jobClass = $job->resolveName();

        // Hard-coded safety rail on top of the user-configurable ignore list: when
        // the SDK is dogfooded inside the LaraBug SaaS itself, the ingest queue jobs
        // must never ship themselves back to the server.
        if (is_string($jobClass) && (
            str_starts_with($jobClass, 'LaraBug\\')
            || str_starts_with($jobClass, 'Larabug\\')
        )) {
            return false;
        }

        foreach ($this->config['jobs']['ignore_jobs'] ?? [] as $ignoredJob) {
            if ($jobClass === $ignoredJob || is_subclass_of($jobClass, $ignoredJob)) {
                return false;
            }
        }

        $jobQueue = $job->getQueue();
        $onlyQueues = $this->config['jobs']['only_queues'] ?? [];
        $ignoreQueues = $this->config['jobs']['ignore_queues'] ?? [];

        if (! empty($onlyQueues) && ! in_array($jobQueue, $onlyQueues)) {
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
            if ($this->buffer) {
                $this->buffer->add($data);

                return;
            }

            // Fall back to direct sending if the buffer was never initialized.
            $payload = [
                'type' => 'queue_job',
                'project' => $this->config['project_key'],
                'job' => $data,
            ];

            $this->client->report($payload);
        } catch (Throwable $e) {
            // Silent fail — never break the user's jobs.
            $this->reportError($e);
        }
    }

    protected function shouldSample(): bool
    {
        $sampleRate = $this->config['jobs']['sample_rate'] ?? 1.0;

        if ($sampleRate >= 1.0) {
            return true;
        }

        if ($sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $sampleRate;
    }

    protected function reportError(Throwable $e): void
    {
        try {
            if ($this->config['jobs']['report_sdk_errors'] ?? false) {
                $this->client->report([
                    'type' => 'sdk_error',
                    'exception' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ]);
            }
        } catch (Throwable $ignored) {
            // Never let error reporting break the app.
        }
    }

    /**
     * Manually flush the buffer (useful for testing or long-running processes).
     */
    public function flush(): void
    {
        $this->buffer?->flush();
    }
}
