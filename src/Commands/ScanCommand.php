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

    /**
     * Exit codes are the literal 0, 1 and 2 rather than Command::SUCCESS,
     * FAILURE and INVALID. Those constants only exist from Laravel 8, and this
     * package still supports 6 and 7, where they are a fatal "undefined class
     * constant" the moment the command returns.
     */
    public function handle(Client $client, ComposerLockScanner $scanner): int
    {
        if (! config('larabug.cve.enabled', true)) {
            $this->warn('CVE scanning is disabled. Remove LB_CVE_ENABLED=false to enable it.');
            return 2;
        }

        $lockPath = $this->option('lock') ?: config('larabug.cve.lock_path');
        $includeDev = (bool) ($this->option('include-dev') ?: config('larabug.cve.include_dev', false));
        $environment = config('larabug.cve.environment') ?: config('app.env');

        $payload = $scanner->scan($lockPath, $includeDev, $environment);

        if ($payload === null) {
            $this->error('Could not read composer.lock at ' . ($lockPath ?: base_path('composer.lock')));
            return 1;
        }

        $this->info('Scanning ' . count($payload['packages']) . ' packages against LaraBug CVE database...');

        $response = $client->report([
            'type' => 'cve_scan',
            'composer_lock' => $payload,
        ]);

        if ($response === null) {
            $this->error('Failed to reach LaraBug. Check your network and configuration.');
            return 1;
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 0;
        $body = method_exists($response, 'getBody') ? (string) $response->getBody() : '';

        if ($status === 403) {
            $this->error('CVE scanning is not enabled on this project in LaraBug. Enable it on the project settings page.');
            return 1;
        }

        if ($status >= 400) {
            $this->error("LaraBug returned HTTP {$status}: {$body}");
            return 1;
        }

        $json = json_decode($body, true);

        if (isset($json['skipped']) && $json['skipped'] === 'unchanged') {
            $this->info('composer.lock unchanged since last scan — skipped.');
            return 0;
        }

        if (isset($json['queued']) && $json['queued']) {
            $this->info('Scan queued. Snapshot ID: ' . ($json['snapshot_id'] ?? 'unknown'));
            $this->line('Results will appear in your Issues list once the scan completes.');
            return 0;
        }

        $this->info('Scan accepted.');
        return 0;
    }
}
