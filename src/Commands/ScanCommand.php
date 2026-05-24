<?php

namespace LaraBug\Commands;

use Illuminate\Console\Command;
use LaraBug\Http\Client;
use LaraBug\Scanners\ComposerLockScanner;

class ScanCommand extends Command
{
    protected $signature = 'larabug:scan
        {--lock= : Path to composer.lock (defaults to base_path("composer.lock"))}
        {--include-dev : Include packages-dev entries in the scan}';

    protected $description = 'Scan this app\'s composer.lock for known CVEs via LaraBug.';

    public function handle(Client $client, ComposerLockScanner $scanner): int
    {
        if (! config('larabug.cve.enabled', false)) {
            $this->warn('CVE scanning is disabled. Set LB_CVE_ENABLED=true to enable.');
            return self::INVALID;
        }

        $lockPath = $this->option('lock') ?: config('larabug.cve.lock_path');
        $includeDev = (bool) ($this->option('include-dev') ?: config('larabug.cve.include_dev', false));
        $environment = config('larabug.cve.environment') ?: config('app.env');

        $payload = $scanner->scan($lockPath, $includeDev, $environment);

        if ($payload === null) {
            $this->error('Could not read composer.lock at ' . ($lockPath ?: base_path('composer.lock')));
            return self::FAILURE;
        }

        $this->info('Scanning ' . count($payload['packages']) . ' packages against LaraBug CVE database...');

        $response = $client->report([
            'type' => 'cve_scan',
            'composer_lock' => $payload,
        ]);

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

        if ($status >= 400) {
            $this->error("LaraBug returned HTTP {$status}: {$body}");
            return self::FAILURE;
        }

        $json = json_decode($body, true);

        if (isset($json['skipped']) && $json['skipped'] === 'unchanged') {
            $this->info('composer.lock unchanged since last scan — skipped.');
            return self::SUCCESS;
        }

        if (isset($json['queued']) && $json['queued']) {
            $this->info('Scan queued. Snapshot ID: ' . ($json['snapshot_id'] ?? 'unknown'));
            $this->line('Results will appear in your Issues list once the scan completes.');
            return self::SUCCESS;
        }

        $this->info('Scan accepted.');
        return self::SUCCESS;
    }
}
