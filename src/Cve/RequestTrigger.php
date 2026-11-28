<?php

namespace LaraBug\Cve;

use Throwable;
use Carbon\Carbon;
use LaraBug\Http\Client;
use LaraBug\Support\LimitBackoff;
use LaraBug\Scanners\NpmLockScanner;
use LaraBug\Scanners\ComposerLockScanner;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Request-piggyback trigger for CVE scans.
 *
 * Wired up via `app()->terminating(...)` from the ServiceProvider, so it runs
 * after the response is sent to the user. The worker is still held while it
 * runs, though, and under PHP-FPM it runs after every request: nothing static
 * in here survives one. So the hot path is a stat per lockfile and the read of
 * one small local file, which say whether anything could be due for lockfiles
 * that look like these. Not a cache read: on a request that has not touched
 * the cache that is a connection opened, or a query run, for this alone. Only
 * when nothing can be said are the lockfiles hashed, the last sent hash of
 * each compared, and a lockfile parsed once its hash says a scan is due. A
 * short-lived cache lock prevents a thundering herd of concurrent requests all
 * triggering scans simultaneously.
 */
class RequestTrigger
{
    /** @var array<string, array<string, mixed>>|null Memoized lockfile payloads for this process, keyed by ecosystem. */
    protected static ?array $cachedPayload = null;

    protected static bool $alreadyFired = false;

    /** @var array<string, string> */
    private array $lastSentHashCacheKeys = [
        'composer_lock' => 'larabug.cve.last_sent_hash',
        'npm_lock' => 'larabug.cve.last_sent_npm_hash',
    ];

    private string $lastSentAtCacheKey = 'larabug.cve.last_sent_at';

    private string $backoffCacheKey = 'larabug.cve.backoff_until';

    private int $backoffSeconds = 3600;

    public function __construct(
        protected readonly CacheRepository $cache,
        protected readonly ComposerLockScanner $composerScanner,
        protected readonly NpmLockScanner $npmScanner,
        protected readonly Client $client,
        protected readonly ?string $settledPath = null,
    ) {
    }

