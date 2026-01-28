<?php

namespace LaraBug\Queue;

use Throwable;
use Countable;
use LaraBug\Http\Client;

/**
 * In-memory event buffer for batching queue job events
 * 
 * Inspired by Laravel Nightwatch's RecordsBuffer but adapted for HTTP transport.
 * Reduces API calls by batching multiple events into single requests.
 */
class EventBuffer implements Countable
{
    protected array $buffer = [];
    
    protected Client $client;
    
    protected array $config;
    
    protected int $batchSize;
    
    protected int $lastFlushTime;
    
    protected int $flushInterval;
    
    protected bool $shutdownHandlerRegistered = false;
    
    protected LoadMonitor $loadMonitor;

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
     * Add an event to the buffer (or send immediately if batching disabled)
     */
    public function add(array $data): void
    {
        // Record job and check if batching should be enabled
        $batchingEnabled = $this->loadMonitor->recordJob();
        
        if (!$batchingEnabled) {
            // Low load - send immediately without buffering
            $this->sendImmediately($data);
            return;
        }
        
        // High load - buffer the event
        $this->buffer[] = $data;
        
        // Auto-flush when buffer is full
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
            return;
        }
        
        // Auto-flush based on time interval
        if (time() - $this->lastFlushTime >= $this->flushInterval) {
            $this->flush();
        }
    }

    /**
     * Send a single event immediately without buffering
     */
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
            // Fail silently to not break user's application
        }
    }

    /**
     * Flush all buffered events to the API
     */
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

    /**
     * Send batch of events with retry logic
     */
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
            
            // Check if request was successful
            if ($response && method_exists($response, 'getStatusCode')) {
                $statusCode = $response->getStatusCode();
                
                // Retry on 5xx errors
                if ($statusCode >= 500 && $attempt < $maxRetries) {
                    usleep(100000 * $attempt); // Exponential backoff: 100ms, 200ms, 300ms
                    $this->sendBatch($events, $attempt + 1);
                    return;
                }
            }
        } catch (Throwable $e) {
            // Retry on network failures
            if ($attempt < ($this->config['jobs']['max_retries'] ?? 3)) {
                usleep(100000 * $attempt); // Exponential backoff
                $this->sendBatch($events, $attempt + 1);
                return;
            }
            
            // After max retries, report the error (if enabled) but don't break user's app
            $this->reportError($e, count($events));
        }
    }

    /**
     * Report buffer errors back to LaraBug (ironic, but useful for debugging)
     */
    protected function reportError(Throwable $e, int $lostEvents): void
    {
        try {
            if ($this->config['jobs']['report_buffer_errors'] ?? false) {
                $this->client->report([
                    'type' => 'buffer_error',
                    'exception' => [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'lost_events' => $lostEvents,
                    ],
                ]);
            }
        } catch (Throwable $ignored) {
            // Never let error reporting break the app
        }
    }

    /**
     * Register shutdown handler to flush buffer on script end
     */
    protected function registerShutdownHandler(): void
    {
        if ($this->shutdownHandlerRegistered) {
            return;
        }

        register_shutdown_function(function () {
            $this->flush();
        });

        $this->shutdownHandlerRegistered = true;
    }

    /**
     * Get current buffer size
     */
    public function count(): int
    {
        return count($this->buffer);
    }

    /**
     * Check if buffer is full
     */
    public function isFull(): bool
    {
        return count($this->buffer) >= $this->batchSize;
    }

    /**
     * Clear the buffer without flushing
     */
    public function clear(): void
    {
        $this->buffer = [];
    }

    /**
     * Get all buffered events (for testing)
     */
    public function getBufferedEvents(): array
    {
        return $this->buffer;
    }
}
