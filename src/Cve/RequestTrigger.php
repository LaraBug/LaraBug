<?php

namespace LaraBug\Cve;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use LaraBug\Http\Client;
use LaraBug\Scanners\ComposerLockScanner;

/**
 * Request-piggyback trigger for CVE scans.
 *
 * Wired up via `app()->terminating(...)` from the ServiceProvider, so it runs
 * after the response is sent to the user — zero perceptible latency. Cached
 * heavily so the hot path is a single Cache::get on most requests:
 *
 *   - The lockfile hash is computed once per process (it doesn't change at runtime).
 *   - The "last sent hash" is cached for `request_throttle_hours`. A request only
 *     does work when (a) the hash changes (deploy), or (b) the throttle expires.
 *   - A short-lived cache lock prevents a thundering herd of concurrent requests
 *     all triggering scans simultaneously.
 */
class RequestTrigger
{
    protected const CACHE_KEY_HASH = 'larabug.cve.last_sent_hash';
    protected const CACHE_KEY_TIMESTAMP = 'larabug.cve.last_sent_at';
    protected const CACHE_KEY_BACKOFF = 'larabug.cve.backoff_until';
    protected const LOCK_KEY = 'larabug.cve.trigger_lock';
    protected const LOCK_SECONDS = 60;
    protected const BACKOFF_SECONDS = 3600;

    /** Memoized lockfile payload for this process. */
    protected static ?array $cachedPayload = null;
    protected static bool $alreadyFired = false;

    protected CacheRepository $cache;
    protected ComposerLockScanner $scanner;
    protected Client $client;

    // Written out rather than promoted: promotion and the trailing comma in a
    // parameter list are both PHP 8.0+, and this package still supports 7.4.
    // Typed properties are fine, those landed in 7.4.
    public function __construct(CacheRepository $cache, ComposerLockScanner $scanner, Client $client)
    {
        $this->cache = $cache;
        $this->scanner = $scanner;
        $this->client = $client;
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

        $payload = $this->payload();
        if ($payload === null) {
            return;
        }

        if (! $this->shouldFire($payload['content_hash'])) {
            return;
        }

        $lock = method_exists($this->cache, 'lock')
            ? $this->cache->lock(self::LOCK_KEY, self::LOCK_SECONDS)
            : null;

        if ($lock && ! $lock->get()) {
            // Another process is already on it.
            return;
        }

        try {
            $this->send($payload);
        } finally {
            optional($lock)->release();
        }
    }

    protected function payload(): ?array
    {
        if (self::$cachedPayload !== null) {
            return self::$cachedPayload;
        }

        $payload = $this->scanner->scan(
            config('larabug.cve.lock_path'),
            (bool) config('larabug.cve.include_dev', false),
            config('larabug.cve.environment') ?: config('app.env'),
        );

        if ($payload !== null) {
            self::$cachedPayload = $payload;
        }

        return $payload;
    }

    protected function shouldFire(string $currentHash): bool
    {
        // The server has told us it does not want these yet. Without this the
        // scan is enabled by default but the project is not, so every single
        // request would post the lockfile and collect another 403.
        if ($this->cache->get(self::CACHE_KEY_BACKOFF)) {
            return false;
        }

        $lastHash = $this->cache->get(self::CACHE_KEY_HASH);

        if ($lastHash !== $currentHash) {
            return true;
        }

        $lastSentAt = (int) $this->cache->get(self::CACHE_KEY_TIMESTAMP, 0);
        $throttleSeconds = max(1, (int) config('larabug.cve.request_throttle_hours', 24)) * 3600;

        return (time() - $lastSentAt) >= $throttleSeconds;
    }

    protected function send(array $payload): void
    {
        $response = $this->client->report([
            'type' => 'cve_scan',
            'composer_lock' => $payload,
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
            $this->cache->put(self::CACHE_KEY_HASH, $payload['content_hash'], $ttl);
            $this->cache->put(self::CACHE_KEY_TIMESTAMP, time(), $ttl);

            return;
        }

        // 403 is the server saying this project has not turned CVE scanning on.
        // That is a settled answer, not a blip, so back off rather than asking
        // again on the very next request. Short enough that enabling it in the
        // panel starts working without waiting out the full throttle window.
        if ($status === 403) {
            $this->cache->put(self::CACHE_KEY_BACKOFF, time(), self::BACKOFF_SECONDS);
        }

        // Anything else (a 5xx, a timeout) leaves the cache untouched, so the
        // next request tries again.
    }
}
