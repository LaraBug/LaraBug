<?php

namespace LaraBug\Http;

use Throwable;
use LaraBug\Support\LimitBackoff;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\TransferException;

/**
 * Events the server could not take, kept on local disk until it can.
 *
 * A 5xx or a server that cannot be reached is usually a deploy, maintenance
 * mode or an outage on LaraBug's side, and it passes. Rather than drop what
 * was being sent, the Client writes it here and it goes out again on the next
 * send that succeeds, or from `larabug:flush` on the scheduler.
 *
 * One file per event, written to a temporary name and renamed into place, so
 * a reader never sees half of one and writers from several processes never
 * need a lock. The name starts with the time it was written, which is both
 * the order events are sent back in and how old one is.
 *
 * What is written is exactly the payload that was about to go over the wire,
 * so it has already been through every filter the SDK applies. The request's
 * headers, the login key among them, are never written.
 *
 * Bounded by count, total size and age. Past a bound the oldest go first.
 *
 * Nothing in here may reach the application. Every public method swallows
 * whatever goes wrong: a buffer that cannot be written loses the event, which
 * is what happened before it existed.
 */
class RetryBuffer
{
    /**
     * Set while this process is flushing, so a send made by the flush cannot
     * start another one inside it.
     */
    protected static bool $flushing = false;

    /** Set once a flush is waiting for this process to shut down. */
    protected static bool $flushOnShutdown = false;

    /**
     * Payload types never kept. A CVE scan is sent again as soon as the
     * lockfile's hash has not landed, and a queue depth sample is measured
     * again every minute: an old copy of either is worth less than a new one.
     *
     * @var array<int, string>
     */
    protected static array $unbufferedTypes = ['cve_scan', 'queue_depth_batch'];

    public function __construct(
        protected readonly string $path,
        protected readonly int $maxEvents = 500,
        protected readonly int $maxBytes = 5 * 1024 * 1024,
        protected readonly int $maxAge = 86400,
        protected readonly int $maxPause = 600,
    ) {
    }

