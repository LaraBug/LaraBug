<?php

namespace LaraBug\Logger;

use Closure;
use Throwable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\InteractsWithTime;

/**
 * Coalesces deprecations before they reach the log stream.
 *
 * A deprecation fires on every call of the code that raises it, and every
 * line shipped counts against the project's telemetry allowance. So one record
 * is sent per deprecation per interval, carrying how many occurrences it
 * stands for.
 *
 * Coalesced through the application's cache, because a request served by
 * PHP-FPM forgets everything when it ends, which would otherwise mean one
 * record per request for a deprecation on a hot path. Whichever process takes
 * a deprecation's lock sends its record, and every other process adds what it
 * counted to a shared count the next record picks up. Without a cache that is
 * shared between processes, each process counts for itself.
 *
 * The cache is used once per deprecation per request, never per occurrence.
 * Nothing in here may throw, for the same reason LogBuffer may not.
 */
class DeprecationBuffer
{
    use InteractsWithTime;

    /**
     * Keyed by fingerprint. The record is the first occurrence not sent yet,
     * built once rather than for every occurrence it stands for.
     *
     * @var array<string, array{count: int, record: ?array<string, mixed>, locked_until: int}>
     */
    protected array $deprecations = [];

    protected Repository $cache;

    protected bool $shared;

    /** Whether records were handed to the log buffer since it last flushed. */
    protected bool $unflushed = false;

    /** Set while the cache is in use, which may raise a deprecation of its own. */
    protected bool $claiming = false;

    public function __construct(
        protected readonly LogBuffer $logs,
        protected readonly int $interval = 3600,
        ?Repository $cache = null,
    ) {
        $this->shared = $cache !== null;
        $this->cache = $cache ?? new Repository(new ArrayStore());

        register_shutdown_function($this->flush(...));
    }

    /**
     * @param  Closure(): array<string, mixed>  $record
     */
    public function add(string $fingerprint, Closure $record): void
    {
        $deprecation = $this->deprecations[$fingerprint] ?? ['count' => 0, 'record' => null, 'locked_until' => 0];

        $deprecation['count']++;
        $deprecation['record'] ??= $record();

        $this->deprecations[$fingerprint] = $deprecation;

        if ($this->isDue($fingerprint)) {
            $this->claim($fingerprint);
        }
    }

    /**
     * Send what this process has counted for longer than the interval. Called
     * on every pass of a worker's loop, so an idle worker still reports a
     * deprecation it kept hitting an hour ago. When another process sent it,
     * what this one counted goes to the shared count for the next record.
     */
    public function flushIfDue(): void
    {
        foreach (array_keys($this->deprecations) as $fingerprint) {
            if (! $this->isDue($fingerprint)) {
                continue;
            }

            $this->claim($fingerprint);

            if ($this->shared) {
                $this->carry($fingerprint);
            }
        }

        $this->flushLogs();
    }

    /**
     * The end of a request. What is not sent goes to the shared count, since a
     * PHP-FPM process forgets it from here.
     */
    public function terminate(): void
    {
        foreach (array_keys($this->deprecations) as $fingerprint) {
            if ($this->isDue($fingerprint)) {
                $this->claim($fingerprint);
            }

            if ($this->shared) {
                $this->carry($fingerprint);
            }
        }

        $this->flushLogs();
    }

    /**
     * The end of the process. Nothing counted is left behind: it is sent if
     * this process can take the lock, else added to the shared count, else,
     * without a shared cache, sent anyway.
     */
    public function flush(): void
    {
        foreach (array_keys($this->deprecations) as $fingerprint) {
            if ($this->deprecations[$fingerprint]['count'] === 0) {
                continue;
            }

            $this->claim($fingerprint);

            $this->shared
                ? $this->carry($fingerprint)
                : $this->send($fingerprint);
        }

        $this->flushLogs();
    }

    protected function isDue(string $fingerprint): bool
    {
        $deprecation = $this->deprecations[$fingerprint];

        if ($deprecation['count'] === 0) {
            return false;
        }

        return $deprecation['locked_until'] <= $this->currentTime();
    }

    /**
     * Send the record if no process has sent one within the interval. Else
     * remember until when another process has, so the cache is not asked
     * again before then.
     */
    protected function claim(string $fingerprint): void
    {
        if ($this->claiming) {
            return;
        }

        $this->claiming = true;

        try {
            $now = $this->currentTime();

            if ($this->interval <= 0 || $this->cache->add($this->key($fingerprint, 'sent'), $now, $this->interval)) {
                $this->send($fingerprint, $this->takeSharedCount($fingerprint));

                $this->deprecations[$fingerprint]['locked_until'] = $now + $this->interval;

                return;
            }

            $sentAt = (int) $this->cache->get($this->key($fingerprint, 'sent'), $now);

            $this->deprecations[$fingerprint]['locked_until'] = $sentAt + $this->interval;
        } catch (Throwable $e) {
            $this->countInThisProcess();

            $this->send($fingerprint);

            $this->deprecations[$fingerprint]['locked_until'] = $this->currentTime() + $this->interval;
        } finally {
            $this->claiming = false;
        }
    }

    /**
     * Read the shared count and take off what was read, rather than forgetting
     * it, so occurrences other processes add in the meantime are kept.
     */
    protected function takeSharedCount(string $fingerprint): int
    {
        if (! $this->shared) {
            return 0;
        }

        $count = (int) $this->cache->get($this->key($fingerprint, 'count'), 0);

        if ($count > 0) {
            $this->cache->decrement($this->key($fingerprint, 'count'), $count);
        }

        return $count;
    }

    protected function carry(string $fingerprint): void
    {
        $count = $this->deprecations[$fingerprint]['count'];

        if ($count === 0 || $this->claiming) {
            return;
        }

        $this->claiming = true;

        try {
            // Created before it is incremented, since not every store creates
            // a missing key on increment. Kept for a week, or twice the interval
            // if that is longer, so a deprecation nobody hits again is dropped.
            $this->cache->add($this->key($fingerprint, 'count'), 0, max($this->interval * 2, 604800));
            $this->cache->increment($this->key($fingerprint, 'count'), $count);

            $this->deprecations[$fingerprint]['count'] = 0;
            $this->deprecations[$fingerprint]['record'] = null;
        } catch (Throwable $e) {
            $this->countInThisProcess();

            $this->send($fingerprint);
        } finally {
            $this->claiming = false;
        }
    }

    /**
     * A cache that cannot be reached is not retried on every request: the
     * rest of this process counts for itself.
     */
    protected function countInThisProcess(): void
    {
        $this->shared = false;
        $this->cache = new Repository(new ArrayStore());
    }

    protected function send(string $fingerprint, int $sharedCount = 0): void
    {
        $deprecation = $this->deprecations[$fingerprint];

        $this->deprecations[$fingerprint]['count'] = 0;
        $this->deprecations[$fingerprint]['record'] = null;

        if ($deprecation['record'] === null) {
            return;
        }

        $record = $deprecation['record'];
        $record['extra']['larabug_deprecation']['count'] = $deprecation['count'] + $sharedCount;

        $this->logs->add($record);

        $this->unflushed = true;
    }

    protected function key(string $fingerprint, string $suffix): string
    {
        return "larabug:deprecations:{$fingerprint}:{$suffix}";
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
