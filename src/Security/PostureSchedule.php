<?php

namespace LaraBug\Security;

use Throwable;
use Illuminate\Contracts\Cache\Repository;

/**
 * When the security posture snapshot is due: once a day, and as soon as the
 * application changed since the last one, which is the first scheduler run
 * after a deploy.
 *
 * A change is noticed by a fingerprint of the release (`project_version`,
 * the git commit, composer.lock) and of the configuration the checks read.
 * Only its hash is kept, in the application's cache, never sent. The cache is
 * shared between servers, so the fingerprint is built from nothing that
 * differs between two servers running the same release.
 *
 * Asked by the scheduler every minute, before it starts a process, so the
 * question has to be cheap: a few cache reads, a hash of composer.lock and
 * two small files under .git.
 */
class PostureSchedule
{
    protected string $stateKey = 'larabug.security_posture.state';

    protected string $holdKey = 'larabug.security_posture.held_until';

    protected int $interval = 86400;

    public function __construct(protected readonly Repository $cache)
    {
    }

    /**
     * Whether the snapshot is switched on. Left unset, it is on everywhere
     * but the local and testing environments.
     */
    public static function enabled(): bool
    {
        $enabled = config('larabug.security.posture.enabled');

        if ($enabled === null || $enabled === '') {
            return ! app()->environment('local', 'testing');
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    /** Whether this environment is one LaraBug is told to report from. */
    public static function reports(): bool
    {
        return in_array(config('app.env'), (array) config('larabug.environments'), true);
    }

    /**
     * Whether the cache outlives the process. When it does not, nothing can
     * be remembered from one run to the next, and the scheduler falls back to
     * once a day.
     */
    public static function remembers(): bool
    {
        $store = config('cache.default');

        return ! in_array(config("cache.stores.{$store}.driver"), ['array', 'null', null], true);
    }

    /**
     * Why a snapshot is due: `deploy` when the application changed since the
     * last one or none was sent yet, `daily` when the last is a day old. Null
     * when none is due, or while the server asked to wait.
     */
    public function due(): ?string
    {
        if (! static::remembers()) {
            return 'daily';
        }

        try {
            if ((int) $this->cache->get($this->holdKey, 0) > now()->getTimestamp()) {
                return null;
            }

            $state = $this->cache->get($this->stateKey);

            if (! is_array($state) || ($state['fingerprint'] ?? null) !== $this->fingerprint()) {
                return 'deploy';
            }

            if (now()->getTimestamp() - (int) ($state['sent_at'] ?? 0) >= $this->interval) {
                return 'daily';
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Remember that this release's snapshot is taken care of, whatever the
     * server made of it, so the next one is due tomorrow or on the next deploy.
     */
    public function sent(): void
    {
        try {
            $this->cache->put($this->stateKey, [
                'fingerprint' => $this->fingerprint(),
                'sent_at' => now()->getTimestamp(),
            ], $this->interval * 7);

            $this->cache->forget($this->holdKey);
        } catch (Throwable) {
            //
        }
    }

    /** Send nothing for this long: the server asked to wait, or could not be reached. */
    public function holdFor(int $seconds): void
    {
        try {
            $this->cache->put($this->holdKey, now()->getTimestamp() + $seconds, max(1, $seconds));
        } catch (Throwable) {
            //
        }
    }

    public function fingerprint(): string
    {
        return sha1((string) json_encode([
            'release' => config('larabug.project_version'),
            'commit' => $this->commit(),
            'composer' => $this->composerLockHash(),
            'config' => array_map(fn (string $key) => config($key), [
                'app.env',
                'app.debug',
                'session.secure',
                'session.http_only',
                'session.same_site',
                'cors.paths',
                'cors.allowed_origins',
                'cors.allowed_origins_patterns',
                'cors.supports_credentials',
                'trustedproxy.proxies',
                'telescope.enabled',
                'telescope.middleware',
                'horizon.middleware',
                'pulse.enabled',
                'pulse.middleware',
                'debugbar.enabled',
                'debugbar.storage.open',
            ]),
        ]));
    }

    protected function composerLockHash(): ?string
    {
        $path = base_path('composer.lock');

        if (! is_file($path)) {
            return null;
        }

        return @hash_file('xxh128', $path) ?: null;
    }

    /**
     * The commit checked out, for a deploy that pulls into place. Read from
     * the files rather than by running git, which the scheduler's user may
     * not be allowed to.
     */
    protected function commit(): ?string
    {
        $git = base_path('.git');

        $head = is_file("{$git}/HEAD") ? trim((string) @file_get_contents("{$git}/HEAD")) : '';

        if ($head === '' || ! str_starts_with($head, 'ref: ')) {
            return $head === '' ? null : $head;
        }

        $ref = substr($head, 5);

        if (is_file("{$git}/{$ref}")) {
            return trim((string) @file_get_contents("{$git}/{$ref}")) ?: null;
        }

        // A ref packed away by git gc lives in packed-refs instead.
        foreach (@file("{$git}/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, " {$ref}")) {
                return strtok($line, ' ') ?: null;
            }
        }

        return null;
    }
}
