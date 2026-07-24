<?php

namespace LaraBug\Logger;

use Throwable;
use LaraBug\Http\Client;

/**
 * Buffers log records and ships them in batches, mirroring Queue\EventBuffer:
 * one HTTP request per log line would cost far more in network than the line
 * is worth.
 *
 * Nothing in here may throw. Monolog rethrows whatever a handler throws, which
 * would surface at the user's Log call site, so every path out of this class
 * swallows.
 */
class LogBuffer
{
    protected array $buffer = [];

    /**
     * Guards against logging while shipping logs. Monolog's own cycle detection
     * is per Logger instance, so it does not help when our HTTP client logs to
     * a different channel that reaches us again.
     */
    protected bool $sending = false;

    public function __construct(
        protected readonly Client $client,
        protected array $config,
    ) {
    }

    public function add(array $record): void
    {
        if ($this->sending) {
            return;
        }

        $this->buffer[] = $record;

        if (count($this->buffer) >= $this->batchSize()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer) || $this->sending) {
            return;
        }

        $records = $this->buffer;
        $this->buffer = [];

        $this->send($records);
    }

    protected function send(array $records, int $attempt = 1): void
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
     * Stop collecting for the rest of this process: the server has said it does
     * not want these, so stop repeating the round trip on every request.
     */
    protected function disable(): void
    {
        $this->buffer = [];
        $this->config['logs']['enabled'] = false;
        $this->sending = false;
    }

    public function enabled(): bool
    {
        return ! isset($this->config['logs']['enabled'])
            || $this->config['logs']['enabled'];
    }

    protected function batchSize(): int
    {
        $size = isset($this->config['logs']['batch_size'])
            ? (int) $this->config['logs']['batch_size']
            : 50;

        return $size > 0 ? $size : 50;
    }

    public function bufferedCount(): int
    {
        return count($this->buffer);
    }
}
