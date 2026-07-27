<?php

namespace LaraBug\Queue;

use Throwable;
use Illuminate\Support\Facades\Queue;

/**
 * A periodic "the workers are alive" report.
 *
 * Every other message this package sends reports something that happened, and
 * none of that can distinguish a queue with no work from a queue with no
 * workers — both send nothing — so this one is sent on a schedule instead.
 */
class Heartbeat
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'reported_at' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'horizon' => $this->horizon(),
            'queues' => $this->queues(),
        ];
    }

    /**
     * What Horizon says about itself, or that it is not here.
     *
     * Every repository call is guarded: Horizon can be installed with its Redis
     * connection down, and a heartbeat that throws never arrives, which the
     * panel would read as the workers being gone.
     *
     * @return array<string, mixed>
     */
    protected function horizon(): array
    {
        if (! class_exists('\Laravel\Horizon\Horizon')) {
            return ['installed' => false];
        }

        $report = [
            'installed' => true,
            'status' => null,
            'masters' => 0,
            'supervisors' => 0,
            'processes' => 0,
        ];

        try {
            $masters = app('\Laravel\Horizon\Contracts\MasterSupervisorRepository')->all();

            $report['masters'] = count($masters);

            // One paused master is the whole thing paused as far as a queue is
            // concerned, so the least healthy status wins.
            foreach ($masters as $master) {
                $status = $master->status ?? null;

                if ($report['status'] === null || $status === 'paused') {
                    $report['status'] = $status;
                }
            }

            if ($report['masters'] === 0) {
                $report['status'] = 'inactive';
            }
        } catch (Throwable $e) {
            $report['status'] = 'unknown';
        }

        try {
            $supervisors = app('\Laravel\Horizon\Contracts\SupervisorRepository')->all();

            $report['supervisors'] = count($supervisors);

            foreach ($supervisors as $supervisor) {
                $processes = isset($supervisor->processes) ? (array) $supervisor->processes : [];

                $report['processes'] += array_sum($processes);

                $options = isset($supervisor->options) ? (array) $supervisor->options : [];

                // The figures Horizon's own overview shows as "default" when the
                // supervisor has not been given them.
                $report['supervisor_options'][] = [
                    'name' => $supervisor->name ?? null,
                    'max_processes' => $options['maxProcesses'] ?? null,
                    'max_runtime' => $options['maxTime'] ?? null,
                    'max_throughput' => $options['maxJobs'] ?? null,
                    'balance' => $options['balance'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            // Leave the counts at zero: the master status above already says
            // whether anything is running.
        }

        return $report;
    }

    /**
     * How much is waiting on each queue, and how long it has been waiting.
     *
     * Horizon's workload answers both per queue; without it only the depth is
     * available and the panel works out the age from the jobs it has been sent.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function queues(): array
    {
        $fromHorizon = $this->horizonWorkload();

        if ($fromHorizon !== null) {
            return $fromHorizon;
        }

        $queues = [];

        foreach ($this->configuredQueues() as $entry) {
            $size = null;

            try {
                $size = Queue::connection($entry['connection'])->size($entry['queue']);
            } catch (Throwable $e) {
                // A driver that cannot be counted (sync) or an unreachable broker.
                // Null says "not measured", which is not the same as nothing waiting.
            }

            $queues[] = [
                'connection' => $entry['connection'],
                'queue' => $entry['queue'],
                'size' => $size,
                'wait' => null,
                'processes' => null,
            ];
        }

        return $queues;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function horizonWorkload(): ?array
    {
        if (! class_exists('\Laravel\Horizon\Horizon')) {
            return null;
        }

        try {
            $workload = app('\Laravel\Horizon\Contracts\WorkloadRepository')->get();
        } catch (Throwable $e) {
            return null;
        }

        $queues = [];

        foreach ($workload as $entry) {
            $entry = (array) $entry;

            $queues[] = [
                // Horizon joins a supervisor's queues with commas; passed through
                // as reported, since that string is what its own dashboard shows.
                'connection' => $entry['connection'] ?? null,
                'queue' => $entry['name'] ?? null,
                'size' => isset($entry['length']) ? (int) $entry['length'] : null,
                // Seconds in Horizon, milliseconds everywhere in this payload.
                'wait' => isset($entry['wait']) ? (int) round($entry['wait'] * 1000) : null,
                'processes' => isset($entry['processes']) ? (int) $entry['processes'] : null,
            ];
        }

        return $queues;
    }

    /**
     * The queues to measure when there is no Horizon to ask: configured
     * explicitly, or the default connection's own queue.
     *
     * @return array<int, array<string, string>>
     */
    protected function configuredQueues(): array
    {
        $configured = config('larabug.heartbeat.queues', []);

        if (! empty($configured)) {
            $queues = [];

            foreach ($configured as $entry) {
                if (is_string($entry)) {
                    $queues[] = ['connection' => config('queue.default'), 'queue' => $entry];

                    continue;
                }

                $queues[] = [
                    'connection' => $entry['connection'] ?? config('queue.default'),
                    'queue' => $entry['queue'] ?? 'default',
                ];
            }

            return $queues;
        }

        $connection = config('queue.default');

        return [[
            'connection' => $connection,
            'queue' => config("queue.connections.{$connection}.queue", 'default'),
        ]];
    }
}
