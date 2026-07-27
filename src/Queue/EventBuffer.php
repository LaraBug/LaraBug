<?php

namespace LaraBug\Queue;

use Countable;
use Throwable;
use LaraBug\Http\Client;

/**
 * In-memory event buffer for batching queue job events, inspired by Laravel
 * Nightwatch's RecordsBuffer but adapted for HTTP transport.
 */
class EventBuffer implements Countable
{
    protected array $buffer = [];

    protected readonly Client $client;

    protected readonly array $config;

    protected readonly int $batchSize;

    protected int $lastFlushTime;

    protected readonly int $flushInterval;

    protected bool $shutdownHandlerRegistered = false;

    protected readonly LoadMonitor $loadMonitor;

    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->batchSize = $config['jobs']['batch_size'] ?? 50;
        $this->flushInterval = $config['jobs']['flush_interval'] ?? 30;
        $this->lastFlushTime = time();
        $this->loadMonitor = new LoadMonitor();

        $this->registerShutdownHandler();
    }

    /**
     * Add an event to the buffer, or send immediately while load is low.
     */
    public function add(array $data): void
    {
        $batchingEnabled = $this->loadMonitor->recordJob();

        if (! $batchingEnabled) {
            $this->sendImmediately($data);

            return;
        }

        $this->buffer[] = $data;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();

            return;
        }

        if (time() - $this->lastFlushTime >= $this->flushInterval) {
            $this->flush();
        }
    }

    protected function sendImmediately(array $data): void
    {
        try {
            $payload = [
                'type' => 'queue_job',
                'project' => $this->config['project_key'],
                'job' => $data,
            ];

            $this->client->report($payload);
        } catch (Throwable $e) {
            // Fail silently to not break the user's application.
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];
        $this->lastFlushTime = time();

        $this->sendBatch($events);
    }

    protected function sendBatch(array $events, int $attempt = 1): void
    {
        try {
            $payload = [
                'type' => 'queue_jobs_batch',
                'project' => $this->config['project_key'],
                'jobs' => $events,
                'count' => count($events),
            ];

            $maxRetries = $this->config['jobs']['max_retries'] ?? 3;

            $response = $this->client->report($payload);

            if ($response && method_exists($response, 'getStatusCode')) {
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 500 && $attempt < $maxRetries) {
                    usleep(100000 * $attempt); // Backoff: 100ms, 200ms, 300ms

                    $this->sendBatch($events, $attempt + 1);

                    return;
                }
            }
        } catch (Throwable $e) {
            // Retry on network failures.
            if ($attempt < ($this->config['jobs']['max_retries'] ?? 3)) {
                usleep(100000 * $attempt); // Backoff: 100ms, 200ms, 300ms

                $this->sendBatch($events, $attempt + 1);

                return;
            }

            // After max retries, report the error (if enabled) but never break the user's app.
            $this->reportError($e, count($events));
        }
    }

    /**
     * Report buffer errors back to LaraBug (ironic, but useful for debugging).
     */
    protected function reportError(Throwable $e, int $lostEvents): void
    {
        try {
            if ($this->config['jobs']['report_buffer_errors'] ?? false) {
                $this->client->report([
                    'type' => 'buffer_error',
                    'exception' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'lost_events' => $lostEvents,
                    ],
                ]);
            }
        } catch (Throwable $ignored) {
            // Never let error reporting break the app.
        }
    }

    /**
     * Flush whatever is still buffered when the script ends.
     */
    protected function registerShutdownHandler(): void
    {
        if ($this->shutdownHandlerRegistered) {
            return;
        }

        register_shutdown_function($this->flush(...));

        $this->shutdownHandlerRegistered = true;
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    public function isFull(): bool
    {
        return count($this->buffer) >= $this->batchSize;
    }

    /**
     * Clear the buffer without flushing.
     */
    public function clear(): void
    {
        $this->buffer = [];
    }

    /**
     * Get all buffered events (for testing).
     */
    public function getBufferedEvents(): array
    {
        return $this->buffer;
    }
}
