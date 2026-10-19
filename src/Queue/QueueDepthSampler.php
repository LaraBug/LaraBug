<?php

namespace LaraBug\Queue;

use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\Factory;

/**
 * How much is waiting on this application's queues, right now.
 *
 * Everything the job monitor reports comes from a worker touching a job, so a
 * queue whose workers have stopped sends nothing at all. Its depth is the one
 * thing that still says so, and it can only be read from the broker.
 *
 * The finer measurements (pending, delayed, reserved, the oldest job) only
 * exist on recent Laravel queues, so each is asked for only when the queue
 * instance has the method, and is null when it does not. Without
 * `pendingSize()`, as on Laravel 11, `pending` falls back to `size()`, which
 * counts delayed and reserved jobs along with the ones ready to run.
 */
class QueueDepthSampler
{
    /**
     * Drivers that run a job the moment it is dispatched, or never, so there
     * is no queue to measure.
     *
     * @var array<int, string>
     */
    protected array $unsizableDrivers = ['sync', 'null', 'deferred', 'background'];

    public function __construct(protected readonly Factory $queues)
    {
    }

    /**
     * @return array<int, array{
     *     connection: string,
     *     queue: string,
     *     driver: string,
     *     pending: int,
     *     delayed: ?int,
     *     reserved: ?int,
     *     oldest_pending_at: ?string,
     *     sampled_at: string
     * }>
     */
    public function sample(): array
    {
        $samples = [];

        foreach ($this->queues() as ['connection' => $connection, 'queue' => $queue]) {
            if (! $this->isSizable($connection)) {
                continue;
            }

            $sample = $this->measure($connection, $queue);

            if ($sample !== null) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * The queues to measure: the configured list when there is one, otherwise
     * the default connection's own queue and every queue Horizon supervises in
     * this environment.
     *
     * @return array<int, array{connection: string, queue: string}>
     */
    public function queues(): array
    {
        $configured = config('larabug.jobs.depth.queues');

        $queues = is_array($configured)
            ? $this->configuredQueues($configured)
            : [$this->defaultQueue(), ...$this->horizonQueues()];

        $unique = [];

        foreach ($queues as $entry) {
            $unique["{$entry['connection']}:{$entry['queue']}"] = $entry;
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, mixed>  $configured  `connection:queue` strings.
     * @return array<int, array{connection: string, queue: string}>
     */
    protected function configuredQueues(array $configured): array
    {
        $queues = [];

        foreach ($configured as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            // Split once: an SQS queue can be named by its URL, colons and all.
            [$connection, $queue] = array_pad(explode(':', $entry, 2), 2, '');

            if ($connection === '' || $queue === '') {
                continue;
            }

            $queues[] = ['connection' => $connection, 'queue' => $queue];
        }

        return $queues;
    }

    /**
     * @return array{connection: string, queue: string}
     */
    protected function defaultQueue(): array
    {
        $connection = (string) config('queue.default');

        return [
            'connection' => $connection,
            'queue' => (string) config("queue.connections.{$connection}.queue", 'default'),
        ];
    }

    /**
     * The queues named by the supervisors Horizon would start here.
     *
     * Read from Horizon's config rather than its repositories, so this works
     * the same whether or not Horizon is running, which is the case this
     * sampler exists for. Environments are matched the way Horizon matches
     * them, so a wildcard environment is honoured, and each supervisor is
     * merged over its `defaults` entry the way Horizon merges it.
     *
     * @return array<int, array{connection: string, queue: string}>
     */
    protected function horizonQueues(): array
    {
        $horizon = config('horizon');

        if (! is_array($horizon)) {
            return [];
        }

        $environment = (string) config('app.env');

        $supervisors = collect($horizon['environments'] ?? [])
            ->first(fn ($supervisors, $name) => Str::is((string) $name, $environment), []);

        $queues = [];

        foreach ((array) $supervisors as $name => $supervisor) {
            $options = array_merge((array) ($horizon['defaults'][$name] ?? []), (array) $supervisor);

            $connection = (string) ($options['connection'] ?? 'redis');

            // Horizon falls back to the connection's own queue the same way.
            $names = $options['queue'] ?? config("queue.connections.{$connection}.queue", 'default');

            foreach (is_array($names) ? $names : explode(',', (string) $names) as $queue) {
                $queue = trim((string) $queue);

                if ($queue !== '') {
                    $queues[] = ['connection' => $connection, 'queue' => $queue];
                }
            }
        }

        return $queues;
    }

    /**
     * A failover connection is only as sizable as the connection it sends to
     * first, since that is the one its own size() asks.
     */
    protected function isSizable(string $connection): bool
    {
        $driver = $this->driver($connection);

        if ($driver === null) {
            return false;
        }

        if (in_array($driver, $this->unsizableDrivers, true)) {
            return false;
        }

        if ($driver !== 'failover') {
            return true;
        }

        $first = config("queue.connections.{$connection}.connections.0");

        if (! is_string($first)) {
            return false;
        }

        $firstDriver = $this->driver($first);

        return $firstDriver !== null
            && $firstDriver !== 'failover'
            && ! in_array($firstDriver, $this->unsizableDrivers, true);
    }

    protected function driver(string $connection): ?string
    {
        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * @return array{
     *     connection: string,
     *     queue: string,
     *     driver: string,
     *     pending: int,
     *     delayed: ?int,
     *     reserved: ?int,
     *     oldest_pending_at: ?string,
     *     sampled_at: string
     * }|null
     */
    protected function measure(string $connection, string $queue): ?array
    {
        try {
            $instance = $this->queues->connection($connection);

            $pending = method_exists($instance, 'pendingSize')
                ? $instance->pendingSize($queue)
                : $instance->size($queue);

            $delayed = method_exists($instance, 'delayedSize') ? $instance->delayedSize($queue) : null;
            $reserved = method_exists($instance, 'reservedSize') ? $instance->reservedSize($queue) : null;

            $oldestPendingAt = method_exists($instance, 'creationTimeOfOldestPendingJob')
                ? $instance->creationTimeOfOldestPendingJob($queue)
                : null;
        } catch (Throwable) {
            // An unreachable broker, or a driver that cannot be counted. Left out
            // rather than sent as zero: nothing measured is not nothing waiting.
            return null;
        }

        return [
            'connection' => $connection,
            'queue' => $queue,
            'driver' => (string) $this->driver($connection),
            'pending' => (int) $pending,
            'delayed' => $delayed === null ? null : (int) $delayed,
            'reserved' => $reserved === null ? null : (int) $reserved,
            'oldest_pending_at' => is_numeric($oldestPendingAt)
                ? Carbon::createFromTimestamp((int) $oldestPendingAt)->toIso8601String()
                : null,
            'sampled_at' => now()->toIso8601String(),
        ];
    }
}
