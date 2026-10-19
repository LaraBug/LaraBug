<?php

namespace LaraBug\Commands;

use LaraBug\Http\Client;
use Illuminate\Console\Command;
use LaraBug\Support\LimitBackoff;
use LaraBug\Queue\QueueDepthSampler;

class QueueDepthCommand extends Command
{
    protected $signature = 'larabug:queue-depth {--show : Print the samples instead of sending them}';

    protected $description = 'Report how many jobs are waiting on this app\'s queues';

    public function handle(Client $client, QueueDepthSampler $sampler): int
    {
        if ($this->option('show')) {
            $this->line(json_encode($sampler->sample(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $environment = config('app.env');

        if (! in_array($environment, (array) config('larabug.environments'), true)) {
            $this->info("[LaraBug] Environment ({$environment}) is not configured to report, nothing sent");

            return self::SUCCESS;
        }

        // The server asked for a pause. Asking the brokers first would only
        // measure something nobody may send.
        if (! LimitBackoff::allows(LimitBackoff::TELEMETRY)) {
            $this->warn('[LaraBug] Telemetry is on hold for now, nothing sent');

            return self::SUCCESS;
        }

        $samples = $sampler->sample();

        if ($samples === []) {
            $this->info('[LaraBug] No queue could be sampled, nothing sent');

            return self::SUCCESS;
        }

        $response = $client->reportQueueDepth($samples);

        if ($response === null) {
            $this->error('[LaraBug] Could not reach the server');

            return self::FAILURE;
        }

        // A refusal the server will lift on its own. Failing on it would have the
        // scheduler report it as an exception every minute until it does. The
        // samples are not kept: the next minute measures again.
        if (LimitBackoff::record($response, LimitBackoff::TELEMETRY)) {
            $this->warn(LimitBackoff::isRateLimitResponse($response)
                ? '[LaraBug] This project is sending faster than its plan\'s rate limit, so the samples were refused'
                : '[LaraBug] The telemetry limit is reached, so the samples were refused');

            return self::SUCCESS;
        }

        $status = $response->getStatusCode();

        // A server that predates this stream answers 422 for a type it does not
        // know. Nothing is broken on this side, so it is not treated as a
        // failure that would be reported every minute until the server upgrades.
        if ($status === 422) {
            $this->comment('[LaraBug] The server does not accept queue depth samples yet');

            return self::SUCCESS;
        }

        if ($status < 200 || $status >= 300) {
            $this->error("[LaraBug] Server answered {$status}");

            return self::FAILURE;
        }

        $count = count($samples);

        $this->info("[LaraBug] Sent the depth of {$count} ".str('queue')->plural($count));

        return self::SUCCESS;
    }
}
