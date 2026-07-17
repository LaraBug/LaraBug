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
    protected const LOCK_KEY = 'larabug.cve.trigger_lock';
    protected const LOCK_SECONDS = 60;

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
        if (! config('larabug.cve.enabled', false)) {
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

        // Only treat 2xx and "skipped" responses as success. 403 (feature off
        // on the server) and other errors leave the cache untouched so we try
        // again on the next request rather than wedging until the throttle expires.
        $status = $response && method_exists($response, 'getStatusCode')
            ? $response->getStatusCode()
            : 0;

        $body = $response && method_exists($response, 'getBody')
            ? json_decode((string) $response->getBody(), true)
            : null;

        $unchanged = is_array($body) && ($body['skipped'] ?? null) === 'unchanged';

        if (($status >= 200 && $status < 300) || $unchanged) {
            $ttl = max(1, (int) config('larabug.cve.request_throttle_hours', 24)) * 3600;
            $this->cache->put(self::CACHE_KEY_HASH, $payload['content_hash'], $ttl);
            $this->cache->put(self::CACHE_KEY_TIMESTAMP, time(), $ttl);
        }
    }
}
