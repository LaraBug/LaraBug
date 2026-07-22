<?php

namespace LaraBug\Console;

use Countable;
use LaraBug\Http\Client;
use Throwable;

/**
 * Batches finished command records.
 *
 * The console counterpart to RequestBuffer. A console process runs one command
 * and then exits, so the flush happens on shutdown as well as on size: without
 * it the record of the command that just finished would be lost at the moment it
 * completed. A long-running worker that this package does not ignore would flush
 * on size instead, but those are on the ignore list precisely because they never
 * finish.
 */
class CommandBuffer implements Countable
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
        $this->batchSize = (int) ($config['commands']['batch_size'] ?? 20);
        $this->maxRetries = (int) ($config['commands']['max_retries'] ?? 2);

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
            $this->client->reportCommands($records);
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
