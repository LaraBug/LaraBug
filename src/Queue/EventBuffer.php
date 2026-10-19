<?php

namespace LaraBug\Queue;

use Countable;
use Throwable;
use LaraBug\Http\Client;
use LaraBug\Support\LimitBackoff;
use LaraBug\Support\Concerns\RequeuesRateLimitedRecords;

/**
 * In-memory event buffer for batching queue job events, inspired by Laravel
 * Nightwatch's RecordsBuffer but adapted for HTTP transport.
 */
class EventBuffer implements Countable
{
    use RequeuesRateLimitedRecords;

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
        // Told about the job even while the stream is held back, so the
        // batching decision on the other side of the window is made from the
        // load that really ran rather than from a gap.
        $batchingEnabled = $this->loadMonitor->recordJob();

        // The telemetry limit is reached for now. Collecting anyway would
        // only fill a buffer nobody may send.
        if (! LimitBackoff::collects(LimitBackoff::TELEMETRY)) {
            return;
        }

        // Buffered during a rate limit even while load is low, so it goes out
        // with the rest once the window passes.
        if (! $batchingEnabled && LimitBackoff::allows(LimitBackoff::TELEMETRY)) {
            $this->sendImmediately($data);

            return;
        }

        $this->buffer[] = $data;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();

            return;
        }

        $this->flushIfDue();
    }

    /**
     * Flush once the interval has passed since the last flush. Checked as
     * events arrive and on every loop of the worker, so an idle worker still
     * sends the tail of a burst instead of holding it until the next job.
     */
    public function flushIfDue(): void
    {
        if (time() - $this->lastFlushTime < $this->flushInterval) {
            return;
        }

        $this->flush();
    }

    protected function sendImmediately(array $data): void
    {
        try {
            $payload = [
                'type' => 'queue_job',
                'project' => $this->config['project_key'],
                'job' => $data,
            ];

            if ($this->refusedByLimit($this->client->report($payload), [$data])) {
                return;
            }

            $this->reportDroppedRecords();
        } catch (Throwable $e) {
            // Fail silently to not break the user's application.
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        // Kept for the other side of the window rather than sent into it.
        if (LimitBackoff::isRateLimited(LimitBackoff::TELEMETRY)) {
            $this->requeue();

            return;
        }

        $events = $this->buffer;
        $this->buffer = [];
        $this->lastFlushTime = time();

        $this->sendBatch($events);
    }

    protected function sendBatch(array $events, int $attempt = 1): void
    {
        // Refused for the rest of the window: these events go no further.
        if (! LimitBackoff::allows(LimitBackoff::TELEMETRY)) {
            return;
        }

        try {
            $payload = [
                'type' => 'queue_jobs_batch',
                'project' => $this->config['project_key'],
                'jobs' => $events,
                'count' => count($events),
            ];

            $maxRetries = $this->config['jobs']['max_retries'] ?? 3;

            $response = $this->client->report($payload);

            // Over the telemetry limit for this billing period, or sending
            // too fast. Nothing more is sent until the window the server asked
            // for has passed, and issues keep going in the meantime. A rate
            // limited batch waits in the buffer for the end of that window.
            if ($this->refusedByLimit($response, $events)) {
                return;
            }

            $this->reportDroppedRecords();

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

    protected function batchSize(): int
    {
        return $this->batchSize;
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
