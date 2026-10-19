<?php

namespace LaraBug\Support\Concerns;

use Throwable;
use OverflowException;
use LaraBug\Http\Client;
use LaraBug\Support\LimitBackoff;

/**
 * What a telemetry buffer does with a batch the server refused.
 *
 * A 402 means the limit for this billing period is reached, and the batch is
 * given up on as it always was. A 429 means the account sent too fast, which
 * lifts within the minute, so the batch goes back into the buffer and out
 * again once the window passes.
 *
 * Bounded, because a buffer that keeps collecting through a hold must not grow
 * without limit: past ten batches the oldest records are dropped, counted, and
 * reported once the stream is open again. A web request that ends inside a hold
 * loses what it held. Waiting the window out would hold the request instead.
 *
 * @property array<int, array<string, mixed>> $buffer
 * @property array<string, mixed> $config
 * @property Client $client
 */
trait RequeuesRateLimitedRecords
{
    protected int $droppedRecords = 0;

    abstract protected function batchSize(): int;

    /**
     * Note a refusal, keeping the batch when it was a rate limit.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    protected function refusedByLimit(mixed $response, array $records): bool
    {
        if (! LimitBackoff::record($response, LimitBackoff::TELEMETRY)) {
            return false;
        }

        if (LimitBackoff::isRateLimitResponse($response)) {
            $this->requeue($records);
        }

        return true;
    }

    /**
     * Put refused records back in front of the ones collected since, then
     * trim the buffer back to its bound, oldest first.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    protected function requeue(array $records = []): void
    {
        $this->buffer = array_merge($records, $this->buffer);

        $overflow = count($this->buffer) - $this->batchSize() * 10;

        if ($overflow <= 0) {
            return;
        }

        $this->buffer = array_slice($this->buffer, $overflow);
        $this->droppedRecords += $overflow;
    }

    /**
     * Report what the bound dropped during a hold. Called once a batch has gone
     * through, so the report is not itself refused by the same limit.
     */
    protected function reportDroppedRecords(): void
    {
        if ($this->droppedRecords === 0) {
            return;
        }

        $dropped = $this->droppedRecords;
        $this->droppedRecords = 0;

        $this->reportError(
            new OverflowException("Dropped {$dropped} records held back by a rate limit"),
            $dropped,
        );
    }

    /**
     * Report buffer errors back to LaraBug (ironic, but useful for debugging).
     */
    protected function reportError(Throwable $e, int $lostEvents): void
    {
        try {
            if ($this->config['jobs']['report_buffer_errors'] ?? false) {
                $this->client->report([
                    'type' => 'buffer_error',
                    'exception' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'lost_events' => $lostEvents,
                    ],
                ]);
            }
        } catch (Throwable $ignored) {
            // Never let error reporting break the app.
        }
    }
}
