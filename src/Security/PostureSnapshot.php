<?php

namespace LaraBug\Security;

use Throwable;
use LaraBug\Support\SdkVersion;
use LaraBug\Security\Checks\CorsCheck;
use LaraBug\Security\Checks\PulseCheck;
use LaraBug\Security\Checks\HorizonCheck;
use LaraBug\Security\Checks\DebugbarCheck;
use LaraBug\Security\Checks\DebugModeCheck;
use LaraBug\Security\Checks\TelescopeCheck;
use LaraBug\Security\Checks\DevPackagesCheck;
use LaraBug\Security\Checks\SessionCookieCheck;
use LaraBug\Security\Checks\TrustedProxiesCheck;

/**
 * The application's security posture as it is configured right now, for
 * LaraBug to check: booleans, names from fixed sets and versions, never a
 * value from the configuration.
 *
 * Every check runs on its own. One that throws is null in the snapshot, so
 * one surprise in an application's setup costs that check rather than the
 * whole snapshot, and nothing ever reaches the application.
 *
 * Taken from the console only, by `larabug:security-posture`. A gate asked
 * about a guest inside a request could find that request's user instead.
 */
class PostureSnapshot
{
    /** @var array<string, class-string<\LaraBug\Security\Checks\PostureCheck>> */
    protected array $checks = [
        'debug_mode' => DebugModeCheck::class,
        'session_cookie' => SessionCookieCheck::class,
        'trusted_proxies' => TrustedProxiesCheck::class,
        'cors' => CorsCheck::class,
        'dev_packages' => DevPackagesCheck::class,
    ];

    /** @var array<string, class-string<\LaraBug\Security\Checks\PostureCheck>> */
    protected array $devTools = [
        'telescope' => TelescopeCheck::class,
        'horizon' => HorizonCheck::class,
        'pulse' => PulseCheck::class,
        'debugbar' => DebugbarCheck::class,
    ];

    /**
     * @param  string  $trigger  `deploy`, `daily` or `manual`.
     * @return array<string, mixed>
     */
    public function take(string $trigger): array
    {
        $environment = (string) config('app.env');

        return [
            // Bumped when the shape changes in a way the server has to know about.
            'schema' => 1,
            'trigger' => $trigger,
            'environment' => $environment,
            'production' => $environment === 'production',
            'release' => $this->release(),
            'host' => (string) gethostname(),
            'taken_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'php' => SdkVersion::php(),
            'laravel' => SdkVersion::laravel(),
            'sdk' => SdkVersion::version(),
            'checks' => [
                ...$this->run($this->checks),
                'dev_tools' => $this->run($this->devTools),
            ],
        ];
    }

    /**
     * @param  array<string, class-string<\LaraBug\Security\Checks\PostureCheck>>  $checks
     * @return array<string, array<string, mixed>|null>
     */
    protected function run(array $checks): array
    {
        $results = [];

        foreach ($checks as $key => $check) {
            try {
                $results[$key] = app($check)->collect();
            } catch (Throwable) {
                $results[$key] = null;
            }
        }

        return $results;
    }

    protected function release(): ?string
    {
        $release = config('larabug.project_version');

        return is_scalar($release) && (string) $release !== '' ? (string) $release : null;
    }
}
