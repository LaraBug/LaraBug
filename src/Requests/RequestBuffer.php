<?php

namespace LaraBug\Requests;

use Countable;
use LaraBug\Http\Client;
use Throwable;

/**
 * Batches finished request records.
 *
 * A duplicate of EventBuffer rather than a generalisation of it, deliberately:
 * that buffer is in production carrying queue jobs, and the way to find out
 * whether one abstraction serves both is to run the second one first. They can
 * be merged once this is live.
 *
 * The flush happens on shutdown as well as on size, because a web process
 * serves one request and then exits: without it, every batch below the
 * threshold would be lost at exactly the moment it was complete.
 */
class RequestBuffer implements Countable
{
    /** @var array<int, array<string, mixed>> */
    protected $buffer = [];

    /** @var Client */
    protected $client;

    /** @var array<string, mixed> */
    protected $config;

    /** @var int */
    protected $batchSize;

    /** @var int */
    protected $maxRetries;

    /** @var bool */
    protected $shutdownRegistered = false;

    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
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
        } catch (Throwable $e) {
            if ($attempt <= $this->maxRetries) {
                // Linear, not exponential. This runs on shutdown, after the
                // response has been sent but while the worker is still held, so
                // a doubling backoff spends the customer's capacity on our
                // outage.
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

        register_shutdown_function(function () {
            $this->flush();
        });
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