    /**
     * The buffer the configuration describes, or null when it is switched off
     * or cannot be described at all.
     */
    public static function fromConfig(): ?self
    {
        try {
            $config = (array) config('larabug.retry_buffer', []);

            if (! filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
                return null;
            }

            $path = (string) ($config['path'] ?? '');

            if ($path === '') {
                $path = storage_path('larabug/retry-buffer');
            }

            return new self(
                rtrim($path, '/\\'),
                max(1, (int) ($config['max_events'] ?? 500)),
                max(1, (int) ($config['max_bytes'] ?? 5 * 1024 * 1024)),
                max(1, (int) ($config['max_age'] ?? 86400)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Would this payload be kept if it could not be sent?
     *
     * @param  array<string, mixed>  $payload
     */
    public function accepts(array $payload): bool
    {
        return ! in_array($payload['type'] ?? null, static::$unbufferedTypes, true);
    }

    /**
     * Decide what a send's outcome means for the buffer: keep the payload when
     * the server failed, flush what is waiting when it answered.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, ?ResponseInterface $response, Client $client): void
    {
        try {
            $status = $response?->getStatusCode();

            if ($status === null || $status >= 500) {
                if ($this->accepts($payload)) {
                    $this->keep($payload);
                }

                $this->pauseFor($response);

                return;
            }

            if ($status >= 200 && $status < 300) {
                $this->resume();

                $this->flushSoon($client);
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Write one payload to the buffer, then trim the buffer to its bounds.
     *
     * @param  array<string, mixed>  $payload
     */
    public function keep(array $payload): void
    {
        try {
            // Encoded the way Guzzle encoded it, so the flush sends the bytes
            // the server failed to take.
            $json = json_encode($payload);

            // One event bigger than the whole buffer would push out everything
            // else and then itself.
            if ($json === false || strlen($json) > $this->maxBytes) {
                return;
            }

            if (! $this->ensureDirectoryExists()) {
                return;
            }

            $now = microtime(true);
            $seconds = (int) $now;
            $microseconds = (int) (($now - $seconds) * 1_000_000);
            $name = sprintf('%s/%010d.%06d-%s', $this->path, $seconds, $microseconds, bin2hex(random_bytes(4)));

            if (@file_put_contents("{$name}.tmp", $json) === false) {
                @unlink("{$name}.tmp");

                return;
            }

            if (! @rename("{$name}.tmp", "{$name}.json")) {
                @unlink("{$name}.tmp");

                return;
            }

            $this->prune();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Send what is waiting, oldest first, and remove each event the server
     * accepts.
     *
     * Holds an exclusive lock on the buffer for as long as it runs. A second
     * process that tries meanwhile does nothing rather than wait, so an event
     * is never sent by both.
     *
     * Stops at the first 5xx or unreachable server, keeping that event and
     * everything after it, and at a 429. A 402 drops the event it refused, the
     * way the stream's own sender would have, and so does any other 4xx.
     */
    public function flush(Client $client, ?int $limit = null): FlushResult
    {
        if (static::$flushing) {
            return new FlushResult(remaining: $this->count(), stoppedBy: FlushStop::Locked);
        }

        try {
            if ($this->isPaused()) {
                return new FlushResult(remaining: $this->count(), stoppedBy: FlushStop::Paused);
            }

            if ($this->entries() === []) {
                return new FlushResult();
            }

            $lock = @fopen("{$this->path}/.lock", 'c');

            if ($lock === false) {
                return new FlushResult(remaining: $this->count(), stoppedBy: FlushStop::Locked);
            }
        } catch (Throwable) {
            return new FlushResult();
        }

        static::$flushing = true;

        try {
            if (! @flock($lock, LOCK_EX | LOCK_NB)) {
                return new FlushResult(remaining: $this->count(), stoppedBy: FlushStop::Locked);
            }

            return $this->sendEntries($client, $limit);
        } catch (Throwable) {
            return new FlushResult(remaining: $this->count());
        } finally {
            static::$flushing = false;

            try {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            } catch (Throwable) {
                //
            }
        }
    }

    public function count(): int
    {
        return count($this->entries());
    }

    public function hasEntries(): bool
    {
        return $this->entries() !== [];
    }

    /**
     * Has the server asked, with a Retry-After on a 5xx, not to be sent
     * anything until a moment that has not come yet?
     */
    public function isPaused(): bool
    {
        return $this->pausedUntil() > time();
    }

    /**
     * The epoch second the server asked to be left alone until, 0 when it did
     * not ask.
     */
    public function pausedUntil(): int
    {
        try {
            $file = "{$this->path}/.retry-after";

            if (! is_file($file)) {
                return 0;
            }

            return (int) @file_get_contents($file);
        } catch (Throwable) {
            return 0;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @internal For tests, which share one process and its static state.
     */
    public static function reset(): void
    {
        static::$flushing = false;
        static::$flushOnShutdown = false;
    }

    protected function sendEntries(Client $client, ?int $limit): FlushResult
    {
        $sent = 0;
        $discarded = 0;
        $attempts = 0;
        $stoppedBy = null;
        $oldestKept = time() - $this->maxAge;

        // Listed again under the lock: a flush that just finished may have
        // emptied what was there when we looked.
        foreach ($this->entries() as $file) {
            if ($limit !== null && $attempts >= $limit) {
                $stoppedBy = FlushStop::Limit;

                break;
            }

            if ($this->writtenAt($file) < $oldestKept) {
                @unlink($file);
                $discarded++;

                continue;
            }

            $json = @file_get_contents($file);

            // Pruned by a writer since it was listed.
            if ($json === false) {
                continue;
            }

            $payload = json_decode($json, true);

            if (! is_array($payload)) {
                @unlink($file);
                $discarded++;

                continue;
            }

            $stream = isset($payload['type']) ? LimitBackoff::TELEMETRY : LimitBackoff::ISSUES;

            // That stream is held back in this process. The event waits for
            // the window to pass, and the other stream carries on.
            if (! LimitBackoff::allows($stream)) {
                continue;
            }

            $attempts++;

            try {
                $response = $client->resend($json);
            } catch (TransferException) {
                $stoppedBy = FlushStop::Unreachable;

                break;
            } catch (Throwable) {
                // Not the network: something in this event cannot be sent at
                // all, and trying it again would stop every flush behind it.
                @unlink($file);
                $discarded++;

                continue;
            }

            $status = $response->getStatusCode();

            if ($status >= 500) {
                $this->pauseFor($response);
                $stoppedBy = FlushStop::ServerError;

                break;
            }

            if (LimitBackoff::record($response, $stream) && LimitBackoff::isRateLimitResponse($response)) {
                $stoppedBy = FlushStop::RateLimited;

                break;
            }

            @unlink($file);

            if ($status >= 200 && $status < 300) {
                $sent++;

                continue;
            }

            $discarded++;
        }

        return new FlushResult($sent, $discarded, $this->count(), $stoppedBy);
    }

    /**
     * Flush once the server has answered again, without making anyone wait.
     *
     * A console process (a queue worker, a command, the scheduler) has nobody
     * waiting on it, so it sends a few right away. A web request leaves it to
     * shutdown, which PHP-FPM runs after the response has gone out. Under
     * Octane that is when the worker stops, and the scheduler's flush gets
     * there first.
     */
    protected function flushSoon(Client $client): void
    {
        if (static::$flushing) {
            return;
        }

        if (! $this->hasEntries()) {
            return;
        }

        if (app()->runningInConsole()) {
            $this->flush($client, 50);

            return;
        }

        if (static::$flushOnShutdown) {
            return;
        }

        static::$flushOnShutdown = true;

        register_shutdown_function(function () use ($client) {
            static::$flushOnShutdown = false;

            $this->flush($client, 50);
        });
    }

    /**
     * Note how long a server that answered with a 5xx asked to be left alone,
     * so that no process sends it anything until then. Capped, because a stray
     * Retry-After must not hold back every event for the rest of the day.
     */
    protected function pauseFor(?ResponseInterface $response): void
    {
        if ($response === null) {
            return;
        }

        $seconds = LimitBackoff::retryAfter($response);

        if (! $seconds) {
            return;
        }

        if (! $this->ensureDirectoryExists()) {
            return;
        }

        $until = time() + min($this->maxPause, $seconds);

        @file_put_contents("{$this->path}/.retry-after", (string) $until, LOCK_EX);
    }

    protected function resume(): void
    {
        if (! is_file("{$this->path}/.retry-after")) {
            return;
        }

        @unlink("{$this->path}/.retry-after");
    }

    /**
     * Remove what is past its age, then the oldest until the buffer is within
     * its count and its size again. Temporary files left by a process that
     * died while writing go once they are an hour old.
     */
    protected function prune(): void
    {
        $oldestKept = time() - $this->maxAge;
        $sizes = [];

        foreach ($this->entries() as $file) {
            if ($this->writtenAt($file) < $oldestKept) {
                @unlink($file);

                continue;
            }

            $sizes[$file] = (int) @filesize($file);
        }

        $count = count($sizes);
        $bytes = array_sum($sizes);

        foreach ($sizes as $file => $size) {
            if ($count <= $this->maxEvents && $bytes <= $this->maxBytes) {
                break;
            }

            @unlink($file);
            $count--;
            $bytes -= $size;
        }

        foreach (@glob("{$this->path}/*.tmp") ?: [] as $temporary) {
            if ($this->writtenAt($temporary) < time() - 3600) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Every event in the buffer, oldest first.
     *
     * @return array<int, string>
     */
    protected function entries(): array
    {
        try {
            $files = @glob("{$this->path}/*.json");

            if (! $files) {
                return [];
            }

            sort($files, SORT_STRING);

            return $files;
        } catch (Throwable) {
            return [];
        }
    }

    protected function writtenAt(string $file): int
    {
        return (int) basename($file);
    }

    protected function ensureDirectoryExists(): bool
    {
        if (is_dir($this->path)) {
            return true;
        }

        return @mkdir($this->path, 0775, true) || is_dir($this->path);
    }
}
