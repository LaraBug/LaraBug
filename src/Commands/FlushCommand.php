<?php

namespace LaraBug\Commands;

use LaraBug\Http\Client;
use LaraBug\Http\FlushStop;
use LaraBug\Http\RetryBuffer;
use Illuminate\Console\Command;

class FlushCommand extends Command
{
    protected $signature = 'larabug:flush';

    protected $description = 'Send the events LaraBug kept while its server could not take them';

    /**
     * Always exits 0. A server that is still down is the reason the events are
     * waiting, not a failure on this side, and a failed exit would have the
     * scheduler report it to that same server every minute it lasts.
     */
    public function handle(Client $client): int
    {
        $retryBuffer = RetryBuffer::fromConfig();

        if ($retryBuffer === null) {
            $this->comment('[LaraBug] The retry buffer is disabled, nothing to send');

            return self::SUCCESS;
        }

        $waiting = $retryBuffer->count();

        if ($waiting === 0) {
            $this->info('[LaraBug] Nothing is waiting to be sent');

            return self::SUCCESS;
        }

        $this->info("[LaraBug] Sending {$waiting} kept ".str('event')->plural($waiting).'...');

        $result = $retryBuffer->flush($client);

        $sent = "[LaraBug] Sent {$result->sent}, {$result->remaining} still waiting";

        if ($result->discarded > 0) {
            $sent .= ", {$result->discarded} discarded as too old or refused by the server";
        }

        $this->info($sent);

        match ($result->stoppedBy) {
            FlushStop::Locked => $this->comment('[LaraBug] Another process is sending them right now'),
            FlushStop::Paused => $this->warn('[LaraBug] The server asked not to be sent anything until '.date('H:i:s', $retryBuffer->pausedUntil())),
            FlushStop::Unreachable => $this->warn('[LaraBug] Could not reach the server, the rest is kept for the next run'),
            FlushStop::ServerError => $this->warn('[LaraBug] The server answered with an error again, the rest is kept for the next run'),
            FlushStop::RateLimited => $this->warn('[LaraBug] This project is sending faster than its plan\'s rate limit, the rest is kept for the next run'),
            FlushStop::Limit, null => null,
        };

        return self::SUCCESS;
    }
}
