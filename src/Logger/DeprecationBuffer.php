<?php

namespace LaraBug\Logger;

use Closure;

/**
 * Coalesces deprecations before they reach the log stream.
 *
 * A deprecation fires on every call of the code that raises it, and every
 * line shipped counts against the project's telemetry allowance. So the first
 * occurrence of each one is sent, and after that at most one record per
 * interval, carrying how many occurrences it stands for.
 *
 * Per process: a request served by PHP-FPM starts over every time, a queue
 * worker or an Octane worker keeps the count for as long as it lives.
 *
 * Nothing in here may throw, for the same reason LogBuffer may not.
 */
class DeprecationBuffer
{
    /**
     * Keyed by fingerprint. The record is the first occurrence not sent yet,
     * built once rather than for every occurrence it stands for.
     *
     * @var array<string, array{sent_at: int, count: int, record: ?array<string, mixed>}>
     */
    protected array $deprecations = [];

    /** Whether records were handed to the log buffer since it last flushed. */
    protected bool $unflushed = false;

    public function __construct(
        protected readonly LogBuffer $logs,
        protected readonly int $interval = 3600,
    ) {
        register_shutdown_function($this->flush(...));
    }

    /**
     * @param  Closure(): array<string, mixed>  $record
     */
    public function add(string $fingerprint, Closure $record): void
    {
        if (! isset($this->deprecations[$fingerprint])) {
            $this->deprecations[$fingerprint] = ['sent_at' => time(), 'count' => 0, 'record' => null];

            $this->send($record(), 1);

            return;
        }

        $this->deprecations[$fingerprint]['count']++;
        $this->deprecations[$fingerprint]['record'] ??= $record();

        if ($this->isDue($fingerprint)) {
            $this->sendCoalesced($fingerprint);
        }
    }

    /**
     * Send what has been coalesced for longer than the interval. Called when a
     * request ends and on every pass of a worker's loop, so an idle worker still
     * reports a deprecation it hit an hour ago.
     */
    public function flushIfDue(): void
    {
        foreach (array_keys($this->deprecations) as $fingerprint) {
            if ($this->isDue($fingerprint)) {
                $this->sendCoalesced($fingerprint);
            }
        }

        $this->flushLogs();
    }

    /**
     * Send everything coalesced so far, whatever the interval says. Called when
     * the process is about to end, so no occurrence goes uncounted.
     */
    public function flush(): void
    {
        foreach (array_keys($this->deprecations) as $fingerprint) {
            if ($this->deprecations[$fingerprint]['count'] > 0) {
                $this->sendCoalesced($fingerprint);
            }
        }

        $this->flushLogs();
    }

    protected function isDue(string $fingerprint): bool
    {
        $deprecation = $this->deprecations[$fingerprint];

        if ($deprecation['count'] === 0) {
            return false;
        }

        return time() - $deprecation['sent_at'] >= $this->interval;
    }

    protected function sendCoalesced(string $fingerprint): void
    {
        $deprecation = $this->deprecations[$fingerprint];

        $this->deprecations[$fingerprint] = ['sent_at' => time(), 'count' => 0, 'record' => null];

        $this->send($deprecation['record'], $deprecation['count']);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function send(array $record, int $count): void
    {
        $record['extra']['larabug_deprecation']['count'] = $count;

        $this->logs->add($record);

        $this->unflushed = true;
    }

    /**
     * A worker that logs nothing else would otherwise hold the record until
     * the log buffer fills, which for a quiet worker can be days.
     */
    protected function flushLogs(): void
    {
        if (! $this->unflushed) {
            return;
        }

        $this->unflushed = false;

        $this->logs->flush();
    }
}
