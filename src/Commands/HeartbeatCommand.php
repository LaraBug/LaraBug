<?php

namespace LaraBug\Commands;

use Illuminate\Console\Command;
use LaraBug\Http\Client;
use LaraBug\Queue\Heartbeat;

class HeartbeatCommand extends Command
{
    protected $signature = 'larabug:heartbeat {--show : Print the payload instead of sending it}';

    protected $description = 'Report that this app\'s queue workers are alive';

    public function handle()
    {
        $payload = app(Heartbeat::class)->payload();

        if ($this->option('show')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! in_array(config('app.env'), (array) config('larabug.environments'), true)) {
            $this->info('[LaraBug] Environment ('.config('app.env').') is not configured to report, nothing sent');

            return 0;
        }

        $response = app(Client::class)->heartbeat($payload);

        // A heartbeat is worth nothing if sending it can break the scheduler, so
        // a failure is reported and swallowed. The panel's own view of a missing
        // heartbeat is the same either way: nothing arrived.
        if ($response === null) {
            $this->error('✗ [LaraBug] Could not reach the server');

            return 0;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $this->info('✓ [LaraBug] Heartbeat sent');

            return 0;
        }

        $this->error('✗ [LaraBug] Server answered '.$status);

        return 0;
    }
}