    public function maybeTrigger(): void
    {
        if (! config('larabug.cve.enabled', true)) {
            return;
        }

        $trigger = strtolower((string) config('larabug.cve.trigger', 'both'));

        if (! in_array($trigger, ['request', 'both'], true)) {
            return;
        }

        // One firing per process is enough — the lockfile is static at runtime.
        if (self::$alreadyFired) {
            return;
        }

        self::$alreadyFired = true;

        // A CVE finding is an issue, and the issue limit is reached for now.
        if (! LimitBackoff::allows(LimitBackoff::ISSUES)) {
            return;
        }

        $fingerprint = $this->fingerprint();

        if ($fingerprint !== null && $this->isSettled($fingerprint)) {
            return;
        }

        $hashes = $this->hashes();

        if ($hashes === []) {
            return;
        }

        $secondsUntilDue = $this->secondsUntilDue($hashes);

        if ($secondsUntilDue > 0) {
            $this->settle($fingerprint, $secondsUntilDue);

            return;
        }

        $payload = $this->payload();

        if ($payload === []) {
            return;
        }

        $lock = method_exists($this->cache, 'lock')
            ? $this->cache->lock('larabug.cve.trigger_lock', 60)
            : null;

        if ($lock && ! $lock->get()) {
            // Another process is already on it.
            return;
        }

        try {
            $this->send($payload, $fingerprint);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Hashing a lockfile is far cheaper than parsing it, so the hashes alone
     * decide whether a scan is due.
     *
     * @return array<string, string>
     */
    protected function hashes(): array
    {
        return array_filter([
            'composer_lock' => $this->composerScanner->hash(config('larabug.cve.lock_path')),
            'npm_lock' => config('larabug.cve.npm', true)
                ? $this->npmScanner->hash()
                : null,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function payload(): array
    {
        if (self::$cachedPayload !== null) {
            return self::$cachedPayload;
        }

        $environment = config('larabug.cve.environment') ?: config('app.env');

        $payload = array_filter([
            'composer_lock' => $this->composerScanner->scan(
                config('larabug.cve.lock_path'),
                (bool) config('larabug.cve.include_dev', false),
                $environment,
            ),
            'npm_lock' => config('larabug.cve.npm', true)
                ? $this->npmScanner->scan($environment)
                : null,
        ]);

        if ($payload !== []) {
            self::$cachedPayload = $payload;
        }

        return $payload;
    }

    /**
     * What stat says about the lockfiles, which costs next to nothing where
     * hashing them costs a read of every byte, after every request.
     *
     * Null when it cannot stand in for the hashes: there is no lockfile, or
     * one was written within the last second. Modification times count in
     * whole seconds, so a lockfile written twice in one second, to the same
     * size, would look unchanged. A lockfile that young is hashed instead.
     */
    protected function fingerprint(): ?string
    {
        $paths = array_filter([
            'composer_lock' => $this->composerScanner->path(config('larabug.cve.lock_path')),
            'npm_lock' => config('larabug.cve.npm', true)
                ? $this->npmScanner->path()
                : null,
        ]);

        $fingerprint = [];

        foreach ($paths as $ecosystem => $path) {
            clearstatcache(true, $path);

            $stat = @stat($path);

            if ($stat === false) {
                return null;
            }

            if ($stat['mtime'] >= time() - 1) {
                return null;
            }

            $fingerprint[] = "{$ecosystem}:{$stat['ino']}:{$stat['size']}:{$stat['mtime']}";
        }

        return $fingerprint === [] ? null : implode('|', $fingerprint);
    }

    /**
     * Remember that nothing is due for lockfiles that look like these, for as
     * long as that stays true. A lockfile that changes stops looking like
     * them, and everything else that makes a scan due is a clock running out.
     *
     * Kept on this server's disk, so each server works it out once from what
     * the cache says was sent. A disk that cannot be written leaves every
     * request hashing the lockfiles, which is what happened before.
     */
    protected function settle(?string $fingerprint, int $seconds): void
    {
        if ($fingerprint === null) {
            return;
        }

        try {
            $path = $this->settledPath();
            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return;
            }

            $until = Carbon::now()->getTimestamp() + $seconds;

            @file_put_contents($path, "{$until}\n{$fingerprint}", LOCK_EX);
        } catch (Throwable) {
            //
        }
    }

    protected function isSettled(string $fingerprint): bool
    {
        try {
            $path = $this->settledPath();

            if (! is_file($path)) {
                return false;
            }

            [$until, $settled] = array_pad(explode("\n", (string) @file_get_contents($path), 2), 2, '');

            return $settled === $fingerprint && (int) $until > Carbon::now()->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }

    protected function settledPath(): string
    {
        return $this->settledPath ?? storage_path('larabug/cve-settled');
    }

    /**
     * How long until a scan of these lockfiles is due, 0 when one is due now.
     *
     * @param  array<string, string>  $hashes
     */
    protected function secondsUntilDue(array $hashes): int
    {
        // The server has told us it does not want these yet. Without this the
        // scan is enabled by default but the project is not, so every single
        // request would post the lockfile and collect another 403.
        $backedOffAt = $this->cache->get($this->backoffCacheKey);

        if ($backedOffAt) {
            return max(1, (int) $backedOffAt + $this->backoffSeconds - time());
        }

        foreach ($hashes as $ecosystem => $hash) {
            if ($this->cache->get($this->lastSentHashCacheKeys[$ecosystem]) !== $hash) {
                return 0;
            }
        }

        $lastSentAt = (int) $this->cache->get($this->lastSentAtCacheKey, 0);

        return max(0, $lastSentAt + $this->throttleSeconds() - time());
    }

    protected function throttleSeconds(): int
    {
        return max(1, (int) config('larabug.cve.request_throttle_hours', 24)) * 3600;
    }

    /**
     * @param  array<string, array<string, mixed>>  $payload
     */
    protected function send(array $payload, ?string $fingerprint): void
    {
        $response = $this->client->report([
            'type' => 'cve_scan',
            ...$payload,
        ]);

        $status = $response && method_exists($response, 'getStatusCode')
            ? $response->getStatusCode()
            : 0;

        $body = $response && method_exists($response, 'getBody')
            ? json_decode((string) $response->getBody(), true)
            : null;

        $unchanged = is_array($body) && ($body['skipped'] ?? null) === 'unchanged';

        // Only 2xx and "skipped" count as the scan having landed.
        if (($status >= 200 && $status < 300) || $unchanged) {
            $ttl = $this->throttleSeconds();

            foreach ($payload as $ecosystem => $lock) {
                $this->cache->put($this->lastSentHashCacheKeys[$ecosystem], $lock['content_hash'], $ttl);
            }

            $this->cache->put($this->lastSentAtCacheKey, time(), $ttl);

            $this->settle($fingerprint, $ttl);

            return;
        }

        // 402 is the issue limit for this billing period being reached, 429 the
        // account sending too fast. Either holds off every sender of issues,
        // not just this one, and lifts by itself once the window passes. The
        // hash is left unsent, so the scan goes again after that.
        if (LimitBackoff::record($response, LimitBackoff::ISSUES)) {
            return;
        }

        // 403 is the server saying this project has not turned CVE scanning on.
        // That is a settled answer, not a blip, so back off rather than asking
        // again on the very next request. An hour is short enough that enabling
        // it in the panel starts working without waiting out the full throttle
        // window.
        if ($status === 403) {
            $this->cache->put($this->backoffCacheKey, time(), $this->backoffSeconds);

            $this->settle($fingerprint, $this->backoffSeconds);
        }

        // Anything else (a 5xx, a timeout) leaves the cache untouched, so the
        // next request tries again.
    }
}
