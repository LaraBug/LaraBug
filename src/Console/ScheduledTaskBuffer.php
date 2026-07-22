<?php

namespace LaraBug\Console;

use Countable;
use LaraBug\Http\Client;
use Throwable;

/**
 * Batches finished scheduled task records.
 *
 * Its own buffer rather than the command one, because the two are told apart on
 * the way out by which sender they use: a task ships as a scheduled_tasks_batch,
 * a command as a commands_batch. The scheduler runs inside a single schedule:run
 * process, so this flushes on shutdown like the command buffer.
 */
class ScheduledTaskBuffer implements Countable
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
        $this->batchSize = (int) ($config['schedule']['batch_size'] ?? 20);
        $this->maxRetries = (int) ($config['schedule']['max_retries'] ?? 2);

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
            $this->client->reportScheduledTasks($records);
        } catch (Throwable $e) {
            if ($attempt <= $this->maxRetries) {
                usleep(100000 * $attempt);

                $this->send($records, $attempt + 1);

                return;
            }

            // Nothing else. A monitoring package that throws on the way out is a
            // monitoring package that takes the application down with it.
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
