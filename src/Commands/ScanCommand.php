<?php

namespace LaraBug\Commands;

use LaraBug\Http\Client;
use Illuminate\Console\Command;
use LaraBug\Scanners\NpmLockScanner;
use LaraBug\Scanners\ComposerLockScanner;

class ScanCommand extends Command
{
    protected $signature = 'larabug:scan
        {--lock= : Path to composer.lock (defaults to base_path("composer.lock"))}
        {--include-dev : Include packages-dev entries in the scan}';

    protected $description = 'Scan this app\'s composer.lock and npm lockfile for known CVEs via LaraBug.';

    public function handle(Client $client, ComposerLockScanner $scanner, NpmLockScanner $npmScanner): int
    {
        if (! config('larabug.cve.enabled', true)) {
            $this->warn('CVE scanning is disabled. Remove LB_CVE_ENABLED=false to enable it.');

            return self::INVALID;
        }

        $lockPath = $this->option('lock') ?: config('larabug.cve.lock_path');
        $includeDev = (bool) ($this->option('include-dev') ?: config('larabug.cve.include_dev', false));
        $environment = config('larabug.cve.environment') ?: config('app.env');

        $scansNpm = (bool) config('larabug.cve.npm', true);

        $composerLock = $scanner->scan($lockPath, $includeDev, $environment);
        $npmLock = $scansNpm ? $npmScanner->scan($environment) : null;

        $resolvedLockPath = $lockPath ?: base_path('composer.lock');

        if ($composerLock === null && $npmLock === null) {
            $this->error("Could not read composer.lock at {$resolvedLockPath}");

            if ($scansNpm) {
                $basePath = base_path();

                $this->error("Found no npm lockfile in {$basePath} either.");
            }

            return self::FAILURE;
        }

        if ($composerLock === null) {
            $this->warn("Could not read composer.lock at {$resolvedLockPath}, so only {$npmLock['lockfile']} is scanned.");
        }

        if ($composerLock !== null) {
            $packageCount = count($composerLock['packages']);

            $this->info("Scanning {$packageCount} packages against LaraBug CVE database...");
        }

        if ($npmLock !== null) {
            $npmPackageCount = count($npmLock['packages']);

            $this->info("Scanning {$npmPackageCount} npm packages from {$npmLock['lockfile']} against LaraBug CVE database...");
        }

        $response = $client->report(array_filter([
            'type' => 'cve_scan',
            'composer_lock' => $composerLock,
            'npm_lock' => $npmLock,
        ]));

        if ($response === null) {
            $this->error('Failed to reach LaraBug. Check your network and configuration.');

            return self::FAILURE;
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 0;
        $body = method_exists($response, 'getBody') ? (string) $response->getBody() : '';

        if ($status === 403) {
            $this->error('CVE scanning is not enabled on this project in LaraBug. Enable it on the project settings page.');

            return self::FAILURE;
        }

        // A refusal that expires, unlike the 403 above: the limit lifts when
        // the billing period rolls over, or the moment the plan does.
        if ($status === 402) {
            $this->error('This month\'s issue limit is reached, so the scan was refused. It runs again once the billing period rolls over.');

            return self::FAILURE;
        }

        if ($status === 429) {
            $this->error('This project is sending faster than its plan\'s rate limit, so the scan was refused. Try again in a minute.');

            return self::FAILURE;
        }

        if ($status >= 400) {
            $this->error("LaraBug returned HTTP {$status}: {$body}");

            return self::FAILURE;
        }

        $json = json_decode($body, true);

        if (isset($json['skipped']) && $json['skipped'] === 'unchanged') {
            $lockfiles = array_filter([
                $composerLock === null ? null : 'composer.lock',
                $npmLock['lockfile'] ?? null,
            ]);

            $this->info(implode(' and ', $lockfiles) . ' unchanged since last scan — skipped.');

            return self::SUCCESS;
        }

        if (! empty($json['queued'])) {
            $snapshotId = $json['snapshot_id'] ?? 'unknown';

            $this->info("Scan queued. Snapshot ID: {$snapshotId}");
            $this->line('Results will appear in your Issues list once the scan completes.');

            return self::SUCCESS;
        }

        $this->info('Scan accepted.');

        return self::SUCCESS;
    }
}
