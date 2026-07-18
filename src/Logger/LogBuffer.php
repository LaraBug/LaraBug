<?php

namespace LaraBug\Logger;

use LaraBug\Http\Client;
use Throwable;

/**
 * Buffers log records and ships them in batches.
 *
 * Mirrors Queue\EventBuffer, which solves the same problem for jobs: one HTTP
 * request per log line would cost far more in network than the line is worth,
 * and at log volume it would be the dominant cost in the request.
 *
 * Nothing in here may throw. Monolog rethrows whatever a handler throws, which
 * would surface at the user's Log::info() call site and break their
 * application, so every path out of this class swallows.
 */
class LogBuffer
{
    /** @var Client */
    protected $client;

    /** @var array */
    protected $config;

    /** @var array */
    protected $buffer = [];

    /**
     * Guards against logging while shipping logs.
     *
     * Monolog's own cycle detection is per Logger instance, so it does not help
     * when our HTTP client, or anything it touches, logs to a different channel
     * that reaches us again.
     *
     * @var bool
     */
    protected $sending = false;

    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * @param array $record
     */
    public function add(array $record)
    {
        if ($this->sending) {
            return;
        }

        $this->buffer[] = $record;

        if (count($this->buffer) >= $this->batchSize()) {
            $this->flush();
        }
    }

    public function flush()
    {
        if (empty($this->buffer) || $this->sending) {
            return;
        }

        $records = $this->buffer;
        $this->buffer = [];

        $this->send($records);
    }

    /**
     * @param array $records
     * @param int $attempt
     */
    protected function send(array $records, $attempt = 1)
    {
        $this->sending = true;

        try {
            $response = $this->client->report([
                'type' => 'logs_batch',
                'project' => $this->config['project_key'] ?? '',
                'logs' => $records,
                'count' => count($records),
            ]);

            $maxRetries = isset($this->config['logs']['max_retries'])
                ? (int) $this->config['logs']['max_retries']
                : 3;

            if ($response && method_exists($response, 'getStatusCode')) {
                $status = $response->getStatusCode();

                // A disabled feature or a rejected project is a permanent no.
                // Retrying it just spends the user's time on every request.
                if ($status === 403 || $status === 402 || $status === 422) {
                    $this->disable();

                    return;
                }

                if ($status >= 500 && $attempt < $maxRetries) {
                    usleep(100000 * $attempt);
                    $this->sending = false;
                    $this->send($records, $attempt + 1);

                    return;
                }
            }
        } catch (Throwable $e) {
            // Dropped on purpose. Losing a batch of logs is strictly better than
            // throwing inside a log call.
        }

        $this->sending = false;
    }

    /**
     * Stop collecting for the rest of this process.
     *
     * The server has told us it does not want these, so the handler should stop
     * asking rather than repeat the round trip on every request.
     */
    protected function disable()
    {
        $this->buffer = [];
        $this->config['logs']['enabled'] = false;
        $this->sending = false;
    }

    /**
     * @return bool
     */
    public function enabled()
    {
        return ! isset($this->config['logs']['enabled'])
            || $this->config['logs']['enabled'];
    }

    /**
     * @return int
     */
    protected function batchSize()
    {
        $size = isset($this->config['logs']['batch_size'])
            ? (int) $this->config['logs']['batch_size']
            : 50;

        return $size > 0 ? $size : 50;
    }

    /**
     * A hard ceiling on what one process may hold, so a long running worker or
     * a command emitting thousands of lines cannot grow the buffer unbounded
     * between flushes.
     *
     * @return int
     */
    public function bufferedCount()
    {
        return count($this->buffer);
    }
}
