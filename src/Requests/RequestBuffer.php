<?php

namespace LaraBug\Requests;

use Countable;
use Throwable;
use LaraBug\Http\Client;

/**
 * Batches finished request records.
 *
 * A duplicate of EventBuffer rather than a generalisation of it, deliberately:
 * that buffer is in production carrying queue jobs, and the way to find out
 * whether one abstraction serves both is to run the second one first.
 *
 * The flush happens on shutdown as well as on size, because a web process
 * serves one request and then exits: without it, every batch below the
 * threshold would be lost at exactly the moment it was complete.
 */
class RequestBuffer implements Countable
{
    /** @var array<int, array<string, mixed>> */
    protected array $buffer = [];

    protected readonly int $batchSize;

    protected readonly int $maxRetries;

    protected bool $shutdownRegistered = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly Client $client,
        protected readonly array $config,
    ) {
        $this->batchSize = (int) ($config['requests']['batch_size'] ?? 20);
        $this->maxRetries = (int) ($config['requests']['max_retries'] ?? 2);

        $this->registerShutdownHandler();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function add(array $record): void
    {
        $this->buffer[] = $record;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $records = $this->buffer;
        $this->buffer = [];

        $this->send($records);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    protected function send(array $records, int $attempt = 1): void
    {
        try {
            $this->client->reportRequests($records);
        } catch (Throwable) {
            if ($attempt <= $this->maxRetries) {
                // Linear, not exponential: this runs on shutdown while the
                // worker is still held, and a doubling backoff spends the
                // customer's capacity on our outage.
                usleep(100000 * $attempt);

                $this->send($records, $attempt + 1);

                return;
            }

            // Nothing else. A monitoring package that throws on the way out is
            // a monitoring package that takes the application down with it.
        }
    }

    protected function registerShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function($this->flush(...));
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->buffer;
    }
}
