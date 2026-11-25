<?php

namespace LaraBug\Commands;

use Throwable;
use LaraBug\Http\Client;
use Illuminate\Console\Command;
use LaraBug\Support\LimitBackoff;
use LaraBug\Security\PostureSchedule;
use LaraBug\Security\PostureSnapshot;

class SecurityPostureCommand extends Command
{
    protected $signature = 'larabug:security-posture {--show : Print the snapshot instead of sending it}';

    protected $description = 'Send LaraBug a snapshot of this app\'s security configuration, without any secret values';

    /**
     * Always exits 0. Nothing on this side failed when LaraBug cannot take the
     * snapshot, and nothing here may surface in the application: a check that
     * throws is left out of the snapshot, and anything else is swallowed.
     */
    public function handle(): int
    {
        try {
            $this->report(app(Client::class), app(PostureSnapshot::class), app(PostureSchedule::class));
        } catch (Throwable) {
            $this->warn('[LaraBug] The security posture could not be taken, nothing sent');
        }

        return self::SUCCESS;
    }

    protected function report(Client $client, PostureSnapshot $snapshot, PostureSchedule $schedule): void
    {
        if ($this->option('show')) {
            $this->line(json_encode($snapshot->take('manual'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if (! PostureSchedule::enabled()) {
            $this->info('[LaraBug] The security posture is switched off in this environment, nothing sent');

            return;
        }

        $environment = config('app.env');

        if (! PostureSchedule::reports()) {
            $this->info("[LaraBug] Environment ({$environment}) is not configured to report, nothing sent");

            return;
        }

        if (! LimitBackoff::allows(LimitBackoff::TELEMETRY)) {
            $this->warn('[LaraBug] Telemetry is on hold for now, nothing sent');

            return;
        }

        $response = $client->reportSecurityPosture($snapshot->take($schedule->due() ?? 'manual'));

        // Kept by the retry buffer when it could not be sent, which sends it
        // again by itself. Without the buffer, the next try is in five minutes
        // rather than on the very next run of the scheduler.
        if ($response === null || $response->getStatusCode() >= 500) {
            if ($client->keepsFailedSends()) {
                $schedule->sent();

                $this->warn('[LaraBug] Could not reach the server, the snapshot is kept to be sent later');

                return;
            }

            $schedule->holdFor(300);

            $this->warn('[LaraBug] Could not reach the server, the snapshot was not sent');

            return;
        }

        if (LimitBackoff::record($response, LimitBackoff::TELEMETRY)) {
            // As long as the backoff asks, the server may name either stream,
            // and never less than a minute: the scheduler asks every minute.
            $schedule->holdFor(max(
                60,
                (LimitBackoff::resumesAt(LimitBackoff::TELEMETRY) ?? 0) - time(),
                (LimitBackoff::resumesAt(LimitBackoff::ISSUES) ?? 0) - time(),
            ));

            $this->warn(LimitBackoff::isRateLimitResponse($response)
                ? '[LaraBug] This project is sending faster than its plan\'s rate limit, so the snapshot was refused'
                : '[LaraBug] The telemetry limit is reached, so the snapshot was refused');

            return;
        }

        // Every other answer is final for this release. Asking again every
        // minute would get the same one, so the next snapshot is tomorrow's.
        $schedule->sent();

        $status = $response->getStatusCode();

        if ($status === 422) {
            $this->comment('[LaraBug] The server does not accept security posture snapshots yet');

            return;
        }

        if ($status < 200 || $status >= 300) {
            $this->warn("[LaraBug] Server answered {$status}, the snapshot was not accepted");

            return;
        }

        $this->info('[LaraBug] Sent the security posture');
    }
}
