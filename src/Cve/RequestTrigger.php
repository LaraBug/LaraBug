<?php

namespace LaraBug\Cve;

use LaraBug\Http\Client;
use LaraBug\Support\LimitBackoff;
use LaraBug\Scanners\NpmLockScanner;
use LaraBug\Scanners\ComposerLockScanner;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Request-piggyback trigger for CVE scans.
 *
 * Wired up via `app()->terminating(...)` from the ServiceProvider, so it runs
 * after the response is sent to the user — zero perceptible latency. Cached
 * heavily so the hot path is hashing the lockfiles and a few Cache::gets on
 * most requests: the last sent hash of each lockfile is cached for
 * `request_throttle_hours`, a lockfile is only parsed once its hash says a
 * scan is due, and a short-lived cache lock prevents a thundering herd of
 * concurrent requests all triggering scans simultaneously.
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

    public function __construct(
        protected readonly CacheRepository $cache,
        protected readonly ComposerLockScanner $composerScanner,
        protected readonly NpmLockScanner $npmScanner,
        protected readonly Client $client,
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

        $hashes = $this->hashes();

        if ($hashes === []) {
            return;
        }

        if (! $this->shouldFire($hashes)) {
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
            $this->send($payload);
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
     * @param  array<string, string>  $hashes
     */
    protected function shouldFire(array $hashes): bool
    {
        // A CVE finding is an issue, and the issue limit is reached for now.
        if (! LimitBackoff::allows(LimitBackoff::ISSUES)) {
            return false;
        }

        // The server has told us it does not want these yet. Without this the
        // scan is enabled by default but the project is not, so every single
        // request would post the lockfile and collect another 403.
        if ($this->cache->get($this->backoffCacheKey)) {
            return false;
        }

        foreach ($hashes as $ecosystem => $hash) {
            if ($this->cache->get($this->lastSentHashCacheKeys[$ecosystem]) !== $hash) {
                return true;
            }
        }

        $lastSentAt = (int) $this->cache->get($this->lastSentAtCacheKey, 0);
        $throttleSeconds = max(1, (int) config('larabug.cve.request_throttle_hours', 24)) * 3600;

        return (time() - $lastSentAt) >= $throttleSeconds;
    }

    /**
     * @param  array<string, array<string, mixed>>  $payload
     */
    protected function send(array $payload): void
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
            $ttl = max(1, (int) config('larabug.cve.request_throttle_hours', 24)) * 3600;

            foreach ($payload as $ecosystem => $lock) {
                $this->cache->put($this->lastSentHashCacheKeys[$ecosystem], $lock['content_hash'], $ttl);
            }

            $this->cache->put($this->lastSentAtCacheKey, time(), $ttl);

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
            $this->cache->put($this->backoffCacheKey, time(), 3600);
        }

        // Anything else (a 5xx, a timeout) leaves the cache untouched, so the
        // next request tries again.
    }
}
